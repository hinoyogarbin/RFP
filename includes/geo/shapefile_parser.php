<?php
/**
 * Pure-PHP Shapefile reader.
 *
 * A Shapefile is not a single file - it is a set of sidecar files that
 * must be read together:
 *
 *   .shp  binary geometry (required)
 *   .shx  index (not required for a sequential read)
 *   .dbf  dBASE attribute table (required for attributes)
 *   .prj  WKT coordinate reference system (required to plot correctly)
 *
 * This reader walks the .shp records sequentially, pairs each shape
 * with its .dbf attribute row, and returns polygon features with their
 * rings correctly split into outer boundaries and holes.
 *
 * Reference: ESRI Shapefile Technical Description (July 1998).
 */

require_once __DIR__ . '/geometry.php';

const SHP_TYPE_NULL        = 0;
const SHP_TYPE_POLYGON     = 5;
const SHP_TYPE_POLYGON_Z   = 15;
const SHP_TYPE_POLYGON_M   = 25;

/**
 * Reads a little-endian double from a binary string at $offset.
 */
function shpReadDouble(string $data, int $offset): float
{
    $bytes = substr($data, $offset, 8);
    if (strlen($bytes) < 8) {
        return NAN;
    }

    // 'e' = little-endian double (PHP 7.0.15+). Fall back to manual
    // byte-order correction on exotic builds where it is unavailable.
    $unpacked = @unpack('e', $bytes);
    if ($unpacked !== false && isset($unpacked[1])) {
        return (float)$unpacked[1];
    }

    if (pack('d', 1.0) !== pack('E', 1.0)) {
        $bytes = strrev($bytes);
    }
    $unpacked = unpack('d', $bytes);

    return isset($unpacked[1]) ? (float)$unpacked[1] : NAN;
}

/**
 * Reads a little-endian signed 32-bit integer.
 */
function shpReadInt32LE(string $data, int $offset): int
{
    $bytes = substr($data, $offset, 4);
    if (strlen($bytes) < 4) {
        return 0;
    }
    $unpacked = unpack('V', $bytes);
    $value = $unpacked[1] ?? 0;

    // Interpret as signed.
    return ($value >= 0x80000000) ? $value - 0x100000000 : $value;
}

/**
 * Reads a big-endian signed 32-bit integer.
 */
function shpReadInt32BE(string $data, int $offset): int
{
    $bytes = substr($data, $offset, 4);
    if (strlen($bytes) < 4) {
        return 0;
    }
    $unpacked = unpack('N', $bytes);
    $value = $unpacked[1] ?? 0;

    return ($value >= 0x80000000) ? $value - 0x100000000 : $value;
}

/**
 * Parses a .shp file into raw polygon geometries (still in the file's
 * own CRS, coordinates in [x, y] order).
 *
 * @return array{ok: bool, error: string, shapes: array, shape_type: int}
 */
function shpParseShapeFile(string $shpPath): array
{
    $result = ['ok' => false, 'error' => '', 'shapes' => [], 'shape_type' => 0];

    if (!is_readable($shpPath)) {
        $result['error'] = 'The .shp file could not be read.';
        return $result;
    }

    $data = file_get_contents($shpPath);
    if ($data === false || strlen($data) < 100) {
        $result['error'] = 'The .shp file is empty or truncated.';
        return $result;
    }

    // ---- File header --------------------------------------------------------
    $fileCode = shpReadInt32BE($data, 0);
    if ($fileCode !== 9994) {
        $result['error'] = 'The .shp file is not a valid ESRI Shapefile.';
        return $result;
    }

    // Header stores length in 16-bit words, including the 100-byte header.
    $declaredLength = shpReadInt32BE($data, 24) * 2;
    $actualLength = strlen($data);
    if ($declaredLength > 0 && $declaredLength > $actualLength) {
        $result['error'] = 'The .shp file appears to be truncated or corrupted.';
        return $result;
    }
    if ($declaredLength > 0 && $declaredLength < $actualLength) {
        // Trailing junk - read only what the header declares.
        $actualLength = $declaredLength;
    }

    $shapeType = shpReadInt32LE($data, 32);
    $result['shape_type'] = $shapeType;

    $polygonTypes = [SHP_TYPE_POLYGON, SHP_TYPE_POLYGON_Z, SHP_TYPE_POLYGON_M];
    if (!in_array($shapeType, $polygonTypes, true)) {
        $result['error'] = 'This Shapefile does not contain polygons. '
            . 'Only polygon boundaries can be imported as reforestation areas.';
        return $result;
    }

    // ---- Records ------------------------------------------------------------
    $shapes = [];
    $offset = 100;
    $guard = 0;

    while ($offset + 8 <= $actualLength) {
        if (++$guard > 200000) {
            $result['error'] = 'The .shp file contains an unexpected number of records.';
            return $result;
        }

        $contentLength = shpReadInt32BE($data, $offset + 4) * 2;
        $contentStart = $offset + 8;

        if ($contentLength <= 0 || $contentStart + $contentLength > $actualLength) {
            break; // Truncated trailing record - stop cleanly.
        }

        $recordType = shpReadInt32LE($data, $contentStart);

        if ($recordType === SHP_TYPE_NULL) {
            $shapes[] = null; // Keep index alignment with the .dbf.
            $offset = $contentStart + $contentLength;
            continue;
        }

        if (!in_array($recordType, $polygonTypes, true)) {
            $shapes[] = null;
            $offset = $contentStart + $contentLength;
            continue;
        }

        $parsed = shpParsePolygonRecord($data, $contentStart, $contentLength);
        $shapes[] = $parsed;

        $offset = $contentStart + $contentLength;
    }

    if (empty(array_filter($shapes))) {
        $result['error'] = 'No polygon boundaries could be read from the .shp file.';
        return $result;
    }

    $result['ok'] = true;
    $result['shapes'] = $shapes;
    return $result;
}

