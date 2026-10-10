<?php

namespace App\Realtime;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Minimal Redis Streams writer. Uses raw commands on purpose: they are sent verbatim, so the
 * stream keys are exactly the documented ones (typed phpredis/Predis calls would prepend Laravel's
 * global REDIS_PREFIX, which the Node service does not know about).
 */
class StreamClient
{
    /**
     * XADD key MAXLEN ~ max * field value ... — returns the generated entry ID.
     *
     * @param  array<string, string>  $fields
     */
    public function add(string $key, array $fields, int $maxLength): string
    {
        $arguments = ['XADD', $key, 'MAXLEN', '~', (string) $maxLength, '*'];
        foreach ($fields as $field => $value) {
            array_push($arguments, $field, $value);
        }

        $id = $this->raw($arguments);

        if (! is_string($id)) {
            throw new RuntimeException("XADD to [{$key}] did not return an entry id.");
        }

        return $id;
    }

    /**
     * Run any raw Redis command on the real-time connection (also used by tests to act as the consumer).
     *
     * @param  list<string>  $arguments  command name first
     */
    public function raw(array $arguments): mixed
    {
        $connection = $this->connection();

        return match (true) {
            $connection instanceof PhpRedisConnection => $connection->client()->rawCommand(...$arguments),
            $connection instanceof PredisConnection => $connection->client()->executeRaw($arguments),
            default => throw new RuntimeException('Unsupported Redis client for streams: '.$connection::class),
        };
    }

    private function connection(): Connection
    {
        return Redis::connection(config('realtime.connection'));
    }
}
