<?php
require_once __DIR__ . '/../includes/auth_check.php';
$pageTitle = 'Species Indicator';
$extraHead = '<link rel="stylesheet" href="/RFP/assets/css/species-indicator.css">';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid mt-3">
  <h3 class="mb-3">Species Indicator</h3>
  <p class="text-muted">Search and visualize species observations via the iNaturalist databasae.</p>

  <div class="row mb-3">
    <div class="col-md-6 position-relative">
      <input type="text" id="query" class="form-control" placeholder="Search species (e.g. Ficus benjamina)"
             oninput="fetchSuggestions()" onkeydown="if(event.key==='Enter') startSearch()">
      <ul id="suggestions" class="list-group position-absolute w-100" style="z-index: 1000;"></ul>
    </div>
    <div class="col-md-2">
      <button class="btn btn-success w-100" onclick="startSearch()">Search</button>
    </div>
    <div class="col-md-2">
      <button class="btn btn-outline-secondary w-100" onclick="resetMapView()">Reset Map</button>
    </div>
  </div>

  <div class="row">
    <div class="col-md-6">
      <div id="map" style="height: 500px; border-radius: 8px;"></div>
    </div>
    <div class="col-md-6">
      <div id="resultCount" class="mb-2 text-muted"></div>
      <div id="results" class="row g-2" style="max-height: 500px; overflow-y: auto;"></div>
      <div class="d-flex justify-content-between mt-2">
        <button class="btn btn-sm btn-outline-primary" onclick="changePage(-1)">Previous</button>
        <span id="pageInfo">Page 1</span>
        <button class="btn btn-sm btn-outline-primary" onclick="changePage(1)">Next</button>
      </div>
    </div>
  </div>
</div>

<!-- Observation Detail Modal -->
<div class="modal fade" id="observationModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalScientificName">Species</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <img id="modalImage" src="" class="img-fluid rounded mb-3" alt="Observation">
        <p id="modalCommonName" class="fw-bold"></p>
        <p id="modalLocation" class="text-muted mb-1"></p>
        <p id="modalDate" class="text-muted"></p>
      </div>
    </div>
  </div>
</div>

<!-- Leaflet + MarkerCluster (CDN) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>

<script src="/RFP/assets/js/SpeciesAPI.js"></script>

