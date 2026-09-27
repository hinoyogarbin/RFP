<?php
/**
 * Coordinate Reference System (CRS) handling.
 *
 * CENRO spatial data is frequently delivered in a projected CRS -
 * typically PRS92 / Philippines Zone or WGS 84 / UTM zone 51N - not in
 * plain latitude/longitude. Plotting those easting/northing values on a
 * web map without converting them puts the polygon in the wrong place
 * entirely (usually somewhere in the Gulf of Guinea).
 *
 * This file reads the CRS description from a Shapefile's .prj sidecar
 * and returns a transformer that converts the file's coordinates to
 * WGS 84 / EPSG:4326.
 *
 * Supported:
 *   - Geographic CRS (already lat/lon), with optional datum shift
 *   - Transverse Mercator projections (covers UTM and PRS92 zones)
 *   - Web Mercator / EPSG:3857
 *
 * Anything else returns an error so the import can show an honest
 * validation message rather than plotting silently-wrong coordinates.
 */

require_once __DIR__ . '/geometry.php';

/**
 * Datum -> WGS 84 geocentric translation parameters (metres).
 * Matched against the DATUM name in the .prj, case-insensitively.
 *
 * PRS92 values follow NAMRIA's published transformation.
 */
const PROJ_DATUM_SHIFTS = [
    'PHILIPPINE_REFERENCE_SYSTEM_1992' => [-127.62, -67.24, -47.04],
    'PRS_1992'                         => [-127.62, -67.24, -47.04],
    'PRS92'                            => [-127.62, -67.24, -47.04],
    'LUZON_1911'                       => [-133.00, -77.00, -51.00],
    'LUZON'                            => [-133.00, -77.00, -51.00],
    'WGS_1984'                         => [0.0, 0.0, 0.0],
    'WGS84'                            => [0.0, 0.0, 0.0],
    'NORTH_AMERICAN_DATUM_1983'        => [0.0, 0.0, 0.0],
];

/**
 * Parses a .prj WKT string into a structured CRS description.
 *
 * @return array{
 *     ok: bool, error: string, kind: string, name: string,
 *     datum: string, a: float, f: float,
 *     projection: string, params: array, unit_factor: float,
 *     shift: array
 * }
 */
function projParseWkt(?string $wkt): array
{
    $crs = [
        'ok'          => false,
        'error'       => '',
        'kind'        => 'unknown',
        'name'        => '',
        'datum'       => '',
        'a'           => 6378137.0,
        'f'           => 1 / 298.257223563,
        'projection'  => '',
        'params'      => [],
        'unit_factor' => 1.0,
        'shift'       => [0.0, 0.0, 0.0],
    ];

    if ($wkt === null || trim($wkt) === '') {
        $crs['error'] = 'no_prj';
        return $crs;
    }

    $wkt = trim($wkt);

    // ---- CRS kind and name -------------------------------------------------
    if (preg_match('/^\s*PROJCS\s*\[\s*"([^"]*)"/i', $wkt, $m)) {
        $crs['kind'] = 'projected';
        $crs['name'] = $m[1];
    } elseif (preg_match('/^\s*GEOGCS\s*\[\s*"([^"]*)"/i', $wkt, $m)) {
        $crs['kind'] = 'geographic';
        $crs['name'] = $m[1];
    } else {
        $crs['error'] = 'unreadable';
        return $crs;
    }

    // ---- Datum -------------------------------------------------------------
    if (preg_match('/DATUM\s*\[\s*"([^"]*)"/i', $wkt, $m)) {
        $crs['datum'] = $m[1];
    }

    // ---- Spheroid (semi-major axis + inverse flattening) -------------------
    if (preg_match('/SPHEROID\s*\[\s*"([^"]*)"\s*,\s*([0-9.eE+-]+)\s*,\s*([0-9.eE+-]+)/i', $wkt, $m)) {
        $a    = (float)$m[2];
        $invF = (float)$m[3];
        if ($a > 6000000 && $a < 7000000) {
            $crs['a'] = $a;
        }
        $crs['f'] = ($invF > 0) ? 1.0 / $invF : 0.0;
    }

    // ---- Datum shift to WGS 84 --------------------------------------------
    // An explicit TOWGS84 in the file always wins over our lookup table.
    if (preg_match('/TOWGS84\s*\[\s*([^\]]+)\]/i', $wkt, $m)) {
        $values = array_map('trim', explode(',', $m[1]));
        if (count($values) >= 3) {
            $crs['shift'] = [(float)$values[0], (float)$values[1], (float)$values[2]];
        }
    } else {
        $datumKey = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $crs['datum']));
        $datumKey = trim($datumKey, '_');
        $datumKey = preg_replace('/^D_/', '', $datumKey);

        foreach (PROJ_DATUM_SHIFTS as $known => $shift) {
            if ($datumKey === $known || strpos($datumKey, $known) !== false) {
                $crs['shift'] = $shift;
                break;
            }
        }
    }

    // ---- Projection + parameters ------------------------------------------
    if ($crs['kind'] === 'projected') {
        if (preg_match('/PROJECTION\s*\[\s*"([^"]*)"/i', $wkt, $m)) {
            $crs['projection'] = strtolower(str_replace([' ', '-'], '_', trim($m[1])));
        }

        if (preg_match_all('/PARAMETER\s*\[\s*"([^"]*)"\s*,\s*([0-9.eE+-]+)\s*\]/i', $wkt, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = strtolower(str_replace([' ', '-'], '_', trim($match[1])));
                $crs['params'][$key] = (float)$match[2];
            }
        }

        // Linear unit of the projected coordinates (metres, feet, ...).
        if (preg_match_all('/UNIT\s*\[\s*"([^"]*)"\s*,\s*([0-9.eE+-]+)\s*\]/i', $wkt, $unitMatches, PREG_SET_ORDER)) {
            $last = end($unitMatches);
            $factor = (float)$last[2];
            if ($factor > 0) {
                $crs['unit_factor'] = $factor;
            }
        }
    }

    $crs['ok'] = true;
    return $crs;
}

