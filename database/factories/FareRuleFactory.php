<?php

namespace Database\Factories;

use App\Enums\VehicleClass;
use App\Models\FareRule;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Economy prices in AMD (minor unit = 1 dram). The rule is open-ended and starts a day ago unless a state says otherwise.
 *
 * @extends Factory<FareRule>
 */
class FareRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'zone_id' => Zone::factory(),
            'vehicle_class' => VehicleClass::Economy->value,
            'base_fare' => 500,
            'per_km' => 150,
            'per_minute' => 20,
            'min_fare' => 800,
            'waiting_free_minutes' => 3,
            'waiting_per_minute' => 30,
            'cancellation_fee' => 500,
            'cancellation_free_seconds' => 120,
            'booking_fee' => 0,
            'tax_rate_bps' => 0,
            'tax_inclusive' => false,
            'currency' => 'AMD',
            'effective_from' => now()->subDay(),
            'effective_to' => null,
        ];
    }

    public function forZone(Zone $zone): static
    {
        return $this->state(fn () => ['zone_id' => $zone->id, 'currency' => $zone->currency]);
    }

    public function vehicleClass(VehicleClass $class): static
    {
        return $this->state(fn () => ['vehicle_class' => $class->value]);
    }

    public function between(mixed $from, mixed $to = null): static
    {
        return $this->state(fn () => ['effective_from' => $from, 'effective_to' => $to]);
    }
}
