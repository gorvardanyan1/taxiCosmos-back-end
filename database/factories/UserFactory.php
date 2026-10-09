<?php

namespace Database\Factories;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'locale' => 'en',
            'timezone' => 'UTC',
        ];
    }

    public function withPhone(?string $phone = null): static
    {
        return $this->state(fn (array $attributes) => [
            'phone' => $phone ?? '+1'.fake()->unique()->numerify('##########'),
        ]);
    }

    public function rider(): static
    {
        return $this->state(fn (array $attributes) => ['is_rider' => true]);
    }

    public function driver(): static
    {
        return $this->state(fn (array $attributes) => ['is_driver' => true]);
    }

    public function suspended(string $reason = 'Suspended by factory'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Suspended,
            'suspension_reason' => $reason,
        ]);
    }

    /**
     * An admin account holding the given role (requires RolesAndPermissionsSeeder to have run).
     */
    public function admin(AdminRole $role = AdminRole::Admin): static
    {
        return $this->state(fn (array $attributes) => ['is_admin' => true])
            ->afterCreating(fn (User $user) => $user->assignRole($role->value));
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
