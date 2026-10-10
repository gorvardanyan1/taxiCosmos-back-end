<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Base for events published to the real-time stream. ShouldDispatchAfterCommit holds the event
 * back until the surrounding DB transaction commits and drops it if the transaction rolls back,
 * so a rolled-back change is never broadcast. The event id and time are fixed here, when the
 * state change happens.
 */
abstract class RealtimeDomainEvent implements RealtimeEvent, ShouldDispatchAfterCommit
{
    use Dispatchable;

    private readonly string $eventId;

    private readonly CarbonImmutable $occurredAt;

    public function __construct()
    {
        $this->eventId = (string) Str::uuid();
        $this->occurredAt = CarbonImmutable::now();
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function occurredAt(): CarbonImmutable
    {
        return $this->occurredAt;
    }

    /**
     * @return array{amount: int, currency: string}
     */
    protected static function money(int $amount, string $currency): array
    {
        return ['amount' => $amount, 'currency' => $currency];
    }
}
