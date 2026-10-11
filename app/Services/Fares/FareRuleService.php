<?php

namespace App\Services\Fares;

use App\Enums\VehicleClass;
use App\Models\FareRule;
use App\Models\User;
use App\Models\Zone;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Changes prices the only way allowed: a new version. Saving closes the rule that is in force (its
 * effective_to becomes now) and inserts the next one with effective_from = now, in one transaction
 * that holds the zone's row lock, so two admins saving at once cannot both think they are first.
 * Saving the values already in force changes nothing (a double-click is one new version at most).
 * The rule's currency is always the zone's.
 */
final class FareRuleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $terms  the FareRule::TERMS values
     * @return array{rule: FareRule, created: bool} created is false when nothing differed from the rule in force
     */
    public function saveVersion(User $actor, Zone $zone, VehicleClass $class, array $terms): array
    {
        $terms = array_intersect_key($terms, array_flip(FareRule::TERMS));

        return DB::transaction(function () use ($actor, $zone, $class, $terms) {
            $locked = Zone::query()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();
            // Times are stored to the second, so versions are cut on whole seconds.
            $now = CarbonImmutable::now()->startOfSecond();
            $current = FareRule::query()
                ->where('zone_id', $locked->id)->where('vehicle_class', $class->value)
                ->effectiveAt($now)->lockForUpdate()->first();

            $terms += $current === null ? [] : $current->only(FareRule::TERMS);
            $changes = $current === null ? $terms : array_diff_assoc($terms, $current->only(FareRule::TERMS));

            if ($current !== null && $changes === []) {
                return ['rule' => $current, 'created' => false];
            }

            if ($current !== null) {
                // The new version starts after the old one began, even if both are saved within the same second.
                $now = $now->greaterThan($current->effective_from) ? $now : CarbonImmutable::instance($current->effective_from)->addSecond();
                $current->forceFill(['effective_to' => $now])->save();
            }

            $rule = FareRule::query()->create([
                ...$terms,
                'zone_id' => $locked->id,
                'vehicle_class' => $class->value,
                'currency' => $locked->currency,
                'effective_from' => $now,
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                actor: $actor,
                action: $current === null ? 'fare_rule.created' : 'fare_rule.versioned',
                target: $rule,
                old: $current === null ? [] : array_intersect_key($current->only(FareRule::TERMS), $changes),
                new: $changes,
                context: ['zone' => $locked->code, 'vehicle_class' => $class->value, 'previous_rule_id' => $current?->id],
            );

            return ['rule' => $rule, 'created' => true];
        });
    }
}
