<?php

namespace App\Services\Geo;

use App\Exceptions\GeoException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * The public Nominatim server allows one request per second for the whole application, so every call to it waits here first:
 * under a cache lock (shared by all PHP workers) the time of the previous call is read, the missing part of the interval is
 * slept off, and the new call time is stored. A self-hosted Nominatim has no such limit: an interval of 0 turns this off.
 *
 * A request never waits long for its turn: a web worker asleep in a queue starves the rest of the API. After `$maxWaitSeconds`
 * it gives up with GeoException::providerUnavailable() (502, "try again"), like any other maps outage.
 */
final class NominatimThrottle
{
    private const LAST_CALL_KEY = 'geo:osm:nominatim:last-call';

    private const LOCK_KEY = 'geo:osm:nominatim:lock';

    public function __construct(private readonly int $intervalMilliseconds, private readonly int $maxWaitSeconds = 3) {}

    /**
     * Runs `$call` no sooner than the interval after the previous Nominatim call.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    public function run(callable $call): mixed
    {
        if ($this->intervalMilliseconds <= 0) {
            return $call();
        }

        try {
            return Cache::lock(self::LOCK_KEY, 15)->block($this->maxWaitSeconds, fn () => $this->afterTheInterval($call));
        } catch (LockTimeoutException) {
            Log::warning('Maps provider call failed', ['provider' => 'osm', 'operation' => 'nominatim_throttle', 'status' => null]);

            throw GeoException::providerUnavailable();
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function afterTheInterval(callable $call): mixed
    {
        $last = Cache::get(self::LAST_CALL_KEY);

        if (is_int($last)) {
            $wait = $this->intervalMilliseconds - (self::nowMilliseconds() - $last);

            if ($wait > 0) {
                Sleep::for($wait)->milliseconds();
            }
        }

        try {
            return $call();
        } finally {
            Cache::put(self::LAST_CALL_KEY, self::nowMilliseconds(), 60);
        }
    }

    private static function nowMilliseconds(): int
    {
        return (int) round(now()->getPreciseTimestamp(3));
    }
}
