<?php

namespace Tests\Feature\DriverLocation;

use App\Data\GeoPoint;
use App\Services\Drivers\DriverLocationStore;
use Carbon\CarbonImmutable;

/**
 * P8-T2: the Redis layout shared with the Node service (drivers:locations geo set, drivers:last_seen sorted set).
 */
class DriverLocationStoreTest extends DriverLocationTestCase
{
    private function store(): DriverLocationStore
    {
        return app(DriverLocationStore::class);
    }

    public function test_the_keys_are_the_configured_ones_verbatim_without_laravels_prefix(): void
    {
        $this->store()->record(7, new GeoPoint(40.18, 44.5), CarbonImmutable::now());

        $this->assertSame('test:drivers:locations', $this->store()->locationsKey());
        $this->assertSame('test:drivers:last_seen', $this->store()->lastSeenKey());
        $this->assertSame(1, (int) $this->redis->raw(['EXISTS', 'test:drivers:locations']));
        $this->assertSame(1, (int) $this->redis->raw(['EXISTS', 'test:drivers:last_seen']));
        // Exactly these two keys, so nothing was written under Laravel's REDIS_PREFIX.
        $this->assertCount(2, $this->redis->raw(['KEYS', '*test:drivers:*']));
    }

    public function test_the_defaults_are_the_names_the_task_and_the_node_service_use(): void
    {
        $source = (string) file_get_contents(base_path('config/realtime.php'));

        $this->assertStringContainsString("env('REALTIME_GEO_KEY', 'drivers:locations')", $source);
        $this->assertStringContainsString("env('REALTIME_LAST_SEEN_KEY', 'drivers:last_seen')", $source);
    }

    public function test_a_position_is_searchable_by_distance_and_carries_its_time(): void
    {
        $now = CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC');
        $this->store()->record(1, new GeoPoint(40.1792, 44.4991), $now);
        $this->store()->record(2, new GeoPoint(40.1800, 44.5000), $now);
        $this->store()->record(3, new GeoPoint(41.5, 45.5), $now);

        // What dispatch (P7-T7) will do: a GEOSEARCH around the pickup.
        $near = $this->redis->raw(['GEOSEARCH', 'test:drivers:locations', 'FROMLONLAT', '44.4995', '40.1795', 'BYRADIUS', '3', 'km', 'ASC']);

        $this->assertSame(['1', '2'], array_map('strval', $near));
        $this->assertSame([1 => $now->getTimestamp(), 3 => $now->getTimestamp(), 9 => null], $this->store()->lastSeen([1, 3, 9]));
    }

    public function test_remove_takes_a_driver_out_of_both_sets_and_only_that_driver(): void
    {
        $this->store()->record(1, new GeoPoint(40.18, 44.5), CarbonImmutable::now());
        $this->store()->record(2, new GeoPoint(40.19, 44.51), CarbonImmutable::now());

        $this->store()->remove(1);

        $this->assertNull($this->position(1));
        $this->assertNull($this->lastSeen(1));
        $this->assertNotNull($this->position(2));
        $this->assertNotNull($this->lastSeen(2));
        $this->store()->remove(99); // a driver who is not there is no error
    }

    public function test_pinged_at_or_before_includes_the_boundary_second(): void
    {
        $this->store()->record(1, new GeoPoint(40.18, 44.5), CarbonImmutable::createFromTimestampUTC(1000));
        $this->store()->record(2, new GeoPoint(40.18, 44.5), CarbonImmutable::createFromTimestampUTC(1001));
        $this->store()->record(3, new GeoPoint(40.18, 44.5), CarbonImmutable::createFromTimestampUTC(999));

        $this->assertEqualsCanonicalizing([1, 3], $this->store()->pingedAtOrBefore(1000));
        $this->assertSame([], $this->store()->pingedAtOrBefore(998));
        $this->assertSame([], $this->store()->lastSeen([]));
    }

    public function test_remove_quietly_swallows_a_redis_failure_and_says_so(): void
    {
        $broken = new class($this->redis) extends DriverLocationStore
        {
            public function remove(int $driverUserId): void
            {
                throw new \RuntimeException('down');
            }
        };

        $this->assertFalse($broken->removeQuietly(1));

        // Whatever the client library throws (not only a RuntimeException) must not undo a committed change.
        $odd = new class($this->redis) extends DriverLocationStore
        {
            public function remove(int $driverUserId): void
            {
                throw new \LogicException('unexpected');
            }
        };
        $this->assertFalse($odd->removeQuietly(1));
        $this->assertTrue($this->store()->removeQuietly(1));
    }
}
