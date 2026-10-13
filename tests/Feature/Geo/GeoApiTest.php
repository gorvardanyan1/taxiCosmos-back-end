<?php

namespace Tests\Feature\Geo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P5-T1: /api/v1/geo/* for the rider and driver apps. The provider is the keyless fake unless a test picks Google or 2GIS
 * (with the HTTP client faked); the answers must have the same shape whichever is chosen.
 */
class GeoApiTest extends TestCase
{
    use RefreshDatabase;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->rider = User::factory()->rider()->create();
    }

    private function token(?User $user = null, array $abilities = ['rider']): string
    {
        return ($user ?? $this->rider)->createToken('device', $abilities)->plainTextToken;
    }

    private function get_(string $url, ?string $token = null): TestResponse
    {
        app('auth')->forgetGuards(); // the guard remembers the first token's user for the rest of the test

        return $this->getJson($url, ['Authorization' => 'Bearer '.($token ?? $this->token())]);
    }

    private function asGoogle(): void
    {
        config(['services.maps.provider' => 'google', 'services.maps.google.key' => 'g-key']);
    }

    private function asTwoGis(): void
    {
        config(['services.maps.provider' => '2gis', 'services.maps.2gis.key' => 't-key']);
    }

    // ---- who may ----

    /**
     * @return array<string, array{string}>
     */
    public static function endpoints(): array
    {
        return [
            'autocomplete' => ['/api/v1/geo/autocomplete?q=abovyan'],
            'place' => ['/api/v1/geo/places/fake-abovyan-1'],
            'reverse' => ['/api/v1/geo/reverse?lat=40.18&lng=44.51'],
            'route' => ['/api/v1/geo/route?from=40.18,44.51&to=40.19,44.52'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_a_rider_and_a_driver_may_call_every_endpoint(string $url): void
    {
        $driver = User::factory()->driver()->create();

        $this->get_($url)->assertOk();
        $this->get_($url, $this->token($driver, ['driver']))->assertOk();
    }

    #[DataProvider('endpoints')]
    public function test_a_guest_a_bad_token_and_a_half_signed_in_session_are_refused(string $url): void
    {
        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['Authorization' => 'Bearer nonsense'])->assertUnauthorized();
        $this->get_($url, $this->token(null, ['pending-phone:rider']))->assertForbidden();
        $this->get_($url, $this->token(null, ['something-else']))->assertForbidden();
    }

    // ---- autocomplete ----

    public function test_autocomplete_returns_suggestions_in_the_neutral_shape(): void
    {
        $response = $this->get_('/api/v1/geo/autocomplete?q=Abovyan&lat=40.18&lng=44.51&session=abc_123-X')->assertOk();

        $this->assertCount(3, $response->json('suggestions'));
        $response->assertJsonStructure(['suggestions' => [['place_id', 'text', 'primary_text', 'secondary_text']]])
            ->assertJsonPath('suggestions.0.place_id', 'fake-abovyan-1')
            ->assertJsonPath('suggestions.0.primary_text', 'Abovyan street 1');
    }

    public function test_nothing_found_is_an_empty_list_not_an_error(): void
    {
        $this->get_('/api/v1/geo/autocomplete?q=nowhere')->assertOk()->assertExactJson(['suggestions' => []]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badAutocomplete(): array
    {
        return [
            'no query' => ['', 'q'],
            'one character' => ['q=a', 'q'],
            'too long' => ['q='.str_repeat('a', 121), 'q'],
            'array query' => ['q[]=abc', 'q'],
            'lat without lng' => ['q=abc&lat=40.1', 'lng'],
            'lng without lat' => ['q=abc&lng=44.5', 'lat'],
            'lat out of range' => ['q=abc&lat=91&lng=44.5', 'lat'],
            'lng out of range' => ['q=abc&lat=40.1&lng=181', 'lng'],
            'lat not a number' => ['q=abc&lat=north&lng=44.5', 'lat'],
            'session with a slash' => ['q=abc&session=a/b', 'session'],
            'session too long' => ['q=abc&session='.str_repeat('s', 65), 'session'],
        ];
    }

    #[DataProvider('badAutocomplete')]
    public function test_autocomplete_validates_every_parameter_and_never_calls_the_provider(string $query, string $field): void
    {
        $this->asGoogle();
        Http::fake();

        $this->get_('/api/v1/geo/autocomplete?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);

        Http::assertNothingSent();
    }

    public function test_a_query_of_exactly_two_and_exactly_120_characters_is_accepted(): void
    {
        $this->get_('/api/v1/geo/autocomplete?q=ab')->assertOk();
        $this->get_('/api/v1/geo/autocomplete?q='.str_repeat('a', 120))->assertOk();
    }

    // ---- place details ----

    public function test_place_details_return_the_place_with_its_coordinates(): void
    {
        $this->get_('/api/v1/geo/places/fake-abovyan-1')->assertOk()
            ->assertJsonStructure(['place' => ['place_id', 'name', 'address', 'location' => ['lat', 'lng']]])
            ->assertJsonPath('place.place_id', 'fake-abovyan-1');
    }

    public function test_an_unknown_place_is_404_place_not_found(): void
    {
        $this->get_('/api/v1/geo/places/fake-nowhere-1')->assertNotFound()
            ->assertExactJson(['message' => 'We could not find that place.', 'code' => 'place_not_found']);
    }

    public function test_a_place_id_cannot_climb_out_of_the_path(): void
    {
        $this->asGoogle();
        Http::fake();

        foreach (['/api/v1/geo/places/..', '/api/v1/geo/places/.hidden', '/api/v1/geo/places/a%2Fb', '/api/v1/geo/places/'.str_repeat('a', 201)] as $url) {
            $this->get_($url)->assertNotFound();
        }

        Http::assertNothingSent();
    }

    public function test_the_session_must_be_well_formed_on_place_details_too(): void
    {
        $this->get_('/api/v1/geo/places/fake-abovyan-1?session=a/b')->assertUnprocessable()->assertJsonValidationErrors('session');
    }

    // ---- reverse ----

    public function test_reverse_geocode_names_the_pin(): void
    {
        $this->get_('/api/v1/geo/reverse?lat=40.18&lng=44.51')->assertOk()
            ->assertJsonPath('place.location', ['lat' => 40.18, 'lng' => 44.51])
            ->assertJsonPath('place.address', 'Fake street, near 40.18, 44.51');
    }

    public function test_reverse_geocode_with_nothing_there_is_404_place_not_found(): void
    {
        $this->get_('/api/v1/geo/reverse?lat=0&lng=0')->assertNotFound()->assertJsonPath('code', 'place_not_found');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badPoints(): array
    {
        return [
            'nothing' => ['/api/v1/geo/reverse'],
            'lat only' => ['/api/v1/geo/reverse?lat=40.1'],
            'lat out of range' => ['/api/v1/geo/reverse?lat=-90.5&lng=10'],
            'lng out of range' => ['/api/v1/geo/reverse?lat=10&lng=-180.1'],
            'text' => ['/api/v1/geo/reverse?lat=a&lng=b'],
            'route without to' => ['/api/v1/geo/route?from=40.18,44.51'],
            'route without from' => ['/api/v1/geo/route?to=40.18,44.51'],
            'route swapped order, out of range' => ['/api/v1/geo/route?from=44.51,200&to=40.18,44.51'],
            'route point with spaces and words' => ['/api/v1/geo/route?from=here&to=there'],
            'route point with three numbers' => ['/api/v1/geo/route?from=40.18,44.51,3&to=40.19,44.52'],
            'route array' => ['/api/v1/geo/route?from[]=40,44&to=40.19,44.52'],
        ];
    }

    #[DataProvider('badPoints')]
    public function test_bad_coordinates_are_422_and_never_reach_the_provider(string $url): void
    {
        $this->asGoogle();
        Http::fake();

        $this->get_($url)->assertUnprocessable();

        Http::assertNothingSent();
    }

    // ---- route ----

    public function test_a_route_has_distance_duration_and_a_polyline(): void
    {
        $response = $this->get_('/api/v1/geo/route?from=40.18,44.51&to=40.19,44.51')->assertOk();

        $response->assertJsonStructure(['route' => ['distance_meters', 'duration_seconds', 'polyline']]);
        $this->assertSame(1446, $response->json('route.distance_meters'));
        $this->assertSame(174, $response->json('route.duration_seconds'));
        $this->assertIsString($response->json('route.polyline'));
    }

    public function test_no_route_is_404_route_not_found(): void
    {
        $this->get_('/api/v1/geo/route?from=40.18,44.51&to=0,0')->assertNotFound()
            ->assertExactJson(['message' => 'We could not find a route between these points.', 'code' => 'route_not_found']);
    }

    public function test_the_same_route_asked_twice_costs_one_provider_call(): void
    {
        $this->asGoogle();
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => 1523, 'duration' => '312s', 'polyline' => ['encodedPolyline' => 'abc']]]])]);

        $first = $this->get_('/api/v1/geo/route?from=40.18,44.51&to=40.19,44.52')->assertOk();
        $second = $this->get_('/api/v1/geo/route?from=40.180001,44.510001&to=40.19,44.52')->assertOk();

        Http::assertSentCount(1);
        $this->assertSame($first->json('route'), $second->json('route'));
    }

    public function test_autocomplete_is_not_cached(): void
    {
        $this->asGoogle();
        Http::fake(['places.googleapis.com/*' => Http::response([])]);

        $this->get_('/api/v1/geo/autocomplete?q=abovyan')->assertOk();
        $this->get_('/api/v1/geo/autocomplete?q=abovyan')->assertOk();

        Http::assertSentCount(2);
    }

    // ---- one shape, any provider ----

    /**
     * @return array<string, array{string}>
     */
    public static function providers(): array
    {
        return ['fake' => ['fake'], 'google' => ['google'], '2gis' => ['2gis']];
    }

    #[DataProvider('providers')]
    public function test_every_provider_answers_in_the_one_documented_shape(string $provider): void
    {
        Http::fake([
            'places.googleapis.com/v1/places:autocomplete' => Http::response(['suggestions' => [['placePrediction' => ['placeId' => 'g1', 'text' => ['text' => 'Abovyan St 1, Yerevan']]]]]),
            'places.googleapis.com/v1/places/*' => Http::response(['id' => 'g1', 'displayName' => ['text' => 'Cafe'], 'formattedAddress' => 'Abovyan St 1', 'location' => ['latitude' => 40.18, 'longitude' => 44.51]]),
            'maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => [['place_id' => 'g1', 'formatted_address' => 'Abovyan St 1', 'geometry' => ['location' => ['lat' => 40.18, 'lng' => 44.51]]]]]),
            'routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => 1523, 'duration' => '312s', 'polyline' => ['encodedPolyline' => 'abc']]]]),
            'catalog.api.2gis.com/3.0/suggests*' => Http::response(['meta' => ['code' => 200], 'result' => ['items' => [['id' => 't1', 'name' => 'Abovyan St 1', 'address_name' => 'Yerevan']]]]),
            'catalog.api.2gis.com/*' => Http::response(['meta' => ['code' => 200], 'result' => ['items' => [['id' => 't1', 'name' => 'Cafe', 'full_name' => 'Abovyan St 1', 'address_name' => 'Abovyan St 1', 'point' => ['lat' => 40.18, 'lon' => 44.51]]]]]),
            'routing.api.2gis.com/*' => Http::response(['result' => [['total_distance' => 1523, 'total_duration' => 312, 'maneuvers' => [['outcoming_path' => ['geometry' => [['selection' => 'LINESTRING(44.51 40.18, 44.52 40.19)']]]]]]]]),
        ]);
        config(['services.maps.provider' => $provider, 'services.maps.google.key' => 'k', 'services.maps.2gis.key' => 'k']);
        $placeId = $provider === 'fake' ? 'fake-abovyan-1' : 'g1';
        $place = ['place_id' => 'string|null', 'name' => 'string|null', 'address' => 'string|null', 'location' => ['lat' => 'float', 'lng' => 'float']];

        $this->assertSame(['suggestions' => ['list' => [['place_id' => 'string|null', 'text' => 'string|null', 'primary_text' => 'string|null', 'secondary_text' => 'string|null']]]],
            $this->shape($this->get_('/api/v1/geo/autocomplete?q=abovyan')->assertOk()->json()));
        $this->assertSame(['place' => $place], $this->shape($this->get_("/api/v1/geo/places/{$placeId}")->assertOk()->json()));
        $this->assertSame(['place' => $place], $this->shape($this->get_('/api/v1/geo/reverse?lat=40.18&lng=44.51')->assertOk()->json()));
        $this->assertSame(['route' => ['distance_meters' => 'int', 'duration_seconds' => 'int', 'polyline' => 'string|null']],
            $this->shape($this->get_('/api/v1/geo/route?from=40.18,44.51&to=40.19,44.52')->assertOk()->json()));
    }

    /**
     * The keys and types of an answer, not its values.
     */
    private function shape(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $shape = array_map(fn ($item) => $this->shape($item), $isList ? array_slice($value, 0, 1) : $value);

            return $isList ? ['list' => $shape] : $shape;
        }

        return get_debug_type($value) === 'null' ? 'string|null' : (is_string($value) ? 'string|null' : get_debug_type($value));
    }

    // ---- failures ----

    public function test_a_provider_outage_is_502_with_a_stable_code_and_no_provider_detail(): void
    {
        $this->asGoogle();
        Http::fake(['*' => Http::response(['error' => ['message' => 'API key g-key is invalid']], 403)]);

        foreach (['/api/v1/geo/autocomplete?q=abovyan', '/api/v1/geo/places/ChIJ1', '/api/v1/geo/reverse?lat=40.18&lng=44.51', '/api/v1/geo/route?from=40.18,44.51&to=40.19,44.52'] as $url) {
            $response = $this->get_($url)->assertStatus(502)
                ->assertExactJson(['message' => 'The maps service is not available right now. Please try again.', 'code' => 'geo_provider_unavailable']);
            $this->assertStringNotContainsString('g-key', $response->getContent());
        }
    }

    public function test_an_outage_is_not_cached_so_the_next_call_recovers(): void
    {
        $this->asGoogle();
        Http::fake(['routes.googleapis.com/*' => Http::sequence()->pushStatus(503)->push(['routes' => [['distanceMeters' => 10, 'duration' => '5s', 'polyline' => ['encodedPolyline' => '']]]])]);

        $this->get_('/api/v1/geo/route?from=40.18,44.51&to=40.19,44.52')->assertStatus(502);
        $this->get_('/api/v1/geo/route?from=40.18,44.51&to=40.19,44.52')->assertOk()->assertJsonPath('route.distance_meters', 10);
    }

    public function test_the_error_message_follows_the_app_language(): void
    {
        $this->get_('/api/v1/geo/places/fake-nowhere-1', $this->token())->assertJsonPath('message', 'We could not find that place.');

        $this->withHeader('Accept-Language', 'ru')->getJson('/api/v1/geo/places/fake-nowhere-1', ['Authorization' => 'Bearer '.$this->token()])
            ->assertNotFound()->assertJsonPath('code', 'place_not_found')->assertJsonPath('message', __('We could not find that place.', [], 'ru'));
    }

    // ---- rate limit ----

    public function test_the_budget_is_per_user_and_answers_429_with_retry_after(): void
    {
        config(['security.rate_limits.geo' => 3]);
        $other = User::factory()->rider()->create();
        $token = $this->token();

        foreach (range(1, 3) as $_) {
            $this->get_('/api/v1/geo/reverse?lat=40.18&lng=44.51', $token)->assertOk();
        }

        $blocked = $this->get_('/api/v1/geo/reverse?lat=40.18&lng=44.51', $token)->assertStatus(429);
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
        $this->get_('/api/v1/geo/autocomplete?q=abovyan', $token)->assertStatus(429);

        $this->get_('/api/v1/geo/reverse?lat=40.18&lng=44.51', $this->token($other))->assertOk();
    }

    public function test_requests_the_provider_never_sees_still_count_when_they_are_valid(): void
    {
        // A request refused by validation must not be billed, but it still uses the caller's budget.
        config(['security.rate_limits.geo' => 2]);
        $token = $this->token();

        $this->get_('/api/v1/geo/autocomplete?q=a', $token)->assertUnprocessable();
        $this->get_('/api/v1/geo/autocomplete?q=a', $token)->assertUnprocessable();
        $this->get_('/api/v1/geo/autocomplete?q=abc', $token)->assertStatus(429);
    }

    public function test_the_provider_receives_the_users_input_unchanged(): void
    {
        $this->asTwoGis();
        Http::fake(['catalog.api.2gis.com/*' => Http::response(['meta' => ['code' => 200], 'result' => ['items' => []]])]);

        $this->get_('/api/v1/geo/autocomplete?q='.rawurlencode('Աբովյան 1').'&lat=40.18&lng=44.51')->assertOk();

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['q'] === 'Աբովյան 1' && $query['location'] === '44.51,40.18';
        });
    }
}
