<?php

namespace App\Services\Drivers;

use App\Models\DriverLocationPing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Appends a ping to MongoDB. History only: it is called after the HTTP response (defer), and a failure is logged with ids,
 * never the position, and does not undo the live position that is already in Redis.
 */
final class DriverPingHistory
{
    public function append(int $driverUserId, int $driverProfileId, float $latitude, float $longitude, ?int $heading, ?float $speed, ?float $accuracy, CarbonImmutable $recordedAt): void
    {
        try {
            DriverLocationPing::query()->create([
                'driver_user_id' => $driverUserId,
                'driver_profile_id' => $driverProfileId,
                // GeoJSON order: longitude first.
                'location' => ['type' => 'Point', 'coordinates' => [$longitude, $latitude]],
                'heading' => $heading,
                'speed' => $speed,
                'accuracy' => $accuracy,
                'source' => 'rest',
                'recorded_at' => $recordedAt,
            ]);
        } catch (Throwable $e) {
            Log::warning('A driver location ping could not be saved to MongoDB.', ['driver_user_id' => $driverUserId, 'exception' => $e::class]);
        }
    }
}
