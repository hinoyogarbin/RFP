<?php
/**
 * CENRO polygon import pipeline.
 *
 *   Upload -> validate -> read geometry -> read attributes -> check CRS
 *          -> convert coordinates -> preview -> confirm -> save
 *
 * This file owns the first half of that flow (everything up to preview).
 * Database writes live in polygon_functions.php.
 *
 * Security notes:
 *   - Uploaded filenames are never trusted or reused on disk.
 *   - Files are stored under storage/ with a random name and an
 *     .htaccess that denies web access and disables the PHP engine.
 *   - Only the extensions below are accepted, and the contents are
 *     validated by parsing, not by trusting the extension.
 */

require_once __DIR__ . '/geo/geometry.php';
require_once __DIR__ . '/geo/proj.php';
require_once __DIR__ . '/geo/shapefile_parser.php';
require_once __DIR__ . '/geo/geojson_parser.php';
require_once __DIR__ . '/geo/kml_parser.php';

const POLYGON_UPLOAD_MAX_BYTES = 33554432; // 32 MB per file
const POLYGON_STORAGE_DIR = __DIR__ . '/../storage/polygons';

/**
 * Extensions the import can actually make use of.
 */
const POLYGON_ALLOWED_EXTENSIONS = [
    'shp', 'shx', 'dbf', 'prj', 'cpg', 'xml',
    'geojson', 'json',
    'kml',
    'zip',
];

/**
 * Extra files that routinely sit alongside a Shapefile. They carry no
 * information this module needs, so they are skipped silently rather
 * than rejecting the whole upload - selecting a whole shapefile folder
 * should just work.
 */
const POLYGON_IGNORED_EXTENSIONS = [
    'sbn', 'sbx', 'qix', 'fix', 'atx', 'qmd', 'aih', 'ain',
    'shp_xml', 'lock', 'idx', 'mxd', 'lyr', 'txt', 'pdf', 'doc', 'docx',
    'xls', 'xlsx', 'csv', 'png', 'jpg', 'jpeg', 'gif', 'dat', 'id', 'map', 'ind',
];

/**
 * Makes sure the storage directory exists and cannot be browsed or
 * executed through the web server.
 */
function polygonEnsureStorage(): bool
{
    $dir = POLYGON_STORAGE_DIR;

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        $rules = "# Uploaded spatial data - never served or executed.\n"
            . "php_flag engine off\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
        @file_put_contents($htaccess, $rules);
    }

    $index = $dir . '/index.html';
    if (!file_exists($index)) {
        @file_put_contents($index, '');
    }

    return true;
}

/**
 * Normalises PHP's awkward multi-file $_FILES structure into a simple
 * list of ['name', 'tmp_name', 'size', 'error'].
 */
function polygonNormaliseUploads(string $inputName): array
{
    if (!isset($_FILES[$inputName]) || !is_array($_FILES[$inputName])) {
        return [];
    }

    $field = $_FILES[$inputName];
    $files = [];

    if (is_array($field['name'])) {
        $count = count($field['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($field['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name'     => (string)$field['name'][$i],
                'tmp_name' => (string)$field['tmp_name'][$i],
                'size'     => (int)$field['size'][$i],
                'error'    => (int)$field['error'][$i],
            ];
        }
    } else {
        if (($field['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $files[] = [
                'name'     => (string)$field['name'],
                'tmp_name' => (string)$field['tmp_name'],
                'size'     => (int)$field['size'],
                'error'    => (int)$field['error'],
            ];
        }
    }

    return $files;
}

/**
 * Translates a PHP upload error code into a message a field officer can
 * act on.
 */
function polygonUploadErrorMessage(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'The file is larger than the server upload limit.';
        case UPLOAD_ERR_PARTIAL:
            return 'The file was only partially uploaded. Please try again.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not save the uploaded file.';
        case UPLOAD_ERR_EXTENSION:
            return 'The upload was blocked by the server configuration.';
        default:
            return 'The file could not be uploaded.';
    }
}

/**
 * Returns a safe lowercase extension for an untrusted filename.
 */
function polygonFileExtension(string $filename): string
{
    $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    return preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
}

/**
 * Generates a random, collision-resistant on-disk filename.
 * The user's filename is kept only as a display label in the database.
 */
function polygonSafeStoredName(string $extension): string
{
    $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'dat';
    return date('Ymd') . '_' . bin2hex(random_bytes(10)) . '.' . $extension;
}

