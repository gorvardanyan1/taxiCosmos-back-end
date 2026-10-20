<?php

namespace Tests\Feature\Geo;

use App\Contracts\GeoProvider;
use App\Data\GeoPoint;
use App\Exceptions\GeoException;
use App\Models\User;
use App\Services\Fares\RouteEstimator;
use App\Services\Geo\NominatimThrottle;
use App\Services\Geo\OsmProvider;
use App\Support\EncodedPolyline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * MVP-1: OpenStreetMap (Nominatim search, OSRM routes) with the HTTP client faked: what is sent (UA, country filter, lon,lat order,
 * 1 request a second), what comes back as the neutral DTOs, what is remembered, and the straight-line fallback when OSRM is down.
 */
class OsmProviderTest extends TestCase
{
    use RefreshDatabase; // the API tests below create a rider: it must be rolled back, not left for the tests that run after

    private const NOMINATIM = 'https://nominatim.test';

    private const OSRM = 'https://osrm.test';

    private const UA = 'TaxiKosmosTest/1.0 (qa@taxikosmos.test)';

    private function osm(int $intervalMs = 0, int $cacheTtl = 600): OsmProvider
    {
        return new OsmProvider(
            ['nominatim_url' => self::NOMINATIM, 'osrm_url' => self::OSRM, 'user_agent' => self::UA, 'country_codes' => 'am', 'search_cache_ttl' => $cacheTtl],
            new NominatimThrottle($intervalMs),
            new RouteEstimator,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.maps.timeout' => 5, 'services.maps.bias_radius_meters' => 50000]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return [...[
            'osm_type' => 'way', 'osm_id' => 4421, 'lat' => '40.1872', 'lon' => '44.5152', 'name' => '',
            'display_name' => '12, Abovyan Street, Kentron, Yerevan, 0001, Armenia',
            'address' => ['house_number' => '12', 'road' => 'Abovyan Street', 'city' => 'Yerevan', 'country' => 'Armenia'],
        ], ...$overrides];
    }

    private function sentTo(string $url): Request
    {
        $sent = Http::recorded(fn (Request $request) => str_starts_with($request->url(), $url));
        $this->assertCount(1, $sent, "expected one request to {$url}");

        return $sent->first()[0];
    }

    // ---- Nominatim ----

    public function test_autocomplete_turns_nominatim_rows_into_suggestions_and_asks_politely(): void
    {
        Http::fake([self::NOMINATIM.'/*' => Http::response([
            self::row(),
            self::row(['osm_type' => 'node', 'osm_id' => 99, 'name' => 'Cascade Complex', 'display_name' => 'Cascade Complex, Kentron, Yerevan, Armenia', 'address' => ['city' => 'Yerevan']]),
            ['osm_type' => 'way', 'osm_id' => 7, 'display_name' => 'a row without a point'],
        ])]);

        $suggestions = $this->osm()->autocomplete('  abovyan   12 ', new GeoPoint(40.18, 44.51));

        $this->assertSame(
            [
                ['place_id' => 'W4421', 'text' => '12, Abovyan Street, Kentron, Yerevan, 0001, Armenia', 'primary_text' => 'Abovyan Street 12', 'secondary_text' => 'Yerevan'],
                ['place_id' => 'N99', 'text' => 'Cascade Complex, Kentron, Yerevan, Armenia', 'primary_text' => 'Cascade Complex', 'secondary_text' => 'Yerevan'],
            ],
            array_map(fn ($suggestion) => $suggestion->toArray(), $suggestions),
        );

        $request = $this->sentTo(self::NOMINATIM.'/search');
        $this->assertSame(self::UA, $request->header('User-Agent')[0], 'Nominatim blocks anonymous clients');
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->assertSame('abovyan 12', $query['q']);
        $this->assertSame('jsonv2', $query['format']);
        $this->assertSame('am', $query['countrycodes']);
        $this->assertSame(app()->getLocale(), $query['accept-language']);
        $this->assertSame('0', $query['bounded'], 'nearer places rank first but a far one is still found');
        [$left, $top, $right, $bottom] = array_map('floatval', explode(',', $query['viewbox']));
        $this->assertEqualsWithDelta(44.51, ($left + $right) / 2, 0.0001);
        $this->assertEqualsWithDelta(40.18, ($top + $bottom) / 2, 0.0001);
        $this->assertGreaterThan(0.4, $right - $left, '50 km around the user is about 0.6 degrees of longitude');
    }

    public function test_autocomplete_without_a_position_sends_no_box_and_no_country_filter_when_the_config_is_empty(): void
    {
        Http::fake([self::NOMINATIM.'/*' => Http::response([])]);
        $osm = new OsmProvider(['nominatim_url' => self::NOMINATIM, 'osrm_url' => self::OSRM, 'user_agent' => self::UA, 'country_codes' => '', 'search_cache_ttl' => 0], new NominatimThrottle(0), new RouteEstimator);

        $this->assertSame([], $osm->autocomplete('nowhere'));

        $url = $this->sentTo(self::NOMINATIM.'/search')->url();
        $this->assertStringNotContainsString('viewbox', $url);
        $this->assertStringNotContainsString('countrycodes', $url);
    }

    public function test_place_details_looks_the_osm_object_up_and_refuses_an_id_that_is_not_one(): void
    {
        Http::fake([self::NOMINATIM.'/lookup*' => Http::response([self::row(['name' => 'Home'])])]);

        $place = $this->osm()->placeDetails('W4421');

        $this->assertSame(['place_id' => 'W4421', 'name' => 'Home', 'address' => '12, Abovyan Street, Kentron, Yerevan, 0001, Armenia', 'location' => ['lat' => 40.1872, 'lng' => 44.5152]], $place->toArray());
        $this->assertStringContainsString('osm_ids=W4421', $this->sentTo(self::NOMINATIM.'/lookup')->url());

        Http::fake();
        foreach (['fake-abovyan-1', 'W', 'X12', 'W12; DROP', 'ChIJ123'] as $notOurs) {
            $this->assertNull($this->osm()->placeDetails($notOurs), $notOurs);
        }
        Http::assertNothingSent();
    }

    public function test_place_details_of_an_unknown_object_is_null(): void
    {
        Http::fake([self::NOMINATIM.'/*' => Http::response([])]);

        $this->assertNull($this->osm()->placeDetails('N1'));
    }

    public function test_geocode_returns_the_best_match_or_null(): void
    {
        Http::fake([self::NOMINATIM.'/search*' => Http::sequence()->push([self::row()])->push([])]);

        $this->assertSame('W4421', $this->osm()->geocode('Abovyan 12')->placeId);
        $this->assertNull($this->osm()->geocode('nowhere at all'));
    }

    public function test_reverse_geocode_names_the_pin_and_keeps_the_pin_where_it_was(): void
    {
        Http::fake([self::NOMINATIM.'/reverse*' => Http::response(self::row())]);

        $place = $this->osm()->reverseGeocode(new GeoPoint(40.18, 44.51));

        $this->assertSame(40.18, $place->location->latitude, 'the building centre is not the pin');
        $this->assertSame(44.51, $place->location->longitude);
        $this->assertSame('12, Abovyan Street, Kentron, Yerevan, 0001, Armenia', $place->address);
        parse_str(parse_url($this->sentTo(self::NOMINATIM.'/reverse')->url(), PHP_URL_QUERY), $query);
        $this->assertSame('40.18', $query['lat']);
        $this->assertSame('44.51', $query['lon']);
    }

    public function test_reverse_geocode_in_the_middle_of_the_sea_is_null_not_an_error(): void
    {
        Http::fake([self::NOMINATIM.'/reverse*' => Http::response(['error' => 'Unable to geocode'])]);

        $this->assertNull($this->osm()->reverseGeocode(new GeoPoint(0, 0)));
    }

    public function test_a_nominatim_failure_is_a_provider_outage_that_leaks_nothing(): void
    {
        Log::spy();
        Http::fake([self::NOMINATIM.'/*' => Http::response('boom secret-body', 503)]);

        try {
            $this->osm()->geocode('abovyan');
            $this->fail('expected an outage');
        } catch (GeoException $e) {
            $this->assertStringNotContainsString('nominatim', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('secret-body', $e->getMessage());
        }

        Log::shouldHaveReceived('warning')->once()->with('Maps provider call failed', ['provider' => 'osm', 'operation' => 'geocode', 'status' => 503]);
    }

    public function test_a_nominatim_timeout_is_a_provider_outage(): void
    {
        Http::fake([self::NOMINATIM.'/*' => fn () => throw new ConnectionException('timed out')]);

        $this->expectException(GeoException::class);

        $this->osm()->autocomplete('abovyan');
    }

    // ---- one request a second ----

    public function test_nominatim_is_called_at_most_once_a_second(): void
    {
        Sleep::fake();
        $this->travelTo(now());
        Http::fake([self::NOMINATIM.'/*' => Http::response([self::row()])]);
        $osm = $this->osm(intervalMs: 1000);

        $osm->geocode('one');
        Sleep::assertNeverSlept();

        $osm->geocode('two');
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(1000)->milliseconds()]);
    }

    public function test_only_the_missing_part_of_the_second_is_waited(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $this->travelTo(now());
        Http::fake([self::NOMINATIM.'/*' => Http::response([self::row()])]);
        $osm = $this->osm(intervalMs: 1000);

        $osm->geocode('one');
        $this->travel(400)->milliseconds();
        $osm->geocode('two');
        Sleep::assertSequence([Sleep::for(600)->milliseconds()]);

        $this->travel(1500)->milliseconds();
        $osm->geocode('three');
        Sleep::assertSleptTimes(1); // late enough: no wait
    }

    public function test_a_request_that_cannot_get_its_turn_soon_fails_as_a_maps_outage_and_does_not_hold_a_worker(): void
    {
        Log::spy();
        Sleep::fake(syncWithCarbon: true);
        Http::fake();
        // Another request is in the middle of its Nominatim turn.
        $this->assertTrue(Cache::lock('geo:osm:nominatim:lock', 120)->get());
        $osm = new OsmProvider(
            ['nominatim_url' => self::NOMINATIM, 'osrm_url' => self::OSRM, 'user_agent' => self::UA, 'country_codes' => 'am', 'search_cache_ttl' => 0],
            new NominatimThrottle(1000, maxWaitSeconds: 3),
            new RouteEstimator,
        );
        $started = now();

        try {
            $osm->geocode('abovyan');
            $this->fail('expected a maps outage');
        } catch (GeoException $e) {
            $this->assertSame(GeoException::providerUnavailable()->getMessage(), $e->getMessage());
        }

        $this->assertLessThanOrEqual(4, $started->diffInSeconds(now(), true), 'it gave up after the short wait, not after 30 s');
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->with('Maps provider call failed', ['provider' => 'osm', 'operation' => 'nominatim_throttle', 'status' => null]);
    }

    public function test_the_api_answers_502_geo_provider_unavailable_when_the_throttle_is_busy_and_recovers_when_it_is_free(): void
    {
        config(['services.maps.provider' => 'osm', 'services.maps.osm.nominatim_interval_ms' => 1000, 'services.maps.osm.nominatim_max_wait_seconds' => 1]);
        Sleep::fake(syncWithCarbon: true);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([self::row()])]);
        $busy = Cache::lock('geo:osm:nominatim:lock', 120);
        $this->assertTrue($busy->get());
        $token = User::factory()->rider()->create()->createToken('device', ['rider'])->plainTextToken;

        $this->getJson('/api/v1/geo/autocomplete?q=abovyan', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'The maps service is not available right now. Please try again.', 'code' => 'geo_provider_unavailable']);

        $busy->release();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/geo/autocomplete?q=abovyan', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('suggestions.0.place_id', 'W4421');
    }

    public function test_the_limit_holds_when_the_cache_returns_the_last_call_time_as_a_string_as_redis_does(): void
    {
        Sleep::fake();
        $this->travelTo(now());
        Http::fake([self::NOMINATIM.'/*' => Http::response([self::row()])]);
        // What Redis gives back for a stored number: Cache::get() is "1792522979836", not 1792522979836.
        $nowMs = (int) round(now()->getPreciseTimestamp(3));
        Cache::put('geo:osm:nominatim:last-call', (string) ($nowMs - 400), 60);
        $this->assertIsString(Cache::get('geo:osm:nominatim:last-call'));

        $this->osm(intervalMs: 1000)->geocode('abovyan');

        Sleep::assertSequence([Sleep::for(600)->milliseconds()]);
    }

    public function test_a_failed_nominatim_call_still_counts_towards_the_one_a_second_limit(): void
    {
        Sleep::fake();
        $this->travelTo(now());
        Http::fake([self::NOMINATIM.'/*' => Http::sequence()->pushStatus(503)->push([self::row()])]);
        $osm = $this->osm(intervalMs: 1000);

        try {
            $osm->geocode('one');
            $this->fail('expected a maps outage');
        } catch (GeoException) {
            // The server answered, so it was a request to Nominatim like any other.
        }
        Sleep::assertNeverSlept();

        $osm->geocode('two');

        Sleep::assertSequence([Sleep::for(1000)->milliseconds()]);
    }

    public function test_how_long_a_request_may_queue_comes_from_config(): void
    {
        config(['services.maps.provider' => 'osm', 'services.maps.osm.nominatim_interval_ms' => 1000, 'services.maps.osm.nominatim_max_wait_seconds' => 1]);
        Sleep::fake(syncWithCarbon: true);
        Http::fake();
        $this->assertTrue(Cache::lock('geo:osm:nominatim:lock', 120)->get());
        $started = now();

        try {
            app(GeoProvider::class)->geocode('abovyan');
            $this->fail('expected a maps outage');
        } catch (GeoException) {
            // gave up
        }

        $waited = $started->diffInSeconds(now(), true);
        $this->assertGreaterThan(0.5, $waited, 'it queued for its turn first');
        $this->assertLessThan(2, $waited, 'one second was configured; the built-in default (three) would wait about 2.75 s here');
    }

    public function test_a_self_hosted_nominatim_never_waits_for_the_lock(): void
    {
        Sleep::fake(syncWithCarbon: true);
        Http::fake([self::NOMINATIM.'/*' => Http::response([self::row()])]);
        $this->assertTrue(Cache::lock('geo:osm:nominatim:lock', 120)->get(), 'another request is in the middle of its turn');

        $place = $this->osm(intervalMs: 0)->geocode('abovyan');

        $this->assertSame('W4421', $place->placeId);
        Sleep::assertNeverSlept();
    }

    public function test_a_self_hosted_nominatim_has_no_limit(): void
    {
        Sleep::fake();
        Http::fake([self::NOMINATIM.'/*' => Http::response([self::row()])]);
        $osm = $this->osm(intervalMs: 0);

        $osm->geocode('one');
        $osm->geocode('two');
        $osm->geocode('three');

        Sleep::assertNeverSlept();
        Http::assertSentCount(3);
    }

    public function test_osrm_is_not_throttled(): void
    {
        Sleep::fake();
        Http::fake([self::OSRM.'/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 100.4, 'duration' => 20.2, 'geometry' => '_p~iF~ps|U']]])]);
        $osm = $this->osm(intervalMs: 1000);

        $osm->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52));
        $osm->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.53));

        Sleep::assertNeverSlept();
    }

    // ---- what is remembered ----

    public function test_the_same_search_twice_costs_one_nominatim_call_even_with_other_spacing_and_case(): void
    {
        Http::fake([self::NOMINATIM.'/*' => Http::response([self::row()])]);
        $osm = $this->osm();

        $first = $osm->autocomplete('Abovyan 12');
        $second = $osm->autocomplete('  abovyan   12 ');

        Http::assertSentCount(1);
        $this->assertEquals($first, $second);
    }

    public function test_a_search_in_another_language_or_near_another_place_is_not_served_from_the_cache(): void
    {
        Http::fake([self::NOMINATIM.'/*' => Http::response([self::row()])]);
        $osm = $this->osm();

        $osm->autocomplete('abovyan', new GeoPoint(40.18, 44.51));
        $osm->autocomplete('abovyan', new GeoPoint(40.18, 44.51));
        $osm->autocomplete('abovyan', new GeoPoint(41.70, 44.80));
        app()->setLocale('ru');
        $osm->autocomplete('abovyan', new GeoPoint(40.18, 44.51));

        Http::assertSentCount(3);
    }

    public function test_place_details_and_geocode_are_remembered_per_language(): void
    {
        Http::fake([self::NOMINATIM.'/*' => Http::sequence()
            ->push([self::row(['display_name' => 'Abovyan Street 12, Yerevan'])])
            ->push([self::row(['display_name' => 'Abovyan Street 12 (ru)'])])
            ->push([self::row(['display_name' => 'Geocoded in English'])])
            ->push([self::row(['display_name' => 'Geocoded in Russian'])])]);
        $osm = $this->osm();

        $english = $osm->placeDetails('W4421')->address;
        $osm->placeDetails('W4421'); // same language: from the cache
        app()->setLocale('ru');
        $russian = $osm->placeDetails('W4421')->address;
        app()->setLocale('en');
        $geocodedEnglish = $osm->geocode('abovyan 12')->address;
        app()->setLocale('ru');
        $geocodedRussian = $osm->geocode('abovyan 12')->address;

        $this->assertSame(['Abovyan Street 12, Yerevan', 'Abovyan Street 12 (ru)', 'Geocoded in English', 'Geocoded in Russian'], [$english, $russian, $geocodedEnglish, $geocodedRussian]);
        Http::assertSentCount(4);
    }

    public function test_the_grey_line_of_a_suggestion_is_the_locality_unless_it_just_repeats_the_bold_line(): void
    {
        Http::fake([self::NOMINATIM.'/*' => Http::response([
            self::row(['osm_id' => 1, 'name' => 'Yerevan', 'display_name' => 'Yerevan, Armenia', 'address' => ['city' => 'Yerevan']]),
            self::row(['osm_id' => 2, 'name' => '', 'display_name' => 'Somewhere, Armenia', 'address' => ['country' => 'Armenia']]),
        ])]);

        $suggestions = $this->osm()->autocomplete('yerevan');

        $this->assertNull($suggestions[0]->secondaryText, 'the city named Yerevan in Yerevan: nothing to add');
        $this->assertSame('Yerevan', $suggestions[0]->primaryText);
        $this->assertNull($suggestions[1]->secondaryText, 'no locality known');
        $this->assertSame('Somewhere', $suggestions[1]->primaryText, 'falls back to the first part of the address');
    }

    public function test_nothing_found_and_failures_are_asked_again(): void
    {
        Http::fake([self::NOMINATIM.'/*' => Http::sequence()->push([])->push([])->push([self::row()])]);
        $osm = $this->osm();

        $osm->geocode('typo');
        $osm->geocode('typo');
        $osm->geocode('typo');
        $osm->geocode('typo');

        Http::assertSentCount(3);
    }

    public function test_a_zero_ttl_turns_the_search_cache_off(): void
    {
        Http::fake([self::NOMINATIM.'/*' => Http::response([self::row()])]);
        $osm = $this->osm(cacheTtl: 0);

        $osm->geocode('abovyan');
        $osm->geocode('abovyan');

        Http::assertSentCount(2);
    }

    // ---- OSRM ----

    public function test_a_route_has_distance_duration_and_osrms_polyline_as_it_is(): void
    {
        Http::fake([self::OSRM.'/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 1523.4, 'duration' => 311.6, 'geometry' => '_p~iF~ps|U_ulLnnqC']]])]);

        $route = $this->osm()->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52));

        $this->assertSame(['distance_meters' => 1523, 'duration_seconds' => 312, 'polyline' => '_p~iF~ps|U_ulLnnqC'], $route->toArray());
        $this->assertFalse($route->estimated);
        $request = $this->sentTo(self::OSRM.'/route/v1/driving/');
        $this->assertStringStartsWith(self::OSRM.'/route/v1/driving/44.51,40.18;44.52,40.19?', $request->url(), 'OSRM takes lon,lat');
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->assertSame('polyline', $query['geometries']);
        $this->assertSame('full', $query['overview']);
        $this->assertSame(self::UA, $request->header('User-Agent')[0]);
    }

    public function test_estimate_eta_is_the_routes_distance_and_duration(): void
    {
        Http::fake([self::OSRM.'/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 900, 'duration' => 120, 'geometry' => 'abc']]])]);

        $this->assertSame(['distance_meters' => 900, 'duration_seconds' => 120], $this->osm()->estimateEta(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52))->toArray());
    }

    public function test_no_route_between_the_points_is_null_not_an_estimate(): void
    {
        Http::fake([self::OSRM.'/*' => Http::sequence()->push(['code' => 'NoRoute'])->push(['code' => 'NoSegment', 'message' => 'Could not find a matching segment'], 400)]);
        $osm = $this->osm();

        $this->assertNull($osm->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(0, 0)));
        $this->assertNull($osm->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(0, 1)));
    }

    /**
     * @return array<string, array{callable}>
     */
    public static function osrmOutages(): array
    {
        return [
            'server error' => [fn () => Http::response('down', 500)],
            'timeout' => [fn () => fn () => throw new ConnectionException('timed out')],
            'rate limited' => [fn () => Http::response(['code' => 'TooBig'], 429)],
            'answer without a route' => [fn () => Http::response(['code' => 'Ok', 'routes' => []])],
            'unexpected code' => [fn () => Http::response(['code' => 'InvalidQuery'], 400)],
            'not json' => [fn () => Http::response('<html>bad gateway</html>', 502)],
        ];
    }

    #[DataProvider('osrmOutages')]
    public function test_when_osrm_is_down_the_route_is_the_straight_line_with_the_detour_factor_and_marked_as_an_estimate(callable $answer): void
    {
        Log::spy();
        Http::fake([self::OSRM.'/*' => $answer()]);
        $from = new GeoPoint(40.18, 44.51);
        $to = new GeoPoint(40.19, 44.52);
        $expected = (new RouteEstimator)->estimate($from, $to);

        $route = $this->osm()->getRoute($from, $to);

        $this->assertTrue($route->estimated);
        $this->assertSame($expected['distance_meters'], $route->distanceMeters);
        $this->assertSame($expected['duration_seconds'], $route->durationSeconds);
        $this->assertGreaterThan(0, $route->distanceMeters);
        $this->assertEquals([$from, $to], EncodedPolyline::decode($route->polyline), 'a line between the two points to draw');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Maps provider call failed' && $context['provider'] === 'osm' && $context['fallback'] === 'straight_line' && ! str_contains(json_encode($context), 'osrm.test'));
    }

    public function test_the_fallback_distance_follows_the_configured_detour_factor(): void
    {
        Http::fake([self::OSRM.'/*' => Http::response('down', 500)]);
        $from = new GeoPoint(40.18, 44.51);
        $to = new GeoPoint(40.19, 44.52);

        config(['taxikosmos.fares.route_detour_percent' => 100]);
        $straight = $this->osm()->getRoute($from, $to)->distanceMeters;
        config(['taxikosmos.fares.route_detour_percent' => 130]);
        $detoured = $this->osm()->getRoute($from, $to)->distanceMeters;

        $this->assertEqualsWithDelta($straight * 1.3, $detoured, 1);
    }

    public function test_a_real_osrm_answer_is_not_marked_as_an_estimate_so_it_can_be_remembered(): void
    {
        Http::fake([self::OSRM.'/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 900, 'duration' => 120, 'geometry' => 'abc']]])]);

        $this->assertFalse($this->osm()->estimateEta(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52))->estimated);
    }

    public function test_the_eta_during_an_outage_is_marked_as_an_estimate_too(): void
    {
        Http::fake([self::OSRM.'/*' => Http::response('down', 500)]);

        $this->assertTrue($this->osm()->estimateEta(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52))->estimated);
    }
}
