<?php

namespace App\Support;

use App\Models\FareRule;

/**
 * Fare rules as the Zones & Fares page shows them: money as {amount (minor units), currency},
 * times as ISO 8601, and the admin who saved the version by name.
 */
final class FareRulePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(FareRule $rule): array
    {
        $money = fn (int $amount) => ['amount' => $amount, 'currency' => $rule->currency];

        return [
            'id' => $rule->id,
            'zone_id' => $rule->zone_id,
            'vehicle_class' => $rule->vehicle_class->value,
            'base_fare' => $money($rule->base_fare),
            'per_km' => $money($rule->per_km),
            'per_minute' => $money($rule->per_minute),
            'min_fare' => $money($rule->min_fare),
            'waiting_free_minutes' => $rule->waiting_free_minutes,
            'waiting_per_minute' => $money($rule->waiting_per_minute),
            'cancellation_fee' => $money($rule->cancellation_fee),
            'cancellation_free_seconds' => $rule->cancellation_free_seconds,
            'booking_fee' => $money($rule->booking_fee),
            'tax_rate_bps' => $rule->tax_rate_bps,
            'tax_inclusive' => $rule->tax_inclusive,
            'effective_from' => $rule->effective_from->toIso8601String(),
            'effective_to' => $rule->effective_to?->toIso8601String(),
            'created_by' => $rule->relationLoaded('creator') ? $rule->creator?->name : null,
        ];
    }
}
