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
    <link rel="stylesheet" href="/RFP/assets/css/style.css?v=8">
</head>
<body>
<main class="login-main">
    <div class="login-box">
        <h1>Reforestation Management Platform</h1>
        <h2>Login</h2>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form action="authenticate.php" method="post" class="form">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= h($oldUsername) ?>" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit" class="btn btn-primary login-submit">Login</button>
        </form>
    </div>
</main>
</body>
</html>
