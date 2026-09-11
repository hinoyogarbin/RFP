<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/photo_functions.php';
require_once __DIR__ . '/../../../includes/log_functions.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'], true)) {
    jsonError('Method not allowed.', 405);
}

$user = authenticateApiRequest();
requireApiRole($user, 'user');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$photoId = (int)($_POST['id'] ?? $input['id'] ?? $_GET['id'] ?? 0);
$photo = $photoId ? findFieldPhotoById($photoId) : null;

// Users may only delete their own photos (same rule as the web app).
if (!$photo || (int)$photo['user_id'] !== (int)$user['user_id']) {
    jsonError('Photo not found.', 404);
}

deleteFieldPhoto($photo);

logActivity(
    $user['user_id'],
    $user['full_name'],
    $user['role'],
    'Delete Photo',
    'Field Photos',
    "Deleted field photo #{$photoId} via mobile app.",
    'success'
);

jsonResponse(['success' => true]);
