<?php

namespace App\Support;

/** Google encoded-polyline decoder. Returns [[lng, lat], ...] (GeoJSON order, same as OSRM geometry). */
final class Polyline
{
    public static function decode(string $encoded): array
    {
        $points = [];
        $index = $lat = $lng = 0;
        $len = strlen($encoded);
        while ($index < $len) {
            foreach (['lat', 'lng'] as $axis) {
                $result = $shift = 0;
                do {
                    $b = ord($encoded[$index++]) - 63;
                    $result |= ($b & 0x1F) << $shift;
                    $shift += 5;
                } while ($b >= 0x20 && $index < $len);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                $$axis += $delta;
            }
            $points[] = [round($lng * 1e-5, 5), round($lat * 1e-5, 5)];
        }

        return $points;
    }
}
