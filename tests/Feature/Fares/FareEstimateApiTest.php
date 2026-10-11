<?php

namespace Tests\Feature\Fares;

use App\Enums\VehicleClass;
use App\Models\FareFee;
use App\Models\FareQuote;
use App\Models\FareRule;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * POST /api/v1/trips/fare-estimate. The route is a straight line of 0.01 degrees of latitude
 * (1111.95 m) lengthened by 1.30 = 1446 m, driven at 30 km/h = 174 s; the prices below follow from
 * those two numbers by hand.
 */
class FareEstimateApiTest extends TestCase
{
    use RefreshDatabase;

    private const PICKUP = ['lat' => 40.18, 'lng' => 44.51];

    private const DROPOFF = ['lat' => 40.19, 'lng' => 44.51];

    private Zone $zone;

    private User $rider;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->around(40.18, 44.51, 0.05)->create(['name' => 'Yerevan']);
        $this->rider = User::factory()->rider()->create();
        $this->token = $this->rider->createToken('device', ['rider'])->plainTextToken;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function estimate(?array $body = null, ?string $token = null, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/trips/fare-estimate', $body ?? ['pickup' => self::PICKUP, 'dropoff' => self::DROPOFF], ['Authorization' => 'Bearer '.($token ?? $this->token), ...$headers]);
    }

    private function economyAndComfort(): void
    {
        // economy: 500 + 1446 m x 150/km (216.9 -> 217) + 174 s x 20/min (58) = 775, lifted to the 800 minimum
        FareRule::factory()->forZone($this->zone)->create();
        // comfort: 700 + 1446 x 200/km (289.2 -> 289) + 174 s x 30/min (87) = 1076, above its 1000 minimum
        FareRule::factory()->forZone($this->zone)->vehicleClass(VehicleClass::Comfort)->create(['base_fare' => 700, 'per_km' => 200, 'per_minute' => 30, 'min_fare' => 1000]);
    }

    // ---- the happy path ----

    public function test_it_returns_one_priced_estimate_per_class_with_a_range_and_a_quote(): void
    {
        $this->economyAndComfort();
        $this->travelTo('2026-10-12 09:00:00');

        $response = $this->estimate()->assertCreated();

        $quote = FareQuote::query()->sole();
        $response->assertExactJson([
            'quote_id' => $quote->id,
            'expires_at' => '2026-10-12T09:10:00+00:00',
            'zone' => ['id' => $this->zone->id, 'name' => 'Yerevan'],
            'currency' => 'AMD',
            'distance_meters' => 1446,
            'duration_seconds' => 174,
            'estimates' => [
                ['vehicle_class' => 'economy', 'currency' => 'AMD', 'amount' => 800, 'amount_min' => 720, 'amount_max' => 880, 'surge_multiplier' => '1.00', 'eta_to_pickup_seconds' => null],
                ['vehicle_class' => 'comfort', 'currency' => 'AMD', 'amount' => 1076, 'amount_min' => 968, 'amount_max' => 1184, 'surge_multiplier' => '1.00', 'eta_to_pickup_seconds' => null],
            ],
        ]);
    }

    public function test_the_quote_is_stored_with_the_rule_surge_and_breakdown_of_every_class(): void
    {
        $this->economyAndComfort();
        $economy = FareRule::query()->where('vehicle_class', 'economy')->sole();
        $this->travelTo('2026-10-12 09:00:00');

        $this->estimate()->assertCreated();

        $quote = FareQuote::query()->sole();
        $this->assertSame($this->rider->id, $quote->rider_id);
        $this->assertSame($this->zone->id, $quote->zone_id);
        $this->assertSame(1446, $quote->distance_meters);
        $this->assertSame(174, $quote->duration_seconds);
        $this->assertEqualsWithDelta(40.18, $quote->pickup_location->latitude, 1e-9);
        $this->assertEqualsWithDelta(44.51, $quote->dropoff_location->longitude, 1e-9);
        $this->assertTrue($quote->expires_at->equalTo(now()->addMinutes(10)), 'quotes live for 10 minutes');
        $this->assertSame(['economy', 'comfort'], array_column($quote->estimates, 'vehicle_class'));

        $stored = $quote->estimates[0];
        $this->assertSame($economy->id, $stored['fare_rule_id']);
        $this->assertSame('1.00', $stored['surge_multiplier']);
        $this->assertSame(500, $stored['breakdown']['base']);
        $this->assertSame(217, $stored['breakdown']['distance']);
        $this->assertSame(58, $stored['breakdown']['time']);
        $this->assertSame(25, $stored['breakdown']['minimum_adjustment']);
        $this->assertSame(800, $stored['breakdown']['total']);
        $this->assertSame($stored['amount'], $stored['breakdown']['total'], 'the shown price is the breakdown total');
    }