/**
 * Accepts the uploaded file(s), works out which format was supplied,
 * extracts geometry and attributes, and converts everything to WGS 84.
 *
 * Returns a structure the preview page can render and the confirm step
 * can save. Nothing is written to the database here.
 *
 * @return array{
 *     ok: bool, error: string, warnings: array,
 *     format: string, source_file: string, source_hash: string,
 *     stored_file: ?string, crs_description: string,
 *     features: array
 * }
 */
function polygonProcessUpload(string $inputName, string $crsChoice = ''): array
{
    $result = [
        'ok'              => false,
        'error'           => '',
        'needs_crs'       => false,
        'suggested_crs'   => null,
        'crs_choice'      => $crsChoice,
        'warnings'        => [],
        'format'          => '',
        'source_file'     => '',
        'source_hash'     => '',
        'stored_file'     => null,
        'crs_description' => '',
        'features'        => [],
        'raw_features'    => [],
    ];

    if (!polygonEnsureStorage()) {
        $result['error'] = 'The server storage folder for spatial files is not writable.';
        return $result;
    }

    $uploads = polygonNormaliseUploads($inputName);

    if (empty($uploads)) {
        $result['error'] = 'Please choose a spatial file to import.';
        return $result;
    }

    // ---- Per-file validation ------------------------------------------------
    // Unusable files are skipped, not treated as fatal. Selecting an entire
    // shapefile folder (which contains .sbn, .qix, .xml and friends) must
    // not fail just because one companion file is not needed here.
    $usable = [];
    $skippedNames = [];

    foreach ($uploads as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $result['error'] = polygonUploadErrorMessage($file['error']);
            return $result;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $result['error'] = 'The upload could not be verified. Please try again.';
            return $result;
        }

        if ($file['size'] > POLYGON_UPLOAD_MAX_BYTES) {
            $result['error'] = '"' . polygonDisplayFilename($file['name']) . '" is larger than the '
                . (POLYGON_UPLOAD_MAX_BYTES / 1048576) . ' MB limit.';
            return $result;
        }

        $extension = polygonFileExtension($file['name']);

        if ($file['size'] <= 0 || !in_array($extension, POLYGON_ALLOWED_EXTENSIONS, true)) {
            $skippedNames[] = polygonDisplayFilename($file['name']);
            continue;
        }

        $usable[] = $file;
    }

    if (empty($usable)) {
        $result['error'] = 'None of the selected files can be imported. Please upload a '
            . 'Shapefile (the .shp file, together with its .shx, .dbf and .prj companions, '
            . 'or a .zip archive containing them), a GeoJSON file, or a KML file.';
        return $result;
    }

    if (!empty($skippedNames)) {
        $shown = array_slice($skippedNames, 0, 5);
        $result['warnings'][] = 'Ignored ' . count($skippedNames) . ' file(s) that are not needed '
            . 'for the import: ' . implode(', ', $shown)
            . (count($skippedNames) > 5 ? ', …' : '') . '.';
    }

    $uploads = $usable;

    // ---- Work out the format ------------------------------------------------
    $workDir = polygonMakeWorkDir();
    if ($workDir === null) {
        $result['error'] = 'The server could not prepare a temporary folder for the import.';
        return $result;
    }

    try {
        $prepared = polygonPrepareFiles($uploads, $workDir);
        if (!$prepared['ok']) {
            $result['error'] = $prepared['error'];
            return $result;
        }

        $result['format'] = $prepared['format'];
        $result['source_file'] = $prepared['source_file'];
        $result['source_hash'] = $prepared['source_hash'];
        $result['warnings'] = array_merge($result['warnings'], $prepared['warnings']);

        // ---- Parse ----------------------------------------------------------
        $parsed = polygonParsePrepared($prepared);
        if (!$parsed['ok']) {
            $result['error'] = $parsed['error'];
            return $result;
        }

        // Keep the untouched source-CRS geometry so the coordinate system
        // can be changed later without re-uploading the file.
        $result['raw_features'] = $parsed['features'];

        // ---- Recover a CRS from the metadata sidecar if there is no .prj ----
        $prjWkt = $parsed['prj_wkt'];

        if ($prjWkt === null && $crsChoice === '' && !empty($prepared['paths']['xml'])) {
            $metadataCrs = projExtractCrsFromMetadata($prepared['paths']['xml']);

            if ($metadataCrs['wkt'] !== null) {
                $prjWkt = $metadataCrs['wkt'];
                $result['warnings'][] = 'No .prj file was included. The coordinate reference '
                    . 'system was read from the ' . $metadataCrs['source']
                    . '. Please confirm the boundary on the preview map.';
            } elseif ($metadataCrs['preset'] !== null) {
                $crsChoice = $metadataCrs['preset'];
                $result['crs_choice'] = $crsChoice;
                $result['warnings'][] = 'No .prj file was included. The coordinate reference '
                    . 'system was identified from the ' . $metadataCrs['source']
                    . '. Please confirm the boundary on the preview map.';
            }
        }

        // ---- CRS handling ---------------------------------------------------
        $converted = polygonConvertFeatures(
            $parsed['features'],
            $prepared['format'],
            $prjWkt,
            $parsed['crs_name'],
            $crsChoice
        );

        if (!$converted['ok']) {
            $result['error'] = $converted['error'];
            $result['needs_crs'] = $converted['needs_crs'];
            $result['suggested_crs'] = $converted['suggested_crs'];
            $result['warnings'] = array_merge($result['warnings'], $converted['warnings']);

            // A missing CRS is recoverable: the operator can pick one and
            // preview the result, so the parsed geometry is kept.
            if ($converted['needs_crs']) {
                $result['stored_file'] = polygonArchiveUpload($prepared, $workDir);
            }

            return $result;
        }

        $result['warnings'] = array_merge($result['warnings'], $converted['warnings']);
        $result['crs_description'] = $converted['crs_description'];
        $result['features'] = $converted['features'];

        // ---- Keep the original upload ---------------------------------------
        $stored = polygonArchiveUpload($prepared, $workDir);
        $result['stored_file'] = $stored;

        $result['ok'] = true;
        return $result;

    } finally {
        polygonRemoveDir($workDir);
    }
}

