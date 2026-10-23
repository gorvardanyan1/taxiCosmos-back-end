<?php

namespace Tests\Feature\Trips;

use App\Enums\DriverAvailability;
use App\Enums\TripCancelledBy;
use App\Enums\TripStatus;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use App\Realtime\RealtimeEventType;
use Carbon\CarbonImmutable;

/**
 * The rider's side of a trip (P7-T6): the current trip, one trip, the history, the receipt and cancelling.
 */
class RiderTripApiTest extends TripApiTestCase
{
    private function other(): array
    {
        $rider = User::factory()->rider()->create();

        return [$rider, $rider->createToken('device', ['rider'])->plainTextToken];
    }

    // ---- current and one trip ----

    public function test_there_is_no_current_trip_when_none_is_active(): void
    {
        $this->fetch('/api/v1/trips/current', $this->riderToken)->assertNoContent();
        Trip::factory()->completed()->create(['rider_id' => $this->rider->id, 'zone_id' => $this->zone->id]);

        $this->fetch('/api/v1/trips/current', $this->riderToken)->assertNoContent();
    }

    public function test_the_current_trip_is_the_one_in_progress(): void
    {
        $trip = $this->requested();

        $this->fetch('/api/v1/trips/current', $this->riderToken)->assertOk()->assertJsonPath('data.id', $trip->id)->assertJsonPath('data.status', 'requested');
    }

    public function test_once_matched_the_trip_shows_the_driver_the_vehicle_and_no_phone_number(): void
    {
        [$trip, $profile] = $this->matched();
        $profile->forceFill(['rating_avg' => '4.85', 'rating_count' => 12])->save();
        $profile->user->forceFill(['phone' => '+37491000111'])->save();
        $vehicle = $profile->primaryVehicle;

        $response = $this->fetch("/api/v1/trips/{$trip->id}", $this->riderToken)->assertOk();

        $response->assertJsonPath('data.status', 'matched')
            ->assertJsonPath('data.driver', ['name' => $profile->user->name, 'rating_avg' => 4.85, 'rating_count' => 12])
            ->assertJsonPath('data.vehicle', ['make' => $vehicle->make, 'model' => $vehicle->model, 'color' => $vehicle->color, 'plate_number' => $vehicle->plate_number, 'vehicle_class' => 'economy'])
            ->assertJsonPath('data.matched_at', $trip->refresh()->matched_at->toIso8601String());
        $this->assertStringNotContainsString('91000111', $response->getContent());
    }

    public function test_the_eta_is_from_the_drivers_last_position_while_they_are_on_the_way(): void
    {
        [$trip, $profile] = $this->matched();
        // 0.01 degrees of latitude south of the pickup: 1446 m by the route estimate, 174 s at 30 km/h.
        $this->redis->raw(['GEOADD', config('realtime.geo.locations_key'), '44.51', '40.17', (string) $profile->user_id]);

        $this->fetch("/api/v1/trips/{$trip->id}", $this->riderToken)->assertOk()->assertJsonPath('data.eta_to_pickup_seconds', 174);
    }

    public function test_there_is_no_eta_without_a_position_or_once_the_driver_has_arrived(): void
    {
        [$trip, $profile, $driverToken] = $this->matched();

        $this->fetch("/api/v1/trips/{$trip->id}", $this->riderToken)->assertJsonPath('data.eta_to_pickup_seconds', null);

        $this->redis->raw(['GEOADD', config('realtime.geo.locations_key'), '44.51', '40.17', (string) $profile->user_id]);
        $this->act("/api/v1/driver/trips/{$trip->id}/arrived", $driverToken)->assertOk();
        $this->fetch("/api/v1/trips/{$trip->id}", $this->riderToken)->assertJsonPath('data.status', 'arrived')->assertJsonPath('data.eta_to_pickup_seconds', null);
    }

    public function test_a_rider_cannot_see_another_riders_trip(): void
    {
        $trip = $this->requested();
        [, $otherToken] = $this->other();

        foreach (["/api/v1/trips/{$trip->id}", "/api/v1/trips/{$trip->id}/receipt"] as $path) {
            $this->fetch($path, $otherToken)->assertNotFound();
        }
        $this->act("/api/v1/trips/{$trip->id}/cancel", $otherToken)->assertNotFound();
        $this->fetch('/api/v1/trips/current', $otherToken)->assertNoContent();
        $this->fetch('/api/v1/trips', $otherToken)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(TripStatus::Requested, $trip->refresh()->status);
    }

