<?php
/**
 * Shared page header + top navigation bar.
 * Expects $pageTitle to be set before including this file.
 * Requires auth_check.php to already have run (so $_SESSION is available).
 */

// Safety net: define h() here if it hasn't been defined yet anywhere else.
// Prevents fatal errors on any page that includes header.php without
// first loading whatever file normally provides h().
if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

$pageTitle = $pageTitle ?? 'Reforestation Management Platform';
$role = $_SESSION['role'] ?? '';
$fullName = $_SESSION['full_name'] ?? '';

// Auto-detect the active nav item from the current script path,
// so individual pages don't need to set anything extra.
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
$isDashboard = (strpos($currentScript, 'dashboard.php') !== false);
$isUsers     = (strpos($currentScript, '/users/') !== false);
$isLogs      = (strpos($currentScript, 'logs.php') !== false);
$isSpecies   = (strpos($currentScript, 'species-indicator.php') !== false || strpos($currentScript, 'Species-indicator.php') !== false);

$dashboardUrl = "/RFP/{$role}/dashboard.php";
$usersUrl = "/RFP/{$role}/users/index.php";
$logsUrl = "/RFP/admin/logs.php";
$speciesUrl = "/RFP/{$role}/Species-indicator.php";

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
    <link rel="stylesheet" href="/RFP/assets/css/style.css?v=5">
    <?= $extraHead ?? '' ?>
</head>
<body>
<nav class="topbar">
    <a class="topbar-brand" href="<?= h($dashboardUrl) ?>">
        <img class="topbar-logo" src="/RFP/assets/img/logo.png" alt="RFP logo">
        <span class="topbar-brand-text">Reforestation MP</span>
    </a>

    <input type="checkbox" id="navToggle" class="nav-toggle-checkbox">
    <label for="navToggle" class="nav-toggle-label" aria-label="Open menu">&#9776;</label>

    <div class="topbar-center">
        <div class="topbar-nav">
            <?php if (in_array($role, ['admin', 'manager', 'user'], true)): ?>
                <a class="topbar-item <?= $isDashboard ? 'active' : '' ?>" href="<?= h($dashboardUrl) ?>">Dashboard</a>
            <?php endif; ?>
            <?php if (in_array($role, ['admin', 'manager'], true)): ?>
                <a class="topbar-item <?= $isUsers ? 'active' : '' ?>" href="<?= h($usersUrl) ?>">User Management</a>
            <?php endif; ?>
            <?php if (in_array($role, ['admin', 'manager'], true)): ?>
                <a class="topbar-item <?= $isSpecies ? 'active' : '' ?>" href="<?= h($speciesUrl) ?>">Species Indicator</a>
            <?php endif; ?>
            <?php if ($role === 'admin'): ?>
                <a class="topbar-item <?= $isLogs ? 'active' : '' ?>" href="<?= h($logsUrl) ?>">Activity Logs</a>
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