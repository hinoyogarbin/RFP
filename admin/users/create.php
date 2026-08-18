<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('admin');
require_once __DIR__ . '/../../includes/user_functions.php';

$errors = [];
$formData = [
    'full_name'      => '',
    'username'       => '',
    'contact_number' => '',
    'role'            => 'user',
    'status'          => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'full_name'      => $_POST['full_name'] ?? '',
        'username'       => $_POST['username'] ?? '',
        'contact_number' => $_POST['contact_number'] ?? '',
        'role'            => $_POST['role'] ?? '',
        'status'          => $_POST['status'] ?? '',
    ];

    $errors = validateUserData(array_merge($formData, [
        'password'         => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? '',
    ]), true);

    if (empty($errors)) {
        // Admin may create accounts of any role, so no extra scope check needed.
        createUser(array_merge($formData, ['password' => $_POST['password']]));

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'User created successfully.'];
        header('Location: index.php');
        exit;
    }
}

$pageTitle = 'Add User';
require_once __DIR__ . '/../../includes/header.php';
?>
<h1>Add User</h1>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form action="create.php" method="post" class="form">
    <label for="full_name">Full Name</label>
    <input type="text" id="full_name" name="full_name" value="<?= h($formData['full_name']) ?>" required>

    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="<?= h($formData['username']) ?>" required>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required minlength="8">

    <label for="confirm_password">Confirm Password</label>
    <input type="password" id="confirm_password" name="confirm_password" required minlength="8">

    <label for="contact_number">Contact Number</label>
    <input type="text" id="contact_number" name="contact_number" value="<?= h($formData['contact_number']) ?>">

    <label for="role">Role</label>
    <select id="role" name="role" required>
        <option value="admin" <?= $formData['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
        <option value="manager" <?= $formData['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
        <option value="user" <?= $formData['role'] === 'user' ? 'selected' : '' ?>>User</option>
    </select>

    <label for="status">Status</label>
    <select id="status" name="status" required>
        <option value="active" <?= $formData['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $formData['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save User</button>
        <a class="btn" href="index.php">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/page_end.php'; ?>