    public function test_a_missing_trip_is_not_found_and_a_guest_is_refused(): void
    {
        $this->fetch('/api/v1/trips/999999', $this->riderToken)->assertNotFound();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/trips/current')->assertUnauthorized();
        $this->getJson('/api/v1/trips')->assertUnauthorized();
    }

    public function test_a_driver_token_cannot_read_rider_trips(): void
    {
        [, , $driverToken] = $this->driver();

        $this->fetch('/api/v1/trips/current', $driverToken)->assertForbidden();
        $this->fetch('/api/v1/trips', $driverToken)->assertForbidden();
    }

    public function test_the_trip_calls_are_rate_limited_per_person(): void
    {
        config(['security.rate_limits.trips' => 2]);
        $other = User::factory()->rider()->create()->createToken('device', ['rider'])->plainTextToken;

        $this->fetch('/api/v1/trips/current', $this->riderToken)->assertNoContent();
        $this->fetch('/api/v1/trips', $this->riderToken)->assertOk();
        $this->fetch('/api/v1/trips/current', $this->riderToken)->assertStatus(429)->assertHeader('Retry-After');
        $this->fetch('/api/v1/trips/current', $other)->assertNoContent();
    }

    // ---- history ----

    public function test_history_lists_finished_trips_newest_first_and_leaves_out_the_active_one(): void
    {
        $old = Trip::factory()->completed()->create(['rider_id' => $this->rider->id, 'zone_id' => $this->zone->id, 'requested_at' => now()->subDays(3)]);
        $cancelled = Trip::factory()->cancelled()->create(['rider_id' => $this->rider->id, 'zone_id' => $this->zone->id, 'requested_at' => now()->subDay()]);
        $noDriver = Trip::factory()->create(['rider_id' => $this->rider->id, 'zone_id' => $this->zone->id, 'status' => TripStatus::NoDriverFound, 'requested_at' => now()->subHours(2)]);
        $this->requested();

        $response = $this->fetch('/api/v1/trips', $this->riderToken)->assertOk();

        $this->assertSame([$noDriver->id, $cancelled->id, $old->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 3]);
    }

