<?php
/**
 * Field Photos — shared logic.
 *
 * Handles saving an uploaded field photograph, extracting its EXIF
 * metadata (GPS coordinates, capture date/time, camera make/model),
 * comparing the EXIF GPS position against the device's recorded GPS
 * position at time of upload, and reading back stored photo records.
 *
 * Requires: config/database.php (getDbConnection())
 * Requires the PHP `exif` extension to be enabled (uncomment
 * `extension=exif` in php.ini for XAMPP) — without it, EXIF fields
 * are simply left null and location_match is 'unavailable'.
 */

require_once __DIR__ . '/../config/database.php';

const PHOTO_UPLOAD_DIR   = __DIR__ . '/../uploads/field_photos/';
const PHOTO_UPLOAD_URL   = '/RFP/uploads/field_photos/';
const PHOTO_MAX_BYTES    = 8 * 1024 * 1024; // 8 MB
const PHOTO_ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

// A photo's EXIF GPS position and the device's recorded GPS position
// are considered a "match" if they're within this many meters of
// each other (accounts for normal GPS drift on phones/cameras).
const PHOTO_LOCATION_MATCH_THRESHOLD_METERS = 75;

/**
 * Handles a full photo upload: validates the file, moves it into
 * storage, extracts EXIF metadata, compares GPS positions, and
 * inserts the record. Returns the new photo_id on success.
 *
 * @throws RuntimeException on validation or filesystem failure.
 */