/**
 * Builds a transformer callable for a parsed CRS.
 *
 * The returned callable takes [x, y] in the source CRS and returns
 * [lon, lat] on WGS 84.
 *
 * @return array{ok: bool, error: string, transform: callable|null, description: string}
 */
function projMakeTransformer(array $crs): array
{
    $result = ['ok' => false, 'error' => '', 'transform' => null, 'description' => ''];

    if (!$crs['ok']) {
        $result['error'] = ($crs['error'] === 'no_prj')
            ? 'no_prj'
            : 'The coordinate reference system of the file could not be read.';
        return $result;
    }

    $shift = $crs['shift'];
    $a = $crs['a'];
    $f = $crs['f'];

    // ---- Already geographic -------------------------------------------------
    if ($crs['kind'] === 'geographic') {
        $result['ok'] = true;
        $result['description'] = $crs['name'] . ' (geographic)';
        $result['transform'] = static function (array $point) use ($shift, $a, $f): array {
            // Shapefile geographic coordinates are stored X=lon, Y=lat.
            $lon = (float)$point[0];
            $lat = (float)$point[1];
            return projDatumShiftToWgs84($lon, $lat, $a, $f, $shift);
        };
        return $result;
    }

    $projection = $crs['projection'];
    $params = $crs['params'];
    $unitFactor = $crs['unit_factor'];

    // ---- Transverse Mercator (UTM, PRS92 zones) ----------------------------
    $tmNames = ['transverse_mercator', 'gauss_kruger', 'transverse_mercator_south_orientated'];
    if (in_array($projection, $tmNames, true)) {
        $lon0 = deg2rad($params['central_meridian'] ?? $params['longitude_of_center'] ?? 0.0);
        $lat0 = deg2rad($params['latitude_of_origin'] ?? $params['latitude_of_center'] ?? 0.0);
        $k0   = $params['scale_factor'] ?? 1.0;
        $fe   = $params['false_easting'] ?? 0.0;
        $fn   = $params['false_northing'] ?? 0.0;

        if ($k0 <= 0) {
            $k0 = 1.0;
        }

        $result['ok'] = true;
        $result['description'] = $crs['name'] . ' (Transverse Mercator)';
        $result['transform'] = static function (array $point) use ($a, $f, $lon0, $lat0, $k0, $fe, $fn, $unitFactor, $shift): array {
            $x = ((float)$point[0] * $unitFactor) - $fe;
            $y = ((float)$point[1] * $unitFactor) - $fn;

            [$lon, $lat] = projInverseTransverseMercator($x, $y, $a, $f, $lon0, $lat0, $k0);

            return projDatumShiftToWgs84($lon, $lat, $a, $f, $shift);
        };
        return $result;
    }

    // ---- Web Mercator / Pseudo-Mercator -------------------------------------
    $webMercatorNames = [
        'mercator_1sp',
        'mercator_auxiliary_sphere',
        'popular_visualisation_pseudo_mercator',
        'popular_visualization_pseudo_mercator',
    ];
    if (in_array($projection, $webMercatorNames, true)
        || stripos($crs['name'], 'Web_Mercator') !== false
        || stripos($crs['name'], 'Pseudo-Mercator') !== false) {

        $fe = $params['false_easting'] ?? 0.0;
        $fn = $params['false_northing'] ?? 0.0;

        $result['ok'] = true;
        $result['description'] = $crs['name'] . ' (Web Mercator)';
        $result['transform'] = static function (array $point) use ($fe, $fn, $unitFactor): array {
            $x = ((float)$point[0] * $unitFactor) - $fe;
            $y = ((float)$point[1] * $unitFactor) - $fn;

            $lon = ($x / GEO_EARTH_RADIUS) * 180.0 / M_PI;
            $lat = (2.0 * atan(exp($y / GEO_EARTH_RADIUS)) - (M_PI / 2.0)) * 180.0 / M_PI;

            return [$lon, $lat];
        };
        return $result;
    }

    $result['error'] = 'unsupported_projection';
    return $result;
}

