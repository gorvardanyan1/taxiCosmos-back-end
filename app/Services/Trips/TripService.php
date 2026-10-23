<?php

namespace App\Services\Trips;

use App\Data\GeoPoint;
use App\Enums\DriverAvailability;
use App\Enums\TripCancelledBy;
use App\Enums\TripPaymentMethod;
use App\Enums\TripPaymentStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleClass;
use App\Events\Realtime\TripStatusChanged;
use App\Events\TripRequested;
use App\Exceptions\DriverStatusException;
use App\Exceptions\FareException;
use App\Exceptions\TripRequestException;
use App\Exceptions\TripStateException;
use App\Models\DriverProfile;
use App\Models\FareRule;
use App\Models\Trip;
use App\Models\TripDriverCancellation;
use App\Models\User;
use App\Services\Commission\TripCommission;
use App\Services\Fares\FareCalculator;
use App\Services\Fares\FareFeeLookup;
use App\Services\Fares\FareQuoteService;
use App\Services\Fares\RouteEstimator;
use App\Services\Zones\ZoneLocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Everything the rider and driver apps do to a trip (P7-T6). One transaction per step, with the trip row locked first (Trip::transitionTo),
 * so a double tap, two racing calls or a cancel against a start can only have one winner; the loser gets TripStateException (409).
 * Every status change announces `trip.status_changed` after the commit (the push notifications listen to it).
 *
 * Money (MVP: cash only): the trip is priced from the quote the rider saw, and when it completes the final fare is worked out with the same
 * fare rule from the real distance and time, the commission is split off, and the cash is taken as paid (the driver holds it).
 *
 * Dispatch (P7-T7) is not in here: a requested trip, or one a driver gave back, announces TripRequested, and dispatch calls assignDriver()
 * when a driver accepts.
 */
final class TripService
{
    private const ACTIVE = [TripStatus::Requested, TripStatus::Matched, TripStatus::Arrived, TripStatus::InProgress];

    private const HUNDREDTHS = 100;

    public function __construct(
        private readonly FareQuoteService $quotes,
        private readonly ZoneLocator $zones,
        private readonly RouteEstimator $routes,
        private readonly TripMetrics $metrics,
        private readonly FareCalculator $calculator,
        private readonly FareFeeLookup $fees,
        private readonly TripCommission $commission,
    ) {}

    /**
     * The rider's trip that is not finished, if any.
     */
    public static function activeFor(User $rider): ?Trip
    {
        return Trip::query()->where('rider_id', $rider->getKey())->whereIn('status', array_map(fn (TripStatus $status) => $status->value, self::ACTIVE))->first();
    }

