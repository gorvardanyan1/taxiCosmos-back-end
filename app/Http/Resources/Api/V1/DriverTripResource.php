<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A trip as the driver app sees it: where to go, the rider's first name only (never their phone: contact goes through the app), what to
 * collect and, once completed, what the driver earned and the commission taken. The fare the driver collects is `fare.final`.
 *
 * @mixin Trip
 */
class DriverTripResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $completed = $this->completed_at !== null;

        return [
            'id' => $this->id,
            'code' => $this->public_code,
            'status' => $this->status->value,
            'vehicle_class' => $this->vehicle_class->value,
            'pickup' => ['lat' => $this->pickup_location->latitude, 'lng' => $this->pickup_location->longitude, 'address' => $this->pickup_address],
            'dropoff' => ['lat' => $this->dropoff_location->latitude, 'lng' => $this->dropoff_location->longitude, 'address' => $this->dropoff_address],
            'rider' => ['first_name' => $this->firstNameOf($this->rider->name)],
            'fare' => ['currency' => $this->fare_currency, 'estimated' => $this->estimated_fare_amount, 'final' => $this->fare_amount],
            'payment' => ['method' => $this->payment_method->value, 'status' => $this->payment_status->value],
            'earnings' => $completed ? ['currency' => $this->fare_currency, 'amount' => $this->driver_earnings_amount, 'commission' => $this->commission_amount] : null,
            'distance_meters' => $this->distance_meters,
            'duration_seconds' => $this->duration_seconds,
            'cancelled_by' => $this->cancelled_by?->value,
            'requested_at' => $this->requested_at->toIso8601String(),
            'matched_at' => $this->matched_at?->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }

    private function firstNameOf(?string $name): ?string
    {
        $first = trim(explode(' ', trim((string) $name))[0] ?? '');

        return $first === '' ? null : $first;
    }
}