/**
 * Inverse Transverse Mercator (Snyder / USGS Professional Paper 1395).
 * Returns [lon, lat] in DEGREES on the source datum.
 */
function projInverseTransverseMercator(
    float $x,
    float $y,
    float $a,
    float $f,
    float $lon0,
    float $lat0,
    float $k0
): array {
    $e2 = (2.0 * $f) - ($f * $f);
    if ($e2 <= 0) {
        // Spherical fallback.
        $lat = $y / ($a * $k0);
        $lon = $lon0 + ($x / ($a * $k0 * cos($lat)));
        return [rad2deg($lon), rad2deg($lat)];
    }

    $ep2 = $e2 / (1.0 - $e2);

    $m0 = projMeridionalArc($lat0, $a, $e2);
    $m  = $m0 + ($y / $k0);

    $mu = $m / ($a * (1.0 - ($e2 / 4.0) - (3.0 * $e2 ** 2 / 64.0) - (5.0 * $e2 ** 3 / 256.0)));

    $e1 = (1.0 - sqrt(1.0 - $e2)) / (1.0 + sqrt(1.0 - $e2));

    $phi1 = $mu
        + ((3.0 * $e1 / 2.0) - (27.0 * $e1 ** 3 / 32.0)) * sin(2.0 * $mu)
        + ((21.0 * $e1 ** 2 / 16.0) - (55.0 * $e1 ** 4 / 32.0)) * sin(4.0 * $mu)
        + (151.0 * $e1 ** 3 / 96.0) * sin(6.0 * $mu)
        + (1097.0 * $e1 ** 4 / 512.0) * sin(8.0 * $mu);

    $sinPhi1 = sin($phi1);
    $cosPhi1 = cos($phi1);
    $tanPhi1 = tan($phi1);

    if (abs($cosPhi1) < 1e-12) {
        return [rad2deg($lon0), rad2deg($phi1)];
    }

    $c1 = $ep2 * $cosPhi1 ** 2;
    $t1 = $tanPhi1 ** 2;
    $n1 = $a / sqrt(1.0 - ($e2 * $sinPhi1 ** 2));
    $r1 = $a * (1.0 - $e2) / ((1.0 - ($e2 * $sinPhi1 ** 2)) ** 1.5);
    $d  = $x / ($n1 * $k0);

    $lat = $phi1 - (($n1 * $tanPhi1 / $r1) * (
        ($d ** 2 / 2.0)
        - ((5.0 + (3.0 * $t1) + (10.0 * $c1) - (4.0 * $c1 ** 2) - (9.0 * $ep2)) * $d ** 4 / 24.0)
        + ((61.0 + (90.0 * $t1) + (298.0 * $c1) + (45.0 * $t1 ** 2) - (252.0 * $ep2) - (3.0 * $c1 ** 2)) * $d ** 6 / 720.0)
    ));

    $lon = $lon0 + ((
        $d
        - ((1.0 + (2.0 * $t1) + $c1) * $d ** 3 / 6.0)
        + ((5.0 - (2.0 * $c1) + (28.0 * $t1) - (3.0 * $c1 ** 2) + (8.0 * $ep2) + (24.0 * $t1 ** 2)) * $d ** 5 / 120.0)
    ) / $cosPhi1);

    return [rad2deg($lon), rad2deg($lat)];
}

