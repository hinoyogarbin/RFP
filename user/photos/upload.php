```php
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

    try {

        $clientExif = [
            'latitude' => $_POST['exif_latitude'] ?? '',
            'longitude' => $_POST['exif_longitude'] ?? '',
            'datetime' => $_POST['exif_datetime'] ?? '',
            'make' => $_POST['exif_make'] ?? '',
            'model' => $_POST['exif_model'] ?? '',
        ];

        $photoId = saveFieldPhotoUpload(
            (int) $_SESSION['user_id'],
            $_FILES['photo'] ?? [],
            $clientExif
        );

        logActivity(
            $_SESSION['user_id'],
            $_SESSION['full_name'],
            $_SESSION['role'],
            'Upload Photo',
            'Field Photos',
            "Uploaded field photo #{$photoId}.",
            'success'
        );

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => 'Photo uploaded and processed successfully.'
        ];

        header('Location: view.php?id=' . $photoId);
        exit;

    } catch (RuntimeException $e) {

        $error = $e->getMessage();

        logActivity(
            $_SESSION['user_id'],
            $_SESSION['full_name'],
            $_SESSION['role'],
            'Upload Photo',
            'Field Photos',
            $e->getMessage(),
            'failed'
        );
    }
}

$pageTitle = 'Upload Field Photo';

require_once __DIR__ . '/../../includes/header.php';

?>

<h1>Upload Field Photo</h1>

<?php if ($error): ?>

    <div class="alert alert-error">
        <?= h($error) ?>
    </div>

<?php endif; ?>

<form action="upload.php" method="post" enctype="multipart/form-data" class="form" id="photo-form">

    <label for="photo">
        Photo
    </label>

    <input type="file" id="photo" name="photo" required>

    <p class="hint">
        Select the original photo from your phone.
        The system will read the GPS location, date/time,
        and camera information from the photo metadata.
    </p>

    <input type="hidden" name="exif_latitude" id="exif_latitude">

    <input type="hidden" name="exif_longitude" id="exif_longitude">

    <input type="hidden" name="exif_datetime" id="exif_datetime">

    <input type="hidden" name="exif_make" id="exif_make">

    <input type="hidden" name="exif_model" id="exif_model">

    <p class="hint" id="exif-preview" style="
            display: none;
            white-space: pre-line;
        "></p>

    <div class="form-actions">

        <button type="submit" class="btn btn-primary" id="upload-button" disabled>
            Upload &amp; Process
        </button>

        <a class="btn" href="index.php">
            Cancel
        </a>

    </div>

</form>

<script src="https://cdn.jsdelivr.net/npm/exif-js"></script>

<script>

    document
        .getElementById('photo')
        .addEventListener('change', function () {

            const file = this.files[0];

            const preview =
                document.getElementById('exif-preview');

            const uploadButton =
                document.getElementById('upload-button');

            document.getElementById(
                'exif_latitude'
            ).value = '';

            document.getElementById(
                'exif_longitude'
            ).value = '';

            document.getElementById(
                'exif_datetime'
            ).value = '';

            document.getElementById(
                'exif_make'
            ).value = '';

            document.getElementById(
                'exif_model'
            ).value = '';

            uploadButton.disabled = true;

            if (!file) {

                preview.style.display = 'none';

                return;
            }

            preview.style.display = 'block';

            preview.textContent =
                'Reading photo metadata...';

            if (typeof EXIF === 'undefined') {

                preview.textContent =
                    'EXIF.js could not be loaded.\n\n' +
                    'The server will try to read the metadata instead.';

                uploadButton.disabled = false;

                return;
            }

            EXIF.getData(file, function () {

                const gpsLat =
                    file.exifdata.GPSLatitude;

                const gpsLon =
                    file.exifdata.GPSLongitude;

                const gpsLatRef =
                    file.exifdata.GPSLatitudeRef;

                const gpsLonRef =
                    file.exifdata.GPSLongitudeRef;

                const dateTime =
                    file.exifdata.DateTimeOriginal ||
                    file.exifdata.DateTime ||
                    '';

                const make =
                    file.exifdata.Make ||
                    '';

                const model =
                    file.exifdata.Model ||
                    '';

                if (gpsLat && gpsLon) {

                    let latitude =
                        gpsLat[0] +
                        gpsLat[1] / 60 +
                        gpsLat[2] / 3600;

                    let longitude =
                        gpsLon[0] +
                        gpsLon[1] / 60 +
                        gpsLon[2] / 3600;

                    if (
                        String(gpsLatRef).toUpperCase() === 'S'
                    ) {

                        latitude = -latitude;

                    }

                    if (
                        String(gpsLonRef).toUpperCase() === 'W'
                    ) {

                        longitude = -longitude;

                    }

                    document.getElementById(
                        'exif_latitude'
                    ).value = latitude;

                    document.getElementById(
                        'exif_longitude'
                    ).value = longitude;

                    document.getElementById(
                        'exif_datetime'
                    ).value = dateTime;

                    document.getElementById(
                        'exif_make'
                    ).value = make;

                    document.getElementById(
                        'exif_model'
                    ).value = model;

                    preview.textContent =
                        'GPS DETECTED\n\n' +

                        'Latitude: ' +
                        latitude +
                        '\n' +

                        'Longitude: ' +
                        longitude +
                        '\n\n' +

                        'Date: ' +
                        dateTime +
                        '\n\n' +

                        'Camera: ' +
                        make +
                        ' ' +
                        model;

                } else {

                    preview.textContent =
                        'EXIF LOADED\n\n' +

                        'No GPS coordinates were detected.\n\n' +

                        'GPS Latitude: ' +
                        JSON.stringify(gpsLat) +
                        '\n\n' +

                        'GPS Longitude: ' +
                        JSON.stringify(gpsLon);

                    document.getElementById(
                        'exif_datetime'
                    ).value = dateTime;

                    document.getElementById(
                        'exif_make'
                    ).value = make;

                    document.getElementById(
                        'exif_model'
                    ).value = model;

                }

                uploadButton.disabled = false;

            });

        });

</script>

<?php require_once __DIR__ . '/../../includes/page_end.php'; ?>
```