<?php

namespace Database\Factories;

use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Zones are written with PostGIS expressions; the factory builds a square around a centre point.
 *
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' Zone',
            'code' => strtoupper(fake()->unique()->bothify('Z-????-###')),
            'timezone' => 'Asia/Yerevan',
            'currency' => 'AMD',
            'priority' => 0,
            'polygon' => self::square(40.18, 44.51, 0.05),
        ];
    }

    /**
     * A square of ±$half degrees around (latitude, longitude) as a MultiPolygon expression.
     */
    public static function square(float $latitude, float $longitude, float $half = 0.05): Expression
    {
        return self::fromGeoJson(self::squareGeoJson($latitude, $longitude, $half));
    }

    /**
     * @return array{type: string, coordinates: list<list<list<float>>>}
     */
    public static function squareGeoJson(float $latitude, float $longitude, float $half = 0.05): array
    {
        $ring = [
            [$longitude - $half, $latitude - $half], [$longitude + $half, $latitude - $half],
            [$longitude + $half, $latitude + $half], [$longitude - $half, $latitude + $half], [$longitude - $half, $latitude - $half],
        ];

        return ['type' => 'Polygon', 'coordinates' => [$ring]];
    }

    /**
     * @param  array<string, mixed>  $geoJson
     */
    public static function fromGeoJson(array $geoJson): Expression
    {
        return DB::raw('ST_SetSRID(ST_Multi(ST_GeomFromGeoJSON('.DB::getPdo()->quote(json_encode($geoJson)).')), 4326)');
    }

    public function around(float $latitude, float $longitude, float $half = 0.05): static
    {
        return $this->state(fn () => ['polygon' => self::square($latitude, $longitude, $half)]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }
}
