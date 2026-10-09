<?php

namespace App\Support;

/**
 * Canonical form of a value before it is encrypted and blind-indexed, so equal values
 * written differently (case, spacing) share one hash for lookups and uniqueness.
 */
interface NormalizesForBlindIndex
{
    public static function normalize(string $value): string;
}
