<?php

namespace App\Listeners;

use App\Realtime\RealtimeEvent;
use App\Realtime\RealtimePublisher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Appends a RealtimeEvent to its Redis Stream. Events are ShouldDispatchAfterCommit, so by the
 * time this runs the database change is committed. If Redis is unreachable the failure is
 * reported but must not turn an already-committed change into an error for the caller; clients
 * recover by refetching state over REST (see docs/realtime-events.md, "Delivery guarantees").
 */
class PublishRealtimeEvent
{
    public function __construct(private readonly RealtimePublisher $publisher) {}

    public function handle(RealtimeEvent $event): void
    {
        try {
            $this->publisher->publish($event);
        } catch (Throwable $e) {
            // Log ids only: payloads can contain personal data.
            Log::error('Real-time event could not be published to Redis.', [
                'event_id' => $event->eventId(),
                'type' => $event->type()->value,
                'exception' => $e::class,
            ]);
            report($e);
        }
    }
}
