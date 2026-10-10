<?php

namespace App\Support;

use RuntimeException;

/**
 * Deterministic HMAC-SHA256 "blind index" for encrypted columns that need exact-match
 * lookups (phone, license number). The encrypted cast is randomised, so equality
 * queries run against the companion *_hash column instead of decrypting rows.
 */
final class BlindIndex
{
    public const MIN_KEY_LENGTH = 32;

    public function __construct(private readonly string $key)
    {
        if ($key === '') {
            throw new RuntimeException('BLIND_INDEX_KEY is not configured.');
        }

        if (strlen($key) < self::MIN_KEY_LENGTH) {
            throw new RuntimeException('BLIND_INDEX_KEY must be at least '.self::MIN_KEY_LENGTH.' characters (generate one with: openssl rand -hex 32).');
        }
    }

    /**
     * The key must be its own secret: if it equalled APP_KEY (or a previous APP_KEY), anyone who
     * can decrypt the data could also forge every blind index, and rotating APP_KEY would silently
     * change the hashes.
     */
    public static function fromConfig(): self
    {
        $key = (string) config('app.blind_index_key');

        foreach ([config('app.key'), ...config('app.previous_keys', [])] as $encryptionKey) {
            if ($encryptionKey !== null && $encryptionKey !== '' && self::sameSecret($key, (string) $encryptionKey)) {
                throw new RuntimeException('BLIND_INDEX_KEY must be a different secret from APP_KEY.');
            }
        }

        return new self($key);
    }

    private static function sameSecret(string $a, string $b): bool
    {
        $decode = fn (string $key) => str_starts_with($key, 'base64:') ? (base64_decode(substr($key, 7), true) ?: $key) : $key;

        return hash_equals($decode($a), $decode($b));
    }

    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }
}
