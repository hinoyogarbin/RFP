<?php
require_once __DIR__ . '/../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../includes/role_check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/polygon_functions.php';

$role = 'admin';
$polygons = getPolygonsList();
$analytics = getPolygonDashboardAnalytics();
$mapPayload = buildMapPayload($polygons);
$pageTitle = 'Admin Dashboard';

$extraHead = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
    . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">';

require_once __DIR__ . '/../includes/header.php';
?>
<h1>Welcome, <?= h($_SESSION['full_name']) ?></h1>

<?php require __DIR__ . '/../includes/dashboard_polygon_panel.php'; ?>

<?php
$extraScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" '
    . 'integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>'
    . '<script src="/RFP/assets/js/polygon_map.js?v=4"></script>'
    . '<script>initPolygonMap("dashboard-map", ' . jsonForScript($mapPayload) . ', '
    . '{locked: true, allowOverview: true});</script>';
require_once __DIR__ . '/../includes/page_end.php';
?>
