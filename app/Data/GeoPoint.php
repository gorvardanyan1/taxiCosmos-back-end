<?php

namespace App\Data;

use InvalidArgumentException;

/**
 * A WGS84 point (EPSG:4326). Longitude first in PostGIS, latitude first in speech: this class takes
 * them by name so the two can never be swapped by accident.
 */
final readonly class GeoPoint
{
    private const SRID = 4326;

    private const EWKB_POINT_WITH_SRID = 0x20000001;

    public function __construct(public float $latitude, public float $longitude)
    {
        if (! is_finite($latitude) || $latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException('Latitude must be between -90 and 90.');
        }

        if (! is_finite($longitude) || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Longitude must be between -180 and 180.');
        }
    }

    /**
     * EWKB (hex) with SRID 4326: the form PostGIS accepts as input and returns for a geography
     * column, so a point round-trips through the database without any SQL function.
     */
    public function toEwkbHex(): string
    {
        return strtoupper(bin2hex(pack('CVV', 1, self::EWKB_POINT_WITH_SRID, self::SRID).pack('e2', $this->longitude, $this->latitude)));
    }

    /**
     * @throws InvalidArgumentException when the value is not a little-endian EWKB point in 4326
     */
    public static function fromEwkbHex(string $hex): self
    {
        $binary = ctype_xdigit($hex) && strlen($hex) === 50 ? hex2bin($hex) : false;

        if ($binary === false) {
            throw new InvalidArgumentException('Not a PostGIS point.');
        }

        $header = unpack('Corder/Vtype/Vsrid', $binary);

        if ($header['order'] !== 1 || $header['type'] !== self::EWKB_POINT_WITH_SRID || $header['srid'] !== self::SRID) {
            throw new InvalidArgumentException('Not a PostGIS point in WGS84.');
        }

        $coordinates = unpack('elongitude/elatitude', $binary, 9);

        return new self($coordinates['latitude'], $coordinates['longitude']);
    }

    public function equals(self $other): bool
    {
        return $this->latitude === $other->latitude && $this->longitude === $other->longitude;
    }
}
