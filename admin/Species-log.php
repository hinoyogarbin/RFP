<?php
// Must short-circuit BEFORE header.php to avoid double-logging page views
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    require_once __DIR__ . '/../includes/auth.php';       // session/role check only, no page-view log
    require_once __DIR__ . '/../includes/log_functions.php';

    $query = trim($_POST['query'] ?? '');

    if ($query !== '' && isset($_SESSION['user_id'])) {
        logActivity(
            $_SESSION['user_id'],
            $_SESSION['full_name'],
            $_SESSION['role'],
            'Search',
            'Species Indicator',
            "Searched species: {$query}",
            'Success'
        );
    }

    http_response_code(204); // no content needed back
    exit;
}

// If someone hits this file directly without ?ajax=1, block it
http_response_code(403);
exit('Forbidden');