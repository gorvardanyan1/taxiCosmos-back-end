<?php

namespace App\Services\Trips;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Services\Drivers\DriverLocationStore;
use App\Services\Fares\RouteEstimator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How long until the driver reaches the pickup, for the rider's trip screen: from the driver's last reported position (Redis) to the
 * pickup with the route estimate. Only for a driver still on the way (matched); otherwise, or when the position is not known, there is no ETA.
 */
final class TripEta
{
    public function __construct(private readonly DriverLocationStore $locations, private readonly RouteEstimator $routes) {}

    public function seconds(Trip $trip): ?int
    {
        if ($trip->status !== TripStatus::Matched || $trip->driver === null) {
            return null;
        }

        try {
            $position = $this->locations->position($trip->driver->user_id);
        } catch (Throwable $e) {
            // The ETA is a nicety: Redis being down must not fail the trip screen. Never log the position.
            Log::warning('A driver position could not be read for a trip ETA.', ['trip_id' => $trip->id, 'exception' => $e::class]);

            return null;
        }

        return $position === null ? null : $this->routes->estimate($position, $trip->pickup_location)['duration_seconds'];
    }
}
