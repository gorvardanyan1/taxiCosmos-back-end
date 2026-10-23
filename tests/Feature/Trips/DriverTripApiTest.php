<?php

namespace Tests\Feature\Trips;

use App\Data\GeoPoint;
use App\Enums\DriverAvailability;
use App\Enums\TripPaymentStatus;
use App\Enums\TripStatus;
use App\Events\TripRequested;
use App\Models\DriverLocationPing;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\TripDriverCancellation;
use App\Models\User;
use App\Realtime\RealtimeEventType;
use App\Services\Fares\RouteEstimator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * The driver's side of a trip (P7-T6): arrived, start, complete, cancel, the current trip and the history.
 */
class DriverTripApiTest extends TripApiTestCase
{
    private function step(Trip $trip, string $step, string $token, array $body = [], ?string $key = null): TestResponse
    {
        return $this->act("/api/v1/driver/trips/{$trip->id}/{$step}", $token, $body, $key);
    }

    /** Pings along a meridian from the pickup, `$legs` legs of 0.009 degrees (about 1000.76 m) a minute apart from `$from`. */
    private function drive(Trip $trip, int $legs, CarbonInterface $from): float
    {
        $from = CarbonImmutable::instance($from);
        $meters = 0.0;
        $routes = app(RouteEstimator::class);
        $previous = null;

        for ($i = 0; $i <= $legs; $i++) {
            $point = new GeoPoint(40.18 + 0.009 * $i, 44.51);
            DriverLocationPing::query()->create([
                'driver_user_id' => $trip->driver->user_id, 'driver_profile_id' => $trip->driver_id,
                'location' => ['type' => 'Point', 'coordinates' => [$point->longitude, $point->latitude]], 'recorded_at' => $from->addMinutes($i),
            ]);
            $meters += $previous === null ? 0 : $routes->straightLineMeters($previous, $point);
            $previous = $point;
        }

        return $meters;
    }

    private function inProgress(): array
    {
        [$trip, $profile, $token] = $this->matched();
        $this->step($trip, 'arrived', $token)->assertOk();
        $this->step($trip, 'start', $token)->assertOk();

        return [$trip->refresh(), $profile, $token];
    }

    /**
     * @return list<array{from: ?string, to: string}>
     */
    private function statusEvents(): array
    {
        return array_map(fn ($e) => ['from' => $e['envelope']->payload['from_status'], 'to' => $e['envelope']->payload['to_status']], $this->entries(RealtimeEventType::TripStatusChanged));
    }

    // ---- current trip ----

    public function test_there_is_no_current_trip_until_one_is_assigned(): void
    {
        [, , $token] = $this->driver();
        $this->requested();

        $this->fetch('/api/v1/driver/trips/current', $token)->assertNoContent();
    }

    public function test_the_current_trip_shows_where_to_go_and_only_the_riders_first_name(): void
    {
        [$trip, , $token] = $this->matched();

        $response = $this->fetch('/api/v1/driver/trips/current', $token)->assertOk();

        $response->assertJsonPath('data.id', $trip->id)->assertJsonPath('data.status', 'matched')->assertJsonPath('data.rider', ['first_name' => 'Anna'])
            ->assertJsonPath('data.pickup.address', 'Republic Square, Yerevan')->assertJsonPath('data.fare', ['currency' => 'AMD', 'estimated' => 800, 'final' => null])
            ->assertJsonPath('data.payment', ['method' => 'cash', 'status' => 'unpaid'])->assertJsonPath('data.earnings', null);
        $this->assertStringNotContainsString($this->rider->phone, $response->getContent());
        $this->assertStringNotContainsString('Rider', $response->getContent(), 'not the riders surname');
    }

    public function test_another_drivers_current_trip_is_not_mine(): void
    {
        $this->matched();
        [, , $other] = $this->driver();

        $this->fetch('/api/v1/driver/trips/current', $other)->assertNoContent();
    }

    // ---- who may ----

    public function test_a_driver_can_only_act_on_trips_assigned_to_them(): void
    {
        [$trip] = $this->matched();
        [, , $other] = $this->driver();
        $unassigned = Trip::factory()->create(['rider_id' => User::factory()->rider(), 'zone_id' => $this->zone->id]);

        foreach (['arrived', 'start', 'complete', 'cancel'] as $step) {
            $this->step($trip, $step, $other, ['reason' => 'Flat tyre'])->assertNotFound();
            $this->step($unassigned, $step, $other, ['reason' => 'Flat tyre'])->assertNotFound();
        }

        $this->assertSame(TripStatus::Matched, $trip->refresh()->status);
    }

