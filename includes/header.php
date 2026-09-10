<?php
/**
 * Shared page header + top navigation bar.
 * Expects $pageTitle to be set before including this file.
 * Requires auth_check.php to already have run (so $_SESSION is available).
 */
$pageTitle = $pageTitle ?? 'Reforestation Management Platform';
$role = $_SESSION['role'] ?? '';
$fullName = $_SESSION['full_name'] ?? '';

// Auto-detect the active nav item from the current script path,
// so individual pages don't need to set anything extra.
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
$isDashboard = (strpos($currentScript, 'dashboard.php') !== false);
$isUsers     = (strpos($currentScript, '/users/') !== false);
$isLogs      = (strpos($currentScript, 'logs.php') !== false);
$isPhotos    = (strpos($currentScript, '/photos/') !== false);

$dashboardUrl = "/RFP/{$role}/dashboard.php";
$usersUrl = "/RFP/{$role}/users/index.php";
$logsUrl = "/RFP/admin/logs.php";
$photosUrl = "/RFP/{$role}/photos/index.php";

// Log every page navigation by a logged-in user (admin or manager).
require_once __DIR__ . '/log_functions.php';
if (!empty($_SESSION['user_id'])) {
    logActivity($_SESSION['user_id'], $fullName, $role, 'Page View', 'Navigation', "Visited {$currentScript}", 'success');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <link rel="stylesheet" href="/RFP/assets/css/style.css?v=8">
    <?= $extraHead ?? '' ?>
</head>
<body>
<nav class="topbar">
    <div class="topbar-left">
        <div class="topbar-nav">
            <?php if (in_array($role, ['admin', 'manager', 'user'], true)): ?>
                <a class="topbar-item <?= $isDashboard ? 'active' : '' ?>" href="<?= h($dashboardUrl) ?>">Dashboard</a>
            <?php endif; ?>
            <?php if (in_array($role, ['admin', 'manager'], true)): ?>
                <a class="topbar-item <?= $isUsers ? 'active' : '' ?>" href="<?= h($usersUrl) ?>">User Management</a>
            <?php endif; ?>
            <?php if ($role === 'admin'): ?>
                <a class="topbar-item <?= $isLogs ? 'active' : '' ?>" href="<?= h($logsUrl) ?>">Activity Logs</a>
            <?php endif; ?>
            <?php if (in_array($role, ['admin', 'manager', 'user'], true)): ?>
                <a class="topbar-item <?= $isPhotos ? 'active' : '' ?>" href="<?= h($photosUrl) ?>">Field Photos</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($fullName): ?>
        <div class="topbar-right">
            <span class="topbar-user"><?= h($fullName) ?> (<?= h(ucfirst($role)) ?>)</span>
            <a class="topbar-logout" href="/RFP/auth/logout.php">Logout</a>
        </div>
    <?php endif; ?>
</nav>

<main class="site-main">