<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

authenticateApiRequest();
revokeApiToken();

jsonResponse(['success' => true]);
