<?php
require_once __DIR__ . '/../../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../../includes/user_functions.php';
require_once __DIR__ . '/../../includes/photo_functions.php';
require_once __DIR__ . '/../../includes/log_functions.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recordedLat = ($_POST['recorded_latitude'] !== '') ? (float)$_POST['recorded_latitude'] : null;
    $recordedLng = ($_POST['recorded_longitude'] !== '') ? (float)$_POST['recorded_longitude'] : null;

    try {
        $photoId = saveFieldPhotoUpload((int)$_SESSION['user_id'], $_FILES['photo'] ?? [], $recordedLat, $recordedLng);

        logActivity($_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], 'Upload Photo', 'Field Photos', "Uploaded field photo #{$photoId}.", 'success');

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Photo uploaded and processed successfully.'];
        header('Location: view.php?id=' . $photoId);
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        logActivity($_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], 'Upload Photo', 'Field Photos', $e->getMessage(), 'failed');
    }
}

$pageTitle = 'Upload Field Photo';
require_once __DIR__ . '/../../includes/header.php';
?>
<h1>Upload Field Photo</h1>

<?php if ($error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endif; ?>

<p class="hint" id="geo-status">Requesting your current location&hellip;</p>

<form action="upload.php" method="post" enctype="multipart/form-data" class="form" id="photo-form">
    <label for="photo">Photo</label>
    <input type="file" id="photo" name="photo" accept="image/jpeg,image/png" capture="environment" required>
    

    <input type="hidden" id="recorded_latitude" name="recorded_latitude" value="">
    <input type="hidden" id="recorded_longitude" name="recorded_longitude" value="">

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Upload &amp; Process</button>
        <a class="btn" href="index.php">Cancel</a>
    </div>
</form>

<script>
(function () {
    var status = document.getElementById('geo-status');

    if (!('geolocation' in navigator)) {
        status.textContent = 'Your browser does not support location services; the location comparison will be skipped.';
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function (position) {
            document.getElementById('recorded_latitude').value = position.coords.latitude;
            document.getElementById('recorded_longitude').value = position.coords.longitude;
            status.textContent = 'Current location captured (accuracy \u00b1' + Math.round(position.coords.accuracy) + 'm). It will be compared against the photo\'s EXIF GPS data.';
        },
        function () {
            status.textContent = 'Could not get your current location; the location comparison will be skipped. You can still upload the photo.';
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
})();
</script>

<?php require_once __DIR__ . '/../../includes/page_end.php'; ?>
