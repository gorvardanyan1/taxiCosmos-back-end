<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Enums\VehicleClass;
use Carbon\CarbonInterface;
use Database\Factories\CommissionRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The platform's share of a fare in one zone for one vehicle class (or every class) and one period.
 * Money is integer minor units of `currency`. Like fare rules, a commission rule is never edited:
 * changing it closes this row (effective_to) and adds the next (CommissionRuleService), and the
 * database refuses edits, overlaps and deletes.
 */
class CommissionRule extends Model
{
    /** @use HasFactory<CommissionRuleFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** The terms a version holds (everything an admin can set). */
    public const TERMS = ['type', 'rate_bps', 'fixed_amount', 'min_commission', 'max_commission', 'applies_to_surge'];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle_class' => VehicleClass::class,
            'type' => CommissionType::class,
            'rate_bps' => 'integer',
            'fixed_amount' => 'integer',
            'min_commission' => 'integer',
            'max_commission' => 'integer',
            'applies_to_surge' => 'boolean',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $rule) {
            if (array_diff(array_keys($rule->getDirty()), ['effective_to']) !== []) {
                throw new LogicException('Commission rules are never edited: add a new version instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('Commission rules are history and cannot be deleted.'));
    }

    /**
     * The rule in force at $at: effective_from <= $at < effective_to (or still open).
     *
     * @param  Builder<CommissionRule>  $query
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
