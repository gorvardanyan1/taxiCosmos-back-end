<?php

namespace App\Data;

/**
 * A driving route: distance, duration and the path as a Google-encoded polyline (precision 5), whichever provider drew it.
 */
final readonly class GeoRoute
{
    public function __construct(public int $distanceMeters, public int $durationSeconds, public string $polyline) {}

    public function estimate(): GeoEstimate
    {
        return new GeoEstimate($this->distanceMeters, $this->durationSeconds);
    }

    /**
     * @return array{distance_meters: int, duration_seconds: int, polyline: string}
     */
    public function toArray(): array
    {
        return ['distance_meters' => $this->distanceMeters, 'duration_seconds' => $this->durationSeconds, 'polyline' => $this->polyline];
    }

    /**
     * @param  array{distance_meters: int, duration_seconds: int, polyline: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['distance_meters'], $data['duration_seconds'], $data['polyline']);
    }
}
