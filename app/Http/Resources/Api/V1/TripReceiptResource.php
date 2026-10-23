<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The receipt of a completed trip: the fare broken down line by line, how it was paid and where it stands. The lines add up to `total`
 * (docs/fares.md). Cash is paid to the driver, so there is no card transaction to wait for.
 *
 * @mixin Trip
 */
class TripReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fare = $this->fare_breakdown ?? [];

        return [
            'trip_id' => $this->id,
            'code' => $this->public_code,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'pickup_address' => $this->pickup_address,
            'dropoff_address' => $this->dropoff_address,
            'distance_meters' => $this->distance_meters,
            'duration_seconds' => $this->duration_seconds,
            'currency' => $this->fare_currency,
            'breakdown' => [
                'base' => $fare['base'] ?? 0,
                'distance' => $fare['distance'] ?? 0,
                'time' => $fare['time'] ?? 0,
                'waiting' => $fare['waiting'] ?? 0,
                'minimum_adjustment' => $fare['minimum_adjustment'] ?? 0,
                'booking_fee' => $fare['booking_fee'] ?? 0,
                'fees' => $fare['fees'] ?? [],
                'surge_multiplier' => $fare['surge_multiplier'] ?? $this->surge_multiplier,
                'surge' => $fare['surge'] ?? 0,
                'tax' => $fare['tax'] ?? 0,
            ],
            'total' => $this->fare_amount,
            'payment' => ['method' => $this->payment_method->value, 'status' => $this->payment_status->value],
        ];
    }
}
