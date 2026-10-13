<?php

namespace App\Support;

use App\Data\GeoPoint;

/**
 * Google's encoded polyline format (precision 5): a path as a short string the apps decode and draw. Google answers
 * with it already; 2GIS answers with WKT, which TwoGisProvider converts here so both providers give the apps the same thing.
 */
final class EncodedPolyline
{
    /**
     * @param  list<GeoPoint>  $points
     */
    public static function encode(array $points): string
    {
        $encoded = '';
        $previousLat = 0;
        $previousLng = 0;

        foreach ($points as $point) {
            $lat = (int) round($point->latitude * 1e5);
            $lng = (int) round($point->longitude * 1e5);
            $encoded .= self::encodeNumber($lat - $previousLat).self::encodeNumber($lng - $previousLng);
            $previousLat = $lat;
            $previousLng = $lng;
        }

        return $encoded;
    }

    /**
     * @return list<GeoPoint>
     */
    public static function decode(string $encoded): array
    {
        $points = [];
        $index = 0;
        $length = strlen($encoded);
        $lat = 0;
        $lng = 0;

        while ($index < $length) {
            $lat += self::decodeNumber($encoded, $index);
            $lng += self::decodeNumber($encoded, $index);
            $points[] = new GeoPoint($lat / 1e5, $lng / 1e5);
        }

        return $points;
    }

    private static function encodeNumber(int $value): string
    {
        $value = $value < 0 ? ~($value << 1) : $value << 1;
        $chunk = '';

        while ($value >= 0x20) {
            $chunk .= chr((0x20 | ($value & 0x1F)) + 63);
            $value >>= 5;
        }

        return $chunk.chr($value + 63);
    }

    private static function decodeNumber(string $encoded, int &$index): int
    {
        $result = 0;
        $shift = 0;

        do {
            $byte = ord($encoded[$index++]) - 63;
            $result |= ($byte & 0x1F) << $shift;
            $shift += 5;
        } while ($byte >= 0x20);

        return ($result & 1) ? ~($result >> 1) : $result >> 1;
    }
}
