<?php

namespace Tests\Support;

use App\Data\RealtimeEnvelope;
use App\Realtime\StreamClient;

/**
 * Stands in for the Node/Socket.io service: a Redis Streams consumer group member
 * (XGROUP CREATE, XREADGROUP, XACK, XPENDING) that decodes entries into envelopes.
 */
class StreamConsumer
{
    public function __construct(
        private readonly StreamClient $redis,
        private readonly string $group,
        private readonly string $name,
    ) {}

    /**
     * @param  list<string>  $keys
     */
    public function createGroup(array $keys, string $startId = '0'): void
    {
        foreach ($keys as $key) {
            $this->redis->raw(['XGROUP', 'CREATE', $key, $this->group, $startId, 'MKSTREAM']);
        }
    }

    /**
     * XREADGROUP without blocking. $id ">" = entries never delivered to this group; "0" = this
     * consumer's own pending (delivered but not acknowledged) entries.
     *
     * @param  list<string>  $keys
     * @return list<array{key: string, id: string, envelope: RealtimeEnvelope}>
     */
    public function read(array $keys, string $id = '>', int $count = 100): array
    {
        $reply = $this->redis->raw([
            'XREADGROUP', 'GROUP', $this->group, $this->name, 'COUNT', (string) $count,
            'STREAMS', ...$keys, ...array_fill(0, count($keys), $id),
        ]);

        $entries = [];
        foreach ($reply ?: [] as [$key, $streamEntries]) {
            foreach ($streamEntries as [$entryId, $flat]) {
                $fields = [];
                for ($i = 0; $i < count($flat); $i += 2) {
                    $fields[$flat[$i]] = $flat[$i + 1];
                }
                $entries[] = ['key' => $key, 'id' => $entryId, 'envelope' => RealtimeEnvelope::fromStreamFields($fields)];
            }
        }

        return $entries;
    }

    public function ack(string $key, string ...$ids): int
    {
        return (int) $this->redis->raw(['XACK', $key, $this->group, ...$ids]);
    }

    public function pendingCount(string $key): int
    {
        return (int) $this->redis->raw(['XPENDING', $key, $this->group])[0];
    }
}
