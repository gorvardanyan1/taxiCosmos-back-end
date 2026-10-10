<?php

namespace Tests\Feature\Realtime;

use App\Events\Realtime\UserSuspended;
use App\Realtime\RealtimeEventType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;

class PublishRealtimeEventTest extends RealtimeTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function eventNames(): array
    {
        return array_map(fn ($name) => [$name], array_combine(array_keys(EventFactory::all()), array_keys(EventFactory::all())));
    }

    #[DataProvider('eventNames')]
    public function test_dispatching_an_event_appends_its_envelope_to_its_own_stream_only(string $name): void
    {
        [$type, $event, $payload] = EventFactory::all()[$name];

        event($event);

        $this->assertSame(1, $this->streamLength($type));
        $this->assertSame(1, $this->totalPublished(), 'Only this event type\'s stream may receive an entry.');
        [$entry] = $this->entries($type);
        $this->assertSame(['event_id', 'type', 'version', 'occurred_at', 'payload'], array_keys($entry['fields']));
        $this->assertSame($event->eventId(), $entry['fields']['event_id']);
        $this->assertSame($type->value, $entry['fields']['type']);
        $this->assertSame('1', $entry['fields']['version']);
        $this->assertSame($event->occurredAt()->utc()->format('Y-m-d\TH:i:s.v\Z'), $entry['fields']['occurred_at']);
        $this->assertSame($payload, $entry['envelope']->payload);
        $this->assertSame($type->streamKey(), config('realtime.stream_prefix').$type->value);
    }

    public function test_the_stream_key_is_written_verbatim_without_the_laravel_redis_prefix(): void
    {
        event(new UserSuspended(42, 'suspended'));

        $this->assertSame(1, (int) $this->redis->raw(['XLEN', 'test:events:user.suspended']));
        $this->assertSame(0, (int) $this->redis->raw(['EXISTS', config('database.redis.options.prefix').'test:events:user.suspended']));
    }

    public function test_events_are_appended_in_dispatch_order_with_increasing_entry_ids(): void
    {
        foreach ([1, 2, 3] as $userId) {
            event(new UserSuspended($userId, 'suspended'));
        }

        $entries = $this->entries(RealtimeEventType::UserSuspended);
        $this->assertSame([1, 2, 3], array_map(fn ($e) => $e['envelope']->payload['user_id'], $entries));
        $ids = array_column($entries, 'id');
        $sorted = $ids;
        usort($sorted, fn ($a, $b) => version_compare(str_replace('-', '.', $a), str_replace('-', '.', $b)));
        $this->assertSame($ids, $sorted);
    }

    public function test_streams_are_trimmed_to_the_configured_length_approximately(): void
    {
        config(['realtime.max_length' => 100]);

        foreach (range(1, 450) as $userId) {
            event(new UserSuspended($userId, 'suspended'));
        }

        $length = $this->streamLength(RealtimeEventType::UserSuspended);
        $this->assertLessThan(450, $length, 'Old entries must be trimmed.');
        $this->assertGreaterThanOrEqual(100, $length, 'MAXLEN ~ never trims below the limit.');
        $newest = array_last($this->entries(RealtimeEventType::UserSuspended));
        $this->assertSame(450, $newest['envelope']->payload['user_id'], 'The newest entry is always kept.');
    }

    public function test_the_default_trim_limit_is_one_hundred_thousand_entries_per_stream(): void
    {
        $this->assertSame(100000, (int) (include base_path('config/realtime.php'))['max_length']);
    }

    public function test_publishing_works_with_the_predis_client_too(): void
    {
        config(['database.redis.client' => 'predis']);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();

        event(new UserSuspended(42, 'suspended'));

        $this->assertSame(1, $this->streamLength(RealtimeEventType::UserSuspended));
        $this->assertSame(42, $this->entries(RealtimeEventType::UserSuspended)[0]['envelope']->payload['user_id']);
    }

    public function test_a_redis_outage_is_logged_without_the_payload_and_does_not_fail_the_caller(): void
    {
        config([
            'realtime.connection' => 'unreachable',
            'database.redis.unreachable' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 1, 'read_timeout' => 1],
        ]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();
        Log::spy();
        $event = new UserSuspended(42, 'suspended');

        event($event);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context = []) use ($event) {
            return str_contains($message, 'could not be published')
                && $context['event_id'] === $event->eventId()
                && $context['type'] === 'user.suspended'
                && ! array_key_exists('payload', $context)
                && ! str_contains(json_encode($context), '"user_id"');
        })->once();
    }
}