/**
 * Parses a single polygon record body into a GeoJSON-shaped geometry.
 * Rings are classified into outer boundaries and holes using their
 * winding direction, per the Shapefile specification (outer rings run
 * clockwise, holes run counter-clockwise).
 */
function shpParsePolygonRecord(string $data, int $start, int $length): ?array
{
    // Layout: type(4) box(32) numParts(4) numPoints(4) parts(4*n) points(16*p)
    if ($length < 44) {
        return null;
    }

    $numParts  = shpReadInt32LE($data, $start + 36);
    $numPoints = shpReadInt32LE($data, $start + 40);

    if ($numParts <= 0 || $numPoints <= 0 || $numParts > 100000 || $numPoints > 5000000) {
        return null;
    }

    $partsOffset  = $start + 44;
    $pointsOffset = $partsOffset + (4 * $numParts);

    if (($pointsOffset + (16 * $numPoints)) > ($start + $length)) {
        return null; // Record shorter than its own declared contents.
    }

    $partStarts = [];
    for ($i = 0; $i < $numParts; $i++) {
        $partStarts[] = shpReadInt32LE($data, $partsOffset + ($i * 4));
    }

    $rings = [];
    for ($p = 0; $p < $numParts; $p++) {
        $from = $partStarts[$p];
        $to   = ($p + 1 < $numParts) ? $partStarts[$p + 1] : $numPoints;

        if ($from < 0 || $to > $numPoints || $to <= $from) {
            continue;
        }

        $ring = [];
        for ($i = $from; $i < $to; $i++) {
            $pointOffset = $pointsOffset + ($i * 16);
            $x = shpReadDouble($data, $pointOffset);
            $y = shpReadDouble($data, $pointOffset + 8);

            if (!is_finite($x) || !is_finite($y)) {
                continue;
            }
            $ring[] = [$x, $y];
        }

        if (count($ring) >= 3) {
            $rings[] = geoCloseRing($ring);
        }
    }

    if (empty($rings)) {
        return null;
    }

    return shpAssembleRings($rings);
}

/**
 * Groups rings into polygons. A clockwise ring (negative signed area in
 * standard maths orientation) starts a new outer boundary; a
 * counter-clockwise ring is a hole belonging to the current boundary.
 *
 * Returns a Polygon when there is one outer ring, MultiPolygon otherwise.
 */
function shpAssembleRings(array $rings): array
{
    $polygons = [];
    $current = null;

    foreach ($rings as $ring) {
        $signedArea = geoRingSignedArea($ring);

        // Clockwise (negative) => outer ring in the Shapefile spec.
        if ($signedArea < 0 || $current === null) {
            if ($current !== null) {
                $polygons[] = $current;
            }
            $current = [$ring];
        } else {
            $current[] = $ring;
        }
    }

    if ($current !== null) {
        $polygons[] = $current;
    }

    if (count($polygons) === 1) {
        return ['type' => 'Polygon', 'coordinates' => $polygons[0]];
    }

    return ['type' => 'MultiPolygon', 'coordinates' => $polygons];
}

/**
 * Reads a .dbf attribute table into an array of associative rows.
 * Deleted records are preserved as empty rows so that record indexes
 * stay aligned with the .shp.
 *
 * @return array{ok: bool, error: string, rows: array, fields: array}
 */
