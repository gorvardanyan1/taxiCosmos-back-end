<?php

namespace App\Data;

/**
 * How far and how long by car, without the path.
 */
final readonly class GeoEstimate
{
    public function __construct(public int $distanceMeters, public int $durationSeconds) {}

    /**
     * @return array{distance_meters: int, duration_seconds: int}
     */
    public function toArray(): array
    {
        return ['distance_meters' => $this->distanceMeters, 'duration_seconds' => $this->durationSeconds];
    }

    /**
     * @param  array{distance_meters: int, duration_seconds: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['distance_meters'], $data['duration_seconds']);
    }
}