/**
 * Creates a private temporary working directory.
 */
function polygonMakeWorkDir(): ?string
{
    $base = sys_get_temp_dir() . '/rfp_polygon_' . bin2hex(random_bytes(8));
    if (!@mkdir($base, 0700, true) && !is_dir($base)) {
        return null;
    }
    return $base;
}

/**
 * Recursively removes a directory.
 */
function polygonRemoveDir(?string $dir): void
{
    if ($dir === null || !is_dir($dir)) {
        return;
    }

    $items = @scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            polygonRemoveDir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

/**
 * Moves the uploads into the working directory, unpacking a .zip when
 * one was supplied, and decides which format we are dealing with.
 *
 * @return array{
 *     ok: bool, error: string, warnings: array, format: string,
 *     paths: array, source_file: string, source_hash: string,
 *     archive: array
 * }
 */
function polygonPrepareFiles(array $uploads, string $workDir): array
{
    $out = [
        'ok' => false, 'error' => '', 'warnings' => [], 'format' => '',
        'paths' => [], 'source_file' => '', 'source_hash' => '', 'archive' => [],
    ];

    $hashParts = [];
    $landed = [];

    foreach ($uploads as $file) {
        $extension = polygonFileExtension($file['name']);
        $target = $workDir . '/upload_' . count($landed) . '.' . $extension;

        if (!@move_uploaded_file($file['tmp_name'], $target)) {
            $out['error'] = 'The uploaded file could not be saved for processing.';
            return $out;
        }

        $landed[] = [
            'original_name' => $file['name'],
            'path' => $target,
            'ext' => $extension,
        ];

        $hashParts[] = sha1_file($target) ?: '';
    }

    sort($hashParts);
    $out['source_hash'] = sha1(implode('', $hashParts));

    // ---- ZIP archive --------------------------------------------------------
    $zips = array_values(array_filter($landed, static fn($f) => $f['ext'] === 'zip'));

    if (!empty($zips)) {
        if (count($landed) > 1) {
            $out['error'] = 'Please upload either a single .zip archive or the individual '
                . 'spatial files, not both at once.';
            return $out;
        }

        $extracted = polygonExtractZip($zips[0]['path'], $workDir . '/unzipped');
        if (!$extracted['ok']) {
            $out['error'] = $extracted['error'];
            return $out;
        }

        $out['source_file'] = polygonDisplayFilename($zips[0]['original_name']);
        $out['archive'] = ['path' => $zips[0]['path'], 'ext' => 'zip'];
        $landed = $extracted['files'];

        if (empty($landed)) {
            $out['error'] = 'The .zip archive does not contain any supported spatial files.';
            return $out;
        }
    }

    // ---- Index by extension -------------------------------------------------
    $byExtension = [];
    foreach ($landed as $file) {
        $byExtension[$file['ext']][] = $file;
    }

    // ---- Shapefile ----------------------------------------------------------
    if (isset($byExtension['shp'])) {
        if (count($byExtension['shp']) > 1) {
            $out['error'] = 'The upload contains more than one .shp file. '
                . 'Please import one Shapefile at a time.';
            return $out;
        }

        $shp = $byExtension['shp'][0];

        // The geometry lives entirely in the .shp, so a missing companion
        // costs attributes or CRS information rather than the import itself.
        if (!isset($byExtension['dbf'])) {
            $out['warnings'][] = 'No .dbf file was included, so this Shapefile carries no '
                . 'attribute columns. The polygon details will need to be entered manually.';
        }

        if (!isset($byExtension['shx'])) {
            $out['warnings'][] = 'No .shx index file was included. The geometry was read '
                . 'directly from the .shp, so the import can continue.';
        }

        $out['format'] = 'shapefile';
        $out['paths'] = [
            'shp' => $shp['path'],
            'shx' => $byExtension['shx'][0]['path'] ?? null,
            'dbf' => $byExtension['dbf'][0]['path'] ?? null,
            'prj' => $byExtension['prj'][0]['path'] ?? null,
            'xml' => $byExtension['xml'][0]['path'] ?? null,
        ];

        if ($out['source_file'] === '') {
            $out['source_file'] = polygonDisplayFilename($shp['original_name']);
        }
        if (empty($out['archive'])) {
            $out['archive'] = ['path' => $shp['path'], 'ext' => 'shp', 'set' => $out['paths']];
        }

        $out['ok'] = true;
        return $out;
    }

    // A lone .shx/.dbf/.prj without the .shp is a common mistake.
    if (isset($byExtension['dbf']) || isset($byExtension['shx']) || isset($byExtension['prj'])) {
        $out['error'] = 'The .shp file containing the polygon geometry is missing. '
            . 'Please upload the .shp file together with its .shx, .dbf and .prj companions.';
        return $out;
    }

    // ---- GeoJSON ------------------------------------------------------------
    $geojsonFiles = array_merge($byExtension['geojson'] ?? [], $byExtension['json'] ?? []);
    if (!empty($geojsonFiles)) {
        if (count($geojsonFiles) > 1) {
            $out['error'] = 'Please import one GeoJSON file at a time.';
            return $out;
        }

        $out['format'] = 'geojson';
        $out['paths'] = ['geojson' => $geojsonFiles[0]['path']];
        if ($out['source_file'] === '') {
            $out['source_file'] = polygonDisplayFilename($geojsonFiles[0]['original_name']);
        }
        if (empty($out['archive'])) {
            $out['archive'] = ['path' => $geojsonFiles[0]['path'], 'ext' => 'geojson'];
        }
        $out['ok'] = true;
        return $out;
    }

    // ---- KML ----------------------------------------------------------------
    if (!empty($byExtension['kml'])) {
        if (count($byExtension['kml']) > 1) {
            $out['error'] = 'Please import one KML file at a time.';
            return $out;
        }

        $out['format'] = 'kml';
        $out['paths'] = ['kml' => $byExtension['kml'][0]['path']];
        if ($out['source_file'] === '') {
            $out['source_file'] = polygonDisplayFilename($byExtension['kml'][0]['original_name']);
        }
        if (empty($out['archive'])) {
            $out['archive'] = ['path' => $byExtension['kml'][0]['path'], 'ext' => 'kml'];
        }
        $out['ok'] = true;
        return $out;
    }

    $out['error'] = 'No supported spatial file was found in the upload. Please upload a '
        . 'Shapefile, GeoJSON, or KML file.';
    return $out;
}

/**
 * Safely extracts the supported members of a .zip archive.
 * Guards against path traversal (zip-slip) and archive bombs.
 */
function polygonExtractZip(string $zipPath, string $destination): array
{
    $out = ['ok' => false, 'error' => '', 'files' => []];

    if (!class_exists('ZipArchive')) {
        $out['error'] = 'This server cannot open .zip archives. Please upload the '
            . 'individual Shapefile components (.shp, .shx, .dbf, .prj) instead.';
        return $out;
    }

    if (!@mkdir($destination, 0700, true) && !is_dir($destination)) {
        $out['error'] = 'The archive could not be opened.';
        return $out;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        $out['error'] = 'The .zip archive could not be opened. It may be corrupted.';
        return $out;
    }

    $totalBytes = 0;
    $extracted = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) {
            continue;
        }

        $entryName = $stat['name'];

        // Skip directories and anything trying to escape the destination.
        if (substr($entryName, -1) === '/' || strpos($entryName, '..') !== false) {
            continue;
        }

        // Ignore macOS resource-fork entries.
        if (strpos($entryName, '__MACOSX/') === 0 || strpos(basename($entryName), '._') === 0) {
            continue;
        }

        $extension = polygonFileExtension(basename($entryName));
        if (!in_array($extension, POLYGON_ALLOWED_EXTENSIONS, true) || $extension === 'zip') {
            continue;
        }

        $size = (int)($stat['size'] ?? 0);
        if ($size <= 0 || $size > POLYGON_UPLOAD_MAX_BYTES) {
            continue;
        }

        $totalBytes += $size;
        if ($totalBytes > POLYGON_UPLOAD_MAX_BYTES * 4) {
            $zip->close();
            $out['error'] = 'The .zip archive is too large to process.';
            return $out;
        }

        $contents = $zip->getFromIndex($i);
        if ($contents === false) {
            continue;
        }

        // Always write to our own flat, safe filename.
        $target = $destination . '/entry_' . $extracted . '.' . $extension;
        if (@file_put_contents($target, $contents) === false) {
            continue;
        }

        $out['files'][] = [
            'original_name' => basename($entryName),
            'path' => $target,
            'ext' => $extension,
        ];
        $extracted++;
    }

    $zip->close();

    $out['ok'] = true;
    return $out;
}

