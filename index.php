<?php
require_once __DIR__ . '/includes/auth_check.php';

if (empty($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

redirectToDashboard($_SESSION['role']);
