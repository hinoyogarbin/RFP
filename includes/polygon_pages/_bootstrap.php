<?php
/**
 * Common bootstrap for every CENRO Polygon Management page.
 *
 * The admin/polygons/* and manager/polygons/* files are thin wrappers
 * that include a shared page from this folder, so the logic is written
 * once. Access control still runs on every request, server-side.
 */

require_once __DIR__ . '/../auth_check.php';
requireLogin();

require_once __DIR__ . '/../role_check.php';
require_once __DIR__ . '/../polygon_functions.php';

// Only Admin and Manager may reach the management pages. A Field User
// typing the URL directly gets the platform's Access Denied page.
requireRole(POLYGON_MANAGER_ROLES);

/** Current actor, used for logging and permission checks. */
$currentRole = (string)($_SESSION['role'] ?? '');
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

/**
 * Leaflet stylesheet include, matching the existing dashboards.
 */
function polygonLeafletHead(): string
{
    return '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
        . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">';
}

/**
 * Leaflet library plus the shared polygon map helper.
 */
function polygonLeafletScripts(): string
{
    return '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" '
        . 'integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>'
        . '<script src="/RFP/assets/js/polygon_map.js?v=3"></script>';
}

/**
 * Reads a flash message set by a previous request.
 */
function polygonTakeFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

/**
 * Sets a flash message for the next request.
 */
function polygonSetFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Sends the operator back to the polygon list within their own role
 * folder, so admin stays in /admin/ and manager stays in /manager/.
 */
function polygonRedirect(string $file): void
{
    header('Location: ' . $file);
    exit;
}

/**
 * Rejects a non-POST request for state-changing actions.
 */
function polygonRequirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        polygonSetFlash('error', 'Invalid request.');
        polygonRedirect('index.php');
    }
}
