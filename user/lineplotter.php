<?php
/**
 * user/Photo-plotter.php
 *
 * EXIF Photo Plotter with Road Paths - User role feature.
 *
 * Lets a user upload geotagged photos, plots their GPS locations on a
 * Leaflet map, draws a road path between them (via OSRM, with a
 * straight-line fallback), and shows distance/time-between-photos info.
 *
 * Every successful upload / route generation / map clear is recorded
 * to the activity log via an AJAX call to Photo-log.php.
 *
 * NOTE ON PATHS: adjust the include paths below ("../includes/...")
 * if your actual folder structure differs from admin/manager pages.
 */

require_once __DIR__ . '/../includes/auth_check.php'; // must set $_SESSION user info, enforce 'user' role
require_once __DIR__ . '/../includes/log_functions.php'; // provides logActivity()

// ---- Nav auto-detection flag (used by header.php for active-state) ----
$isPhotoPlotter = true;
$pageTitle = 'Photo Plotter';

// ---- CSRF token (reuse session token if one already exists, else create) ----
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ---- Page-specific head assets, injected into header.php ----
$extraHead = <<<HTML
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="/RFP/assets/css/lineplotter.css">
HTML;

require_once __DIR__ . '/../includes/header.php';
?>

<main class="pp-page">

    <div class="pp-header">
        <h1 class="pp-title">Photo Plotter</h1>
        <p class="pp-subtitle">
            Upload geotagged photos to plot their GPS locations and generate a road path.
        </p>
    </div>

    <div class="pp-layout">

        <!-- ================================================= -->
        <!-- LEFT PANEL -->
        <!-- ================================================= -->
        <section class="pp-panel">

            <div class="pp-card pp-upload-card">
                <input type="file" id="imageInput" class="pp-visually-hidden" multiple accept="image/*">
                <label for="imageInput" class="pp-btn pp-btn-primary">
                    <span class="pp-icon" aria-hidden="true">+</span>
                    Add Images
                </label>

                <div class="pp-loading" id="loadingSpinner">
                    <span class="pp-spinner" role="status" aria-label="Processing photos"></span>
                    <span>Processing photos...</span>
                </div>
            </div>

            <div id="statusMessage" class="pp-alert" hidden></div>

            <div id="pathInfo" class="pp-info-box" hidden>
                <strong>Total Distance</strong>
                <span id="totalDistance">0 km</span>
            </div>

            <div id="timeInfo" class="pp-info-box" hidden>
                <strong>Time Between Photos</strong>
                <div id="timeDifference">N/A</div>
            </div>

            <div class="pp-gallery-header">
                <h2 class="pp-h2">Photo Gallery</h2>
                <span id="photoCount" class="pp-badge">0</span>
            </div>

            <div id="photoCards" class="pp-gallery">
                <div class="pp-empty-gallery">
                    <div class="pp-empty-icon" aria-hidden="true">&#128247;</div>
                    <div>No photos uploaded</div>
                </div>
            </div>

        </section>

        <!-- ================================================= -->
        <!-- RIGHT PANEL -->
        <!-- ================================================= -->
        <section class="pp-panel pp-panel-map">

            <div class="pp-map-controls">
                <button id="fitButton" class="pp-btn pp-btn-outline" type="button">Fit Photos</button>
                <button id="clearButton" class="pp-btn pp-btn-danger" type="button">Clear Map</button>
            </div>

            <div id="map" class="pp-map"></div>

        </section>

    </div>
    <nav class="bottom-nav" aria-label="Primary mobile navigation">
    <a href="/RFP/user/user.php" class="<?= $isHome ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/></svg>
        <span>Home</span>
    </a>
    <a href="/RFP/user/Species-indicator.php" class="<?= $isSpecies ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <span>Species</span>
    </a>
    <a href="/RFP/user/lineplotter.php" class="<?= $isLinePlotter ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg>
        <span>Line plotter</span>
    </a>
    <a href="/RFP/user/profile.php" class="<?= $isProfile ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
        <span>Profile</span>
    </a>
</nav>

</main>

<!-- CSRF token + current user context for the logging AJAX calls -->
<script>
    window.PP_CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
</script>

<!-- Leaflet + EXIF libraries (functional dependencies, not a styling framework) -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/exif-js"></script>
<link rel="stylesheet" href="/RFP/assets/css/user.css">

<!-- Page logic -->
<script src="/RFP/assets/js/lineplotter.js"></script>

<?php   require_once __DIR__ . '/../includes/page_end.php'; ?>