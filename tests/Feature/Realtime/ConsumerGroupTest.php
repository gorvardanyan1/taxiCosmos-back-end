<?php

namespace Tests\Feature\Realtime;

use App\Events\Realtime\TripStatusChanged;
use App\Events\Realtime\UserSuspended;
use App\Realtime\RealtimeEventType;

/**
 * The Node service reads each stream through a consumer group (XREADGROUP) and XACKs after
 * delivering to clients. These tests play that consumer against real Redis and show that a
 * consumer that is offline, crashes, or arrives late loses nothing.
 */
class ConsumerGroupTest extends RealtimeTestCase
{
    private function key(RealtimeEventType $type): string
    {
        return $type->streamKey();
    }

    public function test_events_xadded_while_the_consumer_is_offline_are_delivered_in_order_after_it_reconnects(): void
    {
        $key = $this->key(RealtimeEventType::UserSuspended);
        $consumer = $this->consumer();
        $consumer->createGroup([$key]); // the service registered its group, then went offline

        $published = [];
        foreach ([101, 102, 103] as $userId) {
            $event = new UserSuspended($userId, 'suspended');
            event($event);
            $published[] = $event->eventId();
        }

        // Reconnect: the consumer reads what accumulated while it was away.
        $delivered = $this->consumer(name: 'node-1-after-restart')->read([$key]);

        $this->assertSame($published, array_map(fn ($d) => $d['envelope']->eventId, $delivered), 'All events, same order, none lost.');
        $this->assertSame([101, 102, 103], array_map(fn ($d) => $d['envelope']->payload['user_id'], $delivered));
        $this->assertSame(3, $this->consumer()->pendingCount($key), 'Delivered but not yet acknowledged.');

        foreach ($delivered as $d) {
            $this->consumer()->ack($d['key'], $d['id']);
        }
        $this->assertSame(0, $this->consumer()->pendingCount($key));
        $this->assertSame([], $this->consumer()->read([$key]), 'Acknowledged events are not delivered again.');
    }

    public function test_a_consumer_that_crashes_before_ack_gets_the_same_events_again_so_none_are_lost(): void
    {
        $key = $this->key(RealtimeEventType::TripStatusChanged);
        $this->consumer()->createGroup([$key]);
        event($event = new TripStatusChanged(501, 'TK-1', 'matched', 'arrived', 7, 42));

        $firstRun = $this->consumer(name: 'node-1')->read([$key]);
        $this->assertCount(1, $firstRun);
        // node-1 crashes here: it never XACKs.

        $recovered = $this->consumer(name: 'node-1')->read([$key], '0'); // pending list of the same consumer name
        $this->assertCount(1, $recovered);
        $this->assertSame($event->eventId(), $recovered[0]['envelope']->eventId);
        $this->assertSame($firstRun[0]['id'], $recovered[0]['id'], 'Same stream entry, so the consumer can deduplicate by event_id or entry id.');

        $this->consumer(name: 'node-1')->ack($key, $recovered[0]['id']);
        $this->assertSame([], $this->consumer(name: 'node-1')->read([$key], '0'));
        $this->assertSame(0, $this->consumer()->pendingCount($key));
    }

    public function test_a_consumer_group_created_from_the_start_reads_the_history_published_before_it_existed(): void
    {
        $key = $this->key(RealtimeEventType::UserSuspended);
        event(new UserSuspended(1, 'suspended'));
        event(new UserSuspended(2, 'suspended'));

        $consumer = $this->consumer();
        $consumer->createGroup([$key], '0');

        $this->assertSame([1, 2], array_map(fn ($d) => $d['envelope']->payload['user_id'], $consumer->read([$key])));
    }

    public function test_events_published_after_a_partial_read_continue_where_the_group_left_off(): void
    {
        $key = $this->key(RealtimeEventType::UserSuspended);
        $consumer = $this->consumer();
        $consumer->createGroup([$key]);
        event(new UserSuspended(1, 'suspended'));
        $first = $consumer->read([$key]);
        $consumer->ack($key, $first[0]['id']);

        event(new UserSuspended(2, 'suspended'));
        event(new UserSuspended(3, 'suspended'));

        $this->assertSame([2, 3], array_map(fn ($d) => $d['envelope']->payload['user_id'], $consumer->read([$key])));
    }

    public function test_one_read_can_follow_several_streams_and_each_entry_is_tagged_with_its_stream(): void
    {
        $suspended = $this->key(RealtimeEventType::UserSuspended);
        $status = $this->key(RealtimeEventType::TripStatusChanged);
        $consumer = $this->consumer();
        $consumer->createGroup([$suspended, $status]);

        event(new TripStatusChanged(501, 'TK-1', null, 'requested', 7, null));
        event(new UserSuspended(42, 'suspended'));

        $byStream = [];
        foreach ($consumer->read([$suspended, $status]) as $d) {
            $byStream[$d['key']][] = $d['envelope']->type->value;
        }

        $this->assertSame([$suspended => ['user.suspended'], $status => ['trip.status_changed']], $byStream);
    }

    public function test_two_consumers_in_one_group_share_the_work_each_event_goes_to_exactly_one_of_them(): void
    {
        $key = $this->key(RealtimeEventType::UserSuspended);
        $this->consumer()->createGroup([$key]);
        foreach (range(1, 6) as $userId) {
            event(new UserSuspended($userId, 'suspended'));
        }

        $a = $this->consumer(name: 'node-a')->read([$key], count: 3);
        $b = $this->consumer(name: 'node-b')->read([$key], count: 3);

        $ids = array_merge(array_map(fn ($d) => $d['envelope']->payload['user_id'], $a), array_map(fn ($d) => $d['envelope']->payload['user_id'], $b));
        sort($ids);
        $this->assertSame([1, 2, 3, 4, 5, 6], $ids, 'Together they get every event once.');
        $this->assertCount(3, $a);
        $this->assertCount(3, $b);
    }

    public function test_the_consumer_sees_the_full_documented_envelope(): void
    {
        $key = $this->key(RealtimeEventType::UserSuspended);
        $consumer = $this->consumer();
        $consumer->createGroup([$key]);
        event($event = new UserSuspended(42, 'deactivated'));

        $envelope = $consumer->read([$key])[0]['envelope'];

        $this->assertSame([
            'event_id' => $event->eventId(),
            'type' => 'user.suspended',
            'version' => 1,
            'occurred_at' => $event->occurredAt()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'payload' => ['user_id' => 42, 'status' => 'deactivated'],
        ], $envelope->toArray());
    }
}
