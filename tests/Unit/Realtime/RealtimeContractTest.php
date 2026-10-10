<?php

namespace Tests\Unit\Realtime;

use App\Data\RealtimeEnvelope;
use App\Events\Realtime\TripOffer;
use App\Realtime\RealtimeEventType;
use Carbon\CarbonImmutable;
use Tests\Feature\Realtime\EventFactory;
use Tests\TestCase;

class RealtimeContractTest extends TestCase
{
    public function test_every_event_type_has_exactly_one_event_class_with_a_payload_matching_its_documented_keys(): void
    {
        $covered = [];

        foreach (EventFactory::all() as $name => [$type, $event, $expectedPayload]) {
            $covered[] = $type;
            $this->assertSame($type, $event->type(), $name);
            $this->assertSame($type->payloadKeys(), array_keys($event->payload()), "{$name}: payload keys must match RealtimeEventType::payloadKeys() in order");
            $this->assertSame($expectedPayload, $event->payload(), $name);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $event->eventId(), $name);
        }

        $this->assertEqualsCanonicalizing(RealtimeEventType::cases(), $covered, 'An event type has no event class (or the factory misses it).');
        $this->assertCount(12, RealtimeEventType::cases());
    }

    public function test_stream_keys_are_one_per_type_built_from_the_configured_prefix(): void
    {
        config(['realtime.stream_prefix' => 'taxikosmos:events:']);

        $keys = array_map(fn (RealtimeEventType $type) => $type->streamKey(), RealtimeEventType::cases());

        $this->assertSame('taxikosmos:events:trip.status_changed', RealtimeEventType::TripStatusChanged->streamKey());
        $this->assertSame('taxikosmos:events:user.suspended', RealtimeEventType::UserSuspended->streamKey());
        $this->assertSame($keys, array_values(array_unique($keys)), 'Stream keys must be unique.');
    }

    public function test_every_type_starts_at_schema_version_one(): void
    {
        foreach (RealtimeEventType::cases() as $type) {
            $this->assertSame(1, $type->version(), $type->value);
        }
    }

    public function test_each_occurrence_has_its_own_event_id_and_a_fixed_occurrence_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:15:30.123456', 'UTC'));
        $first = EventFactory::all()['user.suspended'][1];
        $this->travel(5)->seconds();
        $second = EventFactory::all()['user.suspended'][1];

        $this->assertNotSame($first->eventId(), $second->eventId());
        $this->assertSame('2026-10-10 09:15:30', $first->occurredAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-10 09:15:35', $second->occurredAt()->format('Y-m-d H:i:s'));
        // Reading the time later (e.g. after commit) never changes it.
        $this->travel(60)->seconds();
        $this->assertSame('2026-10-10 09:15:30', $first->occurredAt()->format('Y-m-d H:i:s'));
    }

    public function test_the_envelope_carries_event_id_type_version_occurred_at_and_payload(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 13:15:30.123', 'Asia/Yerevan'));
        [, $event] = EventFactory::all()['trip.fare_adjusted'];

        $envelope = RealtimeEnvelope::from($event);
        $array = $envelope->toArray();

        $this->assertSame(['event_id', 'type', 'version', 'occurred_at', 'payload'], array_keys($array));
        $this->assertSame($event->eventId(), $array['event_id']);
        $this->assertSame('trip.fare_adjusted', $array['type']);
        $this->assertSame(1, $array['version']);
        $this->assertSame('2026-10-10T09:15:30.123Z', $array['occurred_at'], 'occurred_at is UTC with milliseconds.');
        $this->assertSame($event->payload(), $array['payload']);
    }

    public function test_stream_fields_are_flat_strings_and_round_trip_without_loss(): void
    {
        foreach (EventFactory::all() as $name => [, $event]) {
            $envelope = RealtimeEnvelope::from($event);
            $fields = $envelope->toStreamFields();

            $this->assertSame(['event_id', 'type', 'version', 'occurred_at', 'payload'], array_keys($fields), $name);
            $this->assertContainsOnlyString($fields);
            $restored = RealtimeEnvelope::fromStreamFields($fields);

            $this->assertSame($envelope->toArray(), $restored->toArray(), $name);
            $this->assertSame($event->payload(), json_decode($fields['payload'], true), $name);
        }
    }

    public function test_the_payload_json_keeps_unicode_slashes_and_whole_number_floats_readable_and_exact(): void
    {
        $fields = RealtimeEnvelope::from(EventFactory::all()['driver.location_updated'][1])->toStreamFields();
        $this->assertStringContainsString('"latitude":40.1777', $fields['payload']);

        $armenian = new TripOffer(1, 2, 3, CarbonImmutable::now(), 'Հանրապետության հրապարակ / Republic Sq', 'Ջրվեժ', 1000, 'AMD', 'economy');
        $json = RealtimeEnvelope::from($armenian)->toStreamFields()['payload'];

        $this->assertStringContainsString('Հանրապետության հրապարակ / Republic Sq', $json);
        $this->assertStringNotContainsString('\\u', $json);
        $this->assertStringNotContainsString('\\/', $json);
    }
}
