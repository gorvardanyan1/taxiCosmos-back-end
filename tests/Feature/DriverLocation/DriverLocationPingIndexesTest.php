<?php

namespace Tests\Feature\DriverLocation;

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

    public function test_the_retention_default_is_ninety_days(): void
    {
        $this->assertStringContainsString("env('DRIVER_PING_RETENTION_DAYS', 90)", (string) file_get_contents(base_path('config/taxikosmos.php')));
    }
}
