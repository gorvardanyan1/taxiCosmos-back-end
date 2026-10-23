<?php

namespace App\Services\Fares;

use App\Data\GeoPoint;

/**
 * Distance and driving time of a trip before it happens. Until the maps provider supplies routes
 * (P5-T1 getRoute) this is the straight line between the two points, lengthened by a detour factor
 * and driven at an average speed (both in config). Callers use only estimate(), so the provider's
 * route replaces this class's body without touching them.
 */
final class RouteEstimator
{
    private const EARTH_RADIUS_METERS = 6_371_000;

    /**
     * @return array{distance_meters: int, duration_seconds: int}
     */
    public function estimate(GeoPoint $from, GeoPoint $to): array
    {
        $meters = (int) round($this->straightLineMeters($from, $to) * (int) config('taxikosmos.fares.route_detour_percent') / 100);
        // km/h to m/s without floats: meters / (kmh / 3.6) = meters x 18 / (kmh x 5)
        $seconds = intdiv($meters * 18 + (int) config('taxikosmos.fares.route_average_speed_kmh') * 5 - 1, (int) config('taxikosmos.fares.route_average_speed_kmh') * 5);

        return ['distance_meters' => $meters, 'duration_seconds' => $seconds];
    }

    /**
     * Great-circle distance (haversine), also used to sum a driven track and to compare two places. The only floats here are geometry, never money.
     */
    public function straightLineMeters(GeoPoint $from, GeoPoint $to): float
    {
        $dLat = deg2rad($to->latitude - $from->latitude);
        $dLng = deg2rad($to->longitude - $from->longitude);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($from->latitude)) * cos(deg2rad($to->latitude)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($a)));
    }
}
