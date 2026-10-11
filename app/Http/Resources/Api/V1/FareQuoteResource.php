<?php

namespace App\Http\Resources\Api\V1;

use App\Models\FareQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The estimate the rider app shows: one price (and a range) per vehicle class, and the quote_id the
 * trip is created with. `eta_to_pickup_seconds` stays null until drivers' locations arrive (P8-T2).
 * The full breakdown stays on the server; the trip stores it when it is created from the quote.
 *
 * @mixin FareQuote
 */
class FareQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'quote_id' => $this->id,
            'expires_at' => $this->expires_at->toIso8601String(),
            'zone' => ['id' => $this->zone->id, 'name' => $this->zone->name],
            'currency' => $this->currency,
            'distance_meters' => $this->distance_meters,
            'duration_seconds' => $this->duration_seconds,
            'estimates' => array_map(fn (array $estimate) => [
                'vehicle_class' => $estimate['vehicle_class'],
                'currency' => $estimate['currency'],
                'amount' => $estimate['amount'],
                'amount_min' => $estimate['amount_min'],
                'amount_max' => $estimate['amount_max'],
                'surge_multiplier' => $estimate['surge_multiplier'],
                'eta_to_pickup_seconds' => null,
            ], $this->estimates),
        ];
    }
}
