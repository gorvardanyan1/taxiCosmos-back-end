<?php

namespace Database\Factories;

use App\Data\GeoPoint;
use App\Enums\SavedPlaceLabel;
use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedPlace>
 */
class SavedPlaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->rider(),
            'label' => SavedPlaceLabel::Other,
            'name' => fake()->words(2, true),
            'address' => fake()->streetAddress(),
            'location' => new GeoPoint(40.18, 44.51),
        ];
    }
}
