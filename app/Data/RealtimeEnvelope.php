<?php

namespace App\Data;

use App\Realtime\RealtimeEvent;
use App\Realtime\RealtimeEventType;
use Carbon\CarbonImmutable;

/**
 * The JSON envelope every event carries: event_id (UUID), type, version, occurred_at, payload.
 * In the stream it is stored as flat fields (payload JSON-encoded); toArray() is the logical form.
 */
final readonly class RealtimeEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public RealtimeEventType $type,
        public int $version,
        public CarbonImmutable $occurredAt,
        public array $payload,
    ) {}

    public static function from(RealtimeEvent $event): self
    {
        return new self($event->eventId(), $event->type(), $event->type()->version(), $event->occurredAt(), $event->payload());
    }

    /**
     * @param  array<string, string>  $fields  flat stream entry fields
     */
    public static function fromStreamFields(array $fields): self
    {
        return new self(
            $fields['event_id'],
            RealtimeEventType::from($fields['type']),
            (int) $fields['version'],
            CarbonImmutable::parse($fields['occurred_at']),
            json_decode($fields['payload'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toStreamFields(): array
    {
        return [
            'event_id' => $this->eventId,
            'type' => $this->type->value,
            'version' => (string) $this->version,
            // UTC, millisecond precision, e.g. 2026-10-10T09:15:30.123Z
            'occurred_at' => $this->occurredAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'payload' => json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
        ];
    }

    /**
     * @return array{event_id: string, type: string, version: int, occurred_at: string, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        $fields = $this->toStreamFields();

        return [
            'event_id' => $fields['event_id'],
            'type' => $fields['type'],
            'version' => $this->version,
            'occurred_at' => $fields['occurred_at'],
            'payload' => $this->payload,
        ];
    }
}
