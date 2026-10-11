<?php

namespace App\Services\Audit;

use App\Support\Pii\PiiColumns;

/**
 * Builds the before/after diff stored with an audit entry. A sensitive field (encrypted personal
 * data, credentials, secrets, passwords, tokens) is never written as a value: when it changed,
 * both sides show "changed"; when it did not, it is left out.
 */
final class AuditRedactor
{
    public const CHANGED = 'changed';

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{array<string, mixed>, array<string, mixed>} [before, after]
     */
    public static function diff(array $old, array $new): array
    {
        return [self::redact($old, $new), self::redact($new, $old)];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $other
     * @return array<string, mixed>
     */
    private static function redact(array $values, array $other): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                if (! array_key_exists($key, $other) || $other[$key] !== $value) {
                    $result[$key] = self::CHANGED;
                }

                continue;
            }

            $result[$key] = is_array($value) ? self::redact($value, is_array($other[$key] ?? null) ? $other[$key] : []) : $value;
        }

        return $result;
    }

    public static function isSensitive(string $key): bool
    {
        return preg_match(PiiColumns::sensitiveNamePattern(), $key) === 1
            || preg_match('/(password|passcode|token|otp|pin_code|recovery|remember|email)/i', $key) === 1;
    }
}