    public function test_the_response_does_not_leak_the_internal_breakdown_or_rule_ids(): void
    {
        $this->economyAndComfort();

        $json = $this->estimate()->assertCreated()->getContent();

        $this->assertStringNotContainsString('breakdown', $json);
        $this->assertStringNotContainsString('fare_rule_id', $json);
    }

    public function test_only_classes_with_a_rule_in_force_now_are_offered(): void
    {
        FareRule::factory()->forZone($this->zone)->create();
        // comfort: closed yesterday; business: starts tomorrow
        FareRule::factory()->forZone($this->zone)->vehicleClass(VehicleClass::Comfort)->between(now()->subDays(10), now()->subDay())->create();
        FareRule::factory()->forZone($this->zone)->vehicleClass(VehicleClass::Business)->between(now()->addDay())->create();

        $this->estimate()->assertCreated()->assertJsonCount(1, 'estimates')->assertJsonPath('estimates.0.vehicle_class', 'economy');
    }

    public function test_the_current_version_of_a_rule_is_the_one_priced(): void
    {
        FareRule::factory()->forZone($this->zone)->between(now()->subDays(10), now()->subHour())->create(['base_fare' => 100, 'min_fare' => 0]);
        FareRule::factory()->forZone($this->zone)->between(now()->subHour())->create(['base_fare' => 2000, 'min_fare' => 0]);

        // 2000 + 217 + 58
        $this->estimate()->assertCreated()->assertJsonPath('estimates.0.amount', 2275);
    }

    public function test_a_dropoff_outside_the_zone_is_allowed(): void
    {
        $this->economyAndComfort();

        $this->estimate(['pickup' => self::PICKUP, 'dropoff' => ['lat' => 41.5, 'lng' => 45.5]])->assertCreated();
    }

    public function test_the_highest_priority_zone_at_the_pickup_sets_the_prices(): void
    {
        $this->economyAndComfort();
        $airport = Zone::factory()->priority(9)->around(40.18, 44.51, 0.02)->create(['name' => 'Airport']);
        FareRule::factory()->forZone($airport)->create(['base_fare' => 3000, 'min_fare' => 0]);

        $response = $this->estimate()->assertCreated();

        $response->assertJsonPath('zone.name', 'Airport')->assertJsonCount(1, 'estimates')->assertJsonPath('estimates.0.amount', 3275);
    }

    // ---- place fees ----

    public function test_a_fee_for_an_area_at_the_pickup_is_added_once(): void
    {
        $this->economyAndComfort();
        FareFee::factory()->area(40.18, 44.51, 0.005)->create(['name' => 'Airport pickup', 'amount' => 1000, 'applies_to' => 'pickup']);

        $response = $this->estimate()->assertCreated();

        $response->assertJsonPath('estimates.0.amount', 1800)->assertJsonPath('estimates.1.amount', 2076);
        $this->assertSame([['name' => 'Airport pickup', 'amount' => 1000]], FareQuote::query()->sole()->estimates[0]['breakdown']['fees']);
    }

    public function test_a_pickup_fee_is_not_charged_at_the_dropoff_but_a_dropoff_or_both_fee_is(): void
    {
        $this->economyAndComfort();
        // the area is around the DROPOFF only
        $pickupOnly = FareFee::factory()->area(40.19, 44.51, 0.002)->create(['name' => 'Airport pickup', 'amount' => 1000, 'applies_to' => 'pickup']);
        $this->estimate()->assertCreated()->assertJsonPath('estimates.0.amount', 800);

        $pickupOnly->delete();
        FareFee::factory()->area(40.19, 44.51, 0.002)->create(['name' => 'Toll', 'amount' => 300, 'applies_to' => 'dropoff']);
        $this->estimate()->assertCreated()->assertJsonPath('estimates.0.amount', 1100);

        FareFee::query()->delete();
        FareFee::factory()->area(40.19, 44.51, 0.002)->create(['name' => 'Toll', 'amount' => 300, 'applies_to' => 'both']);
        $this->estimate()->assertCreated()->assertJsonPath('estimates.0.amount', 1100);
    }