    public function test_riders_and_guests_cannot_use_the_driver_endpoints(): void
    {
        [$trip] = $this->matched();

        $this->step($trip, 'arrived', $this->riderToken)->assertForbidden();
        $this->fetch('/api/v1/driver/trips', $this->riderToken)->assertForbidden();
        app('auth')->forgetGuards();
        $this->postJson("/api/v1/driver/trips/{$trip->id}/arrived", [], ['Idempotency-Key' => $this->key()])->assertUnauthorized();
        $this->assertSame(TripStatus::Matched, $trip->refresh()->status);
    }

    public function test_a_driver_account_without_a_profile_is_refused(): void
    {
        $user = User::factory()->driver()->create();
        $token = $user->createToken('device', ['driver'])->plainTextToken;

        $this->fetch('/api/v1/driver/trips/current', $token)->assertForbidden();
    }

    // ---- arrived and start ----

    public function test_arrived_then_start_follow_the_trip_and_announce_each_change(): void
    {
        $t0 = CarbonImmutable::now()->startOfMinute();
        $this->travelTo($t0);
        [$trip, , $token] = $this->matched();

        $this->travelTo($t0->addMinutes(5));
        $this->step($trip, 'arrived', $token)->assertOk()->assertJsonPath('data.status', 'arrived')->assertJsonPath('data.arrived_at', $t0->addMinutes(5)->toIso8601String());
        $this->travelTo($t0->addMinutes(7));
        $this->step($trip, 'start', $token)->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.started_at', $t0->addMinutes(7)->toIso8601String());

        $this->assertSame(TripStatus::InProgress, $trip->refresh()->status);
        $this->assertSame([['from' => null, 'to' => 'requested'], ['from' => 'requested', 'to' => 'matched'], ['from' => 'matched', 'to' => 'arrived'], ['from' => 'arrived', 'to' => 'in_progress']], $this->statusEvents());
    }

    public function test_a_step_the_state_does_not_allow_is_a_409_that_carries_the_trip_as_it_is(): void
    {
        [$trip, , $token] = $this->matched();

        // Start before arriving.
        $this->step($trip, 'start', $token)->assertStatus(409)->assertJsonPath('code', 'trip_state_conflict')
            ->assertJsonPath('trip.id', $trip->id)->assertJsonPath('trip.status', 'matched')->assertJsonPath('message', 'A trip cannot move from matched to in_progress.');
        $this->step($trip, 'complete', $token)->assertStatus(409)->assertJsonPath('trip.status', 'matched');
        $this->step($trip, 'arrived', $token)->assertOk();
        // The same step again, with a new key: the trip is already there.
        $this->step($trip, 'arrived', $token)->assertStatus(409)->assertJsonPath('trip.status', 'arrived');
        $this->assertSame(TripStatus::Arrived, $trip->refresh()->status);
        $this->assertCount(3, $this->statusEvents());
    }

