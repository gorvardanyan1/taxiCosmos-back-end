<?php

namespace Tests\Feature\Zones;

use App\Data\GeoPoint;
use App\Models\Zone;
use App\Services\Zones\ZoneLocator;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class ZoneLocatorTest extends TestCase
{
    use RefreshDatabase;

    private function locator(): ZoneLocator
    {
        return app(ZoneLocator::class);
    }

    /** Yerevan centre. */
    private function centre(): GeoPoint
    {
        return new GeoPoint(40.18, 44.51);
    }

    public function test_a_point_inside_a_zone_finds_it_and_a_point_outside_finds_nothing(): void
    {
        $zone = Zone::factory()->around(40.18, 44.51, 0.05)->create(['name' => 'Yerevan']);

        $this->assertSame($zone->id, $this->locator()->find($this->centre())?->id);
        $this->assertTrue($this->locator()->serves($this->centre()));

        $outside = new GeoPoint(40.30, 44.51);
        $this->assertNull($this->locator()->find($outside));
        $this->assertFalse($this->locator()->serves($outside));
    }

    public function test_a_point_on_the_edge_or_a_corner_belongs_to_the_zone(): void
    {
        $zone = Zone::factory()->around(40.0, 44.0, 0.5)->create();

        $this->assertSame($zone->id, $this->locator()->find(new GeoPoint(40.0, 44.5))?->id, 'On the east edge.');
        $this->assertSame($zone->id, $this->locator()->find(new GeoPoint(40.5, 44.5))?->id, 'On a corner.');
        $this->assertNull($this->locator()->find(new GeoPoint(40.0, 44.5001)));
    }

    public function test_latitude_and_longitude_are_not_confused(): void
    {
        Zone::factory()->around(40.18, 44.51, 0.05)->create();

        $this->assertNotNull($this->locator()->find(new GeoPoint(latitude: 40.18, longitude: 44.51)));
        $this->assertNull($this->locator()->find(new GeoPoint(latitude: 44.51, longitude: 40.18)), 'Swapped coordinates are somewhere else.');
    }

    public function test_when_zones_overlap_the_highest_priority_wins(): void
    {
        $city = Zone::factory()->around(40.18, 44.51, 0.2)->priority(0)->create(['name' => 'City']);
        $airport = Zone::factory()->around(40.18, 44.51, 0.05)->priority(10)->create(['name' => 'Airport']);
        $middle = Zone::factory()->around(40.18, 44.51, 0.1)->priority(5)->create(['name' => 'Middle']);

        $this->assertSame($airport->id, $this->locator()->find($this->centre())->id);
        $this->assertSame([$airport->id, $middle->id, $city->id], $this->locator()->allAt($this->centre())->pluck('id')->all());

        // Outside the airport, inside the middle ring: the next priority takes over.
        $this->assertSame($middle->id, $this->locator()->find(new GeoPoint(40.18, 44.51 + 0.08))->id);
        $this->assertSame($city->id, $this->locator()->find(new GeoPoint(40.18, 44.51 + 0.15))->id);
    }

    public function test_equal_priorities_pick_the_oldest_zone_every_time(): void
    {
        $first = Zone::factory()->around(40.18, 44.51, 0.1)->priority(3)->create();
        Zone::factory()->around(40.18, 44.51, 0.1)->priority(3)->create();

        foreach (range(1, 3) as $i) {
            $this->assertSame($first->id, $this->locator()->find($this->centre())->id);
        }
    }

    public function test_priority_beats_size_and_creation_order(): void
    {
        Zone::factory()->around(40.18, 44.51, 0.02)->priority(1)->create(['name' => 'Tiny, created first']);
        $big = Zone::factory()->around(40.18, 44.51, 0.5)->priority(2)->create(['name' => 'Big, created last']);

        $this->assertSame($big->id, $this->locator()->find($this->centre())->id);
    }

    public function test_inactive_zones_are_ignored_even_with_a_higher_priority(): void
    {
        $active = Zone::factory()->around(40.18, 44.51, 0.1)->priority(1)->create();
        $inactive = Zone::factory()->around(40.18, 44.51, 0.1)->priority(99)->inactive()->create();

        $this->assertSame($active->id, $this->locator()->find($this->centre())->id);
        $this->assertSame([$active->id], $this->locator()->allAt($this->centre())->pluck('id')->all());

        $active->forceFill(['is_active' => false])->save();
        $this->assertNull($this->locator()->find($this->centre()));
        $this->assertFalse($this->locator()->serves($this->centre()));
        $this->assertNotNull($inactive);
    }

    public function test_a_multipolygon_zone_matches_every_one_of_its_areas_and_not_the_gap(): void
    {
        $zone = Zone::factory()->create(['polygon' => ZoneFactory::fromGeoJson([
            'type' => 'MultiPolygon',
            'coordinates' => [
                [[[44.0, 40.0], [44.2, 40.0], [44.2, 40.2], [44.0, 40.2], [44.0, 40.0]]],
                [[[45.0, 41.0], [45.2, 41.0], [45.2, 41.2], [45.0, 41.2], [45.0, 41.0]]],
            ],
        ])]);

        $this->assertSame($zone->id, $this->locator()->find(new GeoPoint(40.1, 44.1))->id);
        $this->assertSame($zone->id, $this->locator()->find(new GeoPoint(41.1, 45.1))->id);
        $this->assertNull($this->locator()->find(new GeoPoint(40.6, 44.6)), 'The gap between the areas is outside.');
    }

    public function test_a_hole_is_outside_the_zone(): void
    {
        Zone::factory()->create(['polygon' => ZoneFactory::fromGeoJson([
            'type' => 'Polygon',
            'coordinates' => [
                [[44.0, 40.0], [44.4, 40.0], [44.4, 40.4], [44.0, 40.4], [44.0, 40.0]],
                [[44.1, 40.1], [44.3, 40.1], [44.3, 40.3], [44.1, 40.3], [44.1, 40.1]],
            ],
        ])]);

        $this->assertNull($this->locator()->find(new GeoPoint(40.2, 44.2)), 'In the hole.');
        $this->assertNotNull($this->locator()->find(new GeoPoint(40.05, 44.05)), 'In the ring around the hole.');
    }

    public function test_the_lookup_uses_the_spatial_index(): void
    {
        Zone::factory()->count(5)->create();
        DB::statement('SET LOCAL enable_seqscan = off');
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = [$q->sql, $q->bindings];
        });

        $this->locator()->find($this->centre());

        [$sql, $bindings] = $queries[0];
        $plan = collect(DB::select('EXPLAIN '.$sql, $bindings))->pluck('QUERY PLAN')->implode(' ');
        $this->assertStringContainsString('zones_polygon_gist', $plan);
    }

    public function test_the_point_only_reaches_sql_as_bound_numbers(): void
    {
        Zone::factory()->create();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = [$q->sql, $q->bindings];
        });

        $this->locator()->find(new GeoPoint(40.18, 44.51));

        [$sql, $bindings] = $queries[0];
        $this->assertStringNotContainsString('44.51', $sql);
        $this->assertContains(44.51, $bindings);
        $this->assertContains(40.18, $bindings);
    }

    public function test_a_geo_point_rejects_coordinates_outside_the_world(): void
    {
        foreach ([[91, 0], [-91, 0], [0, 181], [0, -181], [NAN, 0], [0, INF]] as [$lat, $lng]) {
            try {
                new GeoPoint($lat, $lng);
                $this->fail("({$lat}, {$lng}) must be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(90.0, (new GeoPoint(90, 180))->latitude);
        $this->assertSame(-180.0, (new GeoPoint(-90, -180))->longitude);
    }
}