function shpParseDbf(string $dbfPath): array
{
    $result = ['ok' => false, 'error' => '', 'rows' => [], 'fields' => []];

    if (!is_readable($dbfPath)) {
        $result['error'] = 'The .dbf attribute file could not be read.';
        return $result;
    }

    $data = file_get_contents($dbfPath);
    if ($data === false || strlen($data) < 33) {
        $result['error'] = 'The .dbf attribute file is empty or truncated.';
        return $result;
    }

    $recordCount  = unpack('V', substr($data, 4, 4))[1] ?? 0;
    $headerLength = unpack('v', substr($data, 8, 2))[1] ?? 0;
    $recordLength = unpack('v', substr($data, 10, 2))[1] ?? 0;

    if ($headerLength < 33 || $recordLength < 1) {
        $result['error'] = 'The .dbf attribute file header is invalid.';
        return $result;
    }

    // ---- Field descriptors (32 bytes each, terminated by 0x0D) --------------
    $fields = [];
    $offset = 32;
    while ($offset + 32 <= $headerLength) {
        if (substr($data, $offset, 1) === "\x0D") {
            break;
        }

        $rawName = substr($data, $offset, 11);
        $name = trim(str_replace("\x00", '', $rawName));
        $type = substr($data, $offset + 11, 1);
        $size = ord(substr($data, $offset + 16, 1));

        if ($name !== '' && $size > 0) {
            $fields[] = ['name' => $name, 'type' => $type, 'size' => $size];
        }

        $offset += 32;
    }

    if (empty($fields)) {
        $result['error'] = 'The .dbf attribute file does not define any fields.';
        return $result;
    }

    // ---- Records ------------------------------------------------------------
    $rows = [];
    $recordStart = $headerLength;
    $totalLength = strlen($data);

    for ($r = 0; $r < $recordCount; $r++) {
        $base = $recordStart + ($r * $recordLength);
        if ($base + $recordLength > $totalLength) {
            break; // Truncated file - keep what we have.
        }

        $deletionFlag = substr($data, $base, 1);
        if ($deletionFlag === '*') {
            $rows[] = [];
            continue;
        }

        $row = [];
        $cursor = $base + 1;
        foreach ($fields as $field) {
            $raw = substr($data, $cursor, $field['size']);
            $cursor += $field['size'];

            $row[$field['name']] = shpDecodeDbfValue($raw, $field['type']);
        }

        $rows[] = $row;
    }

    $result['ok'] = true;
    $result['rows'] = $rows;
    $result['fields'] = $fields;
    return $result;
}

/**
 * Converts a raw .dbf field value into a PHP scalar.
 */
function shpDecodeDbfValue(string $raw, string $type)
{
    $value = trim($raw);

    switch (strtoupper($type)) {
        case 'N': // Numeric
        case 'F': // Float
            if ($value === '' || !is_numeric($value)) {
                return null;
            }
            return (strpos($value, '.') !== false) ? (float)$value : (int)$value;

        case 'L': // Logical
            if ($value === '') {
                return null;
            }
            return in_array(strtoupper($value), ['Y', 'T'], true);

        case 'D': // Date, stored as YYYYMMDD
            if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
                return "{$m[1]}-{$m[2]}-{$m[3]}";
            }
            return $value !== '' ? $value : null;

        default: // Character and everything else
            if ($value === '') {
                return null;
            }
            // .dbf files are commonly Windows-1252; normalise to UTF-8.
            if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
                $converted = @iconv('CP1252', 'UTF-8//TRANSLIT', $value);
                if ($converted !== false) {
                    $value = $converted;
                }
            }
            return $value;
    }
}

/**
 * Reads the .prj sidecar, if present.
 */
function shpReadPrj(?string $prjPath): ?string
{
    if ($prjPath === null || !is_readable($prjPath)) {
        return null;
    }

    $wkt = file_get_contents($prjPath);
    if ($wkt === false) {
        return null;
    }

    // Strip a UTF-8 BOM if ArcGIS wrote one.
    $wkt = preg_replace('/^\xEF\xBB\xBF/', '', $wkt);

    return trim($wkt) !== '' ? trim($wkt) : null;
}

/**
 * Reads a complete Shapefile set into features.
 *
 * @param array $paths ['shp' => ..., 'dbf' => ..., 'prj' => ...]
 * @return array{ok: bool, error: string, features: array, prj_wkt: ?string}
 */
function shpParseShapefileSet(array $paths): array
{
    $result = ['ok' => false, 'error' => '', 'features' => [], 'prj_wkt' => null];

    if (empty($paths['shp'])) {
        $result['error'] = 'The .shp file is missing from the upload.';
        return $result;
    }

    $shapeResult = shpParseShapeFile($paths['shp']);
    if (!$shapeResult['ok']) {
        $result['error'] = $shapeResult['error'];
        return $result;
    }

    $attributes = [];
    if (!empty($paths['dbf'])) {
        $dbfResult = shpParseDbf($paths['dbf']);
        if ($dbfResult['ok']) {
            $attributes = $dbfResult['rows'];
        }
        // A bad .dbf costs us attributes, not the geometry - keep going.
    }

    $result['prj_wkt'] = shpReadPrj($paths['prj'] ?? null);

    $features = [];
    foreach ($shapeResult['shapes'] as $index => $geometry) {
        if ($geometry === null) {
            continue;
        }

        $features[] = [
            'geometry'   => $geometry,
            'attributes' => $attributes[$index] ?? [],
            'index'      => $index,
        ];
    }

    if (empty($features)) {
        $result['error'] = 'No polygon boundaries could be read from the Shapefile.';
        return $result;
    }

    $result['ok'] = true;
    $result['features'] = $features;
    return $result;
}
