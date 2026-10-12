<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Integer arithmetic on amounts in minor units (no floats). Fares, tax and commission all round the same way.
 */
final class MinorUnits
{
    /**
     * $amount x $numerator / $denominator rounded half-up (all non-negative). An amount whose product would
     * overflow a 64-bit integer is refused instead of silently turning into a float.
     */
    public static function ratio(int $amount, int $numerator, int $denominator): int
    {
        if ($numerator > 0 && $amount > intdiv(PHP_INT_MAX - $denominator, 2 * $numerator)) {
            throw new InvalidArgumentException('The fare is too large to calculate.');
        }

        return intdiv($amount * $numerator * 2 + $denominator, $denominator * 2);
    }
}
