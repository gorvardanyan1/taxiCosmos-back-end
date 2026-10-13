<?php

namespace Tests\Feature\Geo;

use App\Data\GeoPoint;
use App\Exceptions\GeoException;
use App\Services\Geo\TwoGisProvider;
use App\Support\EncodedPolyline;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * P5-T1: 2GIS (Suggest, Places byid, Geocoder, Routing 7.0.0) with the HTTP client faked: what is sent (lon,lat order, key)
 * and what comes back as the same neutral DTOs Google gives.
 */
class TwoGisProviderTest extends TestCase
{
    private const KEY = 'tg-secret-key-456';

    private TwoGisProvider $twoGis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->twoGis = new TwoGisProvider(self::KEY);
    }

    private function sentTo(string $url): Request
    {
        $sent = Http::recorded(fn (Request $request) => str_starts_with($request->url(), $url));
        $this->assertCount(1, $sent, "expected one request to {$url}");

        return $sent->first()[0];
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_autocomplete_sends_the_location_as_lon_lat_and_skips_suggested_phrases(): void
    {
        Http::fake(['catalog.api.2gis.com/*' => Http::response(['meta' => ['code' => 200], 'result' => ['items' => [
            ['id' => '70000001', 'name' => 'Cafe Central', 'full_name' => 'Yerevan, Cafe Central', 'address_name' => 'Abovyan St 1'],
            ['type' => 'suggest', 'tooltip' => 'cafe'],
            ['id' => '70000002', 'name' => 'Abovyan St 3', 'address_name' => 'Abovyan St 3'],
        ]]])]);

        $result = $this->twoGis->autocomplete('abovyan', new GeoPoint(40.18, 44.51), 'ignored-session');

        $request = $this->sentTo('https://catalog.api.2gis.com/3.0/suggests');
        $this->assertSame('GET', $request->method());
        $this->assertSame(['q' => 'abovyan', 'key' => self::KEY, 'location' => '44.51,40.18', 'suggest_type' => 'route_endpoint'], $this->queryOf($request));
        $this->assertStringNotContainsString('ignored-session', $request->url(), '2GIS has no sessions');

        $this->assertCount(2, $result);
        $this->assertSame(['place_id' => '70000001', 'text' => 'Yerevan, Cafe Central', 'primary_text' => 'Cafe Central', 'secondary_text' => 'Abovyan St 1'], $result[0]->toArray());
        $this->assertSame(['place_id' => '70000002', 'text' => 'Abovyan St 3', 'primary_text' => 'Abovyan St 3', 'secondary_text' => null], $result[1]->toArray());
    }

    public function test_autocomplete_without_a_location_leaves_it_out(): void
    {
        Http::fake(['catalog.api.2gis.com/*' => Http::response(['meta' => ['code' => 200], 'result' => ['items' => []]])]);

        $this->assertSame([], $this->twoGis->autocomplete('abovyan'));

        $this->assertArrayNotHasKey('location', $this->queryOf($this->sentTo('https://catalog.api.2gis.com/3.0/suggests')));
    }

    public function test_nothing_found_is_an_empty_list_but_a_meta_error_is_a_failure(): void
    {
        Http::fake(['catalog.api.2gis.com/*' => Http::sequence()
            ->push(['meta' => ['code' => 404, 'error' => ['message' => 'Results not found']]])
            ->push(['meta' => ['code' => 403, 'error' => ['message' => 'Key is not valid '.self::KEY]]])]);

        $this->assertSame([], $this->twoGis->autocomplete('zzzz'));

        try {
            $this->twoGis->autocomplete('anything');
            $this->fail('a 403 in meta must fail');
        } catch (GeoException $e) {
            $this->assertSame('geo_provider_unavailable', $e->errorCode);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function test_place_details_asks_items_byid_for_the_point_and_names(): void
    {
        Http::fake(['catalog.api.2gis.com/*' => Http::response(['meta' => ['code' => 200], 'result' => ['items' => [
            ['id' => '70000001', 'name' => 'Cafe Central', 'full_name' => 'Yerevan, Cafe Central', 'address_name' => 'Abovyan St 1', 'point' => ['lat' => 40.18, 'lon' => 44.51]],
        ]]])]);

        $place = $this->twoGis->placeDetails('70000001');

        $request = $this->sentTo('https://catalog.api.2gis.com/3.0/items/byid');
        $this->assertSame(['id' => '70000001', 'key' => self::KEY, 'fields' => 'items.point,items.address_name,items.full_name'], $this->queryOf($request));
        $this->assertSame(['place_id' => '70000001', 'name' => 'Cafe Central', 'address' => 'Yerevan, Cafe Central', 'location' => ['lat' => 40.18, 'lng' => 44.51]], $place->toArray());
    }

    public function test_an_unknown_id_and_an_item_without_a_point_are_null(): void
    {
        Http::fake(['catalog.api.2gis.com/*' => Http::sequence()
            ->push(['meta' => ['code' => 404]])
            ->push(['meta' => ['code' => 200], 'result' => ['items' => [['id' => '1', 'name' => 'No point', 'full_name' => 'No point']]]])]);

        $this->assertNull($this->twoGis->placeDetails('nope'));
        $this->assertNull($this->twoGis->placeDetails('1'));
    }

    public function test_geocode_sends_the_address(): void
    {
        Http::fake(['catalog.api.2gis.com/*' => Http::response(['meta' => ['code' => 200], 'result' => ['items' => [
            ['id' => '9', 'address_name' => 'Abovyan St 1', 'full_name' => 'Yerevan, Abovyan St 1', 'point' => ['lat' => 40.1812, 'lon' => 44.5136]],
        ]]])]);

        $place = $this->twoGis->geocode('Abovyan 1, Yerevan');

        $this->assertSame(['q' => 'Abovyan 1, Yerevan', 'key' => self::KEY, 'fields' => 'items.point'], $this->queryOf($this->sentTo('https://catalog.api.2gis.com/3.0/items/geocode')));
        $this->assertSame(['place_id' => '9', 'name' => null, 'address' => 'Yerevan, Abovyan St 1', 'location' => ['lat' => 40.1812, 'lng' => 44.5136]], $place->toArray());
    }

    public function test_reverse_geocode_sends_lon_and_lat_and_keeps_the_pin_where_it_was_put(): void
    {
        Http::fake(['catalog.api.2gis.com/*' => Http::response(['meta' => ['code' => 200], 'result' => ['items' => [
            ['id' => '9', 'address_name' => 'Abovyan St 1', 'point' => ['lat' => 40.19, 'lon' => 44.52]],
        ]]])]);

        $place = $this->twoGis->reverseGeocode(new GeoPoint(40.18, 44.51));

        $query = $this->queryOf($this->sentTo('https://catalog.api.2gis.com/3.0/items/geocode'));
        $this->assertSame(['44.51', '40.18', self::KEY], [$query['lon'], $query['lat'], $query['key']]);
        $this->assertArrayNotHasKey('q', $query);
        $this->assertSame(['lat' => 40.18, 'lng' => 44.51], $place->toArray()['location'], 'the asked point, not the building centre');
        $this->assertSame('Abovyan St 1', $place->address);
    }

    public function test_get_route_posts_the_points_and_converts_wkt_to_an_encoded_polyline(): void
    {
        Http::fake(['routing.api.2gis.com/*' => Http::response(['result' => [[
            'total_distance' => 1523, 'total_duration' => 312,
            'maneuvers' => [
                ['outcoming_path' => ['geometry' => [['selection' => 'LINESTRING(44.51 40.18, 44.511 40.181)']]]],
                ['outcoming_path' => ['geometry' => [['selection' => 'LINESTRING(44.511 40.181, 44.512 40.182)']]]],
            ],
        ]]])]);

        $route = $this->twoGis->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52));

        $request = $this->sentTo('https://routing.api.2gis.com/routing/7.0.0/global');
        $this->assertSame('POST', $request->method());
        $this->assertSame(['key' => self::KEY], $this->queryOf($request));
        $this->assertSame('driving', $request['transport']);
        $this->assertSame('fastest', $request['route_mode']);
        $this->assertSame('jam', $request['traffic_mode'], 'the ETA must reflect live traffic');
        $this->assertEquals([
            ['type' => 'walking', 'lon' => 44.51, 'lat' => 40.18, 'start' => true],
            ['type' => 'walking', 'lon' => 44.52, 'lat' => 40.19, 'start' => false],
        ], $request['points']);

        $this->assertSame(1523, $route->distanceMeters);
        $this->assertSame(312, $route->durationSeconds);
        $points = EncodedPolyline::decode($route->polyline);
        $this->assertCount(3, $points, 'the shared joint is kept once');
        $this->assertEqualsWithDelta(40.18, $points[0]->latitude, 1e-6);
        $this->assertEqualsWithDelta(44.51, $points[0]->longitude, 1e-6);
        $this->assertEqualsWithDelta(40.182, $points[2]->latitude, 1e-6);
        $this->assertEqualsWithDelta(44.512, $points[2]->longitude, 1e-6);
    }

    public function test_estimate_eta_is_the_route_without_the_path(): void
    {
        Http::fake(['routing.api.2gis.com/*' => Http::response(['result' => [['total_distance' => 900, 'total_duration' => 120]]])]);

        $eta = $this->twoGis->estimateEta(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52));

        $this->assertSame(['distance_meters' => 900, 'duration_seconds' => 120], $eta->toArray());
    }

    public function test_no_route_is_null(): void
    {
        Http::fake(['routing.api.2gis.com/*' => Http::response(['result' => []])]);

        $this->assertNull($this->twoGis->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(0.0, 0.0)));
    }

    public function test_http_errors_are_a_provider_failure_logged_without_the_url_or_key(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response('boom '.self::KEY, 500)]);

        try {
            $this->twoGis->geocode('anything');
            $this->fail('a 500 must fail');
        } catch (GeoException $e) {
            $this->assertSame(502, $e->status);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }

        Log::shouldHaveReceived('warning')->once()->with('Maps provider call failed', ['provider' => '2gis', 'operation' => 'geocode', 'status' => 500]);
    }

    public function test_a_timeout_is_a_provider_failure_and_does_not_keep_the_url_with_the_key(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out https://x/?key='.self::KEY));

        try {
            $this->twoGis->reverseGeocode(new GeoPoint(40.18, 44.51));
            $this->fail('a timeout must fail');
        } catch (GeoException $e) {
            $this->assertSame('geo_provider_unavailable', $e->errorCode);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function test_the_configured_timeout_is_sent_with_every_request(): void
    {
        config(['services.maps.timeout' => 4]);
        $options = null;
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(['meta' => ['code' => 200], 'result' => ['items' => []]]);
        });

        $this->twoGis->geocode('anything');

        $this->assertEquals(4, $options['timeout']);
    }
}
