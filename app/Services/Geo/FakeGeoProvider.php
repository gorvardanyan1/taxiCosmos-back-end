<?php

namespace App\Services\Geo;

use App\Contracts\GeoProvider;
use App\Data\GeoEstimate;
use App\Data\GeoPlace;
use App\Data\GeoPoint;
use App\Data\GeoRoute;
use App\Data\GeoSuggestion;
use App\Services\Fares\RouteEstimator;
use App\Support\EncodedPolyline;

/**
 * A maps provider that needs no key and no network (MAPS_PROVIDER=fake), for local development, the test suite and
 * the Cypress e2e environment, like the log driver of the SMS sender. Answers are deterministic and made up: places are
 * scattered around Yerevan, a route is the straight line between the points at the configured detour and speed.
 * "Nothing found" cases are available on purpose: a query or an id containing "nowhere", reverse geocoding at 0,0 and a route to or
 * from 0,0. AppServiceProvider refuses to bind it outside local, testing and e2e.
 */
final class FakeGeoProvider implements GeoProvider
{
    private const CENTRE_LAT = 40.1792;

    private const CENTRE_LNG = 44.4991;

    public function __construct(private readonly RouteEstimator $routes) {}

    public function autocomplete(string $query, ?GeoPoint $near = null, ?string $sessionToken = null): array
    {
        if (str_contains(strtolower($query), 'nowhere')) {
            return [];
        }

        $slug = $this->slug($query);

        return array_map(fn (int $n) => new GeoSuggestion("fake-{$slug}-{$n}", ucfirst($query)." street {$n}, Yerevan", ucfirst($query)." street {$n}", 'Yerevan'), [1, 2, 3]);
    }

    public function placeDetails(string $placeId, ?string $sessionToken = null): ?GeoPlace
    {
        if (str_contains($placeId, 'nowhere') || ! str_starts_with($placeId, 'fake-')) {
            return null;
        }

        return new GeoPlace($placeId, 'Fake place', 'Fake address '.substr($placeId, 5).', Yerevan', $this->scatter($placeId));
    }

    public function geocode(string $address): ?GeoPlace
    {
        if (str_contains(strtolower($address), 'nowhere')) {
            return null;
        }

        return new GeoPlace('fake-'.$this->slug($address).'-1', null, $address.', Yerevan', $this->scatter($address));
    }

    public function reverseGeocode(GeoPoint $point): ?GeoPlace
    {
        if ($point->latitude === 0.0 && $point->longitude === 0.0) {
            return null;
        }

        return new GeoPlace('fake-'.round($point->latitude, 4).'-'.round($point->longitude, 4), null, 'Fake street, near '.round($point->latitude, 4).', '.round($point->longitude, 4), $point);
    }

    public function getRoute(GeoPoint $origin, GeoPoint $destination): ?GeoRoute
    {
        if ($this->isNowhere($origin) || $this->isNowhere($destination)) {
            return null;
        }

        $estimate = $this->routes->estimate($origin, $destination);

        return new GeoRoute($estimate['distance_meters'], $estimate['duration_seconds'], EncodedPolyline::encode([$origin, $destination]));
    }

    public function estimateEta(GeoPoint $origin, GeoPoint $destination): ?GeoEstimate
    {
        return $this->getRoute($origin, $destination)?->estimate();
    }

    private function isNowhere(GeoPoint $point): bool
    {
        return $point->latitude === 0.0 && $point->longitude === 0.0;
    }

    private function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-') ?: 'x';
    }

    /**
     * A repeatable point within about 5 km of the centre of Yerevan, from the text.
     */
    private function scatter(string $seed): GeoPoint
    {
        $hash = crc32($seed);

        return new GeoPoint(round(self::CENTRE_LAT + (($hash & 0xFFFF) / 0xFFFF - 0.5) * 0.08, 5), round(self::CENTRE_LNG + ((($hash >> 16) & 0xFFFF) / 0xFFFF - 0.5) * 0.08, 5));
    }
}
