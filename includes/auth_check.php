<?php
/**
 * Starts the session (if not already started) and provides helper
 * functions for login/role redirects.
 *
 * Include this at the very top of every page (before any HTML
 * output). On protected pages, call requireLogin() right after
 * including this file.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Redirects to the login page if no user is logged in.
 */
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /RFP/auth/login.php');
        exit;
    }
}

/**
 * Redirects an already-logged-in user to their role-appropriate
 * dashboard. Useful on the login page so a logged-in user can't
 * see the login form again.
 */
function redirectIfLoggedIn(): void
{
    if (!empty($_SESSION['user_id'])) {
        redirectToDashboard($_SESSION['role']);
    }
}

/**
 * Sends the user to the dashboard matching their role.
 */
function redirectToDashboard(string $role): void
{
    switch ($role) {
        case 'admin':
            header('Location: /RFP/admin/dashboard.php');
            break;
        case 'manager':
            header('Location: /RFP/manager/dashboard.php');
            break;
        default:
            header('Location: /RFP/user/dashboard.php');
            break;
    }
    exit;
}
