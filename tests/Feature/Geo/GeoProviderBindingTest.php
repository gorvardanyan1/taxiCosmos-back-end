<?php

namespace Tests\Feature\Geo;

use App\Contracts\GeoProvider;
use App\Data\GeoPoint;
use App\Services\Geo\CachingGeoProvider;
use App\Services\Geo\FakeGeoProvider;
use App\Services\Geo\GoogleMapsProvider;
use App\Services\Geo\OsmProvider;
use App\Services\Geo\TwoGisProvider;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * P5-T1: MAPS_PROVIDER picks the implementation behind the GeoProvider interface.
 */
class GeoProviderBindingTest extends TestCase
{
    private function inner(): object
    {
        $provider = app(GeoProvider::class);
        $this->assertInstanceOf(CachingGeoProvider::class, $provider, 'every provider sits behind the cache');

        return (new ReflectionProperty($provider, 'inner'))->getValue($provider);
    }

    public function test_each_name_resolves_to_its_provider(): void
    {
        foreach (['osm' => OsmProvider::class, 'google' => GoogleMapsProvider::class, '2gis' => TwoGisProvider::class, 'fake' => FakeGeoProvider::class] as $name => $class) {
            config(['services.maps.provider' => $name]);

            $this->assertInstanceOf($class, $this->inner(), $name);
        }
    }

    public function test_osm_needs_no_key_and_reads_its_servers_and_user_agent_from_config(): void
    {
        config([
            'services.maps.provider' => 'osm',
            'services.maps.osm.nominatim_url' => 'https://nominatim.mine.test',
            'services.maps.osm.osrm_url' => 'https://osrm.mine.test',
            'services.maps.osm.user_agent' => 'Mine/1.0 (me@mine.test)',
            'services.maps.google.key' => null,
            'services.maps.2gis.key' => null,
        ]);
        Http::fake([
            'nominatim.mine.test/*' => Http::response([]),
            'osrm.mine.test/*' => Http::response(['code' => 'NoRoute']),
        ]);

        $provider = app(GeoProvider::class);
        $provider->geocode('anywhere');
        $provider->getRoute(new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.52));

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://nominatim.mine.test/search') && $request['q'] === 'anywhere' && $request->header('User-Agent')[0] === 'Mine/1.0 (me@mine.test)');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://osrm.mine.test/route/v1/driving/') && $request->header('User-Agent')[0] === 'Mine/1.0 (me@mine.test)');
    }

    public function test_the_tests_run_on_the_fake_provider_without_any_key(): void
    {
        $this->assertSame('fake', config('services.maps.provider'));
        $this->assertInstanceOf(FakeGeoProvider::class, $this->inner());
    }

    public function test_an_unknown_name_fails_loudly(): void
    {
        config(['services.maps.provider' => 'mapbox']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown MAPS_PROVIDER "mapbox"');

        app(GeoProvider::class);
    }

    public function test_the_fake_provider_is_refused_in_production(): void
    {
        config(['services.maps.provider' => 'fake']);
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only for local, testing and e2e');

        app(GeoProvider::class);
    }

    public function test_the_keys_come_from_config(): void
    {
        config(['services.maps.provider' => 'google', 'services.maps.google.key' => 'abc']);
        $google = $this->inner();
        $this->assertSame('abc', (new ReflectionProperty($google, 'key'))->getValue($google));

        config(['services.maps.provider' => '2gis', 'services.maps.2gis.key' => 'xyz']);
        $twoGis = $this->inner();
        $this->assertSame('xyz', (new ReflectionProperty($twoGis, 'key'))->getValue($twoGis));
    }
}
