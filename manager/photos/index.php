<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('manager');
require_once __DIR__ . '/../../includes/user_functions.php';
require_once __DIR__ . '/../../includes/photo_functions.php';

$statusFilter = $_GET['status'] ?? '';
$photos = getAllFieldPhotos($statusFilter);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Field Photos';
require_once __DIR__ . '/../../includes/header.php';
?>
<h1>Field Photos</h1>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="toolbar">
    <form action="index.php" method="get" class="filter-form">
        <select name="status">
            <option value="">All Statuses</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
        <button type="submit" class="btn">Filter</button>
    </form>
</div>

<table class="data-table">
    <thead>
    <tr>
        <th>Photo</th>
        <th>Submitted By</th>
        <th>Taken (EXIF)</th>
        <th>Location Check</th>
        <th>Review Status</th>
        <th>Uploaded</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($photos)): ?>
        <tr>
            <td colspan="7" class="empty-row">No field photos found.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($photos as $p): ?>
            <tr>
                <td>
                    <a href="view.php?id=<?= (int)$p['photo_id'] ?>">
                        <img class="photo-thumb" src="<?= h(PHOTO_UPLOAD_URL . $p['file_name']) ?>" alt="Field photo thumbnail">
                    </a>
                </td>
                <td><?= h($p['uploader_name']) ?> (<?= h($p['uploader_username']) ?>)</td>
                <td><?= $p['exif_datetime'] ? h(date('F j, Y g:i A', strtotime($p['exif_datetime']))) : '<span class="hint">Not available</span>' ?></td>
                <td>
                    <span class="status-badge location-<?= h($p['location_match']) ?>">
                        <?php if ($p['location_match'] === 'match'): ?>
                            Match (<?= h((string)$p['distance_meters']) ?> m)
                        <?php elseif ($p['location_match'] === 'mismatch'): ?>
                            Mismatch (<?= h((string)$p['distance_meters']) ?> m)
                        <?php else: ?>
                            Unavailable
                        <?php endif; ?>
                    </span>
                </td>
                <td>
                    <span class="status-badge review-<?= h($p['status']) ?>"><?= h(ucfirst($p['status'])) ?></span>
                </td>
                <td><?= h(date('F j, Y g:i A', strtotime($p['uploaded_at']))) ?></td>
                <td class="actions-cell">
                    <a href="view.php?id=<?= (int)$p['photo_id'] ?>">View</a>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/../../includes/page_end.php'; ?>
