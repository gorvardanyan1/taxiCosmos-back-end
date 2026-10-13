<?php

namespace App\Services\Geo;

use App\Data\GeoEstimate;
use App\Data\GeoPlace;
use App\Data\GeoPoint;
use App\Data\GeoRoute;
use App\Data\GeoSuggestion;
use Illuminate\Http\Client\PendingRequest;

/**
 * Google Maps Platform through its current APIs, called with Laravel's HTTP client (no SDK): Places API (New) for
 * autocomplete and details, the Geocoding API for geocode and reverse geocode, the Routes API for routes.
 * The session token goes to autocomplete and to the details call that ends the session, so Google bills the whole
 * search as one session. The key is sent in a header, never in a URL.
 */
final class GoogleMapsProvider extends HttpGeoProvider
{
    private const PLACES = 'https://places.googleapis.com/v1';

    private const GEOCODING = 'https://maps.googleapis.com/maps/api/geocode/json';

    // Places API (New) rejects a location-bias circle larger than 50 km.
    private const MAX_BIAS_RADIUS = 50000;

    private const ROUTES = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    public function __construct(private readonly string $key) {}

    protected function name(): string
    {
        return 'google';
    }

    public function autocomplete(string $query, ?GeoPoint $near = null, ?string $sessionToken = null): array
    {
        $body = array_filter([
            'input' => $query,
            'sessionToken' => $sessionToken,
            'languageCode' => $this->language(),
            // Bias, not a restriction: a rider can still search for another city.
            'locationBias' => $near === null ? null : ['circle' => ['center' => ['latitude' => $near->latitude, 'longitude' => $near->longitude], 'radius' => (float) min(self::MAX_BIAS_RADIUS, (int) config('services.maps.bias_radius_meters'))]],
        ], fn ($value) => $value !== null);

        $suggestions = $this->send('autocomplete', fn (PendingRequest $http) => $http->withHeaders($this->headers())->post(self::PLACES.'/places:autocomplete', $body))->json('suggestions', []);

        $result = [];
        foreach ($suggestions as $suggestion) {
            $prediction = $suggestion['placePrediction'] ?? null;
            $id = $prediction['placeId'] ?? null;
            $text = $prediction['text']['text'] ?? null;

            if (! is_string($id) || ! is_string($text)) {
                continue; // a query suggestion ("coffee near me") is not a place
            }

            $result[] = new GeoSuggestion($id, $text, $prediction['structuredFormat']['mainText']['text'] ?? $text, $prediction['structuredFormat']['secondaryText']['text'] ?? null);
        }

        return $result;
    }

    public function placeDetails(string $placeId, ?string $sessionToken = null): ?GeoPlace
    {
        $response = $this->send('place_details', fn (PendingRequest $http) => $http->withHeaders($this->headers('id,displayName,formattedAddress,location'))
            ->get(self::PLACES.'/places/'.rawurlencode($placeId), array_filter(['sessionToken' => $sessionToken, 'languageCode' => $this->language()])), allowNotFound: true);

        if ($response === null) {
            return null;
        }

        $place = $response->json();

        return $this->place($place['id'] ?? $placeId, $place['displayName']['text'] ?? null, $place['formattedAddress'] ?? null, $place['location'] ?? null);
    }

    public function geocode(string $address): ?GeoPlace
    {
        return $this->geocoding('geocode', ['address' => $address]);
    }

    public function reverseGeocode(GeoPoint $point): ?GeoPlace
    {
        return $this->geocoding('reverse_geocode', ['latlng' => $point->latitude.','.$point->longitude]);
    }

    public function getRoute(GeoPoint $origin, GeoPoint $destination): ?GeoRoute
    {
        return $this->route('get_route', $origin, $destination, 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline');
    }

    public function estimateEta(GeoPoint $origin, GeoPoint $destination): ?GeoEstimate
    {
        // Without the polyline in the field mask Google answers faster and bills the cheaper SKU.
        return $this->route('estimate_eta', $origin, $destination, 'routes.distanceMeters,routes.duration')?->estimate();
    }

    private function route(string $operation, GeoPoint $origin, GeoPoint $destination, string $fields): ?GeoRoute
    {
        $waypoint = fn (GeoPoint $p) => ['location' => ['latLng' => ['latitude' => $p->latitude, 'longitude' => $p->longitude]]];
        $body = ['origin' => $waypoint($origin), 'destination' => $waypoint($destination), 'travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_AWARE', 'languageCode' => $this->language(), 'units' => 'METRIC'];

        $route = $this->send($operation, fn (PendingRequest $http) => $http->withHeaders($this->headers($fields))->post(self::ROUTES, $body))->json('routes.0');

        if (! is_array($route)) {
            return null; // Google answers {} when there is no route
        }

        // Durations come as "1234s"; a zero distance is left out of the answer.
        return new GeoRoute((int) ($route['distanceMeters'] ?? 0), (int) round((float) ($route['duration'] ?? 0)), $route['polyline']['encodedPolyline'] ?? '');
    }

    /**
     * @param  array<string, string>  $query
     */
    private function geocoding(string $operation, array $query): ?GeoPlace
    {
        $json = $this->send($operation, fn (PendingRequest $http) => $http->get(self::GEOCODING, [...$query, 'key' => $this->key, 'language' => $this->language()]))->json();
        $status = $json['status'] ?? null;

        if ($status === 'ZERO_RESULTS') {
            return null;
        }

        // The Geocoding API reports quota and key problems with HTTP 200 and a status in the body.
        if ($status !== 'OK') {
            $this->fail($operation, null);
        }

        $first = $json['results'][0] ?? [];

        return $this->place($first['place_id'] ?? null, null, $first['formatted_address'] ?? null, isset($first['geometry']['location']) ? ['latitude' => $first['geometry']['location']['lat'], 'longitude' => $first['geometry']['location']['lng']] : null);
    }

    /**
     * @param  array{latitude?: float, longitude?: float}|null  $location
     */
    private function place(?string $id, ?string $name, ?string $address, ?array $location): ?GeoPlace
    {
        if ($address === null || ! isset($location['latitude'], $location['longitude'])) {
            return null;
        }

        return new GeoPlace($id, $name, $address, new GeoPoint((float) $location['latitude'], (float) $location['longitude']));
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $fieldMask = null): array
    {
        return array_filter(['X-Goog-Api-Key' => $this->key, 'X-Goog-FieldMask' => $fieldMask]);
    }

    private function language(): string
    {
        return app()->getLocale();
    }
}
