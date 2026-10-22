<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Minimal GeoJSON geometry handling for geofences.
 * Positions are [lng, lat] as per the GeoJSON spec.
 */
final class GeoJson
{
    /**
     * Accepts a GeoJSON Polygon / MultiPolygon geometry, a Feature wrapping one,
     * or a plain list of {lat, lng} points (converted to a Polygon). Returns a validated
     * geometry with closed rings.
     */
    public static function normalise(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            throw new InvalidArgumentException('Polygon must be GeoJSON or a list of points.');
        }

        if (($input['type'] ?? null) === 'Feature') {
            $input = $input['geometry'] ?? [];
        }

        // Plain list of points: [{lat, lng}, ...] or [[lat, lng], ...] is ambiguous, so only objects are accepted.
        if (array_is_list($input) && isset($input[0]['lat'], $input[0]['lng'])) {
            $ring = array_map(fn ($p) => [(float) $p['lng'], (float) $p['lat']], $input);
            $input = ['type' => 'Polygon', 'coordinates' => [$ring]];
        }

        $type = $input['type'] ?? null;
        $coords = $input['coordinates'] ?? null;
        if (! is_array($coords)) {
            throw new InvalidArgumentException('GeoJSON geometry has no coordinates.');
        }

        return match ($type) {
            'Polygon' => ['type' => 'Polygon', 'coordinates' => self::polygon($coords)],
            'MultiPolygon' => ['type' => 'MultiPolygon', 'coordinates' => array_map(fn ($p) => self::polygon($p), $coords)],
            default => throw new InvalidArgumentException('Geometry must be a Polygon or MultiPolygon.'),
        };
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} [minLat, maxLat, minLng, maxLng] */
    public static function bbox(array $geometry): array
    {
        $minLat = 90.0;
        $maxLat = -90.0;
        $minLng = 180.0;
        $maxLng = -180.0;
        foreach (self::polygons($geometry) as $polygon) {
            foreach ($polygon[0] as [$lng, $lat]) {
                $minLat = min($minLat, $lat);
                $maxLat = max($maxLat, $lat);
                $minLng = min($minLng, $lng);
                $maxLng = max($maxLng, $lng);
            }
        }

        return [$minLat, $maxLat, $minLng, $maxLng];
    }

    public static function contains(array $geometry, float $lat, float $lng): bool
    {
        foreach (self::polygons($geometry) as $polygon) {
            if (! self::inRing($polygon[0], $lat, $lng)) {
                continue;
            }
            $inHole = false;
            foreach (array_slice($polygon, 1) as $hole) {
                if (self::inRing($hole, $lat, $lng)) {
                    $inHole = true;
                    break;
                }
            }
            if (! $inHole) {
                return true;
            }
        }

        return false;
    }

    /** Convenience for seeders/tests: rectangle around a centre point, sizes in metres. */
    public static function rectangle(float $lat, float $lng, float $widthM, float $heightM): array
    {
        $dLat = ($heightM / 2) / 111320;
        $dLng = ($widthM / 2) / (111320 * cos(deg2rad($lat)));

        return self::normalise(['type' => 'Polygon', 'coordinates' => [[
            [$lng - $dLng, $lat - $dLat], [$lng + $dLng, $lat - $dLat],
            [$lng + $dLng, $lat + $dLat], [$lng - $dLng, $lat + $dLat],
        ]]]);
    }

    // ------------------------------------------------------------------ internals

    private static function polygons(array $geometry): array
    {
        return $geometry['type'] === 'MultiPolygon' ? $geometry['coordinates'] : [$geometry['coordinates']];
    }

    private static function polygon(array $rings): array
    {
        if (empty($rings)) {
            throw new InvalidArgumentException('Polygon has no rings.');
        }

        return array_map(fn ($ring) => self::ring($ring), $rings);
    }

    private static function ring(array $ring): array
    {
        $out = [];
        foreach ($ring as $pos) {
            if (! is_array($pos) || count($pos) < 2 || ! is_numeric($pos[0]) || ! is_numeric($pos[1])) {
                throw new InvalidArgumentException('Each position must be [lng, lat].');
            }
            [$lng, $lat] = [(float) $pos[0], (float) $pos[1]];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                throw new InvalidArgumentException("Position out of range: [{$lng}, {$lat}]. GeoJSON order is [lng, lat].");
            }
            $out[] = [$lng, $lat];
        }
        if (count($out) && $out[0] !== $out[count($out) - 1]) {
            $out[] = $out[0];
        }
        if (count($out) < 4) {
            throw new InvalidArgumentException('A ring needs at least 3 distinct points.');
        }

        return $out;
    }

    /** Ray casting. */
    private static function inRing(array $ring, float $lat, float $lng): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
