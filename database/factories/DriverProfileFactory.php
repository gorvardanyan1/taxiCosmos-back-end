<?php

namespace Database\Factories;

use App\Enums\DriverAvailability;
use App\Enums\DriverVerificationStatus;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverProfile>
 */
class DriverProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->driver(),
            'license_number' => fake()->unique()->bothify('DL-########'),
            'license_expiry' => now()->addYears(2)->toDateString(),
        ];
    }

    public function approved(?User $approver = null): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => DriverVerificationStatus::Approved,
            'approved_by' => $approver?->id,
            'approved_at' => now(),
        ]);
    }

    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'availability' => DriverAvailability::Online,
            'last_online_at' => now(),
        ]);
    }
}
