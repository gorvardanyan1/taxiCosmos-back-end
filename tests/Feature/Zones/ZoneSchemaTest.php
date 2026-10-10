<?php

namespace Tests\Feature\Zones;

use App\Models\DriverProfile;
use App\Models\Zone;
use Database\Factories\ZoneFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ZoneSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function insertRaw(string $geoJson, array $extra = []): void
    {
        $columns = ['name' => 'Raw', 'code' => 'RAW-'.uniqid(), 'timezone' => 'Asia/Yerevan', 'currency' => 'AMD', 'priority' => 0, ...$extra];
        // A savepoint, so a refused row does not abort the test's transaction.
        DB::transaction(fn () => DB::insert(
            'INSERT INTO zones (name, code, timezone, currency, priority, polygon, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ST_GeomFromEWKT(?), now(), now())',
            [$columns['name'], $columns['code'], $columns['timezone'], $columns['currency'], $columns['priority'], $geoJson],
        ));
    }

    public function test_the_polygon_column_is_a_multipolygon_in_wgs84(): void
    {
        $column = DB::selectOne("SELECT type, srid FROM geometry_columns WHERE f_table_name = 'zones' AND f_geometry_column = 'polygon'");

        $this->assertSame('MULTIPOLYGON', $column->type);
        $this->assertSame(4326, $column->srid);
    }

    public function test_the_polygon_has_a_gist_index_the_planner_uses_for_point_lookups(): void
    {
        $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'zones' AND indexname = 'zones_polygon_gist'");
        $this->assertStringContainsString('USING gist (polygon)', $index->indexdef);

        Zone::factory()->count(3)->create();
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select('EXPLAIN SELECT id FROM zones WHERE ST_Covers(polygon, ST_SetSRID(ST_MakePoint(44.51, 40.18), 4326))'))->pluck('QUERY PLAN')->implode(' ');

        $this->assertStringContainsString('zones_polygon_gist', $plan);
    }

    public function test_the_database_refuses_invalid_empty_and_wrongly_typed_geometry(): void
    {
        $bowtie = 'SRID=4326;MULTIPOLYGON(((0 0,2 2,2 0,0 2,0 0)))';
        $empty = 'SRID=4326;MULTIPOLYGON EMPTY';
        $cases = ['self-intersecting' => $bowtie, 'empty' => $empty];

        foreach ($cases as $name => $ewkt) {
            try {
                $this->insertRaw($ewkt);
                $this->fail("A {$name} polygon must be refused.");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode(), $name);
            }
        }

        foreach (['the wrong SRID' => 'SRID=3857;MULTIPOLYGON(((0 0,1 0,1 1,0 0)))', 'a point' => 'SRID=4326;POINT(1 1)'] as $name => $ewkt) {
            try {
                $this->insertRaw($ewkt);
                $this->fail("{$name} must be refused by the column type.");
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['22023', 'XX000'], $name);
            }
        }

        $this->assertSame(0, Zone::count());
        // PostGIS promotes a single Polygon to the column's MultiPolygon type.
        $this->insertRaw('SRID=4326;POLYGON((0 0,1 0,1 1,0 0))');
        $this->assertSame('ST_MultiPolygon', DB::selectOne('SELECT GeometryType(polygon) AS t, ST_GeometryType(polygon) AS st FROM zones')->st);
    }

    public function test_codes_are_unique_and_priority_is_bounded(): void
    {
        Zone::factory()->create(['code' => 'YEREVAN']);

        foreach ([['code' => 'YEREVAN'], ['priority' => -1], ['priority' => 1001]] as $bad) {
            try {
                DB::transaction(fn () => Zone::factory()->create($bad));
                $this->fail('Expected a constraint violation.');
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['23505', '23514']);
            }
        }

        Zone::factory()->create(['priority' => 0]);
        Zone::factory()->create(['priority' => 1000]);
        $this->assertSame(3, Zone::count());
    }

    public function test_a_new_zone_is_active_with_priority_zero(): void
    {
        $zone = Zone::factory()->create()->fresh();

        $this->assertTrue($zone->is_active);
        $this->assertSame(0, $zone->priority);
        $this->assertSame('Asia/Yerevan', $zone->timezone);
        $this->assertSame('AMD', $zone->currency);
    }

    public function test_zones_are_never_deleted_the_model_refuses_and_a_driver_home_zone_is_restricted(): void
    {
        $zone = Zone::factory()->create();

        try {
            $zone->delete();
            $this->fail('Deleting a zone must be refused.');
        } catch (LogicException $e) {
            $this->assertSame('Zones are deactivated, never deleted.', $e->getMessage());
        }

        $driver = DriverProfile::factory()->create();
        $driver->forceFill(['home_zone_id' => $zone->id])->save();
        try {
            DB::transaction(fn () => DB::delete('DELETE FROM zones WHERE id = ?', [$zone->id]));
            $this->fail('A zone referenced by a driver cannot be removed even with raw SQL.');
        } catch (QueryException $e) {
            $this->assertContains($e->getCode(), ['23001', '23503']);
        }

        $this->assertNotNull(Zone::find($zone->id));
    }

    public function test_a_driver_cannot_have_a_home_zone_that_does_not_exist(): void
    {
        $driver = DriverProfile::factory()->create();

        try {
            DB::transaction(fn () => $driver->forceFill(['home_zone_id' => 987654])->save());
            $this->fail('An unknown zone must be refused.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }
    }

    public function test_the_polygon_and_state_cannot_be_mass_assigned_and_the_polygon_is_hidden(): void
    {
        $zone = new Zone(['name' => 'X', 'timezone' => 'UTC', 'currency' => 'USD', 'priority' => 3, 'polygon' => 'x', 'code' => 'HACK', 'is_active' => false]);

        $this->assertNull($zone->code);
        $this->assertTrue($zone->is_active, 'Activation goes through ZoneService.');
        $this->assertNull($zone->getAttribute('polygon'));

        $saved = Zone::factory()->create();
        $this->assertArrayNotHasKey('polygon', $saved->fresh()->toArray());
    }

    public function test_the_factory_polygon_is_a_valid_square_around_its_centre(): void
    {
        Zone::factory()->around(40.18, 44.51, 0.05)->create();

        $row = DB::selectOne('SELECT ST_IsValid(polygon) AS valid, ST_NPoints(polygon) AS points, ST_X(ST_Centroid(polygon)) AS lng, ST_Y(ST_Centroid(polygon)) AS lat FROM zones');

        $this->assertTrue($row->valid);
        $this->assertSame(5, $row->points);
        $this->assertEqualsWithDelta(44.51, $row->lng, 1e-9);
        $this->assertEqualsWithDelta(40.18, $row->lat, 1e-9);
        $this->assertNotNull(ZoneFactory::squareGeoJson(0, 0));
    }
}
