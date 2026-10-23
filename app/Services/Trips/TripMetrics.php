<?php

namespace App\Services\Trips;

use App\Data\GeoPoint;
use App\Models\DriverLocationPing;
use App\Models\Trip;
use App\Services\Fares\RouteEstimator;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the ride really was, for the final fare: the distance driven, the time on the road and the time the driver waited.
 *
 * The distance is the sum of the legs between the driver's position pings during the ride (MongoDB history, P8-T2). A leg is left out when
 * the pings are further apart in time than a drive (the app was closed) or when it would need an impossible speed (a GPS jump). With fewer than
 * two pings, or when MongoDB cannot be read, the route estimate from pickup to drop-off stands in (the same estimate the rider was quoted).
 * Duration and waiting come from the trip's own timestamps.
 */
final class TripMetrics
{
    private const MS_PER_KMH = 3.6;

    public function __construct(private readonly RouteEstimator $routes) {}

    /**
     * @return array{distance_meters: int, duration_seconds: int, waiting_seconds: int, source: 'pings'|'route'}
     */
    public function measure(Trip $trip, CarbonInterface $until): array
    {
        $startedAt = $trip->started_at ?? $until;
        $duration = max(0, $startedAt->diffInSeconds($until, true));
        $waiting = $trip->arrived_at === null ? 0 : max(0, (int) $trip->arrived_at->diffInSeconds($startedAt, true));

        $driven = $this->drivenMeters($trip, $until);

        return [
            'distance_meters' => $driven ?? $this->routes->estimate($trip->pickup_location, $trip->dropoff_location)['distance_meters'],
            'duration_seconds' => (int) $duration,
            'waiting_seconds' => $waiting,
            'source' => $driven === null ? 'route' : 'pings',
        ];
    }

    private function drivenMeters(Trip $trip, CarbonInterface $until): ?int
    {
        if ($trip->started_at === null || $trip->driver_id === null) {
            return null;
        }

        try {
            $pings = DriverLocationPing::query()
                ->where('driver_profile_id', $trip->driver_id)
                ->where('recorded_at', '>=', $trip->started_at)
                ->where('recorded_at', '<=', $until)
                ->orderBy('recorded_at')
                ->get(['location', 'recorded_at']);
        } catch (Throwable $e) {
            // Never the positions: they are personal data.
            Log::warning('The driver pings of a trip could not be read; the route estimate is used.', ['trip_id' => $trip->id, 'exception' => $e::class]);

            return null;
        }

        if ($pings->count() < 2) {
            return null;
        }

        $gap = (int) config('taxikosmos.trips.ping_gap_seconds');
        $maxMetersPerSecond = (int) config('taxikosmos.trips.max_speed_kmh') / self::MS_PER_KMH;
        $meters = 0.0;
        $previous = null;

        foreach ($pings as $ping) {
            $point = new GeoPoint((float) $ping->location['coordinates'][1], (float) $ping->location['coordinates'][0]);

            if ($previous !== null) {
                $seconds = $previous['at']->diffInSeconds($ping->recorded_at, true);
                $leg = $this->routes->straightLineMeters($previous['point'], $point);

                if ($seconds > 0 && $seconds <= $gap && $leg / $seconds <= $maxMetersPerSecond) {
                    $meters += $leg;
                }
            }

            $previous = ['point' => $point, 'at' => $ping->recorded_at];
        }

        return (int) round($meters);
    }
}
