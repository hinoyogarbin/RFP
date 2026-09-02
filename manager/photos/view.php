<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('manager');
require_once __DIR__ . '/../../includes/user_functions.php';
require_once __DIR__ . '/../../includes/photo_functions.php';

$photoId = (int)($_GET['id'] ?? 0);
$photo = $photoId ? findFieldPhotoWithNamesById($photoId) : null;

if (!$photo) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Photo not found.'];
    header('Location: index.php');
    exit;
}

$hasLocation = ($photo['recorded_latitude'] !== null) || ($photo['exif_latitude'] !== null);

$extraHead = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
    . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">';

$pageTitle = 'Field Photo Review';
require_once __DIR__ . '/../../includes/header.php';
?>
<h1>Field Photo Review</h1>

<img class="photo-full" src="<?= h(PHOTO_UPLOAD_URL . $photo['file_name']) ?>" alt="Field photo">

<div class="details-box">
    <div class="details-row">
        <span class="details-label">Submitted By</span>
        <span class="details-value"><?= h($photo['uploader_name']) ?> (<?= h($photo['uploader_username']) ?>)</span>
    </div>
    <div class="details-row">
        <span class="details-label">Original Filename</span>
        <span class="details-value"><?= h($photo['original_name']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Uploaded</span>
        <span class="details-value"><?= h(date('F j, Y g:i A', strtotime($photo['uploaded_at']))) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Date/Time Taken (EXIF)</span>
        <span class="details-value"><?= $photo['exif_datetime'] ? h(date('F j, Y g:i A', strtotime($photo['exif_datetime']))) : 'Not available in photo metadata' ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Camera</span>
        <span class="details-value"><?= h(trim(($photo['camera_make'] ?? '') . ' ' . ($photo['camera_model'] ?? '')) ?: 'Not available') ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">EXIF GPS Coordinates</span>
        <span class="details-value">
            <?= $photo['exif_latitude'] !== null ? h($photo['exif_latitude'] . ', ' . $photo['exif_longitude']) : 'Not available in photo metadata' ?>
        </span>
    </div>
    <div class="details-row">
        <span class="details-label">Recorded GPS (marker)</span>
        <span class="details-value">
            <?= $photo['recorded_latitude'] !== null ? h($photo['recorded_latitude'] . ', ' . $photo['recorded_longitude']) : 'Not available' ?>
        </span>
    </div>
    <div class="details-row">
        <span class="details-label">Location Check</span>
        <span class="details-value">
            <span class="status-badge location-<?= h($photo['location_match']) ?>">
                <?php if ($photo['location_match'] === 'match'): ?>
                    Match &mdash; <?= h((string)$photo['distance_meters']) ?> m apart
                <?php elseif ($photo['location_match'] === 'mismatch'): ?>
                    Mismatch &mdash; <?= h((string)$photo['distance_meters']) ?> m apart
                <?php else: ?>
                    Unavailable
                <?php endif; ?>
            </span>
        </span>
    </div>
    <div class="details-row">
        <span class="details-label">Review Status</span>
        <span class="details-value">
            <span class="status-badge review-<?= h($photo['status']) ?>"><?= h(ucfirst($photo['status'])) ?></span>
            <?php if ($photo['status'] !== 'pending'): ?>
                &mdash; by <?= h($photo['reviewer_name'] ?? 'Unknown') ?> on <?= h(date('F j, Y g:i A', strtotime($photo['reviewed_at']))) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php if (!empty($photo['review_notes'])): ?>
        <div class="details-row">
            <span class="details-label">Reviewer Notes</span>
            <span class="details-value"><?= h($photo['review_notes']) ?></span>
        </div>
    <?php endif; ?>
</div>

<?php if ($hasLocation): ?>
    <div class="dashboard-map-section">
        <h2>Photo Location</h2>
        <p class="dashboard-map-caption">Click the marker to view the photo.</p>
        <div id="photo-location-map" class="dashboard-map"></div>
    </div>
<?php endif; ?>

<?php if ($photo['status'] === 'pending'): ?>
    <div class="form">
        <h2>Review Decision</h2>
        <form action="review.php" method="post">
            <input type="hidden" name="id" value="<?= (int)$photo['photo_id'] ?>">
            <label for="notes">Notes (optional)</label>
            <textarea id="notes" name="notes" rows="3" style="font-family: inherit; padding: 8px; border: 1px solid #999999;"></textarea>
            <div class="form-actions">
                <button type="submit" name="decision" value="confirmed" class="btn btn-primary">Confirm</button>
                <button type="submit" name="decision" value="rejected" class="btn link-danger">Reject</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="form-actions">
    <a class="btn" href="index.php">Back to Field Photos</a>
</div>

<?php
if ($hasLocation) {
    $mapLat = $photo['recorded_latitude'] ?? $photo['exif_latitude'];
    $mapLng = $photo['recorded_longitude'] ?? $photo['exif_longitude'];
    $photoUrl = PHOTO_UPLOAD_URL . $photo['file_name'];

    $extraScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" '
        . 'integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>'
        . '<script>
            var map = L.map("photo-location-map").setView([' . (float)$mapLat . ', ' . (float)$mapLng . '], 17);
            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                maxZoom: 19,
                attribution: "&copy; OpenStreetMap contributors"
            }).addTo(map);

            var marker = L.marker([' . (float)$mapLat . ', ' . (float)$mapLng . ']).addTo(map);
            marker.bindPopup(' . json_encode('<img src="' . $photoUrl . '" alt="Field photo" style="width:220px;height:auto;display:block;">') . ');
        </script>';
}
require_once __DIR__ . '/../../includes/page_end.php';
?>
