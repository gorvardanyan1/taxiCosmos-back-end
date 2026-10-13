<?php

namespace App\Services\Drivers;

use App\Data\GeoPoint;
use App\Realtime\StreamClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where drivers are right now, in Redis, shared with the Node service (which GEOADDs to the same keys): a geo set of positions
 * and a sorted set of the unix time of each driver's last ping, both keyed by driver USER id. The keys are used verbatim (raw
 * commands, no Laravel REDIS_PREFIX). A position and its time are written by one script, so they never disagree.
 */
class DriverLocationStore
{
    private const WRITE = "redis.call('GEOADD', KEYS[1], ARGV[1], ARGV[2], ARGV[3]) redis.call('ZADD', KEYS[2], ARGV[4], ARGV[3]) return 1";

    private const REMOVE = "redis.call('ZREM', KEYS[1], ARGV[1]) redis.call('ZREM', KEYS[2], ARGV[1]) return 1";

    public function __construct(private readonly StreamClient $redis) {}

    public function record(int $driverUserId, GeoPoint $point, CarbonInterface $at): void
    {
        $this->redis->raw(['EVAL', self::WRITE, '2', $this->locationsKey(), $this->lastSeenKey(), (string) $point->longitude, (string) $point->latitude, (string) $driverUserId, (string) $at->getTimestamp()]);
    }

    /** Forget a driver's position and last ping (offline, or a position nobody should still use). */
    public function remove(int $driverUserId): void
    {
        $this->redis->raw(['EVAL', self::REMOVE, '2', $this->locationsKey(), $this->lastSeenKey(), (string) $driverUserId]);
    }

    /**
     * remove() for callers that must not fail because Redis is down (the database change is already committed): a failure is
     * logged and reported as false, and the stale sweep removes what is left.
     */
    public function removeQuietly(int $driverUserId): bool
    {
        try {
            $this->remove($driverUserId);

            return true;
        } catch (Throwable $e) {
            Log::warning('A driver position could not be removed from Redis.', ['driver_user_id' => $driverUserId, 'exception' => $e::class]);

            return false;
        }
    }

    /**
     * Unix time of the last ping per driver user id; null for a driver with none.
     *
     * @param  list<int>  $driverUserIds
     * @return array<int, int|null>
     */
    public function lastSeen(array $driverUserIds): array
    {
        if ($driverUserIds === []) {
            return [];
        }

        $scores = $this->redis->raw(['ZMSCORE', $this->lastSeenKey(), ...array_map('strval', $driverUserIds)]);
        $result = [];

        foreach (array_values($driverUserIds) as $i => $id) {
            $score = $scores[$i] ?? null;
            $result[$id] = ($score === null || $score === false) ? null : (int) $score;
        }

        return $result;
    }

    /**
     * Driver user ids whose last ping is at or before the given unix time.
     *
     * @return list<int>
     */
    public function pingedAtOrBefore(int $unixTime): array
    {
        return array_map('intval', $this->redis->raw(['ZRANGEBYSCORE', $this->lastSeenKey(), '-inf', (string) $unixTime]));
    }

    public function locationsKey(): string
    {
        return (string) config('realtime.geo.locations_key');
    }

    public function lastSeenKey(): string
    {
        return (string) config('realtime.geo.last_seen_key');
    }
}
