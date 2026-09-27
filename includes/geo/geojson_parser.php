<?php
/**
 * GeoJSON reader.
 *
 * Accepts a FeatureCollection, a single Feature, or a bare geometry.
 * Only polygonal geometries are kept - a CENRO reforestation area is an
 * area, not a point or a line.
 *
 * Per RFC 7946 GeoJSON is always WGS 84 (EPSG:4326) with coordinates in
 * [longitude, latitude] order. Older files sometimes carry a non-standard
 * "crs" member, which is read here so a mis-projected file can be
 * reported rather than silently mis-plotted.
 */

require_once __DIR__ . '/geometry.php';

/**
 * @return array{ok: bool, error: string, features: array, crs_name: ?string}
 */
function geojsonParse(string $path): array
{
    $result = ['ok' => false, 'error' => '', 'features' => [], 'crs_name' => null];

    if (!is_readable($path)) {
        $result['error'] = 'The GeoJSON file could not be read.';
        return $result;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        $result['error'] = 'The GeoJSON file is empty.';
        return $result;
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $result['error'] = 'The GeoJSON file is not valid JSON.';
        return $result;
    }

    // Legacy, non-RFC7946 CRS declaration.
    if (isset($data['crs']['properties']['name']) && is_string($data['crs']['properties']['name'])) {
        $result['crs_name'] = $data['crs']['properties']['name'];
    }

    $type = $data['type'] ?? '';
    $rawFeatures = [];

    if ($type === 'FeatureCollection') {
        if (!isset($data['features']) || !is_array($data['features'])) {
            $result['error'] = 'The GeoJSON feature collection does not contain any features.';
            return $result;
        }
        $rawFeatures = $data['features'];
    } elseif ($type === 'Feature') {
        $rawFeatures = [$data];
    } elseif (in_array($type, ['Polygon', 'MultiPolygon', 'GeometryCollection'], true)) {
        $rawFeatures = [['type' => 'Feature', 'geometry' => $data, 'properties' => []]];
    } else {
        $result['error'] = 'The GeoJSON file does not contain a supported feature type.';
        return $result;
    }

    $features = [];
    foreach ($rawFeatures as $index => $feature) {
        if (!is_array($feature)) {
            continue;
        }

        $geometry = $feature['geometry'] ?? null;
        if (!is_array($geometry)) {
            continue;
        }

        foreach (geojsonExtractPolygons($geometry) as $polygon) {
            $properties = $feature['properties'] ?? [];
            $features[] = [
                'geometry'   => $polygon,
                'attributes' => is_array($properties) ? geojsonFlattenProperties($properties) : [],
                'index'      => $index,
            ];
        }
    }

    if (empty($features)) {
        $result['error'] = 'The GeoJSON file does not contain any polygon boundaries. '
            . 'Only polygon areas can be imported.';
        return $result;
    }

    $result['ok'] = true;
    $result['features'] = $features;
    return $result;
}

/**
 * Pulls every polygonal geometry out of a geometry node, descending
 * into GeometryCollections.
 */
function geojsonExtractPolygons(array $geometry): array
{
    $type = $geometry['type'] ?? '';

    if ($type === 'Polygon') {
        $rings = geojsonNormaliseRings($geometry['coordinates'] ?? []);
        return $rings ? [['type' => 'Polygon', 'coordinates' => $rings]] : [];
    }

    if ($type === 'MultiPolygon') {
        $polygons = [];
        foreach (($geometry['coordinates'] ?? []) as $polygonRings) {
            $rings = geojsonNormaliseRings(is_array($polygonRings) ? $polygonRings : []);
            if ($rings) {
                $polygons[] = $rings;
            }
        }

        if (empty($polygons)) {
            return [];
        }

        // A single-member MultiPolygon is simpler to handle as a Polygon.
        if (count($polygons) === 1) {
            return [['type' => 'Polygon', 'coordinates' => $polygons[0]]];
        }

        return [['type' => 'MultiPolygon', 'coordinates' => $polygons]];
    }

    if ($type === 'GeometryCollection') {
        $found = [];
        foreach (($geometry['geometries'] ?? []) as $child) {
            if (is_array($child)) {
                $found = array_merge($found, geojsonExtractPolygons($child));
            }
        }
        return $found;
    }

    return [];
}

/**
 * Cleans a set of rings: drops non-numeric points, discards degenerate
 * rings, and closes each remaining ring. Any Z value is dropped.
 */
function geojsonNormaliseRings(array $rings): array
{
    $clean = [];

    foreach ($rings as $ring) {
        if (!is_array($ring)) {
            continue;
        }

        $points = [];
        foreach ($ring as $point) {
            if (!is_array($point) || !isset($point[0], $point[1])) {
                continue;
            }
            if (!is_numeric($point[0]) || !is_numeric($point[1])) {
                continue;
            }
            $points[] = [(float)$point[0], (float)$point[1]];
        }

        if (count($points) >= 3) {
            $clean[] = geoCloseRing($points);
        }
    }

    return $clean;
}

/**
 * Flattens feature properties to scalars so they can be stored and shown
 * as a simple attribute table.
 */
function geojsonFlattenProperties(array $properties): array
{
    $flat = [];

    foreach ($properties as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $flat[(string)$key] = $value;
        } elseif (is_array($value)) {
            $encoded = json_encode($value);
            $flat[(string)$key] = ($encoded !== false && strlen($encoded) <= 500) ? $encoded : null;
        }
    }

    return $flat;
}
