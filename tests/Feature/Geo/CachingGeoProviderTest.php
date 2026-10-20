<?php

namespace Tests\Feature\Geo;

use App\Contracts\GeoProvider;
use App\Data\GeoEstimate;
use App\Data\GeoPlace;
use App\Data\GeoPoint;
use App\Data\GeoRoute;
use App\Exceptions\GeoException;
use App\Services\Geo\CachingGeoProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

/**
 * P5-T1: provider calls are cached where they repeat (reverse geocode, routes, ETAs) and nowhere else.
 */
class CachingGeoProviderTest extends TestCase
{
    private Repository $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.maps.cache' => ['precision' => 4, 'reverse_ttl' => 600, 'route_ttl' => 300]]);
        $this->store = new Repository(new ArrayStore);
    }

    private function inner(): object
    {
        return new class implements GeoProvider
        {
            /** @var array<string, int> */
            public array $calls = [];

            public bool $fail = false;

            public bool $empty = false;

            public bool $estimated = false;

            private function hit(string $name): void
            {
                $this->calls[$name] = ($this->calls[$name] ?? 0) + 1;

                if ($this->fail) {
                    throw GeoException::providerUnavailable();
                }
            }

            public function autocomplete(string $query, ?GeoPoint $near = null, ?string $sessionToken = null): array
            {
                $this->hit('autocomplete');

                return [];
            }

            public function placeDetails(string $placeId, ?string $sessionToken = null): ?GeoPlace
            {
                $this->hit('details');

                return null;
            }

            public function geocode(string $address): ?GeoPlace
            {
                $this->hit('geocode');

                return null;
            }

            public function reverseGeocode(GeoPoint $point): ?GeoPlace
            {
                $this->hit('reverse');

                return $this->empty ? null : new GeoPlace('p', 'N', 'Address '.$this->calls['reverse'], $point);
            }

            public function getRoute(GeoPoint $origin, GeoPoint $destination): ?GeoRoute
            {
                $this->hit('route');

                return $this->empty ? null : new GeoRoute(1000 + $this->calls['route'], 200, 'abc', $this->estimated);
            }

            public function estimateEta(GeoPoint $origin, GeoPoint $destination): ?GeoEstimate
            {
                $this->hit('eta');

                return $this->empty ? null : new GeoEstimate(900, 100 + $this->calls['eta'], $this->estimated);
            }
        };
    }

    private function cache(object $inner, string $provider = 'google'): CachingGeoProvider
    {
        return new CachingGeoProvider($inner, $provider, $this->store);
    }

    public function test_the_second_reverse_geocode_of_the_same_spot_comes_from_the_cache(): void
    {
        $inner = $this->inner();
        $cache = $this->cache($inner);

        $first = $cache->reverseGeocode(new GeoPoint(40.18, 44.51));
        $second = $cache->reverseGeocode(new GeoPoint(40.18, 44.51));

        $this->assertSame(1, $inner->calls['reverse']);
        $this->assertSame('Address 1', $second->address);
        $this->assertEquals($first, $second);
        $this->assertInstanceOf(GeoPlace::class, $second);
    }

    public function test_coordinates_are_rounded_so_a_pin_moved_a_metre_reuses_the_answer_but_a_street_away_does_not(): void
    {
        $inner = $this->inner();
        $cache = $this->cache($inner);

        $cache->reverseGeocode(new GeoPoint(40.180001, 44.510001));
        $cache->reverseGeocode(new GeoPoint(40.180004, 44.509996)); // ~0.5 m away
        $this->assertSame(1, $inner->calls['reverse']);

        $cache->reverseGeocode(new GeoPoint(40.1802, 44.5100)); // ~22 m away
        $this->assertSame(2, $inner->calls['reverse']);
    }

    public function test_routes_and_etas_are_cached_per_direction_and_apart_from_each_other(): void
    {
        $inner = $this->inner();
        $cache = $this->cache($inner);
        $a = new GeoPoint(40.18, 44.51);
        $b = new GeoPoint(40.19, 44.52);

        $route = $cache->getRoute($a, $b);
        $this->assertSame($route->distanceMeters, $cache->getRoute($a, $b)->distanceMeters);
        $this->assertSame(1, $inner->calls['route']);

        $cache->getRoute($b, $a);
        $this->assertSame(2, $inner->calls['route'], 'A to B is not B to A (one-way streets)');

        $eta = $cache->estimateEta($a, $b);
        $this->assertSame($eta->durationSeconds, $cache->estimateEta($a, $b)->durationSeconds);
        $this->assertSame(1, $inner->calls['eta'], 'an ETA is its own entry, not a route');
        $this->assertSame(2, $inner->calls['route']);
    }

    public function test_the_same_origin_with_another_destination_is_another_entry(): void
    {
        $inner = $this->inner();
        $cache = $this->cache($inner);
        $a = new GeoPoint(40.18, 44.51);
        $b = new GeoPoint(40.19, 44.52);
        $c = new GeoPoint(40.25, 44.60);

        $toB = $cache->getRoute($a, $b);
        $toC = $cache->getRoute($a, $c);
        $this->assertSame(2, $inner->calls['route'], 'a route to C is not the cached route to B');
        $this->assertNotSame($toB->distanceMeters, $toC->distanceMeters);

        $etaB = $cache->estimateEta($a, $b);
        $etaC = $cache->estimateEta($a, $c);
        $this->assertSame(2, $inner->calls['eta']);
        $this->assertNotSame($etaB->durationSeconds, $etaC->durationSeconds);

        $this->assertSame($toB->distanceMeters, $cache->getRoute($a, $b)->distanceMeters, 'each is still served from its own entry');
        $this->assertSame(2, $inner->calls['route']);
    }

    public function test_a_cached_route_keeps_every_field(): void
    {
        $cache = $this->cache($this->inner());
        $a = new GeoPoint(40.18, 44.51);
        $b = new GeoPoint(40.19, 44.52);

        $cache->getRoute($a, $b);

        $this->assertSame(['distance_meters' => 1001, 'duration_seconds' => 200, 'polyline' => 'abc'], $cache->getRoute($a, $b)->toArray());
    }

    public function test_the_entry_expires_after_its_ttl(): void
    {
        $inner = $this->inner();
        $cache = $this->cache($inner);
        $point = new GeoPoint(40.18, 44.51);

        $this->travelTo(now());
        $cache->reverseGeocode($point);
        $this->travel(599)->seconds();
        $cache->reverseGeocode($point);
        $this->assertSame(1, $inner->calls['reverse']);

        $this->travel(2)->seconds();
        $cache->reverseGeocode($point);
        $this->assertSame(2, $inner->calls['reverse'], 'after reverse_ttl it is asked again');
    }

    public function test_a_zero_ttl_turns_caching_off(): void
    {
        config(['services.maps.cache.route_ttl' => 0]);
        $inner = $this->inner();
        $cache = $this->cache($inner);

        $cache->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52));
        $cache->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52));

        $this->assertSame(2, $inner->calls['route']);
    }

    public function test_the_provider_and_the_language_are_part_of_the_key(): void
    {
        $inner = $this->inner();
        $point = new GeoPoint(40.18, 44.51);

        $this->cache($inner, 'google')->reverseGeocode($point);
        $this->cache($inner, '2gis')->reverseGeocode($point);
        $this->assertSame(2, $inner->calls['reverse'], 'switching provider does not serve the other one\'s answer');

        app()->setLocale('ru');
        $this->cache($inner, 'google')->reverseGeocode($point);
        $this->assertSame(3, $inner->calls['reverse'], 'an address in Russian is not served in English');

        $this->cache($inner, 'google')->reverseGeocode($point);
        $this->assertSame(3, $inner->calls['reverse']);
    }

    public function test_nothing_found_and_failures_are_not_cached(): void
    {
        $inner = $this->inner();
        $cache = $this->cache($inner);
        $point = new GeoPoint(40.18, 44.51);

        $inner->empty = true;
        $this->assertNull($cache->reverseGeocode($point));
        $this->assertNull($cache->reverseGeocode($point));
        $this->assertSame(2, $inner->calls['reverse'], 'a miss is asked again');

        $inner->empty = false;
        $inner->fail = true;
        try {
            $cache->reverseGeocode($point);
            $this->fail('the failure must pass through');
        } catch (GeoException) {
        }
        $inner->fail = false;
        $this->assertNotNull($cache->reverseGeocode($point), 'an earlier failure left nothing behind');
        $this->assertSame(4, $inner->calls['reverse']);
    }

    public function test_a_straight_line_estimate_is_returned_but_never_remembered_so_the_real_route_is_tried_again(): void
    {
        $inner = $this->inner();
        $inner->estimated = true;
        $cache = $this->cache($inner, 'osm');
        $a = new GeoPoint(40.18, 44.51);
        $b = new GeoPoint(40.19, 44.52);

        $this->assertTrue($cache->getRoute($a, $b)->estimated);
        $this->assertTrue($cache->estimateEta($a, $b)->estimated);
        $cache->getRoute($a, $b);
        $cache->estimateEta($a, $b);
        $this->assertSame(2, $inner->calls['route']);
        $this->assertSame(2, $inner->calls['eta']);

        $inner->estimated = false; // OSRM is back
        $route = $cache->getRoute($a, $b);
        $this->assertFalse($route->estimated);
        $this->assertSame(3, $inner->calls['route']);
        $cache->getRoute($a, $b);
        $this->assertSame(3, $inner->calls['route'], 'a real route is remembered as before');
    }

    public function test_autocomplete_details_and_geocode_always_reach_the_provider(): void
    {
        $inner = $this->inner();
        $cache = $this->cache($inner);

        foreach (range(1, 2) as $_) {
            $cache->autocomplete('abovyan', null, 's');
            $cache->placeDetails('x', 's');
            $cache->geocode('Abovyan 1');
        }

        $this->assertSame(['autocomplete' => 2, 'details' => 2, 'geocode' => 2], $inner->calls);
    }
}
