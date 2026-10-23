<?php

namespace Tests\Feature\Trips;

use App\Enums\DriverAvailability;
use App\Enums\TripStatus;
use App\Exceptions\DriverStatusException;
use App\Exceptions\TripStateException;
use App\Models\Trip;
use App\Models\User;
use App\Services\Trips\TripService;

/**
 * TripService::assignDriver is the seam dispatch (P7-T7) calls when a driver accepts: what it must guarantee.
 */
class TripServiceTest extends TripApiTestCase
{
    public function test_assigning_matches_the_trip_with_the_driver_and_their_primary_vehicle_and_puts_them_on_a_trip(): void
    {
        [, $profile] = $this->driver(DriverAvailability::Online);
        $trip = $this->requested();

        $matched = app(TripService::class)->assignDriver($trip, $profile);

        $this->assertSame(TripStatus::Matched, $matched->status);
        $this->assertSame([$profile->id, $profile->primaryVehicle->id], [$matched->driver_id, $matched->vehicle_id]);
        $this->assertNotNull($matched->matched_at);
        $this->assertSame(DriverAvailability::OnTrip, $profile->refresh()->availability);
    }

    public function test_an_offline_driver_cannot_take_a_trip_and_nothing_changes(): void
    {
        [, $profile] = $this->driver(DriverAvailability::Offline);
        $trip = $this->requested();

        try {
            app(TripService::class)->assignDriver($trip, $profile);
            $this->fail('An offline driver was assigned.');
        } catch (DriverStatusException) {
        }

        $this->assertSame(TripStatus::Requested, $trip->refresh()->status);
        $this->assertNull($trip->driver_id);
        $this->assertSame(DriverAvailability::Offline, $profile->refresh()->availability);
    }

    public function test_a_driver_already_on_a_trip_cannot_take_a_second_one(): void
    {
        [, $profile] = $this->driver(DriverAvailability::Online);
        $service = app(TripService::class);
        $service->assignDriver($this->requested(), $profile);
        $otherRider = User::factory()->rider()->create();
        $second = Trip::factory()->create(['rider_id' => $otherRider->id, 'zone_id' => $this->zone->id]);

        $this->expectException(DriverStatusException::class);
        $service->assignDriver($second, $profile);
    }

    public function test_a_trip_that_is_no_longer_waiting_cannot_be_taken(): void
    {
        [, $profile] = $this->driver(DriverAvailability::Online);
        $trip = $this->requested();
        $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken)->assertOk();

        try {
            app(TripService::class)->assignDriver($trip, $profile);
            $this->fail('A cancelled trip was assigned.');
        } catch (TripStateException) {
        }

        $this->assertSame(TripStatus::Cancelled, $trip->refresh()->status);
        $this->assertSame(DriverAvailability::Online, $profile->refresh()->availability, 'the driver was not put on a trip that is gone');
    }

    public function test_two_drivers_taking_the_same_trip_one_wins_and_the_other_stays_free(): void
    {
        [, $first] = $this->driver(DriverAvailability::Online);
        [, $second] = $this->driver(DriverAvailability::Online);
        $trip = $this->requested();
        $service = app(TripService::class);

        $service->assignDriver($trip, $first);
        try {
            $service->assignDriver(Trip::query()->findOrFail($trip->id), $second);
            $this->fail('The second driver also got the trip.');
        } catch (TripStateException) {
        }

        $this->assertSame($first->id, $trip->refresh()->driver_id);
        $this->assertSame(DriverAvailability::Online, $second->refresh()->availability);
    }

    // ---- row locks: what keeps two racing calls from both winning ----

    /**
     * @param  list<array{sql: string, depth: int}>  $statements
     */
    private function firstIndex(array $statements, string $needle, bool $locking = false): ?int
    {
        foreach ($statements as $i => $statement) {
            if (str_contains($statement['sql'], $needle) && (! $locking || str_contains($statement['sql'], 'for update'))) {
                return $i;
            }
        }

        return null;
    }

    public function test_every_step_locks_the_trip_row_inside_its_transaction_before_it_reads_anything_else(): void
    {
        [$trip, , $driverToken] = $this->matched();
        $steps = [
            'arrived' => fn () => $this->act("/api/v1/driver/trips/{$trip->id}/arrived", $driverToken)->assertOk(),
            'start' => fn () => $this->act("/api/v1/driver/trips/{$trip->id}/start", $driverToken)->assertOk(),
            'complete' => fn () => $this->act("/api/v1/driver/trips/{$trip->id}/complete", $driverToken)->assertOk(),
        ];

        foreach ($steps as $name => $step) {
            $statements = $this->sqlDuring($step);

            $lock = $this->firstIndex($statements, 'from "trips"', locking: true);
            $this->assertNotNull($lock, "{$name} locks the trip row");
            $this->assertGreaterThan(0, $statements[$lock]['depth'], "{$name} takes the lock inside a transaction");
            // The trip is read under the lock, so a competing call cannot change it between the read and the write: nothing the step decides
            // from (the fare rule, the commission rule, the pings) is read first.
            foreach (['from "fare_rules"', 'from "commission_rules"', 'from "driver_profiles" where "driver_profiles"."id"'] as $later) {
                $read = $this->firstIndex($statements, $later);
                $this->assertTrue($read === null || $read > $lock, "{$name} reads {$later} only after the lock");
            }
        }
    }

    public function test_a_rider_cancelling_locks_the_trip_row_inside_its_transaction(): void
    {
        $trip = $this->requested();

        $statements = $this->sqlDuring(fn () => $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken)->assertOk());

        $lock = $this->firstIndex($statements, 'from "trips"', locking: true);
        $this->assertNotNull($lock);
        $this->assertGreaterThan(0, $statements[$lock]['depth']);
    }

    public function test_assigning_locks_the_driver_row_inside_the_transaction_so_one_driver_cannot_take_two_trips_at_once(): void
    {
        [, $profile] = $this->driver(DriverAvailability::Online);
        $trip = $this->requested();

        $locks = $this->profileLocksDuring(fn () => app(TripService::class)->assignDriver($trip, $profile));

        $this->assertNotEmpty($locks, 'the driver profile is locked before the online check');
        $this->assertGreaterThan(0, min(array_column($locks, 'depth')));
    }

    public function test_freeing_a_driver_locks_their_row_inside_the_transaction(): void
    {
        [$trip, , $driverToken] = $this->matched();

        $locks = $this->profileLocksDuring(fn () => $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken)->assertOk());

        $this->assertNotEmpty($locks, 'cancelling a matched trip locks the driver before freeing them');
        $this->assertGreaterThan(0, min(array_column($locks, 'depth')));
        $this->assertNotEmpty($driverToken);
    }
}
