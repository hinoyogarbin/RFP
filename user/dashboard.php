<?php
require_once __DIR__ . '/../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../includes/role_check.php';
requireRole('user');
require_once __DIR__ . '/../includes/user_functions.php';

$pageTitle = 'User Dashboard';

$extraHead = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
    . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">';

require_once __DIR__ . '/../includes/header.php';
?>
<h1>Welcome, <?= h($_SESSION['full_name']) ?></h1>


<div class="dashboard-map-section">
    <h2>Reforestation Areas</h2>
    <p class="dashboard-map-caption">Northern Bukidnon State College, Manolo Fortich, Bukidnon.</p>
    <div id="dashboard-map" class="dashboard-map"></div>
</div>

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
