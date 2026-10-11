<?php

namespace App\Casts;

use App\Data\GeoPoint;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A geography(Point, 4326) column as a GeoPoint. Both directions use EWKB hex, which PostGIS reads
 * and writes natively, so the attribute is a plain string on the wire and no raw SQL is involved.
 *
 * @implements CastsAttributes<GeoPoint, GeoPoint>
 */
class GeoPointCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?GeoPoint
    {
        return $value === null ? null : GeoPoint::fromEwkbHex($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof GeoPoint) {
            throw new InvalidArgumentException("{$key} must be a GeoPoint.");
        }

        return $value->toEwkbHex();
    }
}