    public function test_a_step_repeated_with_the_same_key_gives_the_first_answer_and_changes_nothing(): void
    {
        [$trip, , $token] = $this->matched();
        $key = $this->key();

        $first = $this->step($trip, 'arrived', $token, key: $key);
        $second = $this->step($trip, 'arrived', $token, key: $key);

        $first->assertOk();
        $second->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json(), $second->json());
        $this->assertCount(3, $this->statusEvents());
    }

    public function test_steps_need_an_idempotency_key(): void
    {
        [$trip, , $token] = $this->matched();
        app('auth')->forgetGuards();

        $this->postJson("/api/v1/driver/trips/{$trip->id}/arrived", [], $this->bearer($token))->assertStatus(422)->assertJsonPath('code', 'idempotency_key_required');
        $this->assertSame(TripStatus::Matched, $trip->refresh()->status);
    }

    // ---- complete ----

    public function test_completing_prices_the_ride_from_the_driven_distance_and_time_and_splits_the_commission(): void
    {
        $this->travelTo(CarbonImmutable::now()->startOfMinute());
        [$trip, $profile, $token] = $this->matched();
        $this->travel(4)->minutes();
        $this->step($trip, 'arrived', $token)->assertOk();
        $this->travel(5)->minutes(); // waited 5 minutes, 3 of them free
        $this->step($trip, 'start', $token)->assertOk();
        $startedAt = CarbonImmutable::now();
        $driven = $this->drive($trip->refresh(), 3, $startedAt->addSeconds(10));
        $this->travel(10)->minutes();

        $response = $this->step($trip, 'complete', $token)->assertOk();

        $meters = (int) round($driven);
        // 500 base + 150 per km + 20 per minute + 30 per billable waiting minute (2); 20 % commission of the total, the rest to the driver.
        $total = 500 + (int) round(150 * $meters / 1000) + 200 + 60;
        $commission = (int) round($total * 0.2);
        $response->assertJsonPath('data.status', 'completed')->assertJsonPath('data.distance_meters', $meters)->assertJsonPath('data.duration_seconds', 600)
            ->assertJsonPath('data.fare', ['currency' => 'AMD', 'estimated' => 800, 'final' => $total])
            ->assertJsonPath('data.earnings', ['currency' => 'AMD', 'amount' => $total - $commission, 'commission' => $commission])
            ->assertJsonPath('data.payment', ['method' => 'cash', 'status' => 'paid']);

        $trip->refresh();
        $this->assertSame(TripStatus::Completed, $trip->status);
        $this->assertSame([$meters, 600, $total, $commission, $total - $commission], [$trip->distance_meters, $trip->duration_seconds, $trip->fare_amount, $trip->commission_amount, $trip->driver_earnings_amount]);
        $this->assertSame(TripPaymentStatus::Paid, $trip->payment_status);
        $this->assertNotNull($trip->commission_rule_id);
        $this->assertEquals(['base' => 500, 'waiting' => 60, 'time' => 200, 'total' => $total], array_intersect_key($trip->fare_breakdown, array_flip(['base', 'waiting', 'time', 'total'])));
        $this->assertSame(DriverAvailability::Online, $profile->refresh()->availability, 'the driver is free for the next trip');
        $this->assertSame(['in_progress', 'completed'], array_slice(array_column($this->statusEvents(), 'to'), -2));
    }

    public function test_without_pings_the_quoted_route_stands_in_for_the_distance(): void
    {
        [$trip, , $token] = $this->inProgress();
        $this->travel(10)->minutes();

        $response = $this->step($trip, 'complete', $token)->assertOk();

        // The route of the estimate: 1446 m. 500 + 150 x 1.446 km (217) + 20 x 10 min = 917, above the 800 minimum.
        $response->assertJsonPath('data.distance_meters', 1446)->assertJsonPath('data.fare.final', 917)
            ->assertJsonPath('data.earnings', ['currency' => 'AMD', 'amount' => 734, 'commission' => 183]);
    }

    public function test_a_short_ride_is_lifted_to_the_minimum_fare(): void
    {
        [$trip, , $token] = $this->inProgress();
        $this->drive($trip, 1, now()->addSecond());
        $this->travel(1)->minutes();

        $this->step($trip, 'complete', $token)->assertOk()->assertJsonPath('data.fare.final', 800)->assertJsonPath('data.earnings', ['currency' => 'AMD', 'amount' => 640, 'commission' => 160]);
    }

    public function test_the_surge_the_rider_was_quoted_is_kept_in_the_final_fare(): void
    {
        [$trip, , $token] = $this->inProgress();
        // The quote was worked out with a 1.50 surge (500 + 217 + 58 = 775 x 1.5 = 1162.5 -> 1163 metered with surge).
        Trip::query()->whereKey($trip->id)->update(['surge_multiplier' => '1.50']);
        $this->travel(10)->minutes();

        $response = $this->step($trip, 'complete', $token)->assertOk();

        // metered 500 + 217 + 200 = 917; surge 917 x 0.5 = 458.5 -> 459; total 1376.
        $response->assertJsonPath('data.fare.final', 1376);
        $this->assertSame('1.50', $trip->refresh()->surge_multiplier);
        $this->assertSame(459, $trip->fare_breakdown['surge']);
    }

    public function test_completing_twice_gives_the_same_answer_for_the_same_key_and_a_409_for_a_new_one(): void
    {
        [$trip, , $token] = $this->inProgress();
        $this->travel(10)->minutes();
        $key = $this->key();
        $first = $this->step($trip, 'complete', $token, key: $key)->assertOk();

        $replay = $this->step($trip, 'complete', $token, key: $key);
        $again = $this->step($trip, 'complete', $token);

        $replay->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json(), $replay->json());
        $again->assertStatus(409)->assertJsonPath('trip.status', 'completed')->assertJsonPath('trip.fare.final', $first->json('data.fare.final'));
        $this->assertSame($first->json('data.fare.final'), $trip->refresh()->fare_amount, 'the fare was not worked out again');
    }

    public function test_a_trip_that_is_not_in_progress_cannot_be_completed_and_no_fare_is_written(): void
    {
        [$trip, , $token] = $this->matched();

        $this->step($trip, 'complete', $token)->assertStatus(409);

        $trip->refresh();
        $this->assertNull($trip->fare_amount);
        $this->assertSame(TripPaymentStatus::Unpaid, $trip->payment_status);
    }

    // ---- driver cancel ----

    public function test_a_driver_who_gives_the_trip_back_sends_it_to_dispatch_again_and_the_reason_is_kept(): void
    {
        Event::fake([TripRequested::class]);
        [$trip, $profile, $token] = $this->matched();

        $this->step($trip, 'cancel', $token, ['reason' => 'Flat tyre'])->assertOk()->assertJsonPath('data.status', 'requested');

        $trip->refresh();
        $this->assertSame(TripStatus::Requested, $trip->status);
        $this->assertNull($trip->driver_id);
        $this->assertNull($trip->vehicle_id);
        $this->assertNull($trip->matched_at);
        $cancellation = TripDriverCancellation::query()->sole();
        $this->assertSame([$trip->id, $profile->id, TripStatus::Matched, 'Flat tyre'], [$cancellation->trip_id, $cancellation->driver_id, $cancellation->trip_status, $cancellation->reason]);
        $this->assertSame(DriverAvailability::Online, $profile->refresh()->availability);
        $this->assertSame(['matched', 'requested'], array_slice(array_column($this->statusEvents(), 'to'), -2));
        Event::assertDispatchedTimes(TripRequested::class, 2);
    }

    public function test_the_driver_can_give_the_trip_back_from_the_pickup_too(): void
    {
        [$trip, , $token] = $this->matched();
        $this->step($trip, 'arrived', $token)->assertOk();

        $this->step($trip, 'cancel', $token, ['reason' => 'Rider not here'])->assertOk()->assertJsonPath('data.status', 'requested');
        $this->assertSame(TripStatus::Arrived, TripDriverCancellation::query()->sole()->trip_status);
    }

    public function test_the_reason_is_required_and_a_started_ride_cannot_be_given_back(): void
    {
        [$trip, , $token] = $this->matched();
        $this->step($trip, 'cancel', $token)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->step($trip, 'cancel', $token, ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(TripStatus::Matched, $trip->refresh()->status);
        $this->step($trip, 'arrived', $token)->assertOk();
        $this->step($trip, 'start', $token)->assertOk();

        $this->step($trip, 'cancel', $token, ['reason' => 'Changed my mind'])->assertStatus(409)->assertJsonPath('trip.status', 'in_progress')
            ->assertJsonPath('message', 'A trip that is in_progress cannot be cancelled by the driver.');
        $this->assertSame(0, TripDriverCancellation::query()->count());
        $this->assertNotNull($trip->refresh()->driver_id);
    }

    // ---- history ----

    public function test_history_lists_finished_trips_newest_first_with_what_each_earned(): void
    {
        [$trip, , $token] = $this->inProgress();
        $this->travel(10)->minutes();
        $this->step($trip, 'complete', $token)->assertOk();
        $profile = DriverProfile::query()->findOrFail($trip->driver_id);
        $cancelled = Trip::factory()->cancelled()->create(['rider_id' => User::factory()->rider(), 'zone_id' => $this->zone->id, 'driver_id' => $profile->id, 'requested_at' => now()->subDay(), 'cancelled_at' => now()->subDay()]);
        Trip::factory()->matched()->create(['rider_id' => User::factory()->rider(), 'zone_id' => $this->zone->id, 'driver_id' => $profile->id]);
        Trip::factory()->completed()->create(['rider_id' => User::factory()->rider(), 'zone_id' => $this->zone->id]);

        $response = $this->fetch('/api/v1/driver/trips', $token)->assertOk();

        $this->assertSame([$trip->id, $cancelled->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.earnings', ['currency' => 'AMD', 'amount' => 734, 'commission' => 183])->assertJsonPath('data.1.earnings', null)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_history_is_paginated(): void
    {
        [, $profile, $token] = $this->driver();
        Trip::factory()->count(23)->completed()->create(['rider_id' => User::factory()->rider(), 'zone_id' => $this->zone->id, 'driver_id' => $profile->id]);

        $this->fetch('/api/v1/driver/trips', $token)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2);
        $this->fetch('/api/v1/driver/trips?page=2', $token)->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_a_driver_cannot_see_another_drivers_history(): void
    {
        [, $profile] = $this->driver();
        Trip::factory()->count(2)->completed()->create(['rider_id' => User::factory()->rider(), 'zone_id' => $this->zone->id, 'driver_id' => $profile->id]);
        [, , $other] = $this->driver();

        $this->fetch('/api/v1/driver/trips', $other)->assertOk()->assertJsonCount(0, 'data');
    }
}