/**
 * Sanitises an uploaded filename for display/storage in the database.
 */
function polygonDisplayFilename(string $name): string
{
    $name = basename($name);
    $name = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $name) ?? 'upload';
    return substr(trim($name), 0, 255) ?: 'upload';
}

/**
 * Dispatches to the correct parser for the detected format.
 *
 * @return array{ok: bool, error: string, features: array, prj_wkt: ?string, crs_name: ?string}
 */
function polygonParsePrepared(array $prepared): array
{
    $out = ['ok' => false, 'error' => '', 'features' => [], 'prj_wkt' => null, 'crs_name' => null];

    try {
        switch ($prepared['format']) {
            case 'shapefile':
                $parsed = shpParseShapefileSet($prepared['paths']);
                if (!$parsed['ok']) {
                    $out['error'] = $parsed['error'];
                    return $out;
                }
                $out['features'] = $parsed['features'];
                $out['prj_wkt'] = $parsed['prj_wkt'];
                break;

            case 'geojson':
                $parsed = geojsonParse($prepared['paths']['geojson']);
                if (!$parsed['ok']) {
                    $out['error'] = $parsed['error'];
                    return $out;
                }
                $out['features'] = $parsed['features'];
                $out['crs_name'] = $parsed['crs_name'];
                break;

            case 'kml':
                $parsed = kmlParse($prepared['paths']['kml']);
                if (!$parsed['ok']) {
                    $out['error'] = $parsed['error'];
                    return $out;
                }
                $out['features'] = $parsed['features'];
                break;

            default:
                $out['error'] = 'The spatial file format could not be determined.';
                return $out;
        }
    } catch (Throwable $e) {
        // Never surface a raw parser/library error to the user.
        error_log('Polygon import parse failure: ' . $e->getMessage());
        $out['error'] = 'The polygon file could not be imported. '
            . 'Please verify that the spatial file is valid.';
        return $out;
    }

    $out['ok'] = true;
    return $out;
}

