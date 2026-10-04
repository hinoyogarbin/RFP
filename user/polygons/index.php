<?php
/**
 * Field User - My Assigned Area (read-only).
 *
 * A Field User sees ONLY the polygons assigned to their own account.
 * The restriction is applied in the SQL query via assigned_user_id, so
 * it cannot be bypassed by changing a URL. No management controls are
 * rendered, and the management endpoints refuse this role anyway.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../../includes/polygon_functions.php';

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$polygons = getPolygonsForFieldUser($currentUserId);
$mapPayload = buildMapPayload($polygons);

$pageTitle = 'My Assigned Area';

$extraHead = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
    . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">';

require_once __DIR__ . '/../../includes/header.php';
?>
<h1>My Assigned Area</h1>

<?php if (empty($polygons)): ?>

    <div class="alert alert-info">
        You do not have a reforestation area assigned yet. Please contact your
        Administrator or Manager.
    </div>

<?php else: ?>

    <?php foreach ($polygons as $polygon): ?>
        <div class="details-box">
            <div class="details-row">
                <span class="details-label">Polygon</span>
                <span><?= h($polygon['polygon_code']) ?></span>
            </div>
            <div class="details-row">
                <span class="details-label">Name</span>
                <span><?= h($polygon['polygon_name']) ?></span>
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

        <div class="toolbar">
            <a class="btn" href="view.php?id=<?= (int)$polygon['polygon_id'] ?>">View Map</a>
        </div>

        <hr class="divider">
    <?php endforeach; ?>

    <div class="dashboard-map-section">
        <h2>Assigned Boundaries</h2>
        <p class="dashboard-map-caption">
            The official CENRO boundary of the area assigned to you.
        </p>
        <div id="polygon-map" class="dashboard-map"></div>
    </div>

<?php endif; ?>

<?php
$extraScripts = '';

if (!empty($polygons)) {
    $extraScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" '
        . 'integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>'
        . '<script src="/RFP/assets/js/polygon_map.js?v=3"></script>'
        . '<script>initPolygonMap("polygon-map", ' . jsonForScript($mapPayload) . ');</script>';
}

require_once __DIR__ . '/../../includes/page_end.php';
?>
