<?php

namespace App\Services\Geo;

use App\Contracts\GeoProvider;
use App\Data\GeoEstimate;
use App\Data\GeoPlace;
use App\Data\GeoPoint;
use App\Data\GeoRoute;
use Illuminate\Contracts\Cache\Repository;

/**
 * Provider calls cost money, so the two that repeat for nearly the same input are remembered in the cache (Redis in
 * production): reverse geocoding (the pickup pin) and routes / ETAs. Coordinates are rounded (4 decimals, about 11 m) so a pin
 * dragged a metre reuses the answer, and each entry lives for a short, configured time. The key carries the provider and the
 * language, so switching MAPS_PROVIDER or the app language never serves the other's answer. Only answers are remembered:
 * a failure or a "nothing found" is asked again next time. Autocomplete and place details are never cached
 * (a search session is billed as one, and what the user types is rarely repeated).
 */
final class CachingGeoProvider implements GeoProvider
{
    public function __construct(
        private readonly GeoProvider $inner,
        private readonly string $provider,
        private readonly Repository $cache,
    ) {}

    public function autocomplete(string $query, ?GeoPoint $near = null, ?string $sessionToken = null): array
    {
        return $this->inner->autocomplete($query, $near, $sessionToken);
    }

    public function placeDetails(string $placeId, ?string $sessionToken = null): ?GeoPlace
    {
        return $this->inner->placeDetails($placeId, $sessionToken);
    }

    public function geocode(string $address): ?GeoPlace
    {
        return $this->inner->geocode($address);
    }

    public function reverseGeocode(GeoPoint $point): ?GeoPlace
    {
        $cached = $this->remember(
            'reverse:'.$this->round($point),
            (int) config('services.maps.cache.reverse_ttl'),
            fn () => $this->inner->reverseGeocode($point)?->toArray(),
        );

        return $cached === null ? null : GeoPlace::fromArray($cached);
    }

    public function getRoute(GeoPoint $origin, GeoPoint $destination): ?GeoRoute
    {
        $cached = $this->remember(
            'route:'.$this->round($origin).':'.$this->round($destination),
            (int) config('services.maps.cache.route_ttl'),
            fn () => $this->inner->getRoute($origin, $destination)?->toArray(),
        );

        return $cached === null ? null : GeoRoute::fromArray($cached);
    }

    public function estimateEta(GeoPoint $origin, GeoPoint $destination): ?GeoEstimate
    {
        $cached = $this->remember(
            'eta:'.$this->round($origin).':'.$this->round($destination),
            (int) config('services.maps.cache.route_ttl'),
            fn () => $this->inner->estimateEta($origin, $destination)?->toArray(),
        );

        return $cached === null ? null : GeoEstimate::fromArray($cached);
    }

    /**
     * @param  callable(): ?array<string, mixed>  $fetch
     * @return array<string, mixed>|null
     */
    private function remember(string $key, int $ttl, callable $fetch): ?array
    {
        $key = "geo:{$this->provider}:".app()->getLocale().":{$key}";

        if (is_array($hit = $this->cache->get($key))) {
            return $hit;
        }

        $fresh = $fetch();

        if ($fresh !== null) {
            $this->cache->put($key, $fresh, $ttl);
        }

        return $fresh;
    }

    private function round(GeoPoint $point): string
    {
        $decimals = (int) config('services.maps.cache.precision');

        return number_format($point->latitude, $decimals, '.', '').','.number_format($point->longitude, $decimals, '.', '');
    }
}
