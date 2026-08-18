<?php
/**
 * Activity Logging Helper
 * Reforestation Management Platform - Admin Audit Trail
 *
 * Provides a single reusable function, logActivity(), to be called
 * from any module (auth, user management, monitoring records, etc.)
 * whenever a trackable action occurs.
 *
 * Requires: config/database.php (getDbConnection())
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Record an activity log entry.
 *
 * @param int|null $userId     Session user_id, or null if unknown (e.g. failed login with bad username)
 * @param string   $fullName   Display name of the actor (or the attempted username for failed logins)
 * @param string   $role       'admin' | 'manager' | 'user' (best guess if unauthenticated, e.g. 'user')
 * @param string   $action     Short action label, e.g. 'Login', 'Create User', 'Delete Monitoring Record'
 * @param string   $module     Module/page area, e.g. 'Authentication', 'User Management', 'Monitoring'
 * @param string   $description Free-text detail of what happened
 * @param string   $status     'success' | 'failed' | 'warning'
 */
function logActivity(
    ?int $userId,
    string $fullName,
    string $role,
    string $action,
    string $module,
    string $description = '',
    string $status = 'success'
): void {
    try {
        $pdo = getDbConnection();

        $sql = "INSERT INTO activity_logs
                    (user_id, full_name, role, action, module, description, ip_address, user_agent, status)
                VALUES
                    (:user_id, :full_name, :role, :action, :module, :description, :ip_address, :user_agent, :status)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id'     => $userId,
            ':full_name'   => $fullName,
            ':role'        => $role,
            ':action'      => $action,
            ':module'      => $module,
            ':description' => $description,
            ':ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':status'      => $status,
        ]);
    } catch (PDOException $e) {
        // Never let logging failures break the actual request.
        error_log('Activity log insert failed: ' . $e->getMessage());
    }
}