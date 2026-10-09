<?php

namespace App\Casts;

use App\Support\BlindIndex;
use App\Support\NormalizesForBlindIndex;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Stores the attribute encrypted and keeps its HMAC blind-index column in sync,
 * in the same write, so the two can never drift (no model events involved).
 *
 * Usage: 'phone' => EncryptedWithBlindIndex::class.':phone_hash'
 *        'license_number' => EncryptedWithBlindIndex::class.':license_number_hash,'.LicenseNumber::class
 * The optional second argument (a NormalizesForBlindIndex class) canonicalises the value
 * before it is stored and hashed; lookups must apply the same normaliser.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedWithBlindIndex implements CastsAttributes
{
    /**
     * @param  class-string<NormalizesForBlindIndex>|null  $normalizer
     */
    public function __construct(
        private readonly string $hashColumn,
        private readonly ?string $normalizer = null,
    ) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::decryptString($value);
    }

    /**
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => null, $this->hashColumn => null];
        }

        $value = $this->normalizer === null ? (string) $value : $this->normalizer::normalize((string) $value);

        return [
            $key => Crypt::encryptString($value),
            $this->hashColumn => app(BlindIndex::class)->hash($value),
        ];
    }
}