/**
 * Converts every parsed feature to WGS 84 and derives area, centroid and
 * bounding box. The untouched source geometry is carried alongside.
 *
 * @return array{ok: bool, error: string, warnings: array, crs_description: string, features: array}
 */
function polygonConvertFeatures(
    array $features,
    string $format,
    ?string $prjWkt,
    ?string $crsName,
    string $crsChoice = ''
): array {
    $out = [
        'ok' => false, 'error' => '', 'needs_crs' => false, 'suggested_crs' => null,
        'warnings' => [], 'crs_description' => '', 'features' => [],
    ];

    $transform = null;
    $description = '';

    // ---- An explicit choice by the operator always wins ---------------------
    if ($crsChoice !== '') {
        $presetWkt = projPresetWkt($crsChoice);

        if ($presetWkt === null) {
            $out['error'] = 'The selected coordinate reference system is not recognised.';
            $out['needs_crs'] = true;
            $out['suggested_crs'] = projSuggestPreset(polygonCombinedBbox($features));
            return $out;
        }

        $transformer = projMakeTransformer(projParseWkt($presetWkt));

        if (!$transformer['ok']) {
            $out['error'] = 'The selected coordinate reference system could not be applied.';
            $out['needs_crs'] = true;
            return $out;
        }

        $transform = $transformer['transform'];
        $description = projPresetLabel($crsChoice) . ' — selected manually';

    } elseif ($format === 'shapefile') {
        $crs = projParseWkt($prjWkt);
        $transformer = projMakeTransformer($crs);

        if ($transformer['ok']) {
            $transform = $transformer['transform'];
            $description = $transformer['description'];
        } else {
            // No usable .prj. Only proceed automatically if the numbers
            // really do look like latitude/longitude; never guess a
            // projected CRS on the operator's behalf.
            $rawBbox = polygonCombinedBbox($features);
            $inspection = projInspectRawRange($rawBbox);

            if (!$inspection['looks_geographic']) {
                $out['error'] = ($transformer['error'] === 'unsupported_projection')
                    ? 'The coordinate reference system named in the .prj file is not supported. '
                        . 'Please choose the coordinate system used by this file below.'
                    : $inspection['message'];
                $out['needs_crs'] = true;
                $out['suggested_crs'] = projSuggestPreset($rawBbox);
                return $out;
            }

            $out['warnings'][] = $inspection['message'];
            $description = 'Assumed WGS 84 (EPSG:4326) — no .prj supplied';
            $transform = static fn(array $point): array => [(float)$point[0], (float)$point[1]];
        }
    } else {
        // GeoJSON (RFC 7946) and KML are defined as WGS 84.
        $description = ($format === 'kml') ? 'WGS 84 (EPSG:4326) — KML' : 'WGS 84 (EPSG:4326) — GeoJSON';

        if ($crsName !== null && stripos($crsName, '4326') === false
            && stripos($crsName, 'CRS84') === false && stripos($crsName, 'WGS84') === false) {
            $out['warnings'][] = 'This GeoJSON file declares the coordinate reference system "'
                . $crsName . '". GeoJSON is expected to be WGS 84, so the coordinates were read '
                . 'as latitude/longitude. Please confirm the preview map before importing.';
            $description .= ' (file declares: ' . $crsName . ')';
        }

        $transform = static fn(array $point): array => [(float)$point[0], (float)$point[1]];
    }

    $converted = [];
    $skipped = 0;

    foreach ($features as $feature) {
        $original = $feature['geometry'];

        try {
            $wgs84 = geoTransformGeometry($original, $transform);
        } catch (Throwable $e) {
            error_log('Polygon CRS conversion failure: ' . $e->getMessage());
            $skipped++;
            continue;
        }

        $problems = geoValidateLonLatGeometry($wgs84);
        if (!empty($problems)) {
            $skipped++;
            continue;
        }

        $areaSqm = geoGeometryAreaSqm($wgs84);
        $bbox = geoBoundingBox($wgs84);
        $centroid = geoCentroid($wgs84);

        $converted[] = [
            'geometry'          => $wgs84,
            'original_geometry' => $original,
            'attributes'        => $feature['attributes'] ?? [],
            'index'             => $feature['index'] ?? 0,
            'area_sqm'          => $areaSqm,
            'area_hectares'     => geoSqmToHectares($areaSqm),
            'geometry_hash'     => geoGeometryHash($wgs84),
            'geometry_type'     => $wgs84['type'],
            'point_count'       => geoPointCount($wgs84),
            'bbox'              => $bbox,
            'centroid'          => $centroid,
        ];
    }

    if (empty($converted)) {
        $out['error'] = 'The polygon boundaries could not be converted to valid map '
            . 'coordinates using this coordinate reference system. Please choose the '
            . 'coordinate system that CENRO used for this file.';
        $out['needs_crs'] = true;
        $out['suggested_crs'] = projSuggestPreset(polygonCombinedBbox($features));
        return $out;
    }

    if ($skipped > 0) {
        $out['warnings'][] = $skipped . ' feature(s) in the file were skipped because their '
            . 'geometry or coordinates were not valid.';
    }

    $out['ok'] = true;
    $out['crs_description'] = $description;
    $out['features'] = $converted;
    return $out;
}

