<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../../includes/photo_functions.php';
require_once __DIR__ . '/../../includes/log_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$photoId = (int)($_POST['id'] ?? 0);
$photo = $photoId ? findFieldPhotoById($photoId) : null;

// Users may only delete their own photos.
if ($photo && (int)$photo['user_id'] === (int)$_SESSION['user_id']) {
    deleteFieldPhoto($photo);
    logActivity($_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], 'Delete Photo', 'Field Photos', "Deleted field photo #{$photoId}.", 'success');
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Photo deleted.'];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Photo not found.'];
}

header('Location: index.php');
exit;
