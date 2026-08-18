<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('manager');
require_once __DIR__ . '/../../includes/user_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$userId = (int)($_POST['id'] ?? 0);
$user = $userId ? findUserById($userId) : null;

if (!$user || !canManageTargetRole('manager', $user['role'])) {
    denyAccess();
}

deleteUser($userId);

$_SESSION['flash'] = ['type' => 'success', 'message' => 'User deleted successfully.'];
header('Location: index.php');
exit;