    public function test_a_fee_for_both_ends_is_charged_once_when_both_are_inside(): void
    {
        $this->economyAndComfort();
        FareFee::factory()->forZone($this->zone)->create(['name' => 'City fee', 'amount' => 200, 'applies_to' => 'both']);

        $this->estimate()->assertCreated()->assertJsonPath('estimates.0.amount', 1000);
    }

    public function test_a_zone_wide_fee_applies_to_trips_that_start_or_end_in_that_zone(): void
    {
        $this->economyAndComfort();
        $other = Zone::factory()->around(41.0, 45.0, 0.5)->priority(0)->create();
        FareFee::factory()->forZone($other)->create(['name' => 'Border fee', 'amount' => 400, 'applies_to' => 'dropoff']);

        $this->estimate(['pickup' => self::PICKUP, 'dropoff' => ['lat' => 41.0, 'lng' => 45.0]])->assertCreated()->assertJsonPath('estimates.0.amount', fn ($amount) => $amount > 1200);
        $this->estimate()->assertCreated()->assertJsonPath('estimates.0.amount', 800);
    }

    public function test_fees_in_another_currency_or_outside_their_period_are_not_charged(): void
    {
        $this->economyAndComfort();
        FareFee::factory()->area(40.18, 44.51, 0.005)->create(['amount' => 1000, 'currency' => 'USD']);
        FareFee::factory()->area(40.18, 44.51, 0.005)->create(['amount' => 1000, 'effective_from' => now()->subDays(5), 'effective_to' => now()->subDay()]);
        FareFee::factory()->area(40.18, 44.51, 0.005)->create(['amount' => 1000, 'effective_from' => now()->addDay()]);

        $this->estimate()->assertCreated()->assertJsonPath('estimates.0.amount', 800);
    }

    public function test_tax_is_added_to_the_estimate(): void
    {
        FareRule::factory()->forZone($this->zone)->create(['tax_rate_bps' => 2000, 'min_fare' => 0]);

        // subtotal 500 + 217 + 58 = 775; tax 20% = 155 -> 930
        $this->estimate()->assertCreated()->assertJsonPath('estimates.0.amount', 930);
    }

    // ---- errors ----

    public function test_a_pickup_outside_every_active_zone_is_a_clear_error_and_stores_no_quote(): void
    {
        $this->economyAndComfort();

        $this->estimate(['pickup' => ['lat' => 48.85, 'lng' => 2.35], 'dropoff' => self::DROPOFF])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Sorry, we do not serve this pickup location yet.', 'code' => 'outside_service_area']);

        $this->assertSame(0, FareQuote::count());
    }

    public function test_an_inactive_zone_does_not_count_as_service_area(): void
    {
        $this->economyAndComfort();
        $this->zone->forceFill(['is_active' => false])->save();

        $this->estimate()->assertStatus(422)->assertJsonPath('code', 'outside_service_area');
    }

    public function test_the_edge_of_the_zone_is_inside(): void
    {
        $this->economyAndComfort();

        $this->estimate(['pickup' => ['lat' => 40.23, 'lng' => 44.51], 'dropoff' => self::DROPOFF])->assertCreated();
        $this->estimate(['pickup' => ['lat' => 40.2301, 'lng' => 44.51], 'dropoff' => self::DROPOFF])->assertStatus(422);
    }

    public function test_a_zone_without_prices_says_so(): void
    {
        $this->estimate()->assertStatus(422)->assertJsonPath('code', 'no_fare_rules')->assertJsonPath('message', 'Prices are not available for this area yet.');

        $this->assertSame(0, FareQuote::count());
    }

