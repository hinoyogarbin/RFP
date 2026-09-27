<?php
/**
 * CENRO Polygon Management - delete a polygon.
 *
 * Restricted to Admin. A Manager reaching this endpoint directly - by
 * URL or by replaying the form - is refused server-side.
 */

require_once __DIR__ . '/_bootstrap.php';

polygonRequirePost();

if (!canDeletePolygons($currentRole)) {
    logPolygonActivity(
        'Delete Polygon',
        'Blocked polygon deletion attempt by a non-admin account.',
        'failed'
    );
    denyAccess();
}

$polygonId = (int)($_POST['id'] ?? 0);
$polygon = $polygonId > 0 ? findPolygonById($polygonId) : null;

if ($polygon === null) {
    polygonSetFlash('error', 'The requested polygon could not be found.');
    polygonRedirect('index.php');
}

try {
    deletePolygon($polygonId);

    logPolygonActivity(
        'Deleted Polygon',
        sprintf(
            'Deleted polygon "%s" (%s, ID %d), originally imported from "%s".',
            $polygon['polygon_name'],
            $polygon['polygon_code'],
            $polygonId,
            $polygon['source_file'] ?? 'unknown file'
        ),
        'success'
    );

    polygonSetFlash('success', 'Polygon deleted successfully.');

} catch (PDOException $e) {
    error_log('Polygon delete failed: ' . $e->getMessage());
    polygonSetFlash('error', 'The polygon could not be deleted. It may be referenced by other records.');
}

polygonRedirect('index.php');
