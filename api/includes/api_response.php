<?php
/**
 * Shared JSON response helpers for the mobile API.
 */

function jsonResponse($data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function jsonError(string $message, int $statusCode = 400): void
{
    jsonResponse(['success' => false, 'error' => $message], $statusCode);
}

/**
 * Builds an absolute URL to a stored field photo file, so the mobile
 * app (which has no session/relative-path context) can load it directly.
 * Requires PHOTO_UPLOAD_URL (defined in includes/photo_functions.php)
 * to already be loaded before this is called.
 */
function apiPhotoUrl(string $fileName): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . PHOTO_UPLOAD_URL . $fileName;
}
