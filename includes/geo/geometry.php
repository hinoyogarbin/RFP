<?php
/**
 * Geometry helpers for the CENRO Polygon Management module.
 *
 * Geometries are handled throughout as plain PHP arrays shaped like
 * GeoJSON geometry objects:
 *
 *   ['type' => 'Polygon',      'coordinates' => [ ring, hole, ... ]]
 *   ['type' => 'MultiPolygon', 'coordinates' => [ [ring, hole...], ... ]]
 *
 * A ring is an array of [x, y] pairs. After CRS conversion, x = longitude
 * and y = latitude (GeoJSON order), both in decimal degrees on WGS 84.
 *
 * Pure PHP - no GEOS/PostGIS/composer dependency.
 */

const GEO_EARTH_RADIUS = 6378137.0; // WGS 84 semi-major axis, metres

/**
 * Ensures a ring is explicitly closed (first point repeated at the end).
 */
function geoCloseRing(array $ring): array
{
    $count = count($ring);
    if ($count < 3) {
        return $ring;
    }

    $first = $ring[0];
    $last  = $ring[$count - 1];

    if (abs($first[0] - $last[0]) > 1e-12 || abs($first[1] - $last[1]) > 1e-12) {
        $ring[] = $first;
    }

    return $ring;
}

/**
 * Planar signed area of a ring. Sign indicates winding direction:
 * positive = counter-clockwise, negative = clockwise.
 * Used only for ring orientation (Shapefile holes), never for real area.
 */
function geoRingSignedArea(array $ring): float
{
    $count = count($ring);
    if ($count < 3) {
        return 0.0;
    }

    $sum = 0.0;
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $sum += ($ring[$j][0] * $ring[$i][1]) - ($ring[$i][0] * $ring[$j][1]);
    }

    return $sum / 2.0;
}

/**
 * Geodesic area of a single ring in square metres, using the spherical
 * excess method (the same approach Leaflet.GeometryUtil and OpenLayers
 * use). Accurate to well under 1% for reforestation-sized parcels, and
 * vastly more correct than treating degrees as a flat plane.
 *
 * Expects [lon, lat] pairs in degrees. Returns an UNSIGNED area.
 */
function geoRingGeodesicArea(array $ring): float
{
    $ring  = geoCloseRing($ring);
    $count = count($ring);
    if ($count < 4) {
        return 0.0;
    }

    $total = 0.0;
    for ($i = 0; $i < $count - 1; $i++) {
        $p1 = $ring[$i];
        $p2 = $ring[$i + 1];

        $lon1 = deg2rad((float)$p1[0]);
        $lat1 = deg2rad((float)$p1[1]);
        $lon2 = deg2rad((float)$p2[0]);
        $lat2 = deg2rad((float)$p2[1]);

        $total += ($lon2 - $lon1) * (2 + sin($lat1) + sin($lat2));
    }

    $area = $total * GEO_EARTH_RADIUS * GEO_EARTH_RADIUS / 2.0;

    return abs($area);
}

/**
 * Geodesic area of a whole Polygon / MultiPolygon in square metres.
 * Holes (interior rings) are subtracted.
 */
function geoGeometryAreaSqm(array $geometry): float
{
    $type = $geometry['type'] ?? '';
    $coordinates = $geometry['coordinates'] ?? [];

    if ($type === 'Polygon') {
        $polygons = [$coordinates];
    } elseif ($type === 'MultiPolygon') {
        $polygons = $coordinates;
    } else {
        return 0.0;
    }

    $area = 0.0;
    foreach ($polygons as $rings) {
        foreach ($rings as $index => $ring) {
            $ringArea = geoRingGeodesicArea($ring);
            // First ring is the outer boundary; the rest are holes.
            $area += ($index === 0) ? $ringArea : -$ringArea;
        }
    }

    return max(0.0, $area);
}

/**
 * Converts square metres to hectares.
 */
function geoSqmToHectares(float $sqm): float
{
    return $sqm / 10000.0;
}

/**
 * Walks every coordinate pair of a geometry and applies a callback.
 * The callback receives [x, y] and must return a new [x, y].
 * Used to run CRS conversion across an entire polygon.
 */
function geoTransformGeometry(array $geometry, callable $fn): array
{
    $type = $geometry['type'] ?? '';
    $coordinates = $geometry['coordinates'] ?? [];

    if ($type === 'Polygon') {
        foreach ($coordinates as $r => $ring) {
            foreach ($ring as $p => $point) {
                $coordinates[$r][$p] = $fn($point);
            }
        }
    } elseif ($type === 'MultiPolygon') {
        foreach ($coordinates as $poly => $rings) {
            foreach ($rings as $r => $ring) {
                foreach ($ring as $p => $point) {
                    $coordinates[$poly][$r][$p] = $fn($point);
                }
            }
        }
    }

    $geometry['coordinates'] = $coordinates;
    return $geometry;
}

/**
 * Returns every ring in a geometry as a flat list.
 */
