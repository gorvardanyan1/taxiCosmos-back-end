<?php

namespace App\Services\Commission;

use App\Data\CommissionResult;
use App\Data\FareBreakdown;
use App\Models\CommissionRule;
use App\Support\MinorUnits;
use InvalidArgumentException;

/**
 * Splits a final fare between the platform and the driver with one commission rule.
 *
 *   taxFree = total - tax                    (tax is never part of the commission base, and never the driver's)
 *   base    = taxFree - surge                (only when the rule has applies_to_surge = false)
 *   raw     = base x rate  (+ fixed amount)  by the rule's type, the percentage rounded half-up
 *   commission = raw lifted to min_commission, held to max_commission, and never above taxFree
 *   driver earnings = taxFree - commission   (so the driver keeps all of a surge the rule leaves out)
 *
 * Tips, if added later, are not part of the fare breakdown and always go to the driver in full.
 */
final class CommissionCalculator
{
    private const BASIS_POINTS = 10_000;

    public function calculate(FareBreakdown $fare, CommissionRule $rule): CommissionResult
    {
        if ($fare->currency !== $rule->currency) {
            throw new InvalidArgumentException("A {$rule->currency} commission rule cannot price a {$fare->currency} fare.");
        }

        $taxFree = $fare->total - $fare->tax;
        $base = $rule->applies_to_surge ? $taxFree : max(0, $taxFree - $fare->surge);

        $commission = ($rule->type->usesRate() ? MinorUnits::ratio($base, $rule->rate_bps, self::BASIS_POINTS) : 0)
            + ($rule->type->usesFixedAmount() ? $rule->fixed_amount : 0);

        $commission = max($commission, $rule->min_commission);

        if ($rule->max_commission !== null) {
            $commission = min($commission, $rule->max_commission);
        }

        $commission = min($commission, $taxFree);

        return new CommissionResult($fare->currency, $taxFree, $base, $commission, $taxFree - $commission, $rule->id);
    }
}
