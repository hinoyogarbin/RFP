<?php
/**
 * Server-side Role-Based Access Control (RBAC).
 *
 * IMPORTANT: This is the real access control layer. Hiding buttons
 * or links in the HTML is NOT sufficient on its own — every
 * protected page/action must call one of these functions after
 * auth_check.php has run.
 */

/**
 * Allows only the given role(s) to continue. Anyone else is shown
 * an "Access Denied" page and execution stops.
 *
 * @param string|array $allowedRoles e.g. 'admin' or ['admin', 'manager']
 */
function requireRole($allowedRoles): void
{
    if (is_string($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    $currentRole = $_SESSION['role'] ?? null;

    if (!$currentRole || !in_array($currentRole, $allowedRoles, true)) {
        denyAccess();
    }
}

/**
 * Determines whether the currently logged-in user is allowed to
 * manage (view/edit/activate/delete) a target account, based on
 * the target's role.
 *
 * - Admin can manage admin, manager, and user accounts.
 * - Manager can manage only user accounts (never admin or manager).
 * - User cannot manage anyone.
 */
function canManageTargetRole(string $actingRole, string $targetRole): bool
{
    if ($actingRole === 'admin') {
        return true;
    }

    if ($actingRole === 'manager') {
        return $targetRole === 'user';
    }

    return false;
}

/**
 * Stops execution and renders a simple "Access Denied" page.
 */
function denyAccess(): void
{
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Access Denied</title>
        <link rel="stylesheet" href="/reforestation/assets/css/style.css?v=4">
    </head>
    <body>
        <div class="denied-box">
            <h1>Access Denied</h1>
            <p>You do not have permission to access this page.</p>
            <a class="btn" href="/reforestation/index.php">Back to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
