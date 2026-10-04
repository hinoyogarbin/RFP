<?php
/**
 * Field User - view an assigned polygon boundary (read-only).
 *
 * Access is granted only when the polygon is actually assigned to the
 * logged-in account. Requesting another polygon's ID directly returns
 * the platform's Access Denied page rather than the record.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../../includes/polygon_functions.php';

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$polygonId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$polygon = $polygonId > 0 ? findPolygonById($polygonId) : null;

if ($polygon === null) {
    header('Location: index.php');
    exit;
}

// A Field User may only open their own assigned area.
if (!polygonIsAssignedTo($polygon, $currentUserId)) {
    logActivity(
        $currentUserId,
        (string)($_SESSION['full_name'] ?? 'Unknown'),
        'user',
        'View Polygon',
        'CENRO Polygons',
        'Blocked attempt to open polygon ID ' . $polygonId . ' which is not assigned to this account.',
        'failed'
    );
    denyAccess();
}

$mapPayload = buildMapPayload([$polygon]);

$pageTitle = 'Assigned Area';

$extraHead = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
    . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">';

require_once __DIR__ . '/../../includes/header.php';
?>
<h1><?= h($polygon['polygon_name']) ?></h1>

<div class="details-box">
    <div class="details-row">
        <span class="details-label">Polygon</span>
        <span><?= h($polygon['polygon_code']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Project</span>
        <span><?= h($polygon['project_name'] ?? 'Not associated') ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Location</span>
        <span><?= h($polygon['location'] ?? '-') ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Area</span>
        <span><?= h(formatHectares($polygon['area_hectares'])) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Status</span>
        <span class="status-badge status-<?= h($polygon['status']) ?>">
            <?= h(polygonStatusLabel($polygon['status'])) ?>
        </span>
    </div>
    <div class="details-row">
        <span class="details-label">Steward</span>
        <span><?= h($polygon['steward_name'] ?? '-') ?></span>
    </div>
</div>

<div class="dashboard-map-section">
    <h2>Area Boundary</h2>
    <p class="dashboard-map-caption">
        Official CENRO boundary. This is the geographic area for your field activities.
    </p>
    <div id="polygon-map" class="dashboard-map"></div>
</div>

<div class="toolbar">
    <a class="btn" href="index.php">Back</a>
</div>

<?php
$extraScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" '
    . 'integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>'
    . '<script src="/RFP/assets/js/polygon_map.js?v=4"></script>'
    . '<script>initPolygonMap("polygon-map", ' . jsonForScript($mapPayload) . ', {locked: true});</script>';

require_once __DIR__ . '/../../includes/page_end.php';
?>
