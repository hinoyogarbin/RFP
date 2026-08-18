<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/user_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

$genericError = 'Invalid username or password.';

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = $genericError;
    $_SESSION['old_username'] = $username;
    header('Location: login.php');
    exit;
}

$user = findUserByUsername($username);

if (!$user || !password_verify($password, $user['password'])) {
    $_SESSION['login_error'] = $genericError;
    $_SESSION['old_username'] = $username;
    header('Location: login.php');
    exit;
}

if ($user['status'] !== 'active') {
    $_SESSION['login_error'] = 'This account is inactive. Please contact an administrator.';
    $_SESSION['old_username'] = $username;
    header('Location: login.php');
    exit;
}

// Prevent session fixation by regenerating the session ID on login.
session_regenerate_id(true);

$_SESSION['user_id']   = $user['user_id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['username']  = $user['username'];
$_SESSION['role']      = $user['role'];

redirectToDashboard($user['role']);
