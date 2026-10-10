<?php

namespace App\Support;

use InvalidArgumentException;
use libphonenumber\NumberParseException;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * Canonical form of a phone number: E.164 ("+37491440221"). Used before a phone is encrypted
 * and blind-indexed, so "091 440 221", "+374 91-440-221" and "0037491440221" are the same
 * account and share one phone_hash (OTP login, admin search, uniqueness).
 *
 * Numbers without a country code are read in config('taxikosmos.phone.default_region').
 * This only requires a number that can be parsed, not a currently assigned one; the strict
 * "is this a real number" rule belongs in request validation (the `phone` rule).
 */
final class E164PhoneNumber implements NormalizesForBlindIndex
{
    /**
     * @throws InvalidArgumentException when the value cannot be read as a phone number
     */
    public static function normalize(string $value): string
    {
        // "00" is the international call prefix almost everywhere (incl. Armenia and Georgia) and means "+".
        // The library only understands it for numbers of the default region, so make it explicit.
        $number = preg_replace('/^00/', '+', trim($value));

        try {
            return (new PhoneNumber($number, config('taxikosmos.phone.default_region')))->formatE164();
        } catch (NumberParseException $e) {
            throw new InvalidArgumentException('The value is not a phone number.', previous: $e);
        }
    }

    public static function tryNormalize(string $value): ?string
    {
        try {
            return self::normalize($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
