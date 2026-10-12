<?php

namespace App\Enums;

/**
 * How a commission rule takes the platform's share of a fare: a percentage, a fixed amount, or both.
 */
enum CommissionType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case PercentagePlusFixed = 'percentage_plus_fixed';

    public function usesRate(): bool
    {
        return $this !== self::Fixed;
    }

    public function usesFixedAmount(): bool
    {
        return $this !== self::Percentage;
    }
}
