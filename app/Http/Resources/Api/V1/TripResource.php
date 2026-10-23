<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A trip as the rider app sees it. The driver (name and rating) and the vehicle appear once a driver has the trip; the driver's phone never
 * does. `fare.final` is empty until the ride is completed; `eta_to_pickup_seconds` is only for a driver still on the way.
 *
 * @mixin Trip
 */
class TripResource extends JsonResource
{
    private ?int $eta = null;

    /** The live ETA (seconds) of the driver to the pickup, worked out by the caller from the driver's last position. */
    public function withEta(?int $seconds): static
    {
        $this->eta = $seconds;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $driver = $this->driver_id === null ? null : $this->driver;
        $vehicle = $this->vehicle_id === null ? null : $this->vehicle;

        return [
            'id' => $this->id,
            'code' => $this->public_code,
            'status' => $this->status->value,
            'vehicle_class' => $this->vehicle_class->value,
            'pickup' => ['lat' => $this->pickup_location->latitude, 'lng' => $this->pickup_location->longitude, 'address' => $this->pickup_address],
            'dropoff' => ['lat' => $this->dropoff_location->latitude, 'lng' => $this->dropoff_location->longitude, 'address' => $this->dropoff_address],
            'fare' => [
                'currency' => $this->fare_currency,
                'estimated' => $this->estimated_fare_amount,
                'final' => $this->fare_amount,
            ],
            'payment' => ['method' => $this->payment_method->value, 'status' => $this->payment_status->value],
            'driver' => $driver === null ? null : [
                'name' => $driver->user->name,
                'rating_avg' => $driver->rating_avg === null ? null : (float) $driver->rating_avg,
                'rating_count' => $driver->rating_count,
            ],
            'vehicle' => $vehicle === null ? null : [
                'make' => $vehicle->make, 'model' => $vehicle->model, 'color' => $vehicle->color,
                'plate_number' => $vehicle->plate_number, 'vehicle_class' => $vehicle->vehicle_class->value,
            ],
            'eta_to_pickup_seconds' => $this->eta,
            'distance_meters' => $this->distance_meters,
            'duration_seconds' => $this->duration_seconds,
            'cancelled_by' => $this->cancelled_by?->value,
            'cancel_reason' => $this->cancel_reason,
            'requested_at' => $this->requested_at->toIso8601String(),
            'matched_at' => $this->matched_at?->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
