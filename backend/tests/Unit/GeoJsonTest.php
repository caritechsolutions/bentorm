<?php

namespace Tests\Unit;

use App\Support\GeoJson;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GeoJsonTest extends TestCase
{
    public function test_polygon_with_hole(): void
    {
        $g = GeoJson::normalise(['type' => 'Polygon', 'coordinates' => [
            [[0, 0], [10, 0], [10, 10], [0, 10]],
            [[4, 4], [6, 4], [6, 6], [4, 6]],
        ]]);
        $this->assertTrue(GeoJson::contains($g, 1, 1));
        $this->assertFalse(GeoJson::contains($g, 5, 5), 'inside the hole');
        $this->assertFalse(GeoJson::contains($g, 11, 5));
        $this->assertCount(5, $g['coordinates'][0], 'ring closed');
    }

    public function test_multipolygon_and_point_list(): void
    {
        $mp = GeoJson::normalise(['type' => 'MultiPolygon', 'coordinates' => [
            [[[0, 0], [1, 0], [1, 1]]],
            [[[5, 5], [6, 5], [6, 6], [5, 6]]],
        ]]);
        $this->assertTrue(GeoJson::contains($mp, 5.5, 5.5));

        $pts = GeoJson::normalise([['lat' => 13.0, 'lng' => -59.7], ['lat' => 13.0, 'lng' => -59.4], ['lat' => 13.4, 'lng' => -59.4], ['lat' => 13.4, 'lng' => -59.7]]);
        $this->assertTrue(GeoJson::contains($pts, 13.1, -59.6));
        $this->assertSame([13.0, 13.4, -59.7, -59.4], GeoJson::bbox($pts));
    }

    public function test_rectangle_helper(): void
    {
        $r = GeoJson::rectangle(13.0746, -59.4925, 1000, 1000);
        $this->assertTrue(GeoJson::contains($r, 13.0746, -59.4925));
        $this->assertFalse(GeoJson::contains($r, 13.09, -59.4925));
    }

    public function test_out_of_range_position_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GeoJson::normalise(['type' => 'Polygon', 'coordinates' => [[[-59, 200], [1, 1], [2, 2]]]]);
    }
}
