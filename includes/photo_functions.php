<?php
/**
 * Field Photos — shared logic.
 *
 * Handles saving an uploaded field photograph, extracting its EXIF
 * metadata (GPS coordinates, capture date/time, camera make/model),
 * and reading back stored photo records.
 *
 * Requires: config/database.php (getDbConnection())
 * Requires the PHP `exif` extension to be enabled (uncomment
 * `extension=exif` in php.ini for XAMPP) — without it, EXIF fields
 * are simply left null.
 */

require_once __DIR__ . '/../config/database.php';

const PHOTO_UPLOAD_DIR   = __DIR__ . '/../uploads/field_photos/';
const PHOTO_UPLOAD_URL   = '/RFP/uploads/field_photos/';
const PHOTO_MAX_BYTES    = 8 * 1024 * 1024; // 8 MB
const PHOTO_ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

/**
 * Handles a full photo upload: validates the file, moves it into
 * storage, resolves EXIF metadata, and inserts the record. Returns
 * the new photo_id on success.
 *
 * $clientExif holds whatever the browser already parsed out of the
 * file's EXIF data (see the EXIF.js reader in user/photos/upload.php)
 * and posted alongside it as
 * exif_latitude/exif_longitude/exif_datetime/exif_make/exif_model.
 * It's untrusted input like any other POST field, so every field is
 * validated and range-checked before use, never inserted as-is.
 *
 * Server-side exif_read_data() is the preferred source, since it reads
 * the file we actually stored rather than trusting the browser. But it
 * sometimes can't retrieve GPS data that a browser-side reader can (for
 * example, some mobile share/gallery pickers strip or reformat EXIF in
 * ways exif_read_data() chokes on but a JS parser handles fine). So the
 * two sources are merged per field: for each of latitude/longitude/
 * datetime/make/model, the server-side value is used when present, and
 * the client-side value fills in only the fields the server came back
 * null on.
 *
 * @throws RuntimeException on validation or filesystem failure.
 */
function saveFieldPhotoUpload(int $userId, array $file, array $clientExif = []): int
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
    $clientSanitized = sanitizeClientExif($clientExif);

    // Latitude and longitude are always taken from the same source
    // together, so a server lat paired with a client lng (or vice
    // versa) can never happen.
    if ($exif['latitude'] === null && $exif['longitude'] === null) {
        $exif['latitude'] = $clientSanitized['latitude'];
        $exif['longitude'] = $clientSanitized['longitude'];
    }
    if ($exif['datetime'] === null) {
        $exif['datetime'] = $clientSanitized['datetime'];
    }
    if ($exif['make'] === null) {
        $exif['make'] = $clientSanitized['make'];
    }
    if ($exif['model'] === null) {
        $exif['model'] = $clientSanitized['model'];
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'INSERT INTO field_photos
            (user_id, file_name, original_name, file_size,
             exif_latitude, exif_longitude, exif_datetime,
             camera_make, camera_model)
         VALUES
            (:user_id, :file_name, :original_name, :file_size,
             :exif_lat, :exif_lng, :exif_datetime,
             :camera_make, :camera_model)'
    );

    $stmt->execute([
        'user_id'       => $userId,
        'file_name'     => $storedName,
        'original_name' => $file['name'],
        'file_size'     => $file['size'],
        'exif_lat'      => $exif['latitude'],
        'exif_lng'      => $exif['longitude'],
        'exif_datetime' => $exif['datetime'],
        'camera_make'   => $exif['make'],
        'camera_model'  => $exif['model'],
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
 * Validates the EXIF fields the browser posted alongside the upload.
 *
 * Always returns a well-formed exif array (same shape as
 * extractPhotoExif()): any field that's missing, malformed, or out of
 * range is simply left null rather than trusted. This is untrusted
 * client input like any other POST field — nothing here is inserted
 * as-is; saveFieldPhotoUpload() only uses a given field from this
 * result when the server-side extraction came back null for it.
 */
function sanitizeClientExif(array $clientExif): array
{
    $result = [
        'latitude'  => null,
        'longitude' => null,
        'datetime'  => null,
        'make'      => null,
        'model'     => null,
    ];

    $lat = trim((string)($clientExif['latitude'] ?? ''));
    $lng = trim((string)($clientExif['longitude'] ?? ''));
    if ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)) {
        $latF = (float)$lat;
        $lngF = (float)$lng;
        if ($latF >= -90 && $latF <= 90 && $lngF >= -180 && $lngF <= 180) {
            $result['latitude'] = round($latF, 7);
            $result['longitude'] = round($lngF, 7);
        }
    }

    $rawDateTime = trim((string)($clientExif['datetime'] ?? ''));
    if ($rawDateTime !== '') {
        $parsed = DateTime::createFromFormat('Y:m:d H:i:s', $rawDateTime);
        if ($parsed) {
            $result['datetime'] = $parsed->format('Y-m-d H:i:s');
        }
    }

    $make = trim((string)($clientExif['make'] ?? ''));
    if ($make !== '') {
        $result['make'] = mb_substr($make, 0, 100);
    }

    $model = trim((string)($clientExif['model'] ?? ''));
    if ($model !== '') {
        $result['model'] = mb_substr($model, 0, 100);
    }

    return $result;
}

