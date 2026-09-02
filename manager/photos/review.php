<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('manager');
require_once __DIR__ . '/../../includes/photo_functions.php';
require_once __DIR__ . '/../../includes/log_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$photoId = (int)($_POST['id'] ?? 0);
$decision = $_POST['decision'] ?? '';
$notes = trim($_POST['notes'] ?? '');

$photo = $photoId ? findFieldPhotoById($photoId) : null;

if (!$photo || !in_array($decision, ['confirmed', 'rejected'], true)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid review request.'];
    header('Location: index.php');
    exit;
}

reviewFieldPhoto($photoId, $decision, (int)$_SESSION['user_id'], $notes);

logActivity($_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], ucfirst($decision) . ' Photo', 'Field Photos', "Marked field photo #{$photoId} as {$decision}.", 'success');

$_SESSION['flash'] = ['type' => 'success', 'message' => 'Photo marked as ' . $decision . '.'];
header('Location: view.php?id=' . $photoId);
exit;
