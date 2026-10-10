<?php

namespace Tests\Feature\Realtime;

use App\Events\Realtime\TripStatusChanged;
use App\Events\Realtime\UserSuspended;
use App\Realtime\RealtimeEventType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EventsAfterCommitTest extends RealtimeTestCase
{
    public function test_an_event_raised_inside_a_transaction_that_rolls_back_is_never_xadded(): void
    {
        try {
            DB::transaction(function () {
                event(new UserSuspended(42, 'suspended'));
                event(new TripStatusChanged(501, 'TK-1', 'matched', 'arrived', 7, 42));

                throw new RuntimeException('payment failed, roll back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->totalPublished(), 'Nothing may be published for a rolled-back change.');
    }

    public function test_an_explicit_rollback_publishes_nothing(): void
    {
        DB::beginTransaction();
        event(new UserSuspended(42, 'suspended'));
        DB::rollBack();

        $this->assertSame(0, $this->totalPublished());
    }

    public function test_an_event_is_not_published_until_the_transaction_commits_then_exactly_once(): void
    {
        $insideTransaction = null;

        DB::transaction(function () use (&$insideTransaction) {
            event(new UserSuspended(42, 'suspended'));
            $insideTransaction = $this->streamLength(RealtimeEventType::UserSuspended);
        });

        $this->assertSame(0, $insideTransaction, 'Held back while the transaction is still open.');
        $this->assertSame(1, $this->streamLength(RealtimeEventType::UserSuspended));
    }

    public function test_a_committed_inner_savepoint_is_dropped_when_the_outer_transaction_rolls_back(): void
    {
        try {
            DB::transaction(function () {
                DB::transaction(fn () => event(new UserSuspended(1, 'suspended')));
                event(new UserSuspended(2, 'suspended'));

                throw new RuntimeException('outer fails');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->totalPublished());
    }

    public function test_a_rolled_back_inner_transaction_drops_only_its_own_events(): void
    {
        DB::transaction(function () {
            try {
                DB::transaction(function () {
                    event(new UserSuspended(1, 'suspended'));

                    throw new RuntimeException('inner fails');
                });
            } catch (RuntimeException) {
            }
            event(new UserSuspended(2, 'suspended'));
        });

        $published = array_map(fn ($e) => $e['envelope']->payload['user_id'], $this->entries(RealtimeEventType::UserSuspended));
        $this->assertSame([2], $published);
    }

    public function test_events_of_one_transaction_are_published_in_the_order_they_were_raised(): void
    {
        DB::transaction(function () {
            foreach ([10, 11, 12] as $id) {
                event(new UserSuspended($id, 'suspended'));
            }
        });

        $this->assertSame([10, 11, 12], array_map(fn ($e) => $e['envelope']->payload['user_id'], $this->entries(RealtimeEventType::UserSuspended)));
    }

    public function test_the_occurred_at_time_is_when_the_change_happened_not_when_it_was_committed(): void
    {
        $this->travelTo(now()->startOfSecond());
        $raised = now();

        DB::transaction(function () {
            event(new UserSuspended(42, 'suspended'));
            $this->travel(30)->seconds();
        });

        $entry = $this->entries(RealtimeEventType::UserSuspended)[0];
        $this->assertSame($raised->utc()->format('Y-m-d\TH:i:s.v\Z'), $entry['fields']['occurred_at']);
    }
}
