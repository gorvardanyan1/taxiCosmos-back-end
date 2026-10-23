<?php

namespace Tests\Feature\Trips;

use App\Enums\TripPaymentMethod;
use App\Enums\TripPaymentStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleClass;
use App\Events\TripRequested;
use App\Models\FareQuote;
use App\Models\FareRule;
use App\Models\Trip;
use App\Models\User;
use App\Realtime\RealtimeEventType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /api/v1/trips: a rider turns the quote they saw into a trip (cash, MVP).
 */
class TripRequestApiTest extends TripApiTestCase
{
    public function test_a_quote_becomes_a_requested_trip_priced_as_quoted(): void
    {
        Event::fake([TripRequested::class]);
        $quoteId = $this->quote();

        $response = $this->requestTrip($this->booking($quoteId));

        $response->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.vehicle_class', 'economy')
            ->assertJsonPath('data.pickup', ['lat' => 40.18, 'lng' => 44.51, 'address' => 'Republic Square, Yerevan'])
            ->assertJsonPath('data.dropoff.address', 'Cascade, Yerevan')
            ->assertJsonPath('data.fare', ['currency' => 'AMD', 'estimated' => 800, 'final' => null])
            ->assertJsonPath('data.payment', ['method' => 'cash', 'status' => 'unpaid'])
            ->assertJsonPath('data.driver', null)->assertJsonPath('data.vehicle', null)->assertJsonPath('data.eta_to_pickup_seconds', null);
        $this->assertMatchesRegularExpression('/^TK-[2-9A-HJKMNP-Z]{8}$/', $response->json('data.code'));

        $trip = Trip::query()->findOrFail($response->json('data.id'));
        $quote = FareQuote::query()->findOrFail($quoteId);
        $this->assertSame($this->rider->id, $trip->rider_id);
        $this->assertSame($this->zone->id, $trip->zone_id);
        $this->assertSame(TripStatus::Requested, $trip->status);
        $this->assertSame(TripPaymentMethod::Cash, $trip->payment_method);
        $this->assertSame(TripPaymentStatus::Unpaid, $trip->payment_status);
        $this->assertSame(VehicleClass::Economy, $trip->vehicle_class);
        $this->assertSame(['AMD', 800, '1.00'], [$trip->fare_currency, $trip->estimated_fare_amount, $trip->surge_multiplier]);
        $this->assertSame(FareRule::query()->where('zone_id', $this->zone->id)->value('id'), $trip->fare_rule_id);
        $this->assertSame($quote->estimates[0]['breakdown'], $trip->fare_breakdown, 'the trip keeps the breakdown the rider was shown');
        $this->assertNull($trip->driver_id);
        $this->assertNull($trip->fare_amount);
        $this->assertSame('Republic Square, Yerevan', $trip->pickup_address);
        Event::assertDispatchedTimes(TripRequested::class, 1);
        Event::assertDispatched(TripRequested::class, fn (TripRequested $event) => $event->tripId === $trip->id);
    }

    public function test_the_new_trip_is_announced_on_the_realtime_stream(): void
    {
        $trip = $this->requested();

        $events = $this->entries(RealtimeEventType::TripStatusChanged);

        $this->assertCount(1, $events);
        $this->assertSame(
            ['trip_id' => $trip->id, 'trip_code' => $trip->public_code, 'from_status' => null, 'to_status' => 'requested', 'rider_user_id' => $this->rider->id, 'driver_user_id' => null],
            $events[0]['envelope']->payload,
        );
    }