    /**
     * @throws TripRequestException active_trip_exists, quote_route_changed, outside_service_area
     * @throws FareException the quote is unknown, expired, or does not price this vehicle class
     */
    public function request(User $rider, string $quoteId, VehicleClass $class, TripPaymentMethod $method, GeoPoint $pickup, string $pickupAddress, GeoPoint $dropoff, string $dropoffAddress): Trip
    {
        $quote = $this->quotes->usable($quoteId, $rider);
        $price = $this->quotes->snapshot($quote, $class);

        // The price was worked out for this route; a trip for another one needs another estimate.
        $tolerance = (int) config('taxikosmos.trips.quote_location_tolerance_meters');
        if ($this->routes->straightLineMeters($pickup, $quote->pickup_location) > $tolerance || $this->routes->straightLineMeters($dropoff, $quote->dropoff_location) > $tolerance) {
            throw TripRequestException::quoteRouteChanged();
        }

        // The zone may have been switched off since the estimate.
        if ($this->zones->find($pickup)?->getKey() !== $quote->zone_id) {
            throw TripRequestException::outsideServiceArea();
        }

        if (($active = self::activeFor($rider)) !== null) {
            throw TripRequestException::activeTripExists($active);
        }

        try {
            $trip = DB::transaction(function () use ($rider, $quote, $price, $class, $method, $pickup, $pickupAddress, $dropoff, $dropoffAddress) {
                $trip = (new Trip)->fill([
                    'pickup_location' => $pickup, 'dropoff_location' => $dropoff, 'pickup_address' => $pickupAddress, 'dropoff_address' => $dropoffAddress,
                    'vehicle_class' => $class, 'payment_method' => $method,
                ]);
                $trip->forceFill([
                    'rider_id' => $rider->getKey(),
                    'zone_id' => $quote->zone_id,
                    'fare_currency' => $price['currency'],
                    'estimated_fare_amount' => $price['amount'],
                    'fare_rule_id' => $price['fare_rule_id'],
                    'surge_multiplier' => $price['surge_multiplier'],
                    'fare_breakdown' => $price['breakdown']->toArray(),
                ])->save();

                $this->announce($trip, null, $trip->status);
                TripRequested::dispatch($trip->getKey());

                return $trip;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two requests raced past the check above; the database allows one active trip per rider.
            $active = self::activeFor($rider) ?? throw $e;

            throw TripRequestException::activeTripExists($active);
        }

        return $trip->refresh();
    }

    /**
     * @throws TripStateException the trip is already finished, or the ride has started
     */
    public function cancelByRider(Trip $trip, ?string $reason): Trip
    {
        return $this->step($trip, function (Trip $locked) use ($reason) {
            $driver = $locked->driver_id;
            $locked->transitionTo(TripStatus::Cancelled, TripCancelledBy::Rider, $reason);
            $this->release($driver);
        });
    }

    /**
     * A driver accepted (the seam dispatch, P7-T7, calls): the trip is matched with the driver and their primary vehicle, and the driver is on a trip.
     *
     * @throws TripStateException the trip is no longer waiting for a driver
     * @throws DriverStatusException the driver is not online
     */
    public function assignDriver(Trip $trip, DriverProfile $driver): Trip
    {
        return $this->step($trip, function (Trip $locked) use ($driver) {
            $profile = DriverProfile::query()->lockForUpdate()->findOrFail($driver->getKey());
            $profile->availability === DriverAvailability::Online || throw DriverStatusException::offline();

            $locked->transitionTo(TripStatus::Matched, attributes: ['driver_id' => $profile->getKey(), 'vehicle_id' => $profile->primaryVehicle()->value('id')]);
            $profile->forceFill(['availability' => DriverAvailability::OnTrip])->save();
        });
    }

    public function arrived(Trip $trip): Trip
    {
        return $this->step($trip, fn (Trip $locked) => $locked->transitionTo(TripStatus::Arrived));
    }

    public function start(Trip $trip): Trip
    {
        return $this->step($trip, fn (Trip $locked) => $locked->transitionTo(TripStatus::InProgress));
    }

    /**
     * Ends the ride: measures it, prices it, splits the commission, takes the cash as paid and frees the driver, all in the save that completes the trip.
     *
     * @throws TripStateException the ride is not in progress
     */
    public function complete(Trip $trip): Trip
    {
        return $this->step($trip, function (Trip $locked) {
            $locked->status->canTransitionTo(TripStatus::Completed) || throw TripStateException::invalidTransition($locked->status, TripStatus::Completed);

            $now = now();
            $ride = $this->metrics->measure($locked, $now);
            $rule = FareRule::query()->findOrFail($locked->fare_rule_id);
            $fare = $this->calculator->calculate(
                $rule, $ride['distance_meters'], $ride['duration_seconds'], $ride['waiting_seconds'],
                (int) round((float) $locked->surge_multiplier * self::HUNDREDTHS),
                $this->fees->forTrip($locked->pickup_location, $locked->dropoff_location, $rule->currency, $now),
            );
            $split = $this->commission->snapshot($locked->zone_id, $locked->vehicle_class, $fare, $now);
            $driver = $locked->driver_id;

            $locked->transitionTo(TripStatus::Completed, attributes: [
                'distance_meters' => $ride['distance_meters'],
                'duration_seconds' => $ride['duration_seconds'],
                'fare_currency' => $fare->currency,
                'fare_amount' => $fare->total,
                'fare_breakdown' => $fare->toArray(),
                'surge_multiplier' => $fare->surgeMultiplier,
                'commission_rule_id' => $split['commission_rule_id'],
                'commission_amount' => $split['commission_amount'],
                'driver_earnings_amount' => $split['driver_earnings_amount'],
                // Cash: the driver takes the whole fare from the rider on the spot.
                'payment_status' => TripPaymentStatus::Paid,
            ]);
            $this->release($driver);
        });
    }

    /**
     * The driver gives the trip back before the ride starts: it returns to dispatch to find another driver, and the reason is kept.
     *
     * @throws TripStateException the ride has started, or the trip is finished
     */
    public function cancelByDriver(Trip $trip, string $reason): Trip
    {
        return $this->step($trip, function (Trip $locked) use ($reason) {
            $driver = $locked->driver_id;
            $from = $locked->status;

            // Only while on the way or waiting at the pickup: a started ride cannot be given back.
            in_array($locked->status, [TripStatus::Matched, TripStatus::Arrived], true) || throw TripStateException::cannotCancel($locked->status, TripCancelledBy::Driver);

            $locked->transitionTo(TripStatus::Requested);
            TripDriverCancellation::query()->create(['trip_id' => $locked->getKey(), 'driver_id' => $driver, 'trip_status' => $from, 'reason' => $reason]);
            $this->release($driver);
            TripRequested::dispatch($locked->getKey());
        });
    }

    /**
     * Runs one change on the trip inside a transaction with the row locked, announces the new status after the commit and returns the fresh trip.
     *
     * @param  callable(Trip): mixed  $change
     */
    private function step(Trip $trip, callable $change): Trip
    {
        return DB::transaction(function () use ($trip, $change) {
            $locked = Trip::query()->lockForUpdate()->findOrFail($trip->getKey());
            $from = $locked->status;
            $change($locked);
            $this->announce($locked, $from, $locked->status);

            return $locked->refresh();
        });
    }

    private function announce(Trip $trip, ?TripStatus $from, TripStatus $to): void
    {
        TripStatusChanged::dispatch($trip->getKey(), $trip->public_code, $from?->value, $to->value, $trip->rider_id, $trip->driver?->user_id);
    }

    /**
     * The driver is free for the next offer again (they stay offline if they went offline themselves).
     */
    private function release(?int $driverId): void
    {
        if ($driverId === null) {
            return;
        }

        $profile = DriverProfile::query()->lockForUpdate()->find($driverId);

        if ($profile?->availability === DriverAvailability::OnTrip) {
            $profile->forceFill(['availability' => DriverAvailability::Online])->save();
        }
    }
}
