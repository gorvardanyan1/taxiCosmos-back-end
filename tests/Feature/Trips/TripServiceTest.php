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
}