function saveFieldPhotoUpload(int $userId, array $file, ?float $recordedLat, ?float $recordedLng): int
{
    validatePhotoUpload($file);

    if (!is_dir(PHOTO_UPLOAD_DIR)) {
        mkdir(PHOTO_UPLOAD_DIR, 0755, true);
    }

    $extension = PHOTO_ALLOWED_MIME[mime_content_type($file['tmp_name'])] ?? 'jpg';
    $storedName = uniqid('photo_', true) . '.' . $extension;
    $destination = PHOTO_UPLOAD_DIR . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Failed to save the uploaded photo.');
    }

    $exif = extractPhotoExif($destination);

    $distance = null;
    $locationMatch = 'unavailable';
    if ($recordedLat !== null && $recordedLng !== null && $exif['latitude'] !== null && $exif['longitude'] !== null) {
        $distance = haversineDistanceMeters($recordedLat, $recordedLng, $exif['latitude'], $exif['longitude']);
        $locationMatch = ($distance <= PHOTO_LOCATION_MATCH_THRESHOLD_METERS) ? 'match' : 'mismatch';
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'INSERT INTO field_photos
            (user_id, file_name, original_name, file_size,
             recorded_latitude, recorded_longitude,
             exif_latitude, exif_longitude, exif_datetime,
             camera_make, camera_model,
             distance_meters, location_match)
         VALUES
            (:user_id, :file_name, :original_name, :file_size,
             :recorded_lat, :recorded_lng,
             :exif_lat, :exif_lng, :exif_datetime,
             :camera_make, :camera_model,
             :distance, :location_match)'
    );

    $stmt->execute([
        'user_id'        => $userId,
        'file_name'      => $storedName,
        'original_name'  => $file['name'],
        'file_size'      => $file['size'],
        'recorded_lat'   => $recordedLat,
        'recorded_lng'   => $recordedLng,
        'exif_lat'       => $exif['latitude'],
        'exif_lng'       => $exif['longitude'],
        'exif_datetime'  => $exif['datetime'],
        'camera_make'    => $exif['make'],
        'camera_model'   => $exif['model'],
        'distance'       => $distance,
        'location_match' => $locationMatch,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Validates an uploaded file array (from $_FILES). Throws on failure.
 */
function validatePhotoUpload(array $file): void
{
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please choose a photo to upload.');
    }

    if ($file['size'] > PHOTO_MAX_BYTES) {
        throw new RuntimeException('Photo is too large (max 8 MB).');
    }

    $mime = mime_content_type($file['tmp_name']);
    if (!isset(PHOTO_ALLOWED_MIME[$mime])) {
        throw new RuntimeException('Only JPEG or PNG photos are allowed.');
    }
}

/**
 * Extracts GPS coordinates, capture date/time, and camera make/model
 * from a JPEG's EXIF data. Returns nulls for any field that isn't
 * present (e.g. PNGs, or photos with GPS/location services off).
 */
function extractPhotoExif(string $filePath): array
{
    $result = [
        'latitude'  => null,
        'longitude' => null,
        'datetime'  => null,
        'make'      => null,
        'model'     => null,
    ];

    if (!function_exists('exif_read_data')) {
        return $result;
    }

    // Suppress warnings: exif_read_data() errors out on files with
    // no EXIF data (e.g. PNGs) rather than just returning empty.
    $exif = @exif_read_data($filePath, null, true);
    if ($exif === false) {
        return $result;
    }

    if (!empty($exif['GPS']['GPSLatitude']) && !empty($exif['GPS']['GPSLongitude'])) {
        $lat = gpsCoordinateToDecimal($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLatitudeRef'] ?? 'N');
        $lng = gpsCoordinateToDecimal($exif['GPS']['GPSLongitude'], $exif['GPS']['GPSLongitudeRef'] ?? 'E');
        $result['latitude'] = $lat;
        $result['longitude'] = $lng;
    }

    $rawDateTime = $exif['EXIF']['DateTimeOriginal'] ?? $exif['IFD0']['DateTime'] ?? null;
    if ($rawDateTime) {
        $parsed = DateTime::createFromFormat('Y:m:d H:i:s', $rawDateTime);
        if ($parsed) {
            $result['datetime'] = $parsed->format('Y-m-d H:i:s');
        }
    }

    $result['make'] = isset($exif['IFD0']['Make']) ? trim($exif['IFD0']['Make']) : null;
    $result['model'] = isset($exif['IFD0']['Model']) ? trim($exif['IFD0']['Model']) : null;

    return $result;
}

/**
 * Converts an EXIF GPS coordinate (array of 3 "degrees/minutes/seconds"
 * fractions, e.g. ["8/1", "21/1", "54.12/1"]) plus a hemisphere ref
 * ('N'/'S'/'E'/'W') into a signed decimal degree value.
 */
function gpsCoordinateToDecimal(array $dms, string $ref): float
{
    $degrees = exifFractionToFloat($dms[0] ?? '0/1');
    $minutes = exifFractionToFloat($dms[1] ?? '0/1');
    $seconds = exifFractionToFloat($dms[2] ?? '0/1');

    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

    $ref = strtoupper($ref);
    if ($ref === 'S' || $ref === 'W') {
        $decimal *= -1;
    }

    return round($decimal, 7);
}

/**
 * Converts an EXIF-style fraction string ("54.12/1") to a float.
 */
function exifFractionToFloat(string $fraction): float
{
    $parts = explode('/', $fraction);
    if (count($parts) !== 2 || (float)$parts[1] === 0.0) {
        return (float)($parts[0] ?? 0);
    }
    return (float)$parts[0] / (float)$parts[1];
}

/**
 * Great-circle distance between two lat/lng points, in meters.
 */
function haversineDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000; // meters

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return round($earthRadius * $c, 2);
}

/**
 * Fetches all field photos uploaded by a given user, most recent first.
 */
function getFieldPhotosByUser(int $userId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM field_photos WHERE user_id = :user_id ORDER BY uploaded_at DESC');
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Fetches a single field photo by ID. Returns null if not found.
 */
function findFieldPhotoById(int $photoId): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM field_photos WHERE photo_id = :id');
    $stmt->execute(['id' => $photoId]);
    $photo = $stmt->fetch();
    return $photo ?: null;
}

/**
 * Deletes a field photo record and its file on disk.
 */
function deleteFieldPhoto(array $photo): void
{
    $path = PHOTO_UPLOAD_DIR . $photo['file_name'];
    if (is_file($path)) {
        unlink($path);
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare('DELETE FROM field_photos WHERE photo_id = :id');
    $stmt->execute(['id' => $photo['photo_id']]);
}
