<?php

namespace App\Support;

/**
 * Driver license numbers compare case- and whitespace-insensitively:
 * " am-dl 42 " and "AM-DL42" are the same license.
 */
final class LicenseNumber implements NormalizesForBlindIndex
{
    public static function normalize(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $value));
    }
}
