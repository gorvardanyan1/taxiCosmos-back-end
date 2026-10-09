<?php

namespace Database\Factories;

use App\Enums\VehicleClass;
use App\Enums\VehicleStatus;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => DriverProfile::factory(),
            'make' => fake()->randomElement(['Toyota', 'Hyundai', 'Kia', 'Skoda']),
            'model' => fake()->randomElement(['Camry', 'Elantra', 'Optima', 'Octavia']),
            'year' => fake()->numberBetween(2012, 2026),
            'plate_number' => fake()->unique()->bothify('## ?? ###'),
            'color' => fake()->safeColorName(),
            'vehicle_class' => VehicleClass::Economy,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['status' => VehicleStatus::Active]);
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => ['is_primary' => true]);
    }
}
