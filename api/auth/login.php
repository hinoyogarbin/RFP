<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/user_functions.php';
require_once __DIR__ . '/../../includes/log_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$username = trim($input['username'] ?? '');
$password = (string)($input['password'] ?? '');

if ($username === '' || $password === '') {
    jsonError('Username and password are required.', 422);
}

$user = findUserByUsername($username);

if (!$user || !password_verify($password, $user['password'])) {
    logActivity(
        null,
        $username ?: '(empty)',
        'user',
        'Failed Login Attempt',
        'Authentication',
        "Failed mobile login attempt for username '{$username}'.",
        'failed'
    );
    jsonError('Invalid username or password.', 401);
}

if ($user['status'] !== 'active') {
    logActivity(
        $user['user_id'],
        $user['full_name'],
        $user['role'],
        'Login',
        'Authentication',
        'Mobile login blocked: account inactive.',
        'warning'
    );
    jsonError('This account is inactive. Please contact an administrator.', 403);
}

$token = issueApiToken((int)$user['user_id']);

logActivity(
    $user['user_id'],
    $user['full_name'],
    $user['role'],
    'Login',
    'Authentication',
    'User logged in successfully via mobile app.',
    'success'
);

jsonResponse([
    'success' => true,
    'token'   => $token,
    'user'    => [
        'user_id'   => (int)$user['user_id'],
        'full_name' => $user['full_name'],
        'username'  => $user['username'],
        'role'      => $user['role'],
    ],
]);
