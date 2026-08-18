<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('admin');
require_once __DIR__ . '/../../includes/user_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$userId = (int)($_POST['id'] ?? 0);
$user = $userId ? findUserById($userId) : null;

if (!$user) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'User not found.'];
    header('Location: index.php');
    exit;
}

if ($userId === (int)$_SESSION['user_id']) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'You cannot deactivate your own account.'];
    header('Location: index.php');
    exit;
}

toggleUserStatus($userId);

$_SESSION['flash'] = ['type' => 'success', 'message' => 'User status updated.'];
header('Location: index.php');
exit;
