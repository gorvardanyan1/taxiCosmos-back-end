<?php

namespace Tests\Unit\Trips;

use App\Data\GeoPoint;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GeoPointTest extends TestCase
{
    public function test_ewkb_round_trips_exactly(): void
    {
        $point = new GeoPoint(40.177200123456, -44.503500654321);

        $this->assertTrue($point->equals(GeoPoint::fromEwkbHex($point->toEwkbHex())));
    }

    public function test_ewkb_is_longitude_first_with_srid_4326(): void
    {
        // Known PostGIS output of ST_AsEWKB(ST_SetSRID(ST_MakePoint(44.5, 40.25), 4326)).
        $this->assertSame('0101000020E610000000000000004046400000000000204440', (new GeoPoint(40.25, 44.5))->toEwkbHex());
    }

    public function test_it_rejects_what_is_not_a_wgs84_point(): void
    {
        foreach (['', 'not hex', '0101000000E610000000000000008046400000000000204440', '0101000020E61000000000000000804640', '0101000020E710000000000000008046400000000000204440'] as $bad) {
            try {
                GeoPoint::fromEwkbHex($bad);
                $this->fail("Accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
