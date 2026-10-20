<?php

namespace App\Services\Geo;

use App\Data\GeoPlace;
use App\Data\GeoPoint;
use App\Data\GeoRoute;
use App\Data\GeoSuggestion;
use App\Services\Fares\RouteEstimator;
use App\Support\EncodedPolyline;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * OpenStreetMap, free of charge: Nominatim for address search and geocoding, OSRM for routes, both through Laravel's HTTP client.
 * The base URLs, the User-Agent (Nominatim's usage policy requires one that identifies the application) and the country filter come
 * from config, so launch can point at servers of our own with no code change. The public servers are for development: Nominatim is
 * called at most once a second (NominatimThrottle) and search answers are remembered, because the policy also forbids hammering it.
 *
 * OSM has no search sessions (the token is ignored). A place id is the OSM object, `N`/`W`/`R` + its number, which Nominatim's
 * lookup endpoint resolves again. OSRM's `polyline` geometry is already Google's precision-5 encoding. When OSRM cannot be
 * reached or answers with an error, getRoute() does not fail: the straight line made longer by the fares' detour factor stands in
 * for the route, flagged `estimated`, so a fare can still be quoted.
 */
final class OsmProvider extends HttpGeoProvider
{
    private const PLACE_ID = '/^[NWR]\d{1,18}$/';

    private const OBJECT_TYPES = ['node' => 'N', 'way' => 'W', 'relation' => 'R'];

    private const SEARCH_LIMIT = 5;

    /**
     * @param  array{nominatim_url: string, osrm_url: string, user_agent: string, country_codes: string, search_cache_ttl: int}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly NominatimThrottle $throttle,
        private readonly RouteEstimator $fallback,
    ) {}

    protected function name(): string
    {
        return 'osm';
    }

    protected function http(): PendingRequest
    {
        return parent::http()->withUserAgent($this->config['user_agent']);
    }

    public function autocomplete(string $query, ?GeoPoint $near = null, ?string $sessionToken = null): array
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        $params = [
            'q' => $query,
            'limit' => self::SEARCH_LIMIT,
            ...$this->searchParams(),
            // Nearer places rank first, but a place outside the box is still found (bounded=0).
            ...($near === null ? [] : ['viewbox' => $this->viewbox($near), 'bounded' => 0]),
        ];
        $key = 'suggest:'.app()->getLocale().':'.mb_strtolower($query).':'.($near === null ? '-' : number_format($near->latitude, 2, '.', '').','.number_format($near->longitude, 2, '.', ''));

        $rows = $this->remember($key, fn () => $this->nominatim('autocomplete', '/search', $params));

        $result = [];
        foreach ($rows as $row) {
            $place = $this->placeFrom($row);

            if ($place === null || $place->placeId === null) {
                continue;
            }

            $primary = $place->name ?? $this->streetOf($row) ?? trim(explode(',', $place->address)[0]);
            $locality = $this->localityOf($row);
            $result[] = new GeoSuggestion($place->placeId, $place->address, $primary, $locality !== $primary ? $locality : null);
        }

        return $result;
    }

    public function placeDetails(string $placeId, ?string $sessionToken = null): ?GeoPlace
    {
        if (preg_match(self::PLACE_ID, $placeId) !== 1) {
            return null;
        }

        $rows = $this->remember('place:'.app()->getLocale().':'.$placeId, fn () => $this->nominatim('place_details', '/lookup', ['osm_ids' => $placeId, ...$this->detailParams()]));

        return isset($rows[0]) ? $this->placeFrom($rows[0]) : null;
    }

    public function geocode(string $address): ?GeoPlace
    {
        $address = trim((string) preg_replace('/\s+/u', ' ', $address));
        $rows = $this->remember('geocode:'.app()->getLocale().':'.mb_strtolower($address), fn () => $this->nominatim('geocode', '/search', ['q' => $address, 'limit' => 1, ...$this->searchParams()]));

        return isset($rows[0]) ? $this->placeFrom($rows[0]) : null;
    }

    public function reverseGeocode(GeoPoint $point): ?GeoPlace
    {
        $rows = $this->nominatim('reverse_geocode', '/reverse', ['lat' => $point->latitude, 'lon' => $point->longitude, 'zoom' => 18, ...$this->detailParams()], single: true);
        $place = isset($rows[0]) ? $this->placeFrom($rows[0]) : null;

        // The place's own point is the building's centre, not the spot that was asked about: the pin stays where the rider put it.
        return $place === null ? null : new GeoPlace($place->placeId, $place->name, $place->address, $point);
    }

    public function getRoute(GeoPoint $origin, GeoPoint $destination): ?GeoRoute
    {
        $coordinates = $origin->longitude.','.$origin->latitude.';'.$destination->longitude.','.$destination->latitude;

        try {
            $response = $this->http()->get(rtrim($this->config['osrm_url'], '/').'/route/v1/driving/'.$coordinates, ['overview' => 'full', 'geometries' => 'polyline', 'steps' => 'false', 'alternatives' => 'false']);
        } catch (ConnectionException) {
            return $this->straightLine($origin, $destination, null);
        }

        $code = $response->json('code');
        $route = $response->json('routes.0');

        // "No route" is an answer (OSRM says it with a 200 or a 400); anything else that is not a route is an outage.
        if (in_array($code, ['NoRoute', 'NoSegment'], true)) {
            return null;
        }

        if ($response->failed() || $code !== 'Ok' || ! is_array($route) || ! isset($route['distance'], $route['duration'], $route['geometry'])) {
            return $this->straightLine($origin, $destination, $response->status());
        }

        return new GeoRoute((int) round($route['distance']), (int) round($route['duration']), (string) $route['geometry']);
    }

    /**
     * @return list<array<string, mixed>> the rows Nominatim answered ([] for "nothing found")
     */
    private function nominatim(string $operation, string $path, array $params, bool $single = false): array
    {
        $json = $this->throttle->run(fn () => $this->send($operation, fn (PendingRequest $http) => $http->get(rtrim($this->config['nominatim_url'], '/').$path, [...$params, 'format' => 'jsonv2', 'accept-language' => app()->getLocale()]))->json());

        if (! is_array($json)) {
            return [];
        }

        // /reverse answers one object (or {"error": ...}), the other endpoints a list.
        if ($single) {
            return isset($json['error']) ? [] : [$json];
        }

        return array_values(array_filter($json, 'is_array'));
    }

    /**
     * @param  callable(): list<array<string, mixed>>  $fetch
     * @return list<array<string, mixed>>
     */
    private function remember(string $key, callable $fetch): array
    {
        $ttl = $this->config['search_cache_ttl'];
        $key = 'geo:osm:'.sha1($key);

        if ($ttl > 0 && is_array($hit = Cache::get($key))) {
            return $hit;
        }

        $rows = $fetch();

        // Only answers are remembered: "nothing found" is asked again.
        if ($ttl > 0 && $rows !== []) {
            Cache::put($key, $rows, $ttl);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function searchParams(): array
    {
        return array_filter(['addressdetails' => 1, 'countrycodes' => $this->config['country_codes']], fn ($value) => $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function detailParams(): array
    {
        return ['addressdetails' => 1];
    }

    /**
     * Nominatim's box (left,top,right,bottom as lon,lat) around the user, as wide as the autocomplete bias radius.
     */
    private function viewbox(GeoPoint $centre): string
    {
        $radius = (int) config('services.maps.bias_radius_meters');
        $dLat = $radius / 111_320;
        $dLng = $radius / (111_320 * max(0.01, cos(deg2rad($centre->latitude))));

        return implode(',', [
            round(max(-180, $centre->longitude - $dLng), 6),
            round(min(90, $centre->latitude + $dLat), 6),
            round(min(180, $centre->longitude + $dLng), 6),
            round(max(-90, $centre->latitude - $dLat), 6),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function placeFrom(array $row): ?GeoPlace
    {
        if (! is_string($row['display_name'] ?? null) || ! is_numeric($row['lat'] ?? null) || ! is_numeric($row['lon'] ?? null)) {
            return null;
        }

        $prefix = self::OBJECT_TYPES[$row['osm_type'] ?? ''] ?? null;
        $id = $prefix !== null && is_numeric($row['osm_id'] ?? null) ? $prefix.(int) $row['osm_id'] : null;
        $name = is_string($row['name'] ?? null) && $row['name'] !== '' ? $row['name'] : null;

        return new GeoPlace($id, $name, $row['display_name'], new GeoPoint((float) $row['lat'], (float) $row['lon']));
    }

    /**
     * "Abovyan Street 12": the address line of a place without a name.
     *
     * @param  array<string, mixed>  $row
     */
    private function streetOf(array $row): ?string
    {
        $road = $row['address']['road'] ?? null;

        if (! is_string($road) || $road === '') {
            return null;
        }

        $number = $row['address']['house_number'] ?? null;

        return is_string($number) && $number !== '' ? $road.' '.$number : $road;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function localityOf(array $row): ?string
    {
        foreach (['city', 'town', 'village', 'suburb', 'state'] as $field) {
            if (is_string($row['address'][$field] ?? null) && $row['address'][$field] !== '') {
                return $row['address'][$field];
            }
        }

        return null;
    }

    private function straightLine(GeoPoint $origin, GeoPoint $destination, ?int $status): GeoRoute
    {
        Log::warning('Maps provider call failed', ['provider' => $this->name(), 'operation' => 'get_route', 'status' => $status, 'fallback' => 'straight_line']);

        $estimate = $this->fallback->estimate($origin, $destination);

        return new GeoRoute($estimate['distance_meters'], $estimate['duration_seconds'], EncodedPolyline::encode([$origin, $destination]), estimated: true);
    }
}
