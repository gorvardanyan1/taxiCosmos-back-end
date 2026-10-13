<?php

namespace Tests\Feature\Geo;

use App\Data\GeoPoint;
use App\Exceptions\GeoException;
use App\Services\Geo\GoogleMapsProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * P5-T1: Google Maps Platform through its current APIs (Places API (New), Geocoding, Routes) with the HTTP client faked:
 * what is sent (URL, key in a header, session token, field mask) and what comes back as the neutral DTOs.
 */
class GoogleMapsProviderTest extends TestCase
{
    private const KEY = 'g-secret-key-123';

    private GoogleMapsProvider $google;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.maps.bias_radius_meters' => 50000]);
        $this->google = new GoogleMapsProvider(self::KEY);
    }

    private function sentTo(string $url): Request
    {
        $sent = Http::recorded(fn (Request $request) => str_starts_with($request->url(), $url));
        $this->assertCount(1, $sent, "expected one request to {$url}");

        return $sent->first()[0];
    }

    // ---- autocomplete ----

    public function test_autocomplete_posts_the_input_session_and_location_bias_with_the_key_in_a_header(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['suggestions' => [
            ['placePrediction' => ['placeId' => 'ChIJ1', 'text' => ['text' => 'Abovyan St 1, Yerevan, Armenia'], 'structuredFormat' => ['mainText' => ['text' => 'Abovyan St 1'], 'secondaryText' => ['text' => 'Yerevan, Armenia']]]],
            ['queryPrediction' => ['text' => ['text' => 'abovyan cafe']]],
            ['placePrediction' => ['placeId' => 'ChIJ2', 'text' => ['text' => 'Abovyan Square']]],
        ]])]);

        $result = $this->google->autocomplete('abovyan', new GeoPoint(40.18, 44.51), 'session-1');

        $request = $this->sentTo('https://places.googleapis.com/v1/places:autocomplete');
        $this->assertSame('POST', $request->method());
        $this->assertSame(self::KEY, $request->header('X-Goog-Api-Key')[0]);
        $this->assertStringNotContainsString(self::KEY, $request->url());
        $this->assertSame('abovyan', $request['input']);
        $this->assertSame('session-1', $request['sessionToken']);
        $this->assertSame('en', $request['languageCode']);
        $this->assertEquals(['circle' => ['center' => ['latitude' => 40.18, 'longitude' => 44.51], 'radius' => 50000.0]], $request['locationBias']);

        $this->assertCount(2, $result, 'a query prediction is not a place');
        $this->assertSame(['place_id' => 'ChIJ1', 'text' => 'Abovyan St 1, Yerevan, Armenia', 'primary_text' => 'Abovyan St 1', 'secondary_text' => 'Yerevan, Armenia'], $result[0]->toArray());
        $this->assertSame(['place_id' => 'ChIJ2', 'text' => 'Abovyan Square', 'primary_text' => 'Abovyan Square', 'secondary_text' => null], $result[1]->toArray());
    }

    public function test_autocomplete_without_a_location_or_session_sends_neither(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response([])]);

        $this->assertSame([], $this->google->autocomplete('abovyan'));

        $request = $this->sentTo('https://places.googleapis.com/v1/places:autocomplete');
        $this->assertArrayNotHasKey('sessionToken', $request->data());
        $this->assertArrayNotHasKey('locationBias', $request->data());
    }

    public function test_the_bias_radius_never_exceeds_the_50_km_google_accepts(): void
    {
        config(['services.maps.bias_radius_meters' => 200000]);
        Http::fake(['places.googleapis.com/*' => Http::response([])]);

        $this->google->autocomplete('abovyan', new GeoPoint(40.18, 44.51));

        $this->assertSame(50000.0, (float) $this->sentTo('https://places.googleapis.com/v1/places:autocomplete')['locationBias']['circle']['radius']);
    }

    public function test_the_language_follows_the_app_locale(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response([])]);
        app()->setLocale('ru');

        $this->google->autocomplete('абовян');

        $this->assertSame('ru', $this->sentTo('https://places.googleapis.com/v1/places:autocomplete')['languageCode']);
    }

    // ---- place details ----

    public function test_place_details_asks_only_for_the_fields_it_needs_and_ends_the_session(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['id' => 'ChIJ1', 'displayName' => ['text' => 'Cafe Central'], 'formattedAddress' => 'Abovyan St 1, Yerevan', 'location' => ['latitude' => 40.18, 'longitude' => 44.51]])]);

        $place = $this->google->placeDetails('ChIJ1', 'session-1');

        $request = $this->sentTo('https://places.googleapis.com/v1/places/ChIJ1');
        $this->assertSame('GET', $request->method());
        $this->assertSame('id,displayName,formattedAddress,location', $request->header('X-Goog-FieldMask')[0]);
        $this->assertSame(self::KEY, $request->header('X-Goog-Api-Key')[0]);
        $this->assertStringContainsString('sessionToken=session-1', $request->url());
        $this->assertSame(['place_id' => 'ChIJ1', 'name' => 'Cafe Central', 'address' => 'Abovyan St 1, Yerevan', 'location' => ['lat' => 40.18, 'lng' => 44.51]], $place->toArray());
    }

    public function test_an_id_is_url_encoded_so_it_cannot_change_the_path(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response([], 404)]);

        $this->assertNull($this->google->placeDetails('a/../b?x=1'));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://places.googleapis.com/v1/places/a%2F..%2Fb%3Fx%3D1'));
    }

    public function test_an_unknown_place_id_is_null_not_a_failure(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['code' => 404]], 404)]);

        $this->assertNull($this->google->placeDetails('nope'));
    }

    public function test_a_place_without_coordinates_is_null(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['id' => 'x', 'formattedAddress' => 'Somewhere'])]);

        $this->assertNull($this->google->placeDetails('x'));
    }

    // ---- geocode / reverse ----

    public function test_geocode_sends_the_address_and_maps_the_first_result(): void
    {
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => [
            ['place_id' => 'ChIJ9', 'formatted_address' => 'Abovyan St 1, Yerevan, Armenia', 'geometry' => ['location' => ['lat' => 40.1812, 'lng' => 44.5136]]],
            ['place_id' => 'other', 'formatted_address' => 'Elsewhere', 'geometry' => ['location' => ['lat' => 1.0, 'lng' => 2.0]]],
        ]])]);

        $place = $this->google->geocode('Abovyan 1, Yerevan');

        $request = $this->sentTo('https://maps.googleapis.com/maps/api/geocode/json');
        $this->assertSame('GET', $request->method());
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->assertSame(['address' => 'Abovyan 1, Yerevan', 'key' => self::KEY, 'language' => 'en'], $query);
        $this->assertSame(['place_id' => 'ChIJ9', 'name' => null, 'address' => 'Abovyan St 1, Yerevan, Armenia', 'location' => ['lat' => 40.1812, 'lng' => 44.5136]], $place->toArray());
    }

    public function test_reverse_geocode_sends_latlng(): void
    {
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => [['place_id' => 'p', 'formatted_address' => 'Here', 'geometry' => ['location' => ['lat' => 40.18, 'lng' => 44.51]]]]])]);

        $this->google->reverseGeocode(new GeoPoint(40.18, 44.51));

        parse_str(parse_url($this->sentTo('https://maps.googleapis.com/maps/api/geocode/json')->url(), PHP_URL_QUERY), $query);
        $this->assertSame('40.18,44.51', $query['latlng']);
        $this->assertArrayNotHasKey('address', $query);
    }

    public function test_zero_results_is_null_and_other_statuses_are_a_provider_failure(): void
    {
        Http::fake(['maps.googleapis.com/*' => Http::sequence()->push(['status' => 'ZERO_RESULTS', 'results' => []])->push(['status' => 'REQUEST_DENIED', 'error_message' => 'The provided API key is invalid.', 'results' => []])->push(['status' => 'OVER_QUERY_LIMIT'])]);

        $this->assertNull($this->google->geocode('nowhere'));

        foreach (['REQUEST_DENIED', 'OVER_QUERY_LIMIT'] as $status) {
            try {
                $this->google->geocode('anything');
                $this->fail("{$status} must be a provider failure");
            } catch (GeoException $e) {
                $this->assertSame('geo_provider_unavailable', $e->errorCode);
            }
        }
    }

    // ---- routes ----

    public function test_get_route_asks_the_routes_api_for_a_driving_route_and_maps_distance_duration_and_polyline(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => 1523, 'duration' => '312s', 'polyline' => ['encodedPolyline' => '_p~iF~ps|U_ulLnnqC']]]])]);

        $route = $this->google->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52));

        $request = $this->sentTo('https://routes.googleapis.com/directions/v2:computeRoutes');
        $this->assertSame('routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline', $request->header('X-Goog-FieldMask')[0]);
        $this->assertSame(self::KEY, $request->header('X-Goog-Api-Key')[0]);
        $this->assertEquals(['location' => ['latLng' => ['latitude' => 40.18, 'longitude' => 44.51]]], $request['origin']);
        $this->assertEquals(['location' => ['latLng' => ['latitude' => 40.19, 'longitude' => 44.52]]], $request['destination']);
        $this->assertSame('DRIVE', $request['travelMode']);
        $this->assertSame(['distance_meters' => 1523, 'duration_seconds' => 312, 'polyline' => '_p~iF~ps|U_ulLnnqC'], $route->toArray());
    }

    public function test_estimate_eta_leaves_the_polyline_out_of_the_request(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => 900, 'duration' => '120s']]])]);

        $eta = $this->google->estimateEta(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52));

        $this->assertSame('routes.distanceMeters,routes.duration', $this->sentTo('https://routes.googleapis.com/directions/v2:computeRoutes')->header('X-Goog-FieldMask')[0]);
        $this->assertSame(['distance_meters' => 900, 'duration_seconds' => 120], $eta->toArray());
    }

    public function test_no_route_is_null_and_a_zero_distance_is_a_route_of_zero(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::sequence()->push([])->push(['routes' => [['duration' => '0s', 'polyline' => ['encodedPolyline' => '']]]])]);
        $a = new GeoPoint(40.18, 44.51);

        $this->assertNull($this->google->getRoute($a, new GeoPoint(0.0, 0.0)));
        $this->assertSame(['distance_meters' => 0, 'duration_seconds' => 0, 'polyline' => ''], $this->google->getRoute($a, $a)->toArray());
    }

    // ---- failures ----

    public function test_an_http_error_is_a_provider_failure_that_leaks_neither_key_nor_body_and_is_logged_without_them(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid: '.self::KEY]], 403)]);

        try {
            $this->google->reverseGeocode(new GeoPoint(40.18, 44.51));
            $this->fail('a 403 must fail');
        } catch (GeoException $e) {
            $this->assertSame('geo_provider_unavailable', $e->errorCode);
            $this->assertSame(502, $e->status);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }

        Log::shouldHaveReceived('warning')->once()->with('Maps provider call failed', ['provider' => 'google', 'operation' => 'reverse_geocode', 'status' => 403]);
    }

    public function test_a_timeout_or_connection_error_is_a_provider_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for https://x/?key='.self::KEY));

        try {
            $this->google->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52));
            $this->fail('a timeout must fail');
        } catch (GeoException $e) {
            $this->assertSame('geo_provider_unavailable', $e->errorCode);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertNull($e->getPrevious(), 'the transport error, with its URL, is not kept');
        }
    }

    public function test_the_configured_timeout_is_used(): void
    {
        config(['services.maps.timeout' => 3]);
        Http::fake(['*' => Http::response(['routes' => []])]);

        $this->google->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52));

        Http::assertSent(fn (Request $request) => $request->toPsrRequest() !== null);
        $this->assertSame(3, (int) config('services.maps.timeout'));
    }
}
