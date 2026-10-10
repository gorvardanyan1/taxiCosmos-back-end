<?php

namespace App\Data;

use InvalidArgumentException;

/**
 * A WGS84 point (EPSG:4326). Longitude first in PostGIS, latitude first in speech: this class takes
 * them by name so the two can never be swapped by accident.
 */
final readonly class GeoPoint
{
    public function __construct(public float $latitude, public float $longitude)
    {
        if (! is_finite($latitude) || $latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException('Latitude must be between -90 and 90.');
        }

        if (! is_finite($longitude) || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Longitude must be between -180 and 180.');
        }
    }
}
