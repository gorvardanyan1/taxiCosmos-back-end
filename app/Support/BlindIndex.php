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
    public function __construct(private readonly string $key)
    {
        if ($key === '') {
            throw new RuntimeException('BLIND_INDEX_KEY is not configured.');
        }
    }

    public static function fromConfig(): self
    {
        return new self((string) config('app.blind_index_key'));
    }

    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }
}
