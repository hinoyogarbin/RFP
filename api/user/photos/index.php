<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/photo_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = authenticateApiRequest();
requireApiRole($user, 'user');

$photos = getFieldPhotosByUser((int)$user['user_id']);

$result = array_map(static function (array $p): array {
    return [
        'photo_id'       => (int)$p['photo_id'],
        'photo_url'      => apiPhotoUrl($p['file_name']),
        'original_name'  => $p['original_name'],
        'exif_latitude'  => $p['exif_latitude'] !== null ? (float)$p['exif_latitude'] : null,
        'exif_longitude' => $p['exif_longitude'] !== null ? (float)$p['exif_longitude'] : null,
        'exif_datetime'  => $p['exif_datetime'],
        'camera_make'    => $p['camera_make'],
        'camera_model'   => $p['camera_model'],
        'status'         => $p['status'],
        'uploaded_at'    => $p['uploaded_at'],
    ];
}, $photos);

jsonResponse(['success' => true, 'photos' => $result]);
