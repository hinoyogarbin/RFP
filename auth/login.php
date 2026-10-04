<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/user_functions.php'; // for h()

redirectIfLoggedIn();

$error = '';
if (!empty($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

$oldUsername = $_SESSION['old_username'] ?? '';
unset($_SESSION['old_username']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Reforestation Management Platform</title>
    <link rel="stylesheet" href="/RFP/assets/css/style.css?v=12">
</head>
<body>
<main class="login-main">
    <div class="login-box">
        <div class="login-brand">
            <img class="login-logo" src="/RFP/assets/Logo.png" alt="">
            <h1>Reforestation Management Platform</h1>
        </div>
        <h2>Login</h2>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form action="authenticate.php" method="post" class="form">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= h($oldUsername) ?>" required autofocus>

            
             <label for="password">Password</label>
            <div class="password-wrapper">
                <input type="password" id="password" name="password" required>
                <span class="toggle-password" onclick="togglePassword()">
                    <svg id="eyeIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                </span>
            </div>
            <button type="submit" class="btn btn-primary login-submit">Login</button>
        </form>
    </div>
</main>
<script>
function togglePassword() {
    const input = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');

    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
        input.type = 'password';
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
}
</script>
</body>
</html>
