<?php

namespace App\Services\Commission;

use App\Enums\CommissionType;
use App\Enums\VehicleClass;
use App\Models\CommissionRule;
use App\Models\User;
use App\Models\Zone;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Changes commission the only way allowed: a new version. Saving closes the rule that is open for
 * the zone and class (its effective_to becomes now) and inserts the next one from now, in one
 * transaction holding the zone's row lock. Saving the terms already in force changes nothing.
 * The currency is always the zone's, and terms a type does not use are stored as zero.
 */
final class CommissionRuleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $terms  the CommissionRule::TERMS values
     * @return array{rule: CommissionRule, created: bool} created is false when nothing differed from the rule in force
     */
    public function saveVersion(User $actor, Zone $zone, ?VehicleClass $class, array $terms): array
    {
        $terms = $this->normalised(array_intersect_key($terms, array_flip(CommissionRule::TERMS)));

        return DB::transaction(function () use ($actor, $zone, $class, $terms) {
            $locked = Zone::query()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();
            // Times are stored to the second, so versions are cut on whole seconds.
            $now = CarbonImmutable::now()->startOfSecond();
            $current = CommissionRule::query()
                ->where('zone_id', $locked->id)
                ->when($class === null, fn ($query) => $query->whereNull('vehicle_class'), fn ($query) => $query->where('vehicle_class', $class->value))
                ->whereNull('effective_to')->lockForUpdate()->first();

            $before = $current === null ? [] : $this->comparable($current->only(CommissionRule::TERMS));
            $changes = $current === null ? $terms : array_diff_assoc($this->comparable($terms), $before);

            if ($current !== null && $changes === []) {
                return ['rule' => $current, 'created' => false];
            }

            if ($current !== null) {
                // The new version starts after the old one began, even if several are saved within the same second.
                $now = $now->greaterThan($current->effective_from) ? $now : CarbonImmutable::instance($current->effective_from)->addSecond();
                $current->forceFill(['effective_to' => $now])->save();
            }

            $rule = CommissionRule::query()->create([
                ...$terms,
                'zone_id' => $locked->id,
                'vehicle_class' => $class?->value,
                'currency' => $locked->currency,
                'effective_from' => $now,
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                actor: $actor,
                action: $current === null ? 'commission_rule.created' : 'commission_rule.versioned',
                target: $rule,
                old: $current === null ? [] : array_intersect_key($current->only(CommissionRule::TERMS), $changes),
                new: array_intersect_key($rule->only(CommissionRule::TERMS), $changes),
                context: ['zone' => $locked->code, 'vehicle_class' => $class?->value ?? 'all', 'previous_rule_id' => $current?->id],
            );

            return ['rule' => $rule, 'created' => true];
        });
    }

    /**
     * @param  array<string, mixed>  $terms
     * @return array<string, mixed>
     */
    private function normalised(array $terms): array
    {
        $type = $terms['type'] instanceof CommissionType ? $terms['type'] : CommissionType::from($terms['type']);

        return [
            ...$terms,
            'type' => $type->value,
            'rate_bps' => $type->usesRate() ? (int) $terms['rate_bps'] : 0,
            'fixed_amount' => $type->usesFixedAmount() ? (int) $terms['fixed_amount'] : 0,
            'min_commission' => (int) ($terms['min_commission'] ?? 0),
            'max_commission' => ($terms['max_commission'] ?? null) === null ? null : (int) $terms['max_commission'],
            'applies_to_surge' => (bool) $terms['applies_to_surge'],
        ];
    }

    /**
     * Terms as plain scalars (enum value, null kept) so two versions compare by value.
     *
     * @param  array<string, mixed>  $terms
     * @return array<string, mixed>
     */
    private function comparable(array $terms): array
    {
        return array_map(fn ($value) => $value instanceof CommissionType ? $value->value : $value, $terms);
    }
}