/**
 * Extracts GPS coordinates, capture date/time, and camera make/model
 * from a JPEG's EXIF data. Returns nulls for any field that isn't
 * present (e.g. PNGs, or photos with GPS/location services off).
 *
 * This is the server-side fallback path, used when the browser didn't
 * (or couldn't) supply pre-parsed EXIF fields — see saveFieldPhotoUpload().
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
 * Fetches every field photo across all users, newest first, with the
 * uploader's and reviewer's names joined in. Used by the Admin/Manager
 * review screens. Optionally filtered to a single status.
 */
function getAllFieldPhotos(?string $statusFilter = null): array
{
    $pdo = getDbConnection();

    $sql = 'SELECT fp.*, u.full_name AS uploader_name, u.username AS uploader_username,
                   r.full_name AS reviewer_name
            FROM field_photos fp
            JOIN users u ON u.user_id = fp.user_id
            LEFT JOIN users r ON r.user_id = fp.reviewed_by';

    $params = [];
    if ($statusFilter !== null && $statusFilter !== '') {
        $sql .= ' WHERE fp.status = :status';
        $params['status'] = $statusFilter;
    }
    $sql .= ' ORDER BY fp.uploaded_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Fetches a single field photo by ID, with the uploader's and
 * reviewer's names joined in. Returns null if not found.
 */
function findFieldPhotoWithNamesById(int $photoId): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT fp.*, u.full_name AS uploader_name, u.username AS uploader_username,
                r.full_name AS reviewer_name
         FROM field_photos fp
         JOIN users u ON u.user_id = fp.user_id
         LEFT JOIN users r ON r.user_id = fp.reviewed_by
         WHERE fp.photo_id = :id'
    );
    $stmt->execute(['id' => $photoId]);
    $photo = $stmt->fetch();
    return $photo ?: null;
}

/**
 * Records an Admin/Manager's confirm or reject decision on a photo.
 */
function reviewFieldPhoto(int $photoId, string $decision, int $reviewerId, ?string $notes): void
{
    if (!in_array($decision, ['confirmed', 'rejected'], true)) {
        throw new InvalidArgumentException('Invalid review decision.');
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'UPDATE field_photos
         SET status = :status, reviewed_by = :reviewer, reviewed_at = NOW(), review_notes = :notes
         WHERE photo_id = :id'
    );
    $stmt->execute([
        'status'   => $decision,
        'reviewer' => $reviewerId,
        'notes'    => ($notes !== null && $notes !== '') ? $notes : null,
        'id'       => $photoId,
    ]);
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