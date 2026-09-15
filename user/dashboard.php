<?php
require_once __DIR__ . '/../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../includes/user_functions.php';

$pageTitle = 'User Dashboard';

// Active-state flags for bottom nav (mirrors the $isDashboard / $isUsers
// pattern already used in header.php)
$isHome     = true;
$isSpecies  = false;
$isReports  = false;
$isProfile  = false;

$extraHead = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
    . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">'
    . '<link rel="stylesheet" href="/RFP/assets/css/user.css">';

require_once __DIR__ . '/../includes/header.php';
?>
<h1>Welcome, <?= h($_SESSION['full_name']) ?></h1>


<div class="dashboard-map-section">
    <h2>Reforestation Areas</h2>
    <p class="dashboard-map-caption">Northern Bukidnon State College, Manolo Fortich, Bukidnon.</p>
    <div id="dashboard-map" class="dashboard-map"></div>
</div>

<!-- =========================================
     BOTTOM NAV — Mobile & Tablet only
     Adjust hrefs to match your actual user-role
     filenames if they differ from the ones below.
========================================== -->
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

<?php
$extraScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" '
    . 'integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>'
    . '<script>
        var map = L.map("dashboard-map").setView([8.365, 124.866], 17);
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution: "&copy; OpenStreetMap contributors"
        }).addTo(map);
        L.marker([8.365, 124.866]).addTo(map)
            .bindPopup("Northern Bukidnon State College")
            .openPopup();
    </script>';
require_once __DIR__ . '/../includes/page_end.php';
?>