/**
 * Meridional arc distance from the equator to the given latitude.
 */
function projMeridionalArc(float $lat, float $a, float $e2): float
{
    return $a * (
        ((1.0 - ($e2 / 4.0) - (3.0 * $e2 ** 2 / 64.0) - (5.0 * $e2 ** 3 / 256.0)) * $lat)
        - (((3.0 * $e2 / 8.0) + (3.0 * $e2 ** 2 / 32.0) + (45.0 * $e2 ** 3 / 1024.0)) * sin(2.0 * $lat))
        + (((15.0 * $e2 ** 2 / 256.0) + (45.0 * $e2 ** 3 / 1024.0)) * sin(4.0 * $lat))
        - (((35.0 * $e2 ** 3 / 3072.0)) * sin(6.0 * $lat))
    );
}

/**
 * Applies a 3-parameter geocentric translation from the source datum to
 * WGS 84. With a zero shift this is a no-op beyond round-tripping.
 *
 * @return array [lon, lat] in degrees on WGS 84
 */
function projDatumShiftToWgs84(float $lon, float $lat, float $a, float $f, array $shift): array
{
    if (abs($shift[0]) < 1e-9 && abs($shift[1]) < 1e-9 && abs($shift[2]) < 1e-9) {
        return [$lon, $lat];
    }

    $e2 = (2.0 * $f) - ($f * $f);

    $latRad = deg2rad($lat);
    $lonRad = deg2rad($lon);

    $sinLat = sin($latRad);
    $cosLat = cos($latRad);
    $sinLon = sin($lonRad);
    $cosLon = cos($lonRad);

    $n = $a / sqrt(1.0 - ($e2 * $sinLat ** 2));

    // Geodetic -> geocentric (height assumed 0).
    $x = $n * $cosLat * $cosLon;
    $y = $n * $cosLat * $sinLon;
    $z = ($n * (1.0 - $e2)) * $sinLat;

    $x += $shift[0];
    $y += $shift[1];
    $z += $shift[2];

    // Geocentric -> geodetic on WGS 84.
    $aW  = 6378137.0;
    $fW  = 1 / 298.257223563;
    $e2W = (2.0 * $fW) - ($fW * $fW);

    $p = sqrt(($x ** 2) + ($y ** 2));
    if ($p < 1e-12) {
        return [$lon, $lat];
    }

    $newLat = atan2($z, $p * (1.0 - $e2W));
    for ($i = 0; $i < 6; $i++) {
        $sinNewLat = sin($newLat);
        $nW = $aW / sqrt(1.0 - ($e2W * $sinNewLat ** 2));
        $height = ($p / cos($newLat)) - $nW;
        $newLat = atan2($z, $p * (1.0 - ($e2W * $nW / ($nW + $height))));
    }

    $newLon = atan2($y, $x);

    return [rad2deg($newLon), rad2deg($newLat)];
}

/**
 * Heuristic fallback when a .prj is missing: inspect the raw coordinate
 * range and decide whether the data already looks like lat/lon.
 *
 * This deliberately does NOT guess a projected CRS - guessing a UTM zone
 * from easting/northing alone is unreliable, so the import reports the
 * missing CRS instead of plotting something wrong.
 *
 * @return array{looks_geographic: bool, message: string}
 */