function geoAllRings(array $geometry): array
{
    $type = $geometry['type'] ?? '';
    $coordinates = $geometry['coordinates'] ?? [];

    if ($type === 'Polygon') {
        return $coordinates;
    }

    if ($type === 'MultiPolygon') {
        $rings = [];
        foreach ($coordinates as $polygonRings) {
            foreach ($polygonRings as $ring) {
                $rings[] = $ring;
            }
        }
        return $rings;
    }

    return [];
}

/**
 * Bounding box of a geometry as ['min_x','min_y','max_x','max_y'],
 * or null when the geometry has no usable coordinates.
 */
function geoBoundingBox(array $geometry): ?array
{
    $minX = $minY = INF;
    $maxX = $maxY = -INF;
    $found = false;

    foreach (geoAllRings($geometry) as $ring) {
        foreach ($ring as $point) {
            if (!isset($point[0], $point[1])) {
                continue;
            }
            $x = (float)$point[0];
            $y = (float)$point[1];
            if (!is_finite($x) || !is_finite($y)) {
                continue;
            }
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
            $found = true;
        }
    }

    if (!$found) {
        return null;
    }

    return ['min_x' => $minX, 'min_y' => $minY, 'max_x' => $maxX, 'max_y' => $maxY];
}

/**
 * Area-weighted centroid of a geometry's outer rings, returned as
 * ['x' => lon, 'y' => lat]. Falls back to the bounding-box centre if
 * the rings are degenerate. Used purely for map convenience.
 */
function geoCentroid(array $geometry): ?array
{
    $type = $geometry['type'] ?? '';
    $coordinates = $geometry['coordinates'] ?? [];

    if ($type === 'Polygon') {
        $polygons = [$coordinates];
    } elseif ($type === 'MultiPolygon') {
        $polygons = $coordinates;
    } else {
        return null;
    }

    $sumX = 0.0;
    $sumY = 0.0;
    $sumA = 0.0;

    foreach ($polygons as $rings) {
        if (empty($rings[0])) {
            continue;
        }
        $ring  = geoCloseRing($rings[0]);
        $count = count($ring);

        $cx = 0.0;
        $cy = 0.0;
        $a  = 0.0;

        for ($i = 0; $i < $count - 1; $i++) {
            $x0 = (float)$ring[$i][0];
            $y0 = (float)$ring[$i][1];
            $x1 = (float)$ring[$i + 1][0];
            $y1 = (float)$ring[$i + 1][1];

            $cross = ($x0 * $y1) - ($x1 * $y0);
            $a  += $cross;
            $cx += ($x0 + $x1) * $cross;
            $cy += ($y0 + $y1) * $cross;
        }

        $a = $a / 2.0;
        if (abs($a) > 1e-12) {
            $sumX += ($cx / (6.0 * $a)) * abs($a);
            $sumY += ($cy / (6.0 * $a)) * abs($a);
            $sumA += abs($a);
        }
    }

    if ($sumA > 1e-12) {
        return ['x' => $sumX / $sumA, 'y' => $sumY / $sumA];
    }

    $bbox = geoBoundingBox($geometry);
    if ($bbox === null) {
        return null;
    }

    return [
        'x' => ($bbox['min_x'] + $bbox['max_x']) / 2.0,
        'y' => ($bbox['min_y'] + $bbox['max_y']) / 2.0,
    ];
}

/**
 * Validates a geometry that is expected to already be in WGS 84.
 * Returns a list of human-readable problems; empty array = valid.
 */
function geoValidateLonLatGeometry(array $geometry): array
{
    $errors = [];

    $type = $geometry['type'] ?? '';
    if (!in_array($type, ['Polygon', 'MultiPolygon'], true)) {
        $errors[] = 'The geometry is not a polygon. Only polygon boundaries can be imported.';
        return $errors;
    }

    $rings = geoAllRings($geometry);
    if (empty($rings)) {
        $errors[] = 'The polygon does not contain any boundary coordinates.';
        return $errors;
    }

    $pointCount = 0;
    foreach ($rings as $ring) {
        $closed = geoCloseRing($ring);
        if (count($closed) < 4) {
            $errors[] = 'A polygon boundary needs at least three distinct points.';
            break;
        }

        foreach ($ring as $point) {
            if (!isset($point[0], $point[1]) || !is_numeric($point[0]) || !is_numeric($point[1])) {
                $errors[] = 'The polygon contains a coordinate that is not a valid number.';
                break 2;
            }

            $lon = (float)$point[0];
            $lat = (float)$point[1];

            if (!is_finite($lon) || !is_finite($lat)) {
                $errors[] = 'The polygon contains an invalid coordinate value.';
                break 2;
            }

            if ($lon < -180.0 || $lon > 180.0 || $lat < -90.0 || $lat > 90.0) {
                $errors[] = 'The converted coordinates fall outside the valid range for '
                    . 'latitude/longitude. The coordinate reference system of the file may '
                    . 'not have been interpreted correctly.';
                break 2;
            }

            $pointCount++;
        }
    }

    if ($pointCount === 0 && empty($errors)) {
        $errors[] = 'The polygon does not contain any usable coordinates.';
    }

    return $errors;
}

