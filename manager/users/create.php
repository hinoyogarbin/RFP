<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('manager');
require_once __DIR__ . '/../../includes/user_functions.php';

$errors = [];
$formData = [
    'full_name'      => '',
    'username'       => '',
    'contact_number' => '',
    'role'            => 'user', // fixed - a manager can only create User accounts
    'status'          => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'full_name'      => $_POST['full_name'] ?? '',
        'username'       => $_POST['username'] ?? '',
        'contact_number' => $_POST['contact_number'] ?? '',
        'role'            => 'user', // enforced server-side regardless of any submitted value
        'status'          => $_POST['status'] ?? '',
    ];

    $errors = validateUserData(array_merge($formData, [
        'password'         => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? '',
    ]), true);

    if (empty($errors)) {
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
<p class="hint">As a Manager, you can only create accounts with the User role.</p>

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
    <input type="text" value="User" disabled>

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