/**
 * Re-projects an already-parsed import using a different coordinate
 * reference system, without requiring the operator to upload again.
 *
 * Works from the preserved source-CRS geometry, so repeated attempts
 * never compound rounding from an earlier conversion.
 */
function polygonReprojectPending(array $pending, string $crsChoice): array
{
    $rawFeatures = $pending['raw_features'] ?? [];

    if (empty($rawFeatures)) {
        // Fall back to the original geometry carried on converted features.
        foreach ($pending['features'] ?? [] as $feature) {
            if (!empty($feature['original_geometry'])) {
                $rawFeatures[] = [
                    'geometry'   => $feature['original_geometry'],
                    'attributes' => $feature['attributes'] ?? [],
                    'index'      => $feature['index'] ?? 0,
                ];
            }
        }
    }

    if (empty($rawFeatures)) {
        return ['ok' => false, 'error' => 'The import preview is no longer available. '
            . 'Please upload the file again.', 'pending' => $pending];
    }

    $converted = polygonConvertFeatures(
        $rawFeatures,
        $pending['format'] ?? 'shapefile',
        null,
        null,
        $crsChoice
    );

    if (!$converted['ok']) {
        return [
            'ok' => false,
            'error' => $converted['error'],
            'needs_crs' => $converted['needs_crs'] ?? true,
            'pending' => $pending,
        ];
    }

    $pending['features'] = $converted['features'];
    $pending['crs_description'] = $converted['crs_description'];
    $pending['crs_choice'] = $crsChoice;
    $pending['needs_crs'] = false;
    $pending['warnings'] = $converted['warnings'];

    return ['ok' => true, 'error' => '', 'pending' => $pending];
}

