<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('admin');
require_once __DIR__ . '/../../includes/user_functions.php';

$userId = (int)($_GET['id'] ?? 0);
$user = $userId ? findUserById($userId) : null;

if (!$user) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'User not found.'];
    header('Location: index.php');
    exit;
}

$pageTitle = 'User Details';
require_once __DIR__ . '/../../includes/header.php';
?>
<h1>User Details</h1>

<div class="details-box">
    <div class="details-row">
        <span class="details-label">User ID</span>
        <span class="details-value"><?= h((string)$user['user_id']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Full Name</span>
        <span class="details-value"><?= h($user['full_name']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Username</span>
        <span class="details-value"><?= h($user['username']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Contact Number</span>
        <span class="details-value"><?= h($user['contact_number'] ?? '-') ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Role</span>
        <span class="details-value"><?= h(ucfirst($user['role'])) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Status</span>
        <span class="details-value">
            <span class="status-badge status-<?= h($user['status']) ?>"><?= h(ucfirst($user['status'])) ?></span>
        </span>
    </div>
    <div class="details-row">
        <span class="details-label">Date Created</span>
        <span class="details-value"><?= h(date('F j, Y', strtotime($user['date_created']))) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Date Updated</span>
        <span class="details-value"><?= h(date('F j, Y', strtotime($user['date_updated']))) ?></span>
    </div>
</div>

<div class="form-actions">
    <a class="btn btn-primary" href="edit.php?id=<?= (int)$user['user_id'] ?>">Edit</a>
    <a class="btn" href="index.php">Back to User Management</a>
</div>

<?php require_once __DIR__ . '/../../includes/page_end.php'; ?>
