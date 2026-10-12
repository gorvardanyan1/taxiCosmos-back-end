<?php

namespace Database\Factories;

use App\Models\FareFee;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A fixed surcharge. By default it covers a whole zone; area() draws it as a square instead
 * (the polygon column is written with SQL, so it is set through the state).
 *
 * @extends Factory<FareFee>
 */
class FareFeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'zone_id' => Zone::factory(),
            'name' => 'Airport pickup',
            'amount' => 1000,
            'currency' => 'AMD',
            'applies_to' => 'pickup',
            'effective_from' => now()->subDay(),
            'effective_to' => null,
        ];
    }

    public function forZone(Zone $zone): static
    {
        return $this->state(fn () => ['zone_id' => $zone->id, 'currency' => $zone->currency]);
    }

    /**
     * Charges only inside a square of ±$half degrees around the point (no zone).
     */
    public function area(float $latitude, float $longitude, float $half = 0.01): static
    {
        return $this->state(fn () => ['zone_id' => null, 'polygon' => ZoneFactory::square($latitude, $longitude, $half)]);
    }

    public function appliesTo(string $appliesTo): static
    {
        return $this->state(fn () => ['applies_to' => $appliesTo]);
    }
}