function projInspectRawRange(?array $bbox): array
{
    if ($bbox === null) {
        return ['looks_geographic' => false, 'message' => 'The file contains no readable coordinates.'];
    }

    $withinLonLat = $bbox['min_x'] >= -180.0 && $bbox['max_x'] <= 180.0
        && $bbox['min_y'] >= -90.0 && $bbox['max_y'] <= 90.0;

    if ($withinLonLat) {
        return [
            'looks_geographic' => true,
            'message' => 'No .prj file was supplied. The coordinates are within the valid '
                . 'latitude/longitude range, so the file was read as WGS 84 (EPSG:4326). '
                . 'Please confirm the plotted boundary on the preview map before importing.',
        ];
    }

    return [
        'looks_geographic' => false,
        'message' => 'No coordinate reference system could be determined, and the coordinates '
            . 'are projected easting/northing values rather than latitude/longitude. '
            . 'Please choose the coordinate system used by this file below, then preview '
            . 'the boundary to confirm it is correct.',
    ];
}

// ============================================================
// Selectable coordinate reference systems
//
// Used when a Shapefile arrives without a .prj sidecar. Rather than
// guessing, the operator picks the CRS that CENRO used and verifies the
// result on the preview map. The list covers the systems Philippine
// reforestation data is normally delivered in.
// ============================================================

/**
 * Builds a Transverse Mercator WKT string, so a chosen preset flows
 * through exactly the same parsing path as a real .prj file.
 */
function projMakeTmWkt(
    string $name,
    string $datum,
    string $spheroid,
    float $a,
    float $invF,
    float $centralMeridian,
    float $scaleFactor,
    float $falseEasting,
    float $falseNorthing
): string {
    return sprintf(
        'PROJCS["%s",GEOGCS["GCS_%s",DATUM["%s",SPHEROID["%s",%.4F,%.9F]],'
        . 'PRIMEM["Greenwich",0.0],UNIT["Degree",0.0174532925199433]],'
        . 'PROJECTION["Transverse_Mercator"],'
        . 'PARAMETER["False_Easting",%.1F],PARAMETER["False_Northing",%.1F],'
        . 'PARAMETER["Central_Meridian",%.1F],PARAMETER["Scale_Factor",%.5F],'
        . 'PARAMETER["Latitude_Of_Origin",0.0],UNIT["Meter",1.0]]',
        $name,
        $name,
        $datum,
        $spheroid,
        $a,
        $invF,
        $falseEasting,
        $falseNorthing,
        $centralMeridian,
        $scaleFactor
    );
}

/**
 * The coordinate systems an operator can pick from.
 *
 * @return array<string, array{label: string, wkt: string, hint: string}>
 */
