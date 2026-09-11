<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/photo_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = authenticateApiRequest();
requireApiRole($user, 'user');

$photoId = (int)($_GET['id'] ?? 0);
$photo = $photoId ? findFieldPhotoWithNamesById($photoId) : null;

// Users may only view their own photos (same rule as the web app).
if (!$photo || (int)$photo['user_id'] !== (int)$user['user_id']) {
    jsonError('Photo not found.', 404);
}

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
        'reviewer_name'  => $photo['reviewer_name'],
        'reviewed_at'    => $photo['reviewed_at'],
        'review_notes'   => $photo['review_notes'],
        'uploaded_at'    => $photo['uploaded_at'],
    ],
]);
