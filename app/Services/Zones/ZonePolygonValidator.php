<?php

namespace App\Services\Zones;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checks a zone polygon sent as GeoJSON (Polygon, MultiPolygon, or a Feature holding one) and
 * returns it normalised. The shape is checked in PHP first (type, closed rings with at least 3
 * distinct points, coordinate ranges, size), then PostGIS decides if it is a valid geometry
 * (ST_IsValid: no self-intersection, no overlapping rings). Errors are reported on the
 * `polygon` field. The GeoJSON only ever reaches SQL as a bound parameter.
 */
final class ZonePolygonValidator
{
    private const FIELD = 'polygon';

    /**
     * @param  mixed  $value  decoded GeoJSON, or a JSON string
     * @return array{type: string, coordinates: array<int, mixed>}
     *
     * @throws ValidationException
     */
    public function validate(mixed $value): array
    {
        $geometry = $this->structure($this->decode($value));

        $this->assertValidGeometry($geometry);

        return $geometry;
    }

    private function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $this->fail('The polygon is not valid JSON.');
        }

        return $value;
    }

    /**
     * @return array{type: string, coordinates: array<int, mixed>}
     */
    private function structure(mixed $geo): array
    {
        if (! is_array($geo)) {
            $this->fail('The polygon must be a GeoJSON object.');
        }

        if (($geo['type'] ?? null) === 'Feature' && is_array($geo['geometry'] ?? null)) {
            $geo = $geo['geometry'];
        }

        $type = $geo['type'] ?? null;
        $coordinates = $geo['coordinates'] ?? null;

        if (! in_array($type, ['Polygon', 'MultiPolygon'], true) || ! is_array($coordinates) || $coordinates === []) {
            $this->fail('The polygon must be a GeoJSON Polygon or MultiPolygon with coordinates.');
        }

        $polygons = $type === 'Polygon' ? [$coordinates] : $coordinates;
        $points = 0;

        foreach ($polygons as $polygon) {
            if (! is_array($polygon) || $polygon === []) {
                $this->fail('Every polygon needs at least one ring of coordinates.');
            }

            foreach ($polygon as $ring) {
                $points += $this->assertRing($ring);
            }
        }

        if ($points > (int) config('taxikosmos.zones.max_vertices')) {
            $this->fail('The polygon has too many points (at most '.config('taxikosmos.zones.max_vertices').').');
        }

        return ['type' => $type, 'coordinates' => $coordinates];
    }

    /**
     * @return int number of positions in the ring
     */
    private function assertRing(mixed $ring): int
    {
        if (! is_array($ring) || ! array_is_list($ring)) {
            $this->fail('A ring must be a list of [longitude, latitude] positions.');
        }

        foreach ($ring as $position) {
            if (! is_array($position) || count($position) < 2 || ! is_numeric($position[0] ?? null) || ! is_numeric($position[1] ?? null)) {
                $this->fail('Every position must be [longitude, latitude] numbers.');
            }

            if (abs((float) $position[0]) > 180 || abs((float) $position[1]) > 90) {
                $this->fail('A position is outside the world: longitude must be within ±180 and latitude within ±90.');
            }
        }

        $distinct = count(array_unique(array_map(fn ($p) => ((float) $p[0]).','.((float) $p[1]), $ring)));

        if (count($ring) < 4 || $distinct < 3) {
            $this->fail('A polygon needs at least 3 distinct points (a ring of at least 4 positions that ends where it starts).');
        }

        $first = $ring[0];
        $last = $ring[count($ring) - 1];

        if ((float) $first[0] !== (float) $last[0] || (float) $first[1] !== (float) $last[1]) {
            $this->fail('A ring must be closed: the last position must equal the first.');
        }

        return count($ring);
    }

    /**
     * @param  array{type: string, coordinates: array<int, mixed>}  $geometry
     */
    private function assertValidGeometry(array $geometry): void
    {
        try {
            // A savepoint when called inside a transaction: a rejected shape must not abort it.
            $row = DB::transaction(fn () => DB::selectOne(
                'SELECT ST_IsValid(g) AS valid, ST_IsValidReason(g) AS reason FROM (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) AS g) AS t',
                [json_encode($geometry)],
            ));
        } catch (QueryException) {
            $this->fail('The polygon is not valid GeoJSON.');
        }

        if (! $row->valid) {
            $this->fail(str_starts_with($row->reason, 'Self-intersection') || str_contains($row->reason, 'Ring Self-intersection')
                ? 'Polygon intersects itself.'
                : 'The polygon is not valid: '.$row->reason.'.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages([self::FIELD => $message]);
    }
}
