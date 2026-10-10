<?php

namespace Tests\Feature\Realtime;

use App\Data\RealtimeEnvelope;
use App\Realtime\RealtimeEventType;
use App\Realtime\StreamClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\Support\StreamConsumer;
use Tests\TestCase;

/** Real PostgreSQL + real Redis (test DB 15, key prefix "test:events:"). */
abstract class RealtimeTestCase extends TestCase
{
    use RefreshDatabase;

    protected StreamClient $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = app(StreamClient::class);
        $this->flushStreams();
    }

    protected function tearDown(): void
    {
        // Tests may point the app at another connection/client; clean up on the normal one.
        config(['realtime.connection' => 'default', 'database.redis.client' => 'phpredis']);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();
        $this->flushStreams();

        parent::tearDown();
    }

    private function flushStreams(): void
    {
        foreach (RealtimeEventType::cases() as $type) {
            $this->redis->raw(['DEL', $type->streamKey()]);
        }
    }

    protected function streamLength(RealtimeEventType $type): int
    {
        return (int) $this->redis->raw(['XLEN', $type->streamKey()]);
    }

    /**
     * Every entry of a stream (XRANGE), decoded.
     *
     * @return list<array{id: string, fields: array<string, string>, envelope: RealtimeEnvelope}>
     */
    protected function entries(RealtimeEventType $type): array
    {
        $entries = [];
        foreach ($this->redis->raw(['XRANGE', $type->streamKey(), '-', '+']) as [$id, $flat]) {
            $fields = [];
            for ($i = 0; $i < count($flat); $i += 2) {
                $fields[$flat[$i]] = $flat[$i + 1];
            }
            $entries[] = ['id' => $id, 'fields' => $fields, 'envelope' => RealtimeEnvelope::fromStreamFields($fields)];
        }

        return $entries;
    }

    protected function totalPublished(): int
    {
        return array_sum(array_map(fn (RealtimeEventType $type) => $this->streamLength($type), RealtimeEventType::cases()));
    }

    protected function consumer(string $group = 'socket-service', string $name = 'node-1'): StreamConsumer
    {
        return new StreamConsumer($this->redis, $group, $name);
    }
}
