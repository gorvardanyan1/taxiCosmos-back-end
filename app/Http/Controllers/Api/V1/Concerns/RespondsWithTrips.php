<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Exceptions\TripStateException;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The 409 both trip controllers answer when a step is not allowed in the trip's current state (a start after a cancel, a second complete):
 * it carries the trip as it is now, so the app can show the real state instead of guessing.
 */
trait RespondsWithTrips
{
    /**
     * @param  callable(Trip): JsonResource  $resource  how this app presents the trip
     */
    private function conflict(TripStateException $e, Trip $trip, callable $resource): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'trip_state_conflict',
            'trip' => $resource($trip->refresh())->resolve(),
        ], 409);
    }
}
