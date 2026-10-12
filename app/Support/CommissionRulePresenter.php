<?php

namespace App\Support;

use App\Models\CommissionRule;

/**
 * Commission rules as the Commission page shows them: money as {amount (minor units), currency},
 * times as ISO 8601, and a type's unused terms as null (a fixed rule has no rate, a percentage no fixed fee).
 */
final class CommissionRulePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(CommissionRule $rule): array
    {
        $money = fn (int $amount) => ['amount' => $amount, 'currency' => $rule->currency];

        return [
            'id' => $rule->id,
            'zone_id' => $rule->zone_id,
            'zone' => $rule->zone?->name ?? '',
            'vehicle_class' => $rule->vehicle_class?->value,
            'type' => $rule->type->value,
            'rate_bp' => $rule->type->usesRate() ? $rule->rate_bps : null,
            'fixed_fee' => $rule->type->usesFixedAmount() ? $money($rule->fixed_amount) : null,
            'minimum' => $rule->min_commission > 0 ? $money($rule->min_commission) : null,
            'maximum' => $rule->max_commission === null ? null : $money($rule->max_commission),
            'applies_to_surge' => $rule->applies_to_surge,
            'effective_from' => $rule->effective_from->toIso8601String(),
            'effective_to' => $rule->effective_to?->toIso8601String(),
            'created_by' => $rule->relationLoaded('creator') ? $rule->creator?->name : null,
        ];
    }
}
