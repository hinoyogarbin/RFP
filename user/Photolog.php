<?php
/**
 * user/Photo-log.php
 *
 * AJAX-only endpoint. Records Photo Plotter activity (uploads, route
 * generation, map clears) to the audit log.
 *
 * IMPORTANT: session/auth must be loaded BEFORE anything else so
 * $_SESSION user data is available, but we still respond and exit
 * before any full header/layout include - this endpoint has no HTML
 * output, so there's no "?ajax=1 short-circuit before header.php"
 * concern here the way logs.php has; this file simply never includes
 * header.php/footer.php at all.
 */

require_once __DIR__ . '/../includes/auth_check.php'; // populates $_SESSION user id/fullname/role
require_once __DIR__ . '/../includes/log_functions.php'; // provides logActivity()

header('Content-Type: application/json');

// Only POST is accepted.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Read JSON body.
$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

// ---- CSRF check ----
$submittedToken = $payload['csrf_token'] ?? '';

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

// ---- Validate/sanitize inputs ----
$action = trim((string) ($payload['action'] ?? ''));
$description = trim((string) ($payload['description'] ?? ''));
$status = trim((string) ($payload['status'] ?? 'success'));

if ($action === '' || $description === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing action or description.']);
    exit;
}

// Only allow known status values.
if (!in_array($status, ['success', 'failed'], true)) {
    $status = 'success';
}

// ---- Pull current user from session (adjust keys to match your auth_check.php) ----
$userId = $_SESSION['user_id'] ?? null;
$fullName = $_SESSION['full_name'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'user';

// ---- Write to the audit log ----
// logActivity() takes POSITIONAL arguments only:
// (?int $userId, string $fullName, string $role, string $action, string $module, string $description, string $status)
try {
    logActivity(
        $userId !== null ? (int) $userId : null,
        $fullName,
        $role,
        $action,
        'Photo Plotter',
        $description,
        $status
    );

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('Photo-log.php: logActivity failed - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to record activity log.']);
}