    public function test_the_error_message_follows_the_riders_language(): void
    {
        $this->estimate(['pickup' => ['lat' => 48.85, 'lng' => 2.35], 'dropoff' => self::DROPOFF], null, ['Accept-Language' => 'ru'])
            ->assertStatus(422)->assertJsonPath('code', 'outside_service_area')->assertJsonPath('message', 'К сожалению, мы пока не обслуживаем это место посадки.');
        $this->estimate(['pickup' => ['lat' => 48.85, 'lng' => 2.35], 'dropoff' => self::DROPOFF], null, ['Accept-Language' => 'hy'])
            ->assertStatus(422)->assertJsonPath('message', 'Ցավոք, այս վերցնելու վայրը դեռ սպասարկվում չէ։');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): array
    {
        $ok = ['lat' => 40.18, 'lng' => 44.51];

        return [
            'no body' => [[], 'pickup'],
            'no pickup' => [['dropoff' => $ok], 'pickup'],
            'no dropoff' => [['pickup' => $ok], 'dropoff'],
            'pickup without lng' => [['pickup' => ['lat' => 40.1], 'dropoff' => $ok], 'pickup.lng'],
            'dropoff without lat' => [['pickup' => $ok, 'dropoff' => ['lng' => 44.1]], 'dropoff.lat'],
            'latitude over 90' => [['pickup' => ['lat' => 90.1, 'lng' => 44.5], 'dropoff' => $ok], 'pickup.lat'],
            'latitude under -90' => [['pickup' => $ok, 'dropoff' => ['lat' => -90.1, 'lng' => 44.5]], 'dropoff.lat'],
            'longitude over 180' => [['pickup' => ['lat' => 40, 'lng' => 180.1], 'dropoff' => $ok], 'pickup.lng'],
            'longitude under -180' => [['pickup' => $ok, 'dropoff' => ['lat' => 40, 'lng' => -180.1]], 'dropoff.lng'],
            'latitude is text' => [['pickup' => ['lat' => 'north', 'lng' => 44.5], 'dropoff' => $ok], 'pickup.lat'],
            'pickup is a string' => [['pickup' => '40.18,44.51', 'dropoff' => $ok], 'pickup'],
            'unexpected key' => [['pickup' => $ok + ['alt' => 5], 'dropoff' => $ok], 'pickup'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_bad_coordinates_are_a_422_on_the_field_and_store_nothing(array $body, string $field): void
    {
        $this->economyAndComfort();

        $this->estimate($body)->assertStatus(422)->assertJsonValidationErrors($field);

        $this->assertSame(0, FareQuote::count());
    }

    public function test_the_poles_and_the_date_line_are_valid_coordinates(): void
    {
        $this->economyAndComfort();

        // valid input, but not served
        $this->estimate(['pickup' => ['lat' => 90, 'lng' => 180], 'dropoff' => ['lat' => -90, 'lng' => -180]])->assertStatus(422)->assertJsonPath('code', 'outside_service_area');
    }

    // ---- who may ask ----

    public function test_a_guest_is_refused(): void
    {
        $this->economyAndComfort();

        $this->postJson('/api/v1/trips/fare-estimate', ['pickup' => self::PICKUP, 'dropoff' => self::DROPOFF])->assertUnauthorized();
        $this->estimate(null, 'not-a-real-token')->assertUnauthorized();
        $this->assertSame(0, FareQuote::count());
    }

    public function test_a_driver_token_an_admin_and_a_session_without_a_phone_are_refused(): void
    {
        $this->economyAndComfort();
        $driver = User::factory()->driver()->create()->createToken('device', ['driver'])->plainTextToken;
        $pending = $this->rider->createToken('device', ['pending-phone:rider'])->plainTextToken;
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create()->createToken('stolen', ['rider'])->plainTextToken;

        $this->estimate(null, $driver)->assertForbidden();
        $this->estimate(null, $pending)->assertForbidden();
        $this->estimate(null, $admin)->assertForbidden();
        $this->assertSame(0, FareQuote::count());
    }

    public function test_a_suspended_rider_is_refused(): void
    {
        $this->economyAndComfort();
        $this->rider->forceFill(['status' => 'suspended'])->saveQuietly(); // tokens stay valid: the middleware must still refuse

        $this->estimate()->assertForbidden();
    }

    // ---- limits ----

    public function test_estimates_are_limited_per_rider(): void
    {
        $this->economyAndComfort();

        for ($i = 0; $i < 30; $i++) {
            $this->estimate()->assertCreated();
        }

        $this->estimate()->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(30, FareQuote::count());

        $other = User::factory()->rider()->create()->createToken('device', ['rider'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->estimate(null, $other)->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->travel(61)->seconds();
        $this->estimate()->assertCreated();
    }
}
