<?php
/**
 * Included first by every api/**.php endpoint.
 * Sets permissive CORS headers (tighten Access-Control-Allow-Origin
 * to your app's real origin/domain before going to production) and
 * loads the JSON + auth helpers.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/api_response.php';
require_once __DIR__ . '/api_auth.php';
