<?php

namespace App\Realtime;

use App\Data\RealtimeEnvelope;

/**
 * Appends one event to its Redis Stream (XADD, trimmed with MAXLEN ~). Called only after the
 * database transaction that caused the event has committed (see PublishRealtimeEvent).
 */
class RealtimePublisher
{
    public function __construct(private readonly StreamClient $streams) {}

    /**
     * @return string the Redis stream entry id (e.g. "1791626222366-0")
     */
    public function publish(RealtimeEvent $event): string
    {
        $envelope = RealtimeEnvelope::from($event);

        return $this->streams->add(
            $envelope->type->streamKey(),
            $envelope->toStreamFields(),
            (int) config('realtime.max_length'),
        );
    }
}
