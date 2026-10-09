<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1-T1: the app talks to the real PostgreSQL+PostGIS, Redis and MongoDB services it is configured for.
 */
class ServiceConnectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_migration_file_ran_against_postgres(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('taxikosmos_test', DB::connection()->getDatabaseName());

        $files = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $path) => pathinfo($path, PATHINFO_FILENAME))
            ->sort()
            ->values()
            ->all();

        $ran = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();

        $this->assertNotEmpty($files);
        $this->assertSame($files, $ran);
    }

    public function test_postgres_is_version_18_with_postgis_enabled(): void
    {
        $serverVersion = (int) DB::scalar('SHOW server_version_num');
        $this->assertGreaterThanOrEqual(180000, $serverVersion);
        $this->assertLessThan(190000, $serverVersion);

        $postgis = DB::scalar("SELECT extversion FROM pg_extension WHERE extname = 'postgis'");
        $this->assertNotNull($postgis, 'PostGIS extension is not installed');
        $this->assertStringStartsWith('3.', $postgis);
    }

    public function test_redis_ping_and_round_trip_on_isolated_test_database(): void
    {
        $redis = Redis::connection();

        $this->assertSame('phpredis', config('database.redis.client'));
        $this->assertSame('15', (string) config('database.redis.default.database'));
        $this->assertTrue((bool) $redis->ping());

        $key = 'p1t1:'.Str::uuid();
        $redis->setex($key, 30, 'kosmos');

        try {
            $this->assertSame('kosmos', $redis->get($key));
            $ttl = $redis->ttl($key);
            $this->assertGreaterThan(0, $ttl);
            $this->assertLessThanOrEqual(30, $ttl);
        } finally {
            $redis->del($key);
        }

        $this->assertSame(0, (int) $redis->exists($key));
    }

    public function test_mongo_ping_and_round_trip_on_test_database(): void
    {
        $mongo = DB::connection('mongodb');
        $database = $mongo->getDatabase();

        $this->assertSame('taxikosmos_test', $database->getDatabaseName());

        $ping = $database->command(['ping' => 1])->toArray()[0];
        $this->assertEquals(1, $ping['ok']);

        $collection = 'p1t1_ping_'.Str::lower(Str::random(8));

        try {
            $mongo->table($collection)->insert(['trip_ref' => 'T-1', 'event' => 'requested']);

            $doc = $mongo->table($collection)->where('trip_ref', 'T-1')->first();
            $this->assertSame('requested', $doc->event);
            $this->assertSame(1, $mongo->table($collection)->count());
        } finally {
            $database->dropCollection($collection);
        }
    }
}
