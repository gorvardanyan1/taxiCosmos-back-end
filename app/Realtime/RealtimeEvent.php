<?php

namespace App\Realtime;

use Carbon\CarbonImmutable;

/**
 * A Laravel event that is also published to the real-time Redis Stream. Implementations are
 * plain event objects (see app/Events/Realtime) dispatched after the DB transaction commits;
 * PublishRealtimeEvent turns them into stream entries.
 */
interface RealtimeEvent
{
    /** Stable UUID of this occurrence, so consumers can deduplicate redeliveries. */
    public function eventId(): string;

    public function type(): RealtimeEventType;

    /** When the state change happened (not when it was committed or published). */
    public function occurredAt(): CarbonImmutable;

    /**
     * @return array<string, mixed> keyed exactly like type()->payloadKeys()
     */
    public function payload(): array;
}
