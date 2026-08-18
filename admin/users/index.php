<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('admin');
require_once __DIR__ . '/../../includes/user_functions.php';

$search       = trim($_GET['search'] ?? '');
$roleFilter   = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Admin can see/manage all roles.
$users = getUsersList(VALID_ROLES, $search, $roleFilter, $statusFilter);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'User Management';
require_once __DIR__ . '/../../includes/header.php';
?>
<h1>User Management</h1>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="toolbar">
    <a class="btn btn-primary" href="create.php">Add User</a>

    <form action="index.php" method="get" class="filter-form">
        <input type="text" name="search" placeholder="Search name or username" value="<?= h($search) ?>">

        <select name="role">
            <option value="">All Roles</option>
            <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
            <option value="manager" <?= $roleFilter === 'manager' ? 'selected' : '' ?>>Manager</option>
            <option value="user" <?= $roleFilter === 'user' ? 'selected' : '' ?>>User</option>
        </select>

        <select name="status">
            <option value="">All Statuses</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>

        <button type="submit" class="btn">Filter</button>
        
    </form>
</div>

<table class="data-table">
    <thead>
    <tr>
        <th>User ID</th>
        <th>Full Name</th>
        <th>Username</th>
        <th>Contact Number</th>
        <th>Role</th>
        <th>Status</th>
        <th>Date Created</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($users)): ?>
        <tr>
            <td colspan="8" class="empty-row">No users found.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= h((string)$u['user_id']) ?></td>
                <td><?= h($u['full_name']) ?></td>
                <td><?= h($u['username']) ?></td>
                <td><?= h($u['contact_number'] ?? '-') ?></td>
                <td><?= h(ucfirst($u['role'])) ?></td>
                <td>
                    <span class="status-badge status-<?= h($u['status']) ?>">
                        <?= h(ucfirst($u['status'])) ?>
                    </span>
                </td>
                <td><?= h(date('F j, Y', strtotime($u['date_created']))) ?></td>
                <td class="actions-cell">
                    <a href="view.php?id=<?= (int)$u['user_id'] ?>">View</a>
                    <a href="edit.php?id=<?= (int)$u['user_id'] ?>">Edit</a>
                    <form action="toggle_status.php" method="post" class="inline-form">
                        <input type="hidden" name="id" value="<?= (int)$u['user_id'] ?>">
                        <button type="submit" class="link-button">
                            <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                    <form action="delete.php" method="post" class="inline-form"
                          onsubmit="return confirm('Are you sure you want to delete this user?');">
                        <input type="hidden" name="id" value="<?= (int)$u['user_id'] ?>">
                        <button type="submit" class="link-button link-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/../../includes/page_end.php'; ?>
