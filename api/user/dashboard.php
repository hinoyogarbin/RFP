<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = authenticateApiRequest();
requireApiRole($user, 'user');

jsonResponse([
    'success'   => true,
    'full_name' => $user['full_name'],
    // Mirrors the marker shown on the web dashboard (user/dashboard.php).
    'area'      => [
        'name'      => 'Northern Bukidnon State College, Manolo Fortich, Bukidnon',
        'latitude'  => 8.365,
        'longitude' => 124.866,
    ],
]);