function projCrsPresets(): array
{
    $presets = [];

    $presets['epsg:4326'] = [
        'label' => 'WGS 84 latitude/longitude (EPSG:4326)',
        'hint'  => 'Coordinates are degrees, e.g. 124.8660, 8.3650',
        'wkt'   => 'GEOGCS["GCS_WGS_1984",DATUM["D_WGS_1984",'
            . 'SPHEROID["WGS_1984",6378137.0,298.257223563]],PRIMEM["Greenwich",0.0],'
            . 'UNIT["Degree",0.0174532925199433]]',
    ];

    // WGS 84 / UTM - the most common projected delivery format.
    $utmZones = [50 => 117.0, 51 => 123.0, 52 => 129.0];
    foreach ($utmZones as $zone => $centralMeridian) {
        $epsg = 32600 + $zone;
        $presets['epsg:' . $epsg] = [
            'label' => sprintf('WGS 84 / UTM zone %dN (EPSG:%d)', $zone, $epsg),
            'hint'  => sprintf('Metres, central meridian %d°E', (int)$centralMeridian),
            'wkt'   => projMakeTmWkt(
                sprintf('WGS_1984_UTM_Zone_%dN', $zone),
                'D_WGS_1984', 'WGS_1984', 6378137.0, 298.257223563,
                $centralMeridian, 0.9996, 500000.0, 0.0
            ),
        ];
    }

    // PRS92 / Philippines zones - the official national grid.
    $philippineZones = [
        1 => ['cm' => 117.0, 'roman' => 'I'],
        2 => ['cm' => 119.0, 'roman' => 'II'],
        3 => ['cm' => 121.0, 'roman' => 'III'],
        4 => ['cm' => 123.0, 'roman' => 'IV'],
        5 => ['cm' => 125.0, 'roman' => 'V'],
    ];

    foreach ($philippineZones as $zone => $info) {
        $epsg = 3120 + $zone; // EPSG:3121..3125
        $presets['epsg:' . $epsg] = [
            'label' => sprintf('PRS92 / Philippines zone %s (EPSG:%d)', $info['roman'], $epsg),
            'hint'  => sprintf('Metres, central meridian %d°E', (int)$info['cm']),
            'wkt'   => projMakeTmWkt(
                sprintf('PRS_1992_Philippines_Zone_%s', $info['roman']),
                'D_Philippine_Reference_System_1992', 'Clarke_1866', 6378206.4, 294.9786982,
                $info['cm'], 0.99995, 500000.0, 0.0
            ),
        ];
    }

    // Luzon 1911 zones - older CENRO datasets.
    foreach ($philippineZones as $zone => $info) {
        $epsg = 25390 + $zone; // EPSG:25391..25395
        $presets['epsg:' . $epsg] = [
            'label' => sprintf('Luzon 1911 / Philippines zone %s (EPSG:%d)', $info['roman'], $epsg),
            'hint'  => sprintf('Metres, central meridian %d°E', (int)$info['cm']),
            'wkt'   => projMakeTmWkt(
                sprintf('Luzon_1911_Philippines_Zone_%s', $info['roman']),
                'D_Luzon_1911', 'Clarke_1866', 6378206.4, 294.9786982,
                $info['cm'], 0.99995, 500000.0, 0.0
            ),
        ];
    }

    $presets['epsg:3857'] = [
        'label' => 'Web Mercator (EPSG:3857)',
        'hint'  => 'Metres, values in the millions',
        'wkt'   => 'PROJCS["WGS_1984_Web_Mercator_Auxiliary_Sphere",'
            . 'GEOGCS["GCS_WGS_1984",DATUM["D_WGS_1984",'
            . 'SPHEROID["WGS_1984",6378137.0,298.257223563]],PRIMEM["Greenwich",0.0],'
            . 'UNIT["Degree",0.0174532925199433]],'
            . 'PROJECTION["Mercator_Auxiliary_Sphere"],'
            . 'PARAMETER["False_Easting",0.0],PARAMETER["False_Northing",0.0],'
            . 'UNIT["Meter",1.0]]',
    ];

    return $presets;
}

/**
 * Returns the WKT for a chosen preset, or null when the id is unknown.
 */
function projPresetWkt(?string $presetId): ?string
{
    if ($presetId === null || $presetId === '') {
        return null;
    }

    $presets = projCrsPresets();
    return $presets[$presetId]['wkt'] ?? null;
}

/**
 * Human label for a preset id.
 */
function projPresetLabel(?string $presetId): ?string
{
    if ($presetId === null || $presetId === '') {
        return null;
    }

    $presets = projCrsPresets();
    return $presets[$presetId]['label'] ?? null;
}

/**
 * Suggests which preset is most likely, given the raw coordinate range.
 *
 * This only ever pre-selects a choice in the dropdown for the operator to
 * confirm against the preview map - it never silently applies a CRS.
 */
function projSuggestPreset(?array $bbox): ?string
{
    if ($bbox === null) {
        return null;
    }

    // Already degrees.
    if ($bbox['min_x'] >= -180.0 && $bbox['max_x'] <= 180.0
        && $bbox['min_y'] >= -90.0 && $bbox['max_y'] <= 90.0) {
        return 'epsg:4326';
    }

    // Web Mercator values run to the millions.
    if (abs($bbox['max_x']) > 1000000.0) {
        return 'epsg:3857';
    }

    // A Transverse Mercator easting sits near the 500,000 false easting.
    // The northing gives away the latitude band, but not the zone, so we
    // fall back to the zone covering most Philippine data.
    $easting = ($bbox['min_x'] + $bbox['max_x']) / 2.0;
    if ($easting > 100000.0 && $easting < 900000.0) {
        return 'epsg:32651';
    }

    return null;
}

/**
 * Attempts to recover a coordinate reference system from an ESRI/ISO
 * metadata XML sidecar (typically "<name>.shp.xml").
 *
 * Many CENRO deliveries include this metadata file instead of - or as
 * well as - a .prj. It frequently embeds the full WKT, or at least the
 * coordinate system name.
 *
 * @return array{wkt: ?string, preset: ?string, source: ?string}
 */
