<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * One lightweight position ping of a driver, kept in MongoDB for history (P8-T2). Append-only and never read to decide anything:
 * the live position is the Redis geo set, and PostgreSQL is the source of truth for the driver's state. The collection has a TTL
 * index on `recorded_at` (taxikosmos.driver_location.ping_retention_days), so old pings delete themselves.
 *
 * @property int $driver_user_id
 * @property int $driver_profile_id
 * @property array{type: string, coordinates: array{0: float, 1: float}} $location GeoJSON point, [longitude, latitude]
 */
class DriverLocationPing extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'driver_location_pings';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'driver_user_id' => 'integer', 'driver_profile_id' => 'integer', 'heading' => 'integer', 'speed' => 'float', 'accuracy' => 'float'];
    }
}
