<?php

namespace App\Services\Geo;

use App\Data\GeoPlace;
use App\Data\GeoPoint;
use App\Data\GeoRoute;
use App\Data\GeoSuggestion;
use App\Support\EncodedPolyline;
use Illuminate\Http\Client\PendingRequest;

/**
 * 2GIS through its current APIs, called with Laravel's HTTP client (no SDK): the Suggest API for autocomplete, the Places
 * API (items/byid) for details, the Geocoder API for geocode and reverse geocode, the Routing API (7.0.0) for routes.
 * 2GIS has no search sessions, so the session token is accepted and ignored. Coordinates are `lon,lat` in 2GIS requests.
 * The route's path comes back as WKT; it is converted to the encoded polyline the apps get from Google too.
 */
final class TwoGisProvider extends HttpGeoProvider
{
    private const CATALOG = 'https://catalog.api.2gis.com/3.0';

    private const ROUTING = 'https://routing.api.2gis.com/routing/7.0.0/global';

    public function __construct(private readonly string $key) {}

    protected function name(): string
    {
        return '2gis';
    }

    public function autocomplete(string $query, ?GeoPoint $near = null, ?string $sessionToken = null): array
    {
        $params = array_filter([
            'q' => $query,
            'key' => $this->key,
            // Where the user is: nearer places rank first (2GIS wants longitude first).
            'location' => $near === null ? null : $near->longitude.','.$near->latitude,
            // "Route endpoint": addresses and the places people ride to and from.
            'suggest_type' => 'route_endpoint',
        ], fn ($value) => $value !== null);

        $items = $this->items($this->send('autocomplete', fn (PendingRequest $http) => $http->get(self::CATALOG.'/suggests', $params))->json());

        $result = [];
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $name = $item['name'] ?? null;

            if (! is_string($id) || ! is_string($name)) {
                continue; // a suggested search phrase, not a place
            }

            $text = is_string($item['full_name'] ?? null) ? $item['full_name'] : $name;
            $address = $item['address_name'] ?? null;
            $result[] = new GeoSuggestion($id, $text, $name, is_string($address) && $address !== $name ? $address : null);
        }

        return $result;
    }

    public function placeDetails(string $placeId, ?string $sessionToken = null): ?GeoPlace
    {
        $params = ['id' => $placeId, 'key' => $this->key, 'fields' => 'items.point,items.address_name,items.full_name'];

        return $this->firstPlace($this->items($this->send('place_details', fn (PendingRequest $http) => $http->get(self::CATALOG.'/items/byid', $params))->json()));
    }

    public function geocode(string $address): ?GeoPlace
    {
        $params = ['q' => $address, 'key' => $this->key, 'fields' => 'items.point'];

        return $this->firstPlace($this->items($this->send('geocode', fn (PendingRequest $http) => $http->get(self::CATALOG.'/items/geocode', $params))->json()));
    }

    public function reverseGeocode(GeoPoint $point): ?GeoPlace
    {
        $params = ['lon' => $point->longitude, 'lat' => $point->latitude, 'key' => $this->key, 'fields' => 'items.point'];
        $place = $this->firstPlace($this->items($this->send('reverse_geocode', fn (PendingRequest $http) => $http->get(self::CATALOG.'/items/geocode', $params))->json()));

        // The item's own point can be its centre, not the spot that was asked about: the pin stays where the rider put it.
        return $place === null ? null : new GeoPlace($place->placeId, $place->name, $place->address, $point);
    }

    public function getRoute(GeoPoint $origin, GeoPoint $destination): ?GeoRoute
    {
        $body = [
            'points' => [
                ['type' => 'walking', 'lon' => $origin->longitude, 'lat' => $origin->latitude, 'start' => true],
                ['type' => 'walking', 'lon' => $destination->longitude, 'lat' => $destination->latitude, 'start' => false],
            ],
            'transport' => 'driving',
            'route_mode' => 'fastest',
            'traffic_mode' => 'jam',
        ];

        $route = $this->send('get_route', fn (PendingRequest $http) => $http->post(self::ROUTING.'?'.http_build_query(['key' => $this->key]), $body))->json('result.0');

        if (! is_array($route) || ! isset($route['total_distance'], $route['total_duration'])) {
            return null;
        }

        return new GeoRoute((int) $route['total_distance'], (int) $route['total_duration'], EncodedPolyline::encode($this->path($route)));
    }

    /**
     * The items of a Catalog API answer. 2GIS reports "nothing found" in `meta` (code 404) with a normal HTTP status;
     * any other meta error is a failure.
     *
     * @param  array<string, mixed>|null  $json
     * @return list<array<string, mixed>>
     */
    private function items(?array $json): array
    {
        $code = (int) ($json['meta']['code'] ?? 200);

        if ($code === 404) {
            return [];
        }

        if ($code >= 400) {
            $this->fail('catalog', $code);
        }

        return array_values($json['result']['items'] ?? []);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function firstPlace(array $items): ?GeoPlace
    {
        $item = $items[0] ?? [];
        $address = $item['full_name'] ?? $item['address_name'] ?? $item['name'] ?? null;

        if (! is_string($address) || ! isset($item['point']['lat'], $item['point']['lon'])) {
            return null;
        }

        return new GeoPlace(isset($item['id']) ? (string) $item['id'] : null, is_string($item['name'] ?? null) ? $item['name'] : null, $address, new GeoPoint((float) $item['point']['lat'], (float) $item['point']['lon']));
    }

    /**
     * The route's points in order, from the WKT LINESTRINGs of its maneuvers (`lon lat [altitude]` each).
     *
     * @param  array<string, mixed>  $route
     * @return list<GeoPoint>
     */
    private function path(array $route): array
    {
        $points = [];

        foreach ($route['maneuvers'] ?? [] as $maneuver) {
            foreach ($maneuver['outcoming_path']['geometry'] ?? [] as $geometry) {
                if (! is_string($selection = $geometry['selection'] ?? null) || preg_match_all('/(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)(?:\s+-?\d+(?:\.\d+)?)?\s*[,)]/', $selection, $matches, PREG_SET_ORDER) === 0) {
                    continue;
                }

                foreach ($matches as $match) {
                    $point = new GeoPoint((float) $match[2], (float) $match[1]);
                    // Consecutive maneuvers share their joint: keep it once.
                    if ($points === [] || ! end($points)->equals($point)) {
                        $points[] = $point;
                    }
                }
            }
        }

        return $points;
    }
}
