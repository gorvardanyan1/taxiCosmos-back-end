<?php

namespace App\Support\Pii;

use App\Support\NormalizesForBlindIndex;

/**
 * One encrypted personal-data column: where it lives, the blind-index column kept next to it
 * (only for fields that must be searchable) and how its value is normalised before hashing.
 */
final readonly class PiiColumn
{
    /**
     * @param  class-string<NormalizesForBlindIndex>|null  $normalizer
     */
    public function __construct(
        public string $table,
        public string $column,
        public ?string $hashColumn = null,
        public ?string $normalizer = null,
        public string $primaryKey = 'id',
    ) {}

    public function label(): string
    {
        return "{$this->table}.{$this->column}";
    }
}
