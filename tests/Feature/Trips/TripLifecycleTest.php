<?php

namespace Tests\Feature\Trips;

use App\Enums\TripCancelledBy;
use App\Enums\TripStatus;
use App\Exceptions\TripStateException;
use App\Models\DriverProfile;
use App\Models\Trip;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function storedStatus(Trip $trip): string
    {
        return Trip::findOrFail($trip->id)->status->value;
    }

    public function test_completed_can_not_go_back_to_in_progress(): void
    {
        $trip = Trip::factory()->completed()->create();

        try {
            $trip->transitionTo(TripStatus::InProgress);
            $this->fail('The transition was accepted.');
        } catch (TripStateException $e) {
            $this->assertStringContainsString('completed to in_progress', $e->getMessage());
        }

        $this->assertSame('completed', $this->storedStatus($trip));
    }

    public function test_a_plain_save_can_not_skip_the_state_machine_either(): void
    {
        $trip = Trip::factory()->completed()->create();

        $trip->status = TripStatus::InProgress;

        $this->expectException(TripStateException::class);
        try {
            $trip->save();
        } finally {
            $this->assertSame('completed', $this->storedStatus($trip));
        }
    }

    public function test_a_requested_trip_can_not_jump_straight_to_in_progress_or_completed(): void
    {
        foreach ([TripStatus::Arrived, TripStatus::InProgress, TripStatus::Completed] as $target) {
            $trip = Trip::factory()->create();

            try {
                $trip->transitionTo($target);
                $this->fail("requested → {$target->value} was accepted.");
            } catch (TripStateException) {
                $this->assertSame('requested', $this->storedStatus($trip));
            }
        }
    }

    public function test_the_happy_path_records_each_timestamp_and_the_final_money(): void
    {
        $this->travelTo('2026-10-11 10:00:00');
        $trip = Trip::factory()->create();
        $driver = DriverProfile::factory()->approved()->create();

        $trip->transitionTo(TripStatus::Matched, attributes: ['driver_id' => $driver->id]);
        $this->travelTo('2026-10-11 10:05:00');
        $trip->transitionTo(TripStatus::Arrived);
        $this->travelTo('2026-10-11 10:07:00');
        $trip->transitionTo(TripStatus::InProgress);
        $this->travelTo('2026-10-11 10:20:00');
        $trip->transitionTo(TripStatus::Completed, attributes: [
            'fare_amount' => 150000, 'commission_amount' => 30000, 'driver_earnings_amount' => 120000,
            'distance_meters' => 4200, 'duration_seconds' => 780,
        ]);

        $fresh = Trip::findOrFail($trip->id);
        $this->assertSame(TripStatus::Completed, $fresh->status);
        $this->assertSame($driver->id, $fresh->driver_id);
        $this->assertSame('2026-10-11 10:00:00', $fresh->matched_at->toDateTimeString());
        $this->assertSame('2026-10-11 10:05:00', $fresh->arrived_at->toDateTimeString());
        $this->assertSame('2026-10-11 10:07:00', $fresh->started_at->toDateTimeString());
        $this->assertSame('2026-10-11 10:20:00', $fresh->completed_at->toDateTimeString());
        $this->assertSame(150000, $fresh->fare_amount);
        $this->assertNull($fresh->cancelled_at);
    }

    public function test_cancelling_records_who_when_and_why(): void
    {
        $this->travelTo('2026-10-11 12:00:00');
        $trip = Trip::factory()->create();

        $trip->transitionTo(TripStatus::Cancelled, TripCancelledBy::Rider, 'Found another ride');

        $fresh = Trip::findOrFail($trip->id);
        $this->assertSame(TripStatus::Cancelled, $fresh->status);
        $this->assertSame(TripCancelledBy::Rider, $fresh->cancelled_by);
        $this->assertSame('Found another ride', $fresh->cancel_reason);
        $this->assertSame('2026-10-11 12:00:00', $fresh->cancelled_at->toDateTimeString());
    }

    public function test_a_cancellation_without_an_actor_is_refused(): void
    {
        $trip = Trip::factory()->create();

        $this->expectException(TripStateException::class);
        try {
            $trip->transitionTo(TripStatus::Cancelled);
        } finally {
            $this->assertSame('requested', $this->storedStatus($trip));
        }
    }

    public function test_a_driver_can_not_cancel_before_matching_but_can_afterwards(): void
    {
        $requested = Trip::factory()->create();
        try {
            $requested->transitionTo(TripStatus::Cancelled, TripCancelledBy::Driver);
            $this->fail('A driver cancelled a trip that was never theirs.');
        } catch (TripStateException) {
            $this->assertSame('requested', $this->storedStatus($requested));
        }

        $matched = Trip::factory()->matched()->create();
        $matched->transitionTo(TripStatus::Cancelled, TripCancelledBy::Driver, 'Flat tyre');
        $this->assertSame('cancelled', $this->storedStatus($matched));
    }

    public function test_only_an_admin_can_cancel_a_ride_in_progress(): void
    {
        foreach ([TripCancelledBy::Rider, TripCancelledBy::Driver, TripCancelledBy::System] as $by) {
            $trip = Trip::factory()->inProgress()->create();

            try {
                $trip->transitionTo(TripStatus::Cancelled, $by);
                $this->fail("{$by->value} force-cancelled a ride in progress.");
            } catch (TripStateException) {
                $this->assertSame('in_progress', $this->storedStatus($trip));
            }
        }

        $trip = Trip::factory()->inProgress()->create();
        $trip->transitionTo(TripStatus::Cancelled, TripCancelledBy::Admin, 'Safety incident');
        $this->assertSame('cancelled', $this->storedStatus($trip));
    }

    public function test_back_to_dispatch_clears_the_driver_vehicle_and_arrival(): void
    {
        $trip = Trip::factory()->arrived()->create();
        $this->assertNotNull($trip->driver_id);

        $trip->transitionTo(TripStatus::Requested);

        $fresh = Trip::findOrFail($trip->id);
        $this->assertSame(TripStatus::Requested, $fresh->status);
        $this->assertNull($fresh->driver_id);
        $this->assertNull($fresh->vehicle_id);
        $this->assertNull($fresh->matched_at);
        $this->assertNull($fresh->arrived_at);
        $this->assertNotNull($fresh->requested_at);
    }

    public function test_no_driver_found_is_final(): void
    {
        $trip = Trip::factory()->create();
        $trip->transitionTo(TripStatus::NoDriverFound);

        foreach (TripStatus::cases() as $target) {
            try {
                $trip->transitionTo($target, TripCancelledBy::System);
                $this->fail("no_driver_found → {$target->value} was accepted.");
            } catch (TripStateException) {
                $this->assertSame('no_driver_found', $this->storedStatus($trip));
            }
        }
    }

    public function test_matched_without_a_driver_is_refused_and_leaves_the_trip_requested(): void
    {
        $trip = Trip::factory()->create();

        try {
            $trip->transitionTo(TripStatus::Matched);
            $this->fail('Matched with no driver.');
        } catch (QueryException) {
            $this->assertSame('requested', $this->storedStatus($trip));
        }
    }

    public function test_the_second_of_two_racing_accepts_loses_instead_of_overwriting(): void
    {
        $trip = Trip::factory()->create();
        $winner = DriverProfile::factory()->approved()->create();
        $loser = DriverProfile::factory()->approved()->create();

        // Two requests loaded the trip while it was still "requested".
        $first = Trip::findOrFail($trip->id);
        $second = Trip::findOrFail($trip->id);

        $first->transitionTo(TripStatus::Matched, attributes: ['driver_id' => $winner->id]);

        try {
            $second->transitionTo(TripStatus::Matched, attributes: ['driver_id' => $loser->id]);
            $this->fail('Both drivers were matched to the same trip.');
        } catch (TripStateException $e) {
            $this->assertStringContainsString('matched to matched', $e->getMessage());
        }

        $this->assertSame($winner->id, Trip::findOrFail($trip->id)->driver_id);
    }

    public function test_a_cancel_against_a_trip_that_just_started_is_refused(): void
    {
        $trip = Trip::factory()->arrived()->create();
        $stale = Trip::findOrFail($trip->id);

        $trip->transitionTo(TripStatus::InProgress);

        $this->expectException(TripStateException::class);
        try {
            $stale->transitionTo(TripStatus::Cancelled, TripCancelledBy::Rider);
        } finally {
            $this->assertSame('in_progress', $this->storedStatus($trip));
        }
    }
}