function projExtractCrsFromMetadata(?string $xmlPath): array
{
    $result = ['wkt' => null, 'preset' => null, 'source' => null];

    if ($xmlPath === null || !is_readable($xmlPath)) {
        return $result;
    }

    $raw = file_get_contents($xmlPath);
    if ($raw === false || $raw === '') {
        return $result;
    }

    // Guard against enormous metadata files.
    if (strlen($raw) > 4194304) {
        $raw = substr($raw, 0, 4194304);
    }

    // ---- 1. An embedded WKT definition -------------------------------------
    if (preg_match('/(PROJCS|GEOGCS)\s*\[/i', $raw, $match, PREG_OFFSET_CAPTURE)) {
        $start = $match[0][1];
        $candidate = projExtractBalancedWkt($raw, $start);

        if ($candidate !== null) {
            $candidate = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $parsed = projParseWkt($candidate);
            $transformer = projMakeTransformer($parsed);

            if ($transformer['ok']) {
                $result['wkt'] = $candidate;
                $result['source'] = 'WKT definition in the metadata file';
                return $result;
            }
        }
    }

    // ---- 2. A named coordinate system --------------------------------------
    $name = null;
    foreach (['projcsn', 'geogcsn', 'identCode', 'code'] as $tag) {
        if (preg_match('/<' . $tag . '[^>]*>([^<]{3,200})<\/' . $tag . '>/i', $raw, $m)) {
            $name = trim($m[1]);
            break;
        }
    }

    if ($name !== null) {
        $preset = projMatchPresetByName($name);
        if ($preset !== null) {
            $result['preset'] = $preset;
            $result['source'] = 'coordinate system name "' . $name . '" in the metadata file';
            return $result;
        }
    }

    // ---- 3. A bare EPSG reference ------------------------------------------
    if (preg_match('/EPSG[":\s_-]*(\d{4,5})/i', $raw, $m)) {
        $candidate = 'epsg:' . $m[1];
        if (projPresetWkt($candidate) !== null) {
            $result['preset'] = $candidate;
            $result['source'] = 'EPSG code ' . $m[1] . ' in the metadata file';
            return $result;
        }
    }

    return $result;
}

/**
 * Extracts a bracket-balanced WKT string starting at $start.
 */
function projExtractBalancedWkt(string $text, int $start): ?string
{
    $length = strlen($text);
    $depth = 0;
    $started = false;

    for ($i = $start; $i < $length && ($i - $start) < 20000; $i++) {
        $char = $text[$i];

        if ($char === '[') {
            $depth++;
            $started = true;
        } elseif ($char === ']') {
            $depth--;
            if ($started && $depth === 0) {
                return substr($text, $start, $i - $start + 1);
            }
        }
    }

    return null;
}

/**
 * Matches a coordinate system name against the preset list.
 */
function projMatchPresetByName(string $name): ?string
{
    $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');

    if ($key === '') {
        return null;
    }

    // UTM zone, e.g. "WGS_1984_UTM_Zone_51N"
    if (preg_match('/UTMZONE(\d{2})N/', $key, $m)) {
        $candidate = 'epsg:' . (32600 + (int)$m[1]);
        if (projPresetWkt($candidate) !== null) {
            return $candidate;
        }
    }

    // Philippine zone, e.g. "PRS_1992_Philippines_Zone_V"
    if (preg_match('/ZONE(IV|IX|V?I{0,3})$/', $key, $m)) {
        $roman = $m[1];
        $romanMap = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5];

        if (isset($romanMap[$roman])) {
            $zone = $romanMap[$roman];
            $isLuzon = strpos($key, 'LUZON') !== false;
            $base = $isLuzon ? 25390 : 3120;
            $candidate = 'epsg:' . ($base + $zone);

            if (projPresetWkt($candidate) !== null) {
                return $candidate;
            }
        }
    }

    if (strpos($key, 'WEBMERCATOR') !== false || strpos($key, 'PSEUDOMERCATOR') !== false) {
        return 'epsg:3857';
    }

    if (strpos($key, 'WGS1984') !== false && strpos($key, 'UTM') === false) {
        return 'epsg:4326';
    }

    return null;
}