/**
 * Stable fingerprint of a geometry's coordinates, used for duplicate
 * detection. Coordinates are rounded to ~1cm so that trivial floating
 * point noise does not defeat the check.
 */
function geoGeometryHash(array $geometry): string
{
    $parts = [$geometry['type'] ?? ''];

    foreach (geoAllRings($geometry) as $ring) {
        $ringParts = [];
        foreach ($ring as $point) {
            $ringParts[] = number_format((float)$point[0], 7, '.', '')
                . ',' . number_format((float)$point[1], 7, '.', '');
        }
        $parts[] = implode(' ', $ringParts);
    }

    return sha1(implode('|', $parts));
}

/**
 * Counts the total number of coordinate points in a geometry.
 */
function geoPointCount(array $geometry): int
{
    $count = 0;
    foreach (geoAllRings($geometry) as $ring) {
        $count += count($ring);
    }
    return $count;
}

/**
 * Reduces the point count of a geometry for PREVIEW purposes only,
 * using Ramer-Douglas-Peucker. The stored geometry is never simplified.
 */
function geoSimplifyForPreview(array $geometry, int $maxPoints = 4000): array
{
    if (geoPointCount($geometry) <= $maxPoints) {
        return $geometry;
    }

    $tolerance = 0.00002; // ~2m
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $simplified = geoTransformRings($geometry, static function (array $ring) use ($tolerance): array {
            if (count($ring) <= 5) {
                return $ring;
            }
            $result = geoDouglasPeucker($ring, $tolerance);
            return count($result) >= 4 ? geoCloseRing($result) : $ring;
        });

        if (geoPointCount($simplified) <= $maxPoints) {
            return $simplified;
        }

        $geometry = $simplified;
        $tolerance *= 3;
    }

    return $geometry;
}

/**
 * Applies a callback to each complete ring of a geometry.
 */
function geoTransformRings(array $geometry, callable $fn): array
{
    $type = $geometry['type'] ?? '';
    $coordinates = $geometry['coordinates'] ?? [];

    if ($type === 'Polygon') {
        foreach ($coordinates as $r => $ring) {
            $coordinates[$r] = $fn($ring);
        }
    } elseif ($type === 'MultiPolygon') {
        foreach ($coordinates as $poly => $rings) {
            foreach ($rings as $r => $ring) {
                $coordinates[$poly][$r] = $fn($ring);
            }
        }
    }

    $geometry['coordinates'] = $coordinates;
    return $geometry;
}

/**
 * Ramer-Douglas-Peucker line simplification.
 */
function geoDouglasPeucker(array $points, float $tolerance): array
{
    $count = count($points);
    if ($count < 3) {
        return $points;
    }

    $maxDistance = 0.0;
    $index = 0;

    for ($i = 1; $i < $count - 1; $i++) {
        $distance = geoPerpendicularDistance($points[$i], $points[0], $points[$count - 1]);
        if ($distance > $maxDistance) {
            $maxDistance = $distance;
            $index = $i;
        }
    }

    if ($maxDistance > $tolerance) {
        $left  = geoDouglasPeucker(array_slice($points, 0, $index + 1), $tolerance);
        $right = geoDouglasPeucker(array_slice($points, $index), $tolerance);
        array_pop($left);
        return array_merge($left, $right);
    }

    return [$points[0], $points[$count - 1]];
}

/**
 * Perpendicular distance from a point to the line through $start/$end.
 */
function geoPerpendicularDistance(array $point, array $start, array $end): float
{
    $x  = (float)$point[0];
    $y  = (float)$point[1];
    $x1 = (float)$start[0];
    $y1 = (float)$start[1];
    $x2 = (float)$end[0];
    $y2 = (float)$end[1];

    $dx = $x2 - $x1;
    $dy = $y2 - $y1;

    if (abs($dx) < 1e-15 && abs($dy) < 1e-15) {
        return sqrt((($x - $x1) ** 2) + (($y - $y1) ** 2));
    }

    $numerator = abs(($dy * $x) - ($dx * $y) + ($x2 * $y1) - ($y2 * $x1));
    return $numerator / sqrt(($dx ** 2) + ($dy ** 2));
}

/**
 * Converts a geometry to Leaflet's latLng ordering ([lat, lng]) so the
 * map layer can consume it directly without re-ordering in JavaScript.
 * Returns a nested array of rings per polygon.
 */
function geoToLeafletLatLngs(array $geometry): array
{
    $type = $geometry['type'] ?? '';
    $coordinates = $geometry['coordinates'] ?? [];

    if ($type === 'Polygon') {
        $polygons = [$coordinates];
    } elseif ($type === 'MultiPolygon') {
        $polygons = $coordinates;
    } else {
        return [];
    }

    $output = [];
    foreach ($polygons as $rings) {
        $polygonRings = [];
        foreach ($rings as $ring) {
            $latLngs = [];
            foreach ($ring as $point) {
                $latLngs[] = [(float)$point[1], (float)$point[0]];
            }
            if (count($latLngs) >= 3) {
                $polygonRings[] = $latLngs;
            }
        }
        if ($polygonRings) {
            $output[] = $polygonRings;
        }
    }

    return $output;
}
