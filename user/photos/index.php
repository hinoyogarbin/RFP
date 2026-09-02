<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../../includes/user_functions.php';
require_once __DIR__ . '/../../includes/photo_functions.php';

$photos = getFieldPhotosByUser((int)$_SESSION['user_id']);

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
    <a class="btn btn-primary" href="upload.php">Upload Photo</a>
</div>

<table class="data-table">
    <thead>
    <tr>
        <th>Photo</th>
        <th>Taken (EXIF)</th>
        <th>Camera</th>
       
        <th>Uploaded</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($photos)): ?>
        <tr>
            <td colspan="6" class="empty-row">No field photos uploaded yet.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($photos as $p): ?>
            <tr>
                <td>
                    <a href="view.php?id=<?= (int)$p['photo_id'] ?>">
                        <img class="photo-thumb" src="<?= h(PHOTO_UPLOAD_URL . $p['file_name']) ?>" alt="Field photo thumbnail">
                    </a>
                </td>
                <td><?= $p['exif_datetime'] ? h(date('F j, Y g:i A', strtotime($p['exif_datetime']))) : '<span class="hint">Not available</span>' ?></td>
                <td><?= h(trim(($p['camera_make'] ?? '') . ' ' . ($p['camera_model'] ?? '')) ?: '-') ?></td>
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
                <td><?= h(date('F j, Y g:i A', strtotime($p['uploaded_at']))) ?></td>
                <td class="actions-cell">
                    <a href="view.php?id=<?= (int)$p['photo_id'] ?>">View</a>
                    <form action="delete.php" method="post" class="inline-form"
                          onsubmit="return confirm('Delete this photo? This cannot be undone.');">
                        <input type="hidden" name="id" value="<?= (int)$p['photo_id'] ?>">
                        <button type="submit" class="link-button link-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/../../includes/page_end.php'; ?>
