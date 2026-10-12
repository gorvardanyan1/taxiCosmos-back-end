<?php

namespace App\Services\Commission;

use App\Data\FareBreakdown;
use App\Enums\VehicleClass;
use App\Models\Zone;
use Carbon\CarbonInterface;

/**
 * The commission part of a trip's pricing snapshot: the rule in force when the trip is completed
 * and the split it gives. The result is written to the trip in the save that completes it
 * (`Trip::transitionTo(Completed, attributes: ...)`), after which the trip's pricing is frozen, so a
 * later rule change can never reach it. A zone with no rule takes no commission (all zeros, no rule id).
 */
final class TripCommission
{
    public function __construct(
        private readonly CommissionRuleLookup $rules,
        private readonly CommissionCalculator $calculator,
    ) {}

    /**
     * @return array{commission_rule_id: ?int, commission_amount: int, driver_earnings_amount: int}
     */
    public function snapshot(Zone|int $zone, VehicleClass $class, FareBreakdown $fare, ?CarbonInterface $at = null): array
    {
        $rule = $this->rules->at($zone, $class, $at);

        if ($rule === null) {
            return ['commission_rule_id' => null, 'commission_amount' => 0, 'driver_earnings_amount' => $fare->total - $fare->tax];
        }

        $result = $this->calculator->calculate($fare, $rule);

        return ['commission_rule_id' => $rule->id, 'commission_amount' => $result->commission, 'driver_earnings_amount' => $result->driverEarnings];
    }
}
