<?php
/**
 * Shared User Management functions.
 * Used by both admin/users/* and manager/users/* pages so the
 * CRUD logic and validation rules live in exactly one place.
 */

require_once __DIR__ . '/../config/database.php';

const VALID_ROLES   = ['admin', 'manager', 'user'];
const VALID_STATUSES = ['active', 'inactive'];

/**
 * Fetches a single user by ID. Returns null if not found.
 */
function findUserById(int $userId): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = :id');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/**
 * Fetches a single user by username. Returns null if not found.
 */
function findUserByUsername(string $username): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u');
    $stmt->execute(['u' => $username]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/**
 * Returns a filtered/searched list of users.
 *
 * @param array $roleScope Roles the caller is allowed to see, e.g. ['user'] for a manager.
 * @param string $search Free-text search across full name / username.
 * @param string $roleFilter '', 'admin', 'manager', or 'user'.
 * @param string $statusFilter '', 'active', or 'inactive'.
 */
function getUsersList(array $roleScope, string $search = '', string $roleFilter = '', string $statusFilter = ''): array
{
    $pdo = getDbConnection();

    $conditions = [];
    $params = [];

    // Restrict to the roles this caller is allowed to manage/view.
    $placeholders = [];
    foreach ($roleScope as $i => $role) {
        $key = "scope_role_$i";
        $placeholders[] = ":$key";
        $params[$key] = $role;
    }
    if ($placeholders) {
        $conditions[] = 'role IN (' . implode(',', $placeholders) . ')';
    }

    if ($search !== '') {
        $conditions[] = '(full_name LIKE :search OR username LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    if ($roleFilter !== '' && in_array($roleFilter, VALID_ROLES, true)) {
        $conditions[] = 'role = :role_filter';
        $params['role_filter'] = $roleFilter;
    }

    if ($statusFilter !== '' && in_array($statusFilter, VALID_STATUSES, true)) {
        $conditions[] = 'status = :status_filter';
        $params['status_filter'] = $statusFilter;
    }

    $sql = 'SELECT user_id, full_name, username, contact_number, role, status, date_created, date_updated FROM users';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY date_created DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Validates data for creating or editing a user.
 *
 * @param array $data Raw input (full_name, username, contact_number, role, status, password, confirm_password).
 * @param bool $isNew Whether this is a new user (password required) or an edit (password not required here).
 * @param int|null $editingUserId The user_id being edited, so the username-uniqueness check can exclude itself.
 * @return array List of human-readable error messages. Empty array = valid.
 */
function validateUserData(array $data, bool $isNew, ?int $editingUserId = null): array
{
    $errors = [];

    $fullName = trim($data['full_name'] ?? '');
    $username = trim($data['username'] ?? '');
    $contact  = trim($data['contact_number'] ?? '');
    $role     = $data['role'] ?? '';
    $status   = $data['status'] ?? '';

    if ($fullName === '') {
        $errors[] = 'Full Name is required.';
    }

    if ($username === '') {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) {
        $errors[] = 'Username must be 3-50 characters and may only contain letters, numbers, underscores, and periods.';
    } else {
        $existing = findUserByUsername($username);
        if ($existing && (int)$existing['user_id'] !== (int)$editingUserId) {
            $errors[] = 'This username is already taken.';
        }
    }

    if ($isNew) {
        $password = $data['password'] ?? '';
        $confirm  = $data['confirm_password'] ?? '';

        if ($password === '') {
            $errors[] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        }

        if ($confirm === '') {
            $errors[] = 'Password confirmation is required.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Password and confirmation do not match.';
        }
    }

    if ($contact !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $contact)) {
        $errors[] = 'Contact number format is invalid.';
    }

    if (!in_array($role, VALID_ROLES, true)) {
        $errors[] = 'Invalid role selected.';
    }

    if (!in_array($status, VALID_STATUSES, true)) {
        $errors[] = 'Invalid status selected.';
    }

    return $errors;
}

/**
 * Validates a new password submission (Change Password form).
 */
function validatePasswordChange(string $password, string $confirm): array
{
    $errors = [];

    if ($password === '') {
        $errors[] = 'New password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }

    if ($confirm === '') {
        $errors[] = 'Password confirmation is required.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Password and confirmation do not match.';
    }

    return $errors;
}

/**
 * Inserts a new user. Assumes validateUserData() already passed.
 */
function createUser(array $data): int
{
    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, username, password, contact_number, role, status)
         VALUES (:full_name, :username, :password, :contact_number, :role, :status)'
    );

    $stmt->execute([
        'full_name'      => trim($data['full_name']),
        'username'       => trim($data['username']),
        'password'       => password_hash($data['password'], PASSWORD_DEFAULT),
        'contact_number' => trim($data['contact_number']) !== '' ? trim($data['contact_number']) : null,
        'role'           => $data['role'],
        'status'         => $data['status'],
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Updates an existing user's profile fields (not the password).
 */
function updateUser(int $userId, array $data): void
{
    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        'UPDATE users
         SET full_name = :full_name,
             username = :username,
             contact_number = :contact_number,
             role = :role,
             status = :status
         WHERE user_id = :id'
    );

    $stmt->execute([
        'full_name'      => trim($data['full_name']),
        'username'       => trim($data['username']),
        'contact_number' => trim($data['contact_number']) !== '' ? trim($data['contact_number']) : null,
        'role'           => $data['role'],
        'status'         => $data['status'],
        'id'             => $userId,
    ]);
}

/**
 * Updates only the password for a given user.
 */
function updateUserPassword(int $userId, string $newPassword): void
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('UPDATE users SET password = :password WHERE user_id = :id');
    $stmt->execute([
        'password' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id'       => $userId,
    ]);
}

/**
 * Toggles a user's status between active and inactive.
 */
function toggleUserStatus(int $userId): void
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        "UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE user_id = :id"
    );
    $stmt->execute(['id' => $userId]);
}

/**
 * Deletes a user permanently.
 * Per the platform's security notes, callers should prefer
 * deactivating an account; this hard-delete is reserved for cases
 * where the account should truly be removed (e.g. created by mistake).
 */
function deleteUser(int $userId): void
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('DELETE FROM users WHERE user_id = :id');
    $stmt->execute(['id' => $userId]);
}

/**
 * Small helper to escape output safely in views.
 */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
