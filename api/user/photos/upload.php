<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/photo_functions.php';
require_once __DIR__ . '/../../../includes/log_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = authenticateApiRequest();
requireApiRole($user, 'user');

// The app pre-parses EXIF client-side (same idea as exif-extract.js on
// the web) and posts it alongside the file; saveFieldPhotoUpload()
// only trusts these as a fallback for whatever the server-side
// exif_read_data() couldn't find.
$clientExif = [
    'latitude'  => $_POST['exif_latitude'] ?? '',
    'longitude' => $_POST['exif_longitude'] ?? '',
    'datetime'  => $_POST['exif_datetime'] ?? '',
    'make'      => $_POST['exif_make'] ?? '',
    'model'     => $_POST['exif_model'] ?? '',
];

try {
    $photoId = saveFieldPhotoUpload((int)$user['user_id'], $_FILES['photo'] ?? [], $clientExif);

    logActivity(
        $user['user_id'],
        $user['full_name'],
        $user['role'],
        'Upload Photo',
        'Field Photos',
        "Uploaded field photo #{$photoId} via mobile app.",
        'success'
    );

    $photo = findFieldPhotoById($photoId);

    jsonResponse([
        'success' => true,
        'photo'   => [
            'photo_id'       => (int)$photo['photo_id'],
            'photo_url'      => apiPhotoUrl($photo['file_name']),
            'original_name'  => $photo['original_name'],
            'exif_latitude'  => $photo['exif_latitude'] !== null ? (float)$photo['exif_latitude'] : null,
            'exif_longitude' => $photo['exif_longitude'] !== null ? (float)$photo['exif_longitude'] : null,
            'exif_datetime'  => $photo['exif_datetime'],
            'camera_make'    => $photo['camera_make'],
            'camera_model'   => $photo['camera_model'],
            'status'         => $photo['status'],
            'uploaded_at'    => $photo['uploaded_at'],
        ],
    ], 201);

} catch (RuntimeException $e) {
    logActivity(
        $user['user_id'],
        $user['full_name'],
        $user['role'],
        'Upload Photo',
        'Field Photos',
        $e->getMessage(),
        'failed'
    );
    jsonError($e->getMessage(), 422);
}
