<?php

namespace Tests\Feature\DriverLocation;

use App\Enums\DriverAvailability;
use App\Enums\DriverDocumentType;
use App\Models\DriverDocument;
use App\Models\DriverLocationPing;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Realtime\RealtimeTestCase;

/**
 * Real PostgreSQL, real Redis (test DB 15, keys "test:drivers:*") and the test MongoDB database, like the realtime tests.
 */
abstract class DriverLocationTestCase extends RealtimeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->flushPositions();
        DriverLocationPing::query()->delete();
    }

    protected function tearDown(): void
    {
        $this->flushPositions();
        DriverLocationPing::query()->delete();

        parent::tearDown();
    }

    private function flushPositions(): void
    {
        $this->redis->raw(['DEL', config('realtime.geo.locations_key'), config('realtime.geo.last_seen_key')]);
    }

    /**
     * A driver who may work: approved, with an active primary vehicle.
     *
     * @param  array<string, mixed>  $profile
     * @return array{0: User, 1: DriverProfile, 2: string}
     */
    protected function driver(DriverAvailability $availability = DriverAvailability::Online, array $profile = [], bool $withVehicle = true): array
    {
        $driver = DriverProfile::factory()->approved()->create(['availability' => $availability, 'last_online_at' => $availability === DriverAvailability::Offline ? null : now(), ...$profile]);

        if ($withVehicle) {
            Vehicle::factory()->active()->primary()->for($driver, 'driver')->create();
        }

        return [$driver->user, $driver, $driver->user->createToken('device', ['driver'])->plainTextToken];
    }

    /** All the papers a driver needs, approved; one of them can be given an expiry date. */
    protected function allDocuments(DriverProfile $profile, ?DriverDocumentType $expiring = null, string $expiryDate = '2020-01-01'): void
    {
        foreach (DriverDocumentType::cases() as $type) {
            DriverDocument::factory()->ofType($type)->approved()->expiring($type === $expiring ? $expiryDate : '2099-01-01')->create(['driver_id' => $profile->id]);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function ping(string $token, array $body = ['lat' => 40.1792, 'lng' => 44.4991])
    {
        app('auth')->forgetGuards(); // the guard remembers the first token's user for the rest of the test

        return $this->postJson('/api/v1/driver/location', $body, $this->bearer($token));
    }

    /**
     * Run the callback and return the statements that locked a driver profile row (SELECT ... FOR UPDATE), each with the
     * transaction depth it ran at: a lock outside a transaction is released at once and protects nothing.
     *
     * @return list<array{sql: string, depth: int}>
     */
    protected function profileLocksDuring(callable $callback): array
    {
        $locks = [];
        DB::listen(function ($query) use (&$locks) {
            if (str_contains($query->sql, 'driver_profiles') && str_contains(strtolower($query->sql), 'for update')) {
                $locks[] = ['sql' => $query->sql, 'depth' => DB::transactionLevel()];
            }
        });

        $callback();

        return $locks;
    }

    protected function setAvailability(string $token, mixed $availability)
    {
        app('auth')->forgetGuards();

        return $this->postJson('/api/v1/driver/availability', ['availability' => $availability], $this->bearer($token));
    }

    /** @return array{0: float, 1: float}|null [longitude, latitude] in the Redis geo set */
    protected function position(int $userId): ?array
    {
        $result = $this->redis->raw(['GEOPOS', config('realtime.geo.locations_key'), (string) $userId]);

        return ($result[0] ?? null) ? [(float) $result[0][0], (float) $result[0][1]] : null;
    }

    protected function lastSeen(int $userId): ?int
    {
        $score = $this->redis->raw(['ZSCORE', config('realtime.geo.last_seen_key'), (string) $userId]);

        return ($score === false || $score === null) ? null : (int) $score;
    }

    protected function positionCount(): int
    {
        return (int) $this->redis->raw(['ZCARD', config('realtime.geo.locations_key')]) + (int) $this->redis->raw(['ZCARD', config('realtime.geo.last_seen_key')]);
    }

    /** Put a position and a ping time in Redis the way the Node service would. */
    protected function seedPosition(int $userId, int $seenAt): void
    {
        $this->redis->raw(['GEOADD', config('realtime.geo.locations_key'), '44.5', '40.2', (string) $userId]);
        $this->redis->raw(['ZADD', config('realtime.geo.last_seen_key'), (string) $seenAt, (string) $userId]);
    }
}
