<?php

namespace Tests\Unit\Support;

use App\Data\GeoPoint;
use App\Support\EncodedPolyline;
use PHPUnit\Framework\TestCase;

class EncodedPolylineTest extends TestCase
{
    public function test_it_encodes_the_example_from_googles_documentation(): void
    {
        $path = [new GeoPoint(38.5, -120.2), new GeoPoint(40.7, -120.95), new GeoPoint(43.252, -126.453)];

        $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', EncodedPolyline::encode($path));
    }

    public function test_it_decodes_what_it_encodes_to_five_decimals(): void
    {
        $path = [new GeoPoint(40.17921, 44.49912), new GeoPoint(40.18, 44.51), new GeoPoint(-33.86785, 151.20732), new GeoPoint(0.0, 0.0), new GeoPoint(-0.00001, -0.00001)];

        $decoded = EncodedPolyline::decode(EncodedPolyline::encode($path));

        $this->assertCount(5, $decoded);
        foreach ($path as $i => $point) {
            $this->assertEqualsWithDelta($point->latitude, $decoded[$i]->latitude, 0.000001);
            $this->assertEqualsWithDelta($point->longitude, $decoded[$i]->longitude, 0.000001);
        }
    }

    public function test_an_empty_path_is_an_empty_string_and_back(): void
    {
        $this->assertSame('', EncodedPolyline::encode([]));
        $this->assertSame([], EncodedPolyline::decode(''));
    }

    public function test_decoding_googles_example_gives_its_points(): void
    {
        $decoded = EncodedPolyline::decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@');

        $this->assertEqualsWithDelta(38.5, $decoded[0]->latitude, 1e-9);
        $this->assertEqualsWithDelta(-120.2, $decoded[0]->longitude, 1e-9);
        $this->assertEqualsWithDelta(43.252, $decoded[2]->latitude, 1e-9);
        $this->assertEqualsWithDelta(-126.453, $decoded[2]->longitude, 1e-9);
    }
}
