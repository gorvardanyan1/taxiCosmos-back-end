<?php

namespace Database\Factories;

use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverBankAccount>
 */
class DriverBankAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => DriverProfile::factory(),
            'account_holder' => fake()->name(),
            'account_number' => fake()->iban(),
            'bank_name' => fake()->company().' Bank',
            'swift' => strtoupper(fake()->lexify('????AM22')),
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => ['verified_at' => now()]);
    }
}
