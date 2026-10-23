<?php

namespace App\Exceptions;

use App\Http\Resources\Api\V1\TripResource;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A trip request the rider's app has to handle. Renders itself as the JSON the app switches on: `code` is stable
 * (active_trip_exists, quote_route_changed, outside_service_area), `message` is in the rider's language. active_trip_exists carries
 * the trip the rider already has, so the app can show it instead of the booking screen.
 */
class TripRequestException extends RuntimeException
{
    private function __construct(string $message, public readonly string $errorCode, public readonly int $status, private readonly ?Trip $trip = null)
    {
        parent::__construct($message);
    }

    public static function activeTripExists(Trip $trip): self
    {
        return new self(__('You already have a trip in progress.'), 'active_trip_exists', 409, $trip);
    }

    public static function quoteRouteChanged(): self
    {
        return new self(__('The pickup or drop-off is not where the price was worked out. Please get a new estimate.'), 'quote_route_changed', 422);
    }

    public static function outsideServiceArea(): self
    {
        return new self(__('Sorry, we do not serve this pickup location yet.'), 'outside_service_area', 422);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'trip' => $this->trip === null ? null : (new TripResource($this->trip))->resolve(),
        ], fn ($value) => $value !== null), $this->status);
    }
}
