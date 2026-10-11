<?php

namespace App\Models;

use App\Enums\VehicleClass;
use Carbon\CarbonInterface;
use Database\Factories\FareRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The prices of one vehicle class in one zone for one period. Money is integer minor units of
 * `currency`. A rule is never edited: changing a price closes this row (effective_to) and adds the
 * next one (FareRuleService), so "what was the base fare for zone X, class Y at time T" is answered by
 * effectiveAt($T). The database enforces the same: no overlap, no edits, no deletes.
 */
class FareRule extends Model
{
    /** @use HasFactory<FareRuleFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** The prices and terms a version holds (everything an admin can set). */
    public const TERMS = [
        'base_fare', 'per_km', 'per_minute', 'min_fare', 'waiting_free_minutes', 'waiting_per_minute',
        'cancellation_fee', 'cancellation_free_seconds', 'booking_fee', 'tax_rate_bps', 'tax_inclusive',
    ];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle_class' => VehicleClass::class,
            'base_fare' => 'integer',
            'per_km' => 'integer',
            'per_minute' => 'integer',
            'min_fare' => 'integer',
            'waiting_free_minutes' => 'integer',
            'waiting_per_minute' => 'integer',
            'cancellation_fee' => 'integer',
            'cancellation_free_seconds' => 'integer',
            'booking_fee' => 'integer',
            'tax_rate_bps' => 'integer',
            'tax_inclusive' => 'boolean',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $rule) {
            if (array_diff(array_keys($rule->getDirty()), ['effective_to']) !== []) {
                throw new LogicException('Fare rules are never edited: add a new version instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('Fare rules are history and cannot be deleted.'));
    }

    /**
     * The rule in force at $at: effective_from <= $at < effective_to (or still open).
     *
     * @param  Builder<FareRule>  $query
     */
    public function scopeEffectiveAt(Builder $query, CarbonInterface $at): void
    {
        $query->where('effective_from', '<=', $at)
            ->where(fn (Builder $open) => $open->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
