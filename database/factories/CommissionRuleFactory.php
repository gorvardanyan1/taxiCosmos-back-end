<?php

namespace Database\Factories;

use App\Enums\CommissionType;
use App\Enums\VehicleClass;
use App\Models\CommissionRule;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A 20 % rule for every class of a zone, open-ended and in force since a day ago.
 *
 * @extends Factory<CommissionRule>
 */
class CommissionRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'zone_id' => Zone::factory(),
            'vehicle_class' => null,
            'type' => CommissionType::Percentage->value,
            'rate_bps' => 2000,
            'fixed_amount' => 0,
            'currency' => 'AMD',
            'min_commission' => 0,
            'max_commission' => null,
            'applies_to_surge' => true,
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
