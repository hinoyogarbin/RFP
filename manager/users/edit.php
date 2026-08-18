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
$formData = [
    'full_name'      => $user['full_name'],
    'username'       => $user['username'],
    'contact_number' => $user['contact_number'] ?? '',
    'role'            => 'user', // a manager can never change an account's role away from 'user'
    'status'          => $user['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'full_name'      => $_POST['full_name'] ?? '',
        'username'       => $_POST['username'] ?? '',
        'contact_number' => $_POST['contact_number'] ?? '',
        'role'            => 'user', // enforced server-side regardless of any submitted value
        'status'          => $_POST['status'] ?? '',
    ];

    $errors = validateUserData($formData, false, $userId);

    if (empty($errors)) {
        updateUser($userId, $formData);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'User updated successfully.'];
        header('Location: index.php');
        exit;
    }
}

$pageTitle = 'Edit User';
require_once __DIR__ . '/../../includes/header.php';
?>
<h1>Edit User</h1>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form action="edit.php?id=<?= (int)$user['user_id'] ?>" method="post" class="form">
    <input type="hidden" name="id" value="<?= (int)$user['user_id'] ?>">

    <label for="full_name">Full Name</label>
    <input type="text" id="full_name" name="full_name" value="<?= h($formData['full_name']) ?>" required>

    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="<?= h($formData['username']) ?>" required>

    <label for="contact_number">Contact Number</label>
    <input type="text" id="contact_number" name="contact_number" value="<?= h($formData['contact_number']) ?>">

    <label for="role">Role</label>
    <input type="text" value="User" disabled>

    <label for="status">Status</label>
    <select id="status" name="status" required>
        <option value="active" <?= $formData['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $formData['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a class="btn" href="index.php">Cancel</a>
    </div>
</form>

<hr class="divider">

<h2>Password</h2>
<p>Passwords cannot be edited here directly. Use the dedicated form below.</p>
<a class="btn" href="change_password.php?id=<?= (int)$user['user_id'] ?>">Change Password</a>

<?php require_once __DIR__ . '/../../includes/page_end.php'; ?>
