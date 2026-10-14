<?php

namespace Tests\Feature\DriverLocation;

use App\Models\DriverLocationPing;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P8-T2: the MongoDB collection of pings has a history index and a TTL index (taxikosmos.driver_location.ping_retention_days).
 */
class DriverLocationPingIndexesTest extends TestCase
{
    /** @return array<string, array{key: array<string, int>, ttl: ?int}> */
    private function indexes(): array
    {
        $found = [];
        foreach (DB::connection('mongodb')->getCollection('driver_location_pings')->listIndexes() as $index) {
            $found[$index['name']] = ['key' => (array) $index['key'], 'ttl' => $index['expireAfterSeconds'] ?? null];
        }

        return $found;
    }

    public function test_pings_expire_after_the_configured_retention_and_are_indexed_for_one_drivers_history(): void
    {
        $indexes = $this->indexes();

        $this->assertSame(['driver_user_id' => 1, 'recorded_at' => -1], $indexes['driver_history']['key']);
        $this->assertNull($indexes['driver_history']['ttl'], 'history is not deleted by the history index');
        $this->assertSame(['recorded_at' => 1], $indexes['recorded_at_1']['key']);
        $this->assertSame(90 * 86400, $indexes['recorded_at_1']['ttl'], 'the default retention is 90 days');
    }

    public function test_rolling_the_migration_back_keeps_the_history_and_only_drops_the_indexes(): void
    {
        $migration = require base_path('database/migrations/2026_10_14_000001_create_driver_location_pings_collection.php');
        DriverLocationPing::query()->delete();
        DriverLocationPing::query()->create(['driver_user_id' => 1, 'driver_profile_id' => 1, 'location' => ['type' => 'Point', 'coordinates' => [44.5, 40.1]], 'source' => 'rest', 'recorded_at' => now()]);

        try {
            $migration->down();

            $this->assertSame(1, DriverLocationPing::query()->count(), 'the collected history is not deleted by a rollback');
            $this->assertSame(['_id_'], array_keys($this->indexes()));
        } finally {
            $migration->up(); // leave the test database as the suite expects it
            DriverLocationPing::query()->delete();
        }

        $this->assertSame(90 * 86400, $this->indexes()['recorded_at_1']['ttl']);
        $this->assertArrayHasKey('driver_history', $this->indexes());
    }

    public function test_the_retention_default_is_ninety_days(): void
    {
        $this->assertStringContainsString("env('DRIVER_PING_RETENTION_DAYS', 90)", (string) file_get_contents(base_path('config/taxikosmos.php')));
    }
}
