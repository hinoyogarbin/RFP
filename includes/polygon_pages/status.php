<?php
/**
 * CENRO Polygon Management - change polygon status.
 *
 * Admin and Manager only. Field Users cannot reach this endpoint:
 * _bootstrap.php already restricts the page to POLYGON_MANAGER_ROLES.
 */

require_once __DIR__ . '/_bootstrap.php';

polygonRequirePost();

$polygonId = (int)($_POST['id'] ?? 0);
$newStatus = (string)($_POST['status'] ?? '');

$polygon = $polygonId > 0 ? findPolygonById($polygonId) : null;

if ($polygon === null) {
    polygonSetFlash('error', 'The requested polygon could not be found.');
    polygonRedirect('index.php');
}

if (!in_array($newStatus, VALID_POLYGON_STATUSES, true)) {
    polygonSetFlash('error', 'Invalid polygon status selected.');
    polygonRedirect('view.php?id=' . $polygonId);
}

try {
    setPolygonStatus($polygonId, $newStatus);

    logPolygonActivity(
        'Changed Polygon Status',
        sprintf(
            'Polygon "%s" (%s, ID %d) status changed from %s to %s.',
            $polygon['polygon_name'],
            $polygon['polygon_code'],
            $polygonId,
            polygonStatusLabel($polygon['status']),
            polygonStatusLabel($newStatus)
        ),
        'success'
    );

    polygonSetFlash('success', 'Polygon status updated to ' . polygonStatusLabel($newStatus) . '.');

} catch (PDOException $e) {
    error_log('Polygon status change failed: ' . $e->getMessage());
    polygonSetFlash('error', 'The polygon status could not be updated. Please try again.');
}

polygonRedirect('view.php?id=' . $polygonId);