    public function test_history_is_paginated(): void
    {
        Trip::factory()->count(21)->completed()->create(['rider_id' => $this->rider->id, 'zone_id' => $this->zone->id]);

        $this->fetch('/api/v1/trips', $this->riderToken)->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2);
        $this->fetch('/api/v1/trips?page=2', $this->riderToken)->assertJsonCount(1, 'data');
    }

    // ---- receipt ----

    public function test_the_receipt_of_a_completed_trip_breaks_the_fare_down_and_adds_up(): void
    {
        $this->travelTo(CarbonImmutable::now()->startOfMinute());
        [$trip, , $driverToken] = $this->matched();
        $this->act("/api/v1/driver/trips/{$trip->id}/arrived", $driverToken)->assertOk();
        $this->act("/api/v1/driver/trips/{$trip->id}/start", $driverToken)->assertOk();
        $this->travel(10)->minutes();
        $this->act("/api/v1/driver/trips/{$trip->id}/complete", $driverToken)->assertOk();

        $response = $this->fetch("/api/v1/trips/{$trip->id}/receipt", $this->riderToken)->assertOk();

        $receipt = $response->json('data');
        $this->assertSame(['trip_id' => $trip->id, 'code' => $trip->public_code, 'currency' => 'AMD', 'total' => 917, 'payment' => ['method' => 'cash', 'status' => 'paid']], array_intersect_key($receipt, array_flip(['trip_id', 'code', 'currency', 'total', 'payment'])));
        $lines = $receipt['breakdown'];
        $this->assertSame(500, $lines['base']);
        $this->assertSame(217, $lines['distance']);
        $this->assertSame(200, $lines['time']);
        $this->assertSame($receipt['total'], $lines['base'] + $lines['distance'] + $lines['time'] + $lines['waiting'] + $lines['minimum_adjustment'] + $lines['booking_fee'] + array_sum(array_column($lines['fees'], 'amount')) + $lines['surge'] + $lines['tax'], 'the lines add up to the total');
        $this->assertSame([1446, 600], [$receipt['distance_meters'], $receipt['duration_seconds']]);
        $this->assertArrayNotHasKey('commission', $receipt);
        $this->assertArrayNotHasKey('driver_earnings', $receipt);
    }

    public function test_there_is_no_receipt_before_the_trip_is_completed(): void
    {
        $trip = $this->requested();

        $this->fetch("/api/v1/trips/{$trip->id}/receipt", $this->riderToken)->assertStatus(409)->assertJsonPath('code', 'trip_not_completed');
        $cancelled = Trip::factory()->cancelled()->create(['rider_id' => $this->rider->id, 'zone_id' => $this->zone->id]);
        $this->fetch("/api/v1/trips/{$cancelled->id}/receipt", $this->riderToken)->assertStatus(409);
    }

    // ---- cancel ----

    public function test_a_rider_cancels_a_trip_that_is_waiting_for_a_driver(): void
    {
        $trip = $this->requested();

        $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken, ['reason' => 'Plans changed'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancelled_by', 'rider')->assertJsonPath('data.cancel_reason', 'Plans changed');

        $trip->refresh();
        $this->assertSame([TripStatus::Cancelled, TripCancelledBy::Rider, 'Plans changed'], [$trip->status, $trip->cancelled_by, $trip->cancel_reason]);
        $this->assertNotNull($trip->cancelled_at);
        $this->assertSame(['requested', 'cancelled'], array_slice(array_column(array_map(fn ($e) => $e['envelope']->payload, $this->entries(RealtimeEventType::TripStatusChanged)), 'to_status'), 0, 2));
        // The rider is free to book again.
        $this->requestTrip()->assertCreated();
    }

    public function test_the_reason_is_optional_and_limited(): void
    {
        $trip = $this->requested();

        $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken, ['reason' => str_repeat('x', 501)])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(TripStatus::Requested, $trip->refresh()->status);

        $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken)->assertOk()->assertJsonPath('data.cancel_reason', null);
    }

    public function test_cancelling_after_a_driver_accepted_frees_the_driver(): void
    {
        [$trip, $profile] = $this->matched();
        $this->assertSame(DriverAvailability::OnTrip, $profile->refresh()->availability);

        $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken)->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(DriverAvailability::Online, $profile->refresh()->availability);
        $driverEvents = array_filter(array_map(fn ($e) => $e['envelope']->payload, $this->entries(RealtimeEventType::TripStatusChanged)), fn ($p) => $p['to_status'] === 'cancelled');
        $this->assertSame([$profile->user_id], array_values(array_column($driverEvents, 'driver_user_id')), 'the driver is told too');
    }

    public function test_a_driver_who_went_offline_stays_offline_when_the_rider_cancels(): void
    {
        [$trip, $profile] = $this->matched();
        $profile->forceFill(['availability' => DriverAvailability::Offline])->save();

        $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken)->assertOk();

        $this->assertSame(DriverAvailability::Offline, $profile->refresh()->availability);
    }

    public function test_a_ride_in_progress_or_a_finished_trip_cannot_be_cancelled_by_the_rider(): void
    {
        [$trip, , $driverToken] = $this->matched();
        $this->act("/api/v1/driver/trips/{$trip->id}/arrived", $driverToken)->assertOk();
        $this->act("/api/v1/driver/trips/{$trip->id}/start", $driverToken)->assertOk();

        $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken, ['reason' => 'Too slow'])
            ->assertStatus(409)->assertJsonPath('code', 'trip_state_conflict')->assertJsonPath('trip.id', $trip->id)->assertJsonPath('trip.status', 'in_progress');
        $this->assertSame(TripStatus::InProgress, $trip->refresh()->status);

        $this->act("/api/v1/driver/trips/{$trip->id}/complete", $driverToken)->assertOk();
        $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken)->assertStatus(409)->assertJsonPath('trip.status', 'completed');
    }

    public function test_cancelling_twice_with_the_same_key_gives_the_first_answer_and_a_new_key_a_409(): void
    {
        $trip = $this->requested();
        $key = $this->key();
        $first = $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken, [], $key)->assertOk();

        $replay = $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken, [], $key);
        $again = $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken);

        $replay->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json(), $replay->json());
        $again->assertStatus(409)->assertJsonPath('trip.status', 'cancelled');
        $this->assertCount(2, $this->entries(RealtimeEventType::TripStatusChanged), 'one request, one cancellation');
    }

    public function test_cancel_needs_an_idempotency_key(): void
    {
        $trip = $this->requested();
        app('auth')->forgetGuards();

        $this->postJson("/api/v1/trips/{$trip->id}/cancel", [], $this->bearer($this->riderToken))->assertStatus(422)->assertJsonPath('code', 'idempotency_key_required');
        $this->assertSame(TripStatus::Requested, $trip->refresh()->status);
    }

    public function test_another_driver_profile_is_never_touched(): void
    {
        [$trip] = $this->matched();
        $bystander = DriverProfile::factory()->approved()->online()->create();

        $this->act("/api/v1/trips/{$trip->id}/cancel", $this->riderToken)->assertOk();

        $this->assertSame(DriverAvailability::Online, $bystander->refresh()->availability);
    }
}
