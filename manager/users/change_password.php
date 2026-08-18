<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('manager');
require_once __DIR__ . '/../../includes/user_functions.php';

$userId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$user = $userId ? findUserById($userId) : null;

if (!$user || !canManageTargetRole('manager', $user['role'])) {
    denyAccess();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirm     = $_POST['confirm_new_password'] ?? '';

    $errors = validatePasswordChange($newPassword, $confirm);

    if (empty($errors)) {
        updateUserPassword($userId, $newPassword);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Password updated successfully.'];
        header('Location: index.php');
        exit;
    }
}

$pageTitle = 'Change Password';
require_once __DIR__ . '/../../includes/header.php';
?>
<h1>Change Password</h1>
<p>User: <?= h($user['full_name']) ?> (<?= h($user['username']) ?>)</p>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form action="change_password.php?id=<?= (int)$user['user_id'] ?>" method="post" class="form">
    <input type="hidden" name="id" value="<?= (int)$user['user_id'] ?>">

    <label for="new_password">New Password</label>
    <input type="password" id="new_password" name="new_password" required minlength="8">

    <label for="confirm_new_password">Confirm New Password</label>
    <input type="password" id="confirm_new_password" name="confirm_new_password" required minlength="8">

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Update Password</button>
        <a class="btn" href="edit.php?id=<?= (int)$user['user_id'] ?>">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/page_end.php'; ?>
