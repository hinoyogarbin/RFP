<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../../includes/user_functions.php';
require_once __DIR__ . '/../../includes/photo_functions.php';

$photoId = (int)($_GET['id'] ?? 0);
$photo = $photoId ? findFieldPhotoById($photoId) : null;

// Users may only view their own photos.
if (!$photo || (int)$photo['user_id'] !== (int)$_SESSION['user_id']) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Photo not found.'];
    header('Location: index.php');
    exit;
}

$hasBothPoints = $photo['recorded_latitude'] !== null && $photo['exif_latitude'] !== null;

$extraHead = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
    . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">';

$pageTitle = 'Field Photo Details';
require_once __DIR__ . '/../../includes/header.php';
?>
<h1>Field Photo Details</h1>

<img class="photo-full" src="<?= h(PHOTO_UPLOAD_URL . $photo['file_name']) ?>" alt="Field photo">

<div class="details-box">
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
        <span class="details-label">Recorded GPS (at upload)</span>
        <span class="details-value">
            <?= $photo['recorded_latitude'] !== null ? h($photo['recorded_latitude'] . ', ' . $photo['recorded_longitude']) : 'Not available (location permission denied or unsupported)' ?>
        </span>
    </div>
    
</div>

<?php if ($hasBothPoints): ?>
    <div class="dashboard-map-section">
        
        
        <div id="compare-map" class="dashboard-map"></div>
    </div>
<?php endif; ?>

<div class="form-actions">
    <a class="btn" href="index.php">Back to Field Photos</a>
</div>

<?php
if ($hasBothPoints) {
    $exifLat = (float)$photo['exif_latitude'];
    $exifLng = (float)$photo['exif_longitude'];
    $recLat = (float)$photo['recorded_latitude'];
    $recLng = (float)$photo['recorded_longitude'];
    $midLat = ($exifLat + $recLat) / 2;
    $midLng = ($exifLng + $recLng) / 2;

    $extraScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" '
        . 'integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>'
        . '<script>
            var map = L.map("compare-map").setView([' . $midLat . ', ' . $midLng . '], 16);
            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                maxZoom: 19,
                attribution: "&copy; OpenStreetMap contributors"
            }).addTo(map);

            var exifIcon = L.divIcon({className: "gps-marker gps-marker-exif"});
            var recordedIcon = L.divIcon({className: "gps-marker gps-marker-recorded"});

            L.marker([' . $exifLat . ', ' . $exifLng . '], ).addTo(map)
                .bindPopup("EXIF GPS (from photo)");

            var bounds = L.latLngBounds([[' . $exifLat . ',' . $exifLng . '],[' . $recLat . ',' . $recLng . ']]);
            map.fitBounds(bounds.pad(0.5));
        </script>';
}
require_once __DIR__ . '/../../includes/page_end.php';
?>
