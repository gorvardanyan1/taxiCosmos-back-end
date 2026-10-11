<?php

namespace App\Models;

use App\Casts\GeoPointCast;
use App\Enums\TripCancelledBy;
use App\Enums\TripPaymentMethod;
use App\Enums\TripPaymentStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleClass;
use App\Exceptions\TripStateException;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One ride request and everything that happened to it. Status, driver, timestamps and the pricing
 * snapshot are not mass-assignable: they change through transitionTo() and the services that price
 * and settle the trip.
 *
 * Money is integer minor units in fare_currency. The pricing columns are a snapshot of the rules the
 * trip was priced with; once the trip is finished they can no longer be edited, so changing a fare
 * or commission rule later never rewrites history.
 */
#[Fillable(['pickup_location', 'dropoff_location', 'pickup_address', 'dropoff_address', 'vehicle_class', 'payment_method', 'payment_method_id'])]
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory;

    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const CODE_LENGTH = 8;

    /** Columns frozen once a trip is completed, cancelled or abandoned. */
    public const PRICING_COLUMNS = [
        'fare_currency', 'estimated_fare_amount', 'fare_amount', 'fare_rule_id', 'commission_rule_id',
        'surge_multiplier', 'fare_breakdown', 'commission_amount', 'driver_earnings_amount',
    ];

    protected $attributes = [
        'status' => 'requested',
        'payment_status' => 'unpaid',
        'surge_multiplier' => '1.00',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'cancelled_by' => TripCancelledBy::class,
            'payment_method' => TripPaymentMethod::class,
            'payment_status' => TripPaymentStatus::class,
            'vehicle_class' => VehicleClass::class,
            'pickup_location' => GeoPointCast::class,
            'dropoff_location' => GeoPointCast::class,
            'requested_at' => 'datetime',
            'matched_at' => 'datetime',
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'distance_meters' => 'integer',
            'duration_seconds' => 'integer',
            'estimated_fare_amount' => 'integer',
            'fare_amount' => 'integer',
            'fare_rule_id' => 'integer',
            'commission_rule_id' => 'integer',
            'surge_multiplier' => 'decimal:2',
            'fare_breakdown' => 'array',
            'commission_amount' => 'integer',
            'driver_earnings_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Trip $trip) {
            $trip->public_code ??= self::newPublicCode();
            $trip->requested_at ??= now();
        });

        static::updating(function (Trip $trip) {
            $trip->guardStatusChange();
            $trip->guardFrozenPricing();
        });
    }

    /**
     * A short code riders, drivers and staff can read out loud (no 0/O/1/I): "TK-7H4M9QXD".
     */
    public static function newPublicCode(): string
    {
        do {
            $code = 'TK-'.implode('', array_map(
                fn () => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)],
                range(1, self::CODE_LENGTH),
            ));
        } while (self::query()->where('public_code', $code)->exists());

        return $code;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    /**
     * @return BelongsTo<DriverProfile, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_id');
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * Move the trip to a new status, recording the matching timestamp. The row is locked and its
     * status re-read first, so two requests racing for the same trip (two accepts, a cancel against a
     * start) cannot both win: the second one sees the new status and gets a TripStateException (409).
     *
     * $attributes are set in the same save (driver and vehicle on a match, the final fare on
     * completion, …). Going back to "requested" clears the driver, vehicle and arrival times.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws TripStateException
     */
    public function transitionTo(TripStatus $to, ?TripCancelledBy $cancelledBy = null, ?string $reason = null, array $attributes = []): self
    {
        return DB::transaction(function () use ($to, $cancelledBy, $reason, $attributes) {
            $current = static::query()->lockForUpdate()->findOrFail($this->getKey());
            $this->setRawAttributes($current->getAttributes(), true);

            // Checked here as well as on save: moving to the status the row already has is not a
            // dirty change, so only this check stops a second accept of an already matched trip.
            $this->status->canTransitionTo($to) || throw TripStateException::invalidTransition($this->status, $to);

            $this->forceFill($attributes)->forceFill(['status' => $to]);

            match ($to) {
                TripStatus::Matched => $this->matched_at = now(),
                TripStatus::Arrived => $this->arrived_at = now(),
                TripStatus::InProgress => $this->started_at = now(),
                TripStatus::Completed => $this->completed_at = now(),
                TripStatus::Cancelled => $this->forceFill(['cancelled_by' => $cancelledBy, 'cancelled_at' => now(), 'cancel_reason' => $reason]),
                TripStatus::Requested => $this->forceFill(['driver_id' => null, 'vehicle_id' => null, 'matched_at' => null, 'arrived_at' => null]),
                TripStatus::NoDriverFound => null,
            };

            $this->save();

            return $this;
        });
    }

    private function guardStatusChange(): void
    {
        if (! $this->isDirty('status')) {
            return;
        }

        $from = $this->getOriginal('status');
        $to = $this->status;

        if (! $from->canTransitionTo($to)) {
            throw TripStateException::invalidTransition($from, $to);
        }

        if ($to === TripStatus::Cancelled) {
            $this->cancelled_by instanceof TripCancelledBy || throw TripStateException::cancellationNeedsActor();
            $from->canBeCancelledBy($this->cancelled_by) || throw TripStateException::cannotCancel($from, $this->cancelled_by);
        }
    }

    private function guardFrozenPricing(): void
    {
        // The save that finishes the trip is the last one allowed to write the snapshot.
        if ($this->getOriginal('status')->isTerminal() && $this->isDirty(self::PRICING_COLUMNS)) {
            throw TripStateException::pricingFrozen();
        }
    }
}