    public function test_the_same_idempotency_key_twice_makes_one_trip_and_gives_the_first_answer_back(): void
    {
        Event::fake([TripRequested::class]);
        $body = $this->booking($this->quote());
        $key = $this->key();

        $first = $this->requestTrip($body, key: $key);
        $second = $this->requestTrip($body, key: $key);

        $first->assertCreated();
        $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, Trip::query()->count());
        $this->assertCount(1, $this->entries(RealtimeEventType::TripStatusChanged));
        Event::assertDispatchedTimes(TripRequested::class, 1);
    }

    public function test_a_retry_after_the_first_answer_was_lost_finds_the_trip_even_after_it_moved_on(): void
    {
        $body = $this->booking($this->quote());
        $key = $this->key();
        $first = $this->requestTrip($body, key: $key);
        Trip::query()->findOrFail($first->json('data.id'))->transitionTo(TripStatus::NoDriverFound);

        $replay = $this->requestTrip($body, key: $key);

        $replay->assertCreated()->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.status', 'requested');
        $this->assertSame(1, Trip::query()->count());
    }

    public function test_the_same_key_with_another_request_is_refused_and_creates_nothing(): void
    {
        $key = $this->key();
        $this->requestTrip($this->booking($this->quote()), key: $key)->assertCreated();

        $this->requestTrip($this->booking($this->quote(), ['vehicle_class' => 'comfort']), key: $key)
            ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
        $this->assertSame(1, Trip::query()->count());
    }

    public function test_a_request_without_a_usable_idempotency_key_is_refused(): void
    {
        $body = $this->booking($this->quote());

        foreach ([null, 'short', str_repeat('k', 65), 'has spaces here'] as $key) {
            app('auth')->forgetGuards();
            $headers = $this->bearer($this->riderToken) + ($key === null ? [] : ['Idempotency-Key' => $key]);

            $this->postJson('/api/v1/trips', $body, $headers)->assertStatus(422)->assertJsonPath('code', 'idempotency_key_required');
        }

        $this->assertSame(0, Trip::query()->count());
    }

    public function test_a_rider_with_a_trip_in_progress_cannot_request_another(): void
    {
        $first = $this->requested();

        $response = $this->requestTrip();

        $response->assertStatus(409)->assertJsonPath('code', 'active_trip_exists')->assertJsonPath('trip.id', $first->id)->assertJsonPath('trip.status', 'requested');
        $this->assertSame(1, Trip::query()->count());
    }

    #[DataProvider('finishedStatuses')]
    public function test_a_finished_trip_does_not_stop_the_next_request(string $state): void
    {
        Trip::factory()->{$state}()->create(['rider_id' => $this->rider->id, 'zone_id' => $this->zone->id]);

        $this->requestTrip()->assertCreated();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function finishedStatuses(): array
    {
        return ['completed' => ['completed'], 'cancelled' => ['cancelled']];
    }

    public function test_the_database_allows_one_active_trip_per_rider(): void
    {
        Trip::factory()->create(['rider_id' => $this->rider->id, 'zone_id' => $this->zone->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        Trip::factory()->matched()->create(['rider_id' => $this->rider->id, 'zone_id' => $this->zone->id]);
    }

    public function test_two_requests_racing_past_the_check_still_make_one_trip(): void
    {
        $rider = $this->rider;
        $zone = $this->zone;
        $racing = false;
        // The other request's trip lands right after this one's "no active trip" check, before its insert.
        DB::listen(function ($query) use ($rider, $zone, &$racing) {
            if (! $racing && str_contains($query->sql, 'from "trips" where "rider_id"')) {
                $racing = true;
                Trip::factory()->create(['rider_id' => $rider->id, 'zone_id' => $zone->id]);
            }
        });

        $response = $this->requestTrip();

        $response->assertStatus(409)->assertJsonPath('code', 'active_trip_exists');
        $this->assertSame(1, Trip::query()->count());
        $this->assertCount(0, $this->entries(RealtimeEventType::TripStatusChanged), 'the request that lost announced nothing');
    }

    // ---- validation ----

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function badBodies(): array
    {
        return [
            'card is not available yet' => [['payment_method' => 'card'], 'payment_method'],
            'wallet is not available yet' => [['payment_method' => 'wallet'], 'payment_method'],
            'unknown payment method' => [['payment_method' => 'gold'], 'payment_method'],
            'missing payment method' => [['payment_method' => null], 'payment_method'],
            'unknown vehicle class' => [['vehicle_class' => 'rocket'], 'vehicle_class'],
            'missing quote' => [['quote_id' => null], 'quote_id'],
            'pickup without address' => [['pickup' => ['lat' => 40.18, 'lng' => 44.51]], 'pickup.address'],
            'pickup address too long' => [['pickup' => ['lat' => 40.18, 'lng' => 44.51, 'address' => 'x']], 'pickup.address'],
            'latitude out of range' => [['pickup' => ['lat' => 95, 'lng' => 44.51, 'address' => 'A']], 'pickup.lat'],
            'longitude out of range' => [['dropoff' => ['lat' => 40.19, 'lng' => 181, 'address' => 'B']], 'dropoff.lng'],
            'dropoff with a stray field' => [['dropoff' => ['lat' => 40.19, 'lng' => 44.51, 'address' => 'B', 'zone' => 1]], 'dropoff'],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('badBodies')]
    public function test_a_bad_request_is_refused_next_to_the_field_and_creates_nothing(array $change, string $field): void
    {
        if ($field === 'pickup.address' && isset($change['pickup']['address'])) {
            $change['pickup']['address'] = str_repeat('x', 256);
        }

        $this->requestTrip($this->booking($this->quote(), $change))->assertStatus(422)->assertJsonValidationErrors($field);
        $this->assertSame(0, Trip::query()->count());
    }

    public function test_the_card_refusal_says_why(): void
    {
        $this->requestTrip($this->booking($this->quote(), ['payment_method' => 'card']))
            ->assertStatus(422)->assertJsonPath('errors.payment_method.0', 'Only cash is available for now.');
    }

    // ---- the quote ----

    public function test_an_unknown_quote_is_not_found(): void
    {
        $this->requestTrip($this->booking('00000000-0000-4000-8000-000000000000'))->assertNotFound()->assertJsonPath('code', 'quote_not_found');
        $this->requestTrip($this->booking('not-a-uuid'))->assertNotFound()->assertJsonPath('code', 'quote_not_found');
    }

    public function test_another_riders_quote_looks_like_no_quote(): void
    {
        $other = User::factory()->rider()->create();
        $quote = $this->quote($other->createToken('device', ['rider'])->plainTextToken);

        $this->requestTrip($this->booking($quote))->assertNotFound()->assertJsonPath('code', 'quote_not_found');
        $this->assertSame(0, Trip::query()->count());
    }

    public function test_an_expired_quote_is_gone(): void
    {
        $quote = $this->quote();
        $this->travel(11)->minutes();

        $this->requestTrip($this->booking($quote))->assertStatus(410)->assertJsonPath('code', 'quote_expired');
        $this->assertSame(0, Trip::query()->count());
    }

    public function test_a_vehicle_class_the_quote_did_not_price_is_refused(): void
    {
        $this->requestTrip($this->booking($this->quote(), ['vehicle_class' => 'comfort']))->assertStatus(422)->assertJsonPath('code', 'vehicle_class_not_quoted');
    }

    public function test_a_trip_for_another_route_than_the_quote_is_refused(): void
    {
        $quote = $this->quote();

        // 0.005 degrees of latitude is about 556 m: not the place the price was worked out for.
        $this->requestTrip($this->booking($quote, ['pickup' => ['lat' => 40.185, 'lng' => 44.51, 'address' => 'Elsewhere']]))->assertStatus(422)->assertJsonPath('code', 'quote_route_changed');
        $this->requestTrip($this->booking($quote, ['dropoff' => ['lat' => 40.195, 'lng' => 44.51, 'address' => 'Elsewhere']]))->assertStatus(422)->assertJsonPath('code', 'quote_route_changed');
        $this->assertSame(0, Trip::query()->count());
    }

    public function test_a_pickup_a_few_metres_from_the_quote_is_the_same_place(): void
    {
        $quote = $this->quote();

        // 0.0003 degrees of latitude is about 33 m (the pin the rider dropped is not exactly where they typed).
        $this->requestTrip($this->booking($quote, ['pickup' => ['lat' => 40.1803, 'lng' => 44.51, 'address' => 'Republic Square, Yerevan']]))->assertCreated();
    }

    public function test_a_zone_switched_off_since_the_estimate_is_outside_the_service_area(): void
    {
        $quote = $this->quote();
        DB::table('zones')->where('id', $this->zone->id)->update(['is_active' => false]);

        $this->requestTrip($this->booking($quote))->assertStatus(422)->assertJsonPath('code', 'outside_service_area');
        $this->assertSame(0, Trip::query()->count());
    }

    // ---- who may ----

    public function test_a_guest_cannot_request_a_trip(): void
    {
        $body = $this->booking($this->quote());
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/trips', $body, ['Idempotency-Key' => $this->key()])->assertUnauthorized();
        $this->assertSame(0, Trip::query()->count());
    }

    public function test_a_driver_token_cannot_request_a_trip(): void
    {
        [, , $driverToken] = $this->driver();

        $this->requestTrip($this->booking($this->quote()), $driverToken)->assertForbidden();
        $this->assertSame(0, Trip::query()->count());
    }

    public function test_a_suspended_rider_cannot_request_a_trip(): void
    {
        $quote = $this->quote();
        User::query()->whereKey($this->rider->id)->update(['status' => 'suspended']);

        $this->requestTrip($this->booking($quote))->assertForbidden();
        $this->assertSame(0, Trip::query()->count());
    }
}
