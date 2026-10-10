<?php

namespace Tests\Feature\Zones;

use App\Models\Zone;
use App\Services\Zones\ZonePolygonValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZonePolygonValidatorTest extends TestCase
{
    use RefreshDatabase;

    private const SQUARE = [[44.4, 40.1], [44.6, 40.1], [44.6, 40.3], [44.4, 40.3], [44.4, 40.1]];

    private function validate(mixed $value): array
    {
        return app(ZonePolygonValidator::class)->validate($value);
    }

    private function polygon(array ...$rings): array
    {
        return ['type' => 'Polygon', 'coordinates' => $rings];
    }

    private function messageFor(mixed $value): string
    {
        try {
            $this->validate($value);
        } catch (ValidationException $e) {
            $this->assertSame(['polygon'], array_keys($e->errors()), 'The error is reported on the polygon field.');

            return $e->errors()['polygon'][0];
        }

        $this->fail('The polygon should have been rejected.');
    }

    public function test_a_valid_polygon_multipolygon_feature_and_json_string_are_accepted(): void
    {
        $polygon = $this->polygon(self::SQUARE);

        $this->assertSame($polygon, $this->validate($polygon));
        $this->assertSame($polygon, $this->validate(json_encode($polygon)));
        $this->assertSame($polygon, $this->validate(['type' => 'Feature', 'properties' => [], 'geometry' => $polygon]));
        $multi = ['type' => 'MultiPolygon', 'coordinates' => [[self::SQUARE], [[[45.0, 40.1], [45.2, 40.1], [45.2, 40.3], [45.0, 40.1]]]]];
        $this->assertSame($multi, $this->validate($multi));
    }

    public function test_a_polygon_with_a_hole_is_accepted(): void
    {
        $hole = [[44.45, 40.15], [44.55, 40.15], [44.55, 40.25], [44.45, 40.25], [44.45, 40.15]];

        $this->assertSame('Polygon', $this->validate($this->polygon(self::SQUARE, $hole))['type']);
    }

    public function test_a_self_intersecting_polygon_is_rejected_with_a_clear_message(): void
    {
        $bowtie = $this->polygon([[44.4, 40.1], [44.6, 40.3], [44.6, 40.1], [44.4, 40.3], [44.4, 40.1]]);

        $this->assertSame('Polygon intersects itself.', $this->messageFor($bowtie));
    }

    #[DataProvider('tooFewPoints')]
    public function test_fewer_than_three_points_are_rejected(array $ring): void
    {
        $this->assertStringContainsString('at least 3 distinct points', $this->messageFor($this->polygon($ring)));
    }

    /**
     * @return array<string, array{array<int, array<int, float>>}>
     */
    public static function tooFewPoints(): array
    {
        return [
            'one point' => [[[44.4, 40.1]]],
            'two points' => [[[44.4, 40.1], [44.6, 40.1]]],
            'a closed line (2 distinct points)' => [[[44.4, 40.1], [44.6, 40.1], [44.4, 40.1]]],
            'three positions only' => [[[44.4, 40.1], [44.6, 40.1], [44.4, 40.1], [44.4, 40.1]]],
            'empty ring' => [[]],
        ];
    }

    #[DataProvider('openRings')]
    public function test_an_open_ring_is_rejected(array $open): void
    {
        $this->assertStringContainsString('must be closed', $this->messageFor($this->polygon($open)));
    }

    /**
     * @return array<string, array{array<int, array<int, float>>}>
     */
    public static function openRings(): array
    {
        return [
            'last point differs in both' => [[[44.4, 40.1], [44.6, 40.1], [44.6, 40.3], [44.4, 40.3]]],
            'last point differs only in longitude' => [[[44.4, 40.1], [44.6, 40.1], [44.6, 40.3], [44.5, 40.1]]],
            'last point differs only in latitude' => [[[44.4, 40.1], [44.6, 40.1], [44.6, 40.3], [44.4, 40.2]]],
        ];
    }

    #[DataProvider('badShapes')]
    public function test_malformed_input_is_rejected_on_the_polygon_field(mixed $value): void
    {
        $this->assertNotSame('', $this->messageFor($value));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badShapes(): array
    {
        $ring = self::SQUARE;

        return [
            'null' => [null],
            'a number' => [42],
            'plain text' => ['not json'],
            'a json string that is not an object' => ['"hello"'],
            'a Point' => [['type' => 'Point', 'coordinates' => [44.5, 40.2]]],
            'a LineString' => [['type' => 'LineString', 'coordinates' => [[44.4, 40.1], [44.6, 40.3]]]],
            'a GeometryCollection' => [['type' => 'GeometryCollection', 'geometries' => []]],
            'no type' => [['coordinates' => [$ring]]],
            'no coordinates' => [['type' => 'Polygon']],
            'empty coordinates' => [['type' => 'Polygon', 'coordinates' => []]],
            'a polygon without rings' => [['type' => 'MultiPolygon', 'coordinates' => [[]]]],
            'a ring that is not a list' => [['type' => 'Polygon', 'coordinates' => [['a' => [44.4, 40.1]]]]],
            'positions as strings' => [['type' => 'Polygon', 'coordinates' => [[['x', 'y'], ['x', 'y'], ['x', 'y'], ['x', 'y']]]]],
            'a position with one number' => [['type' => 'Polygon', 'coordinates' => [[[44.4], [44.6], [44.6], [44.4]]]]],
            'longitude 181' => [['type' => 'Polygon', 'coordinates' => [[[181.0, 40.1], [44.6, 40.1], [44.6, 40.3], [181.0, 40.1]]]]],
            'longitude -181' => [['type' => 'Polygon', 'coordinates' => [[[-181.0, 40.1], [44.6, 40.1], [44.6, 40.3], [-181.0, 40.1]]]]],
            'latitude 91' => [['type' => 'Polygon', 'coordinates' => [[[44.4, 91.0], [44.6, 40.1], [44.6, 40.3], [44.4, 91.0]]]]],
            'latitude -91' => [['type' => 'Polygon', 'coordinates' => [[[44.4, -91.0], [44.6, 40.1], [44.6, 40.3], [44.4, -91.0]]]]],
            'swapped lat/lng that leaves the world' => [['type' => 'Polygon', 'coordinates' => [[[40.1, 144.4], [40.1, 144.6], [40.3, 144.6], [40.1, 144.4]]]]],
            'SQL in the type' => [['type' => "Polygon'); DROP TABLE zones;--", 'coordinates' => [$ring]]],
        ];
    }

    public function test_the_boundaries_of_the_world_are_accepted(): void
    {
        $edge = $this->polygon([[-180, -90], [180, -90], [180, 90], [-180, 90], [-180, -90]]);

        $this->assertSame('Polygon', $this->validate($edge)['type']);
    }

    public function test_the_point_limit_is_enforced_at_the_boundary(): void
    {
        config(['taxikosmos.zones.max_vertices' => 5]);
        $this->validate($this->polygon(self::SQUARE));

        config(['taxikosmos.zones.max_vertices' => 4]);
        $this->assertStringContainsString('too many points (at most 4)', $this->messageFor($this->polygon(self::SQUARE)));
    }

    public function test_overlapping_polygons_in_a_multipolygon_are_rejected_by_postgis(): void
    {
        $a = [[44.4, 40.1], [44.6, 40.1], [44.6, 40.3], [44.4, 40.3], [44.4, 40.1]];
        $b = [[44.5, 40.2], [44.7, 40.2], [44.7, 40.4], [44.5, 40.4], [44.5, 40.2]];

        // PostGIS reports overlapping parts as a self-intersection.
        $this->assertSame('Polygon intersects itself.', $this->messageFor(['type' => 'MultiPolygon', 'coordinates' => [[$a], [$b]]]));
    }

    public function test_a_hole_outside_the_shell_is_rejected_by_postgis(): void
    {
        $outside = [[45.0, 40.1], [45.2, 40.1], [45.2, 40.3], [45.0, 40.1]];

        $this->assertStringStartsWith('The polygon is not valid: ', $this->messageFor($this->polygon(self::SQUARE, $outside)));
    }

    public function test_a_rejected_shape_does_not_break_the_surrounding_transaction(): void
    {
        DB::beginTransaction();
        try {
            $this->messageFor($this->polygon([[44.4, 40.1], [44.6, 40.3], [44.6, 40.1], [44.4, 40.3], [44.4, 40.1]]));

            // The transaction is still usable: no "current transaction is aborted".
            $this->assertSame(0, Zone::count());
            $this->validate($this->polygon(self::SQUARE));
        } finally {
            DB::rollBack();
        }
    }
}
