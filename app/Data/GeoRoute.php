<?php

namespace App\Data;

/**
 * A driving route: distance, duration and the path as a Google-encoded polyline (precision 5), whichever provider drew it.
 * `estimated` is true when the routing service was down and the route is only the straight line made longer by a detour
 * factor; it is not part of the API answer and an estimated route is never cached.
 */
final readonly class GeoRoute
{
    public function __construct(public int $distanceMeters, public int $durationSeconds, public string $polyline, public bool $estimated = false) {}

    public function estimate(): GeoEstimate
    {
        return new GeoEstimate($this->distanceMeters, $this->durationSeconds, $this->estimated);
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
