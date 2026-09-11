<?php
/**
 * Bearer-token authentication for the mobile API.
 *
 * The existing web app authenticates with PHP sessions, which don't
 * suit a mobile client well. This adds a parallel, opt-in token
 * scheme (api_tokens table) that sits alongside the session-based
 * auth without changing it: web logins are untouched, mobile logins
 * get a long-lived random token to send as `Authorization: Bearer <token>`.
 */

require_once __DIR__ . '/../../config/database.php';

const API_TOKEN_TTL_SECONDS = 60 * 60 * 24 * 30; // 30 days

/**
 * Creates and stores a new token for a user, returning it.
 */
function issueApiToken(int $userId): string
{
    $pdo = getDbConnection();
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + API_TOKEN_TTL_SECONDS);

    $stmt = $pdo->prepare(
        'INSERT INTO api_tokens (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)'
    );
    $stmt->execute(['user_id' => $userId, 'token' => $token, 'expires_at' => $expiresAt]);

    return $token;
}

/**
 * Reads the Bearer token from the Authorization header, if present.
 */
function getBearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!$header && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

/**
 * Validates the request's Bearer token and returns the authenticated
 * user's row. Sends a 401/403 JSON error and exits if invalid.
 */
function authenticateApiRequest(): array
{
    $token = getBearerToken();
    if (!$token) {
        jsonError('Missing or invalid Authorization header.', 401);
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT u.* FROM api_tokens t
         JOIN users u ON u.user_id = t.user_id
         WHERE t.token = :token AND t.expires_at > NOW()'
    );
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonError('Invalid or expired token. Please log in again.', 401);
    }

    if ($user['status'] !== 'active') {
        jsonError('This account is inactive. Please contact an administrator.', 403);
    }

    return $user;
}

/**
 * Ensures the authenticated user has a specific role, otherwise
 * sends a 403 JSON error and exits.
 */
function requireApiRole(array $user, string $role): void
{
    if ($user['role'] !== $role) {
        jsonError('Forbidden: this endpoint is not available for your role.', 403);
    }
}

/**
 * Deletes the token used for the current request (logout).
 */
function revokeApiToken(): void
{
    $token = getBearerToken();
    if ($token) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('DELETE FROM api_tokens WHERE token = :token');
        $stmt->execute(['token' => $token]);
    }
}