/**
 * Bounding box across all raw (pre-conversion) features, used for the
 * "does this look like lat/lon?" check.
 */
function polygonCombinedBbox(array $features): ?array
{
    $combined = null;

    foreach ($features as $feature) {
        $bbox = geoBoundingBox($feature['geometry']);
        if ($bbox === null) {
            continue;
        }

        if ($combined === null) {
            $combined = $bbox;
            continue;
        }

        $combined['min_x'] = min($combined['min_x'], $bbox['min_x']);
        $combined['min_y'] = min($combined['min_y'], $bbox['min_y']);
        $combined['max_x'] = max($combined['max_x'], $bbox['max_x']);
        $combined['max_y'] = max($combined['max_y'], $bbox['max_y']);
    }

    return $combined;
}

/**
 * Copies the original upload into permanent storage so the official
 * CENRO file is retained alongside the imported record.
 *
 * Returns the stored filename, or null if archiving was not possible
 * (which is not fatal - the import still proceeds).
 */
function polygonArchiveUpload(array $prepared, string $workDir): ?string
{
    $archive = $prepared['archive'] ?? [];
    if (empty($archive['path']) || !is_readable($archive['path'])) {
        return null;
    }

    $extension = $archive['ext'] ?? 'dat';

    // For a loose Shapefile set, bundle the components so the whole set
    // is preserved together rather than just the .shp.
    if ($extension === 'shp' && !empty($archive['set']) && class_exists('ZipArchive')) {
        $bundlePath = $workDir . '/bundle.zip';
        $zip = new ZipArchive();

        if ($zip->open($bundlePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($archive['set'] as $component => $path) {
                if ($path !== null && is_readable($path)) {
                    $zip->addFile($path, 'polygon.' . $component);
                }
            }
            $zip->close();

            if (is_readable($bundlePath)) {
                $archive['path'] = $bundlePath;
                $extension = 'zip';
            }
        }
    }

    $storedName = polygonSafeStoredName($extension);
    $target = POLYGON_STORAGE_DIR . '/' . $storedName;

    if (!@copy($archive['path'], $target)) {
        error_log('Polygon import: could not archive source file.');
        return null;
    }

    @chmod($target, 0640);

    return $storedName;
}
