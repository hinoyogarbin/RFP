let currentPage = 1;
let currentQuery = '';
let map;
let markerClusterGroup;

document.addEventListener('DOMContentLoaded', () => {
    // Base Layers
    const osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '© OpenStreetMap contributors'
    });

    const satelliteLayer = L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
        maxZoom: 18,
        attribution: '© Satellite imagery by Maps.com'
    });

    map = L.map('map', {
        center: [12, 122],
        zoom: 3,
        layers: [osmLayer]
    });

    markerClusterGroup = L.markerClusterGroup();
    map.addLayer(markerClusterGroup);

    L.control.layers({
        "OpenStreetMap": osmLayer,
        "Satellite": satelliteLayer
    }).addTo(map);
});

async function startSearch() {
    currentPage = 1;
    currentQuery = document.getElementById('query').value;
    await searchObservations();
    logSpeciesSearch(currentQuery); // fire-and-forget audit log
}

async function changePage(offset) {
    currentPage += offset;
    if (currentPage < 1) currentPage = 1;
    await searchObservations();
}

async function searchObservations() {
    if (!currentQuery) return;

    const resultsDiv = document.getElementById('results');
    const resultCountDiv = document.getElementById('resultCount');
    resultsDiv.innerHTML = '';
    resultCountDiv.textContent = `Loading results...`;

    if (markerClusterGroup) markerClusterGroup.clearLayers();

    let allObservations = [];
    let page = 1;
    const perPage = 30;
    const maxPages = 5;

    while (page <= maxPages) {
        const res = await fetch(`https://api.inaturalist.org/v1/observations?taxon_name=${encodeURIComponent(currentQuery)}&per_page=${perPage}&page=${page}`);
        const data = await res.json();
        allObservations = allObservations.concat(data.results);
        if (data.results.length < perPage) break;
        page++;
    }

    resultCountDiv.textContent = `Total on Map: ${allObservations.length}`;
    const start = (currentPage - 1) * 9;
    const end = start + 9;
    const currentResults = allObservations.slice(start, end);

    currentResults.forEach(obs => {
        const imgUrl = obs.photos?.[0]?.url?.replace('square', 'small') || '';
        const div = document.createElement('div');
        div.className = 'col-12';
        div.innerHTML = `
      <div class="card h-100 shadow-sm" onclick='showModal(${JSON.stringify(obs).replace(/'/g, "\\'")})'>
        <div class="row g-0">
          <div class="col-3">
            <img src="${imgUrl}" class="img-fluid rounded-start h-100 object-fit-cover" alt="Observation Image" loading="lazy">
          </div>
          <div class="col-9">
            <div class="card-body">
              <h5 class="card-title mb-1">${obs.taxon?.name || 'Unknown'}</h5>
              <p class="card-text mb-0 text-muted">${obs.place_guess || 'Unknown location'}</p>
            </div>
          </div>
        </div>
      </div>
    `;
        resultsDiv.appendChild(div);
    });

    let firstCoord = null;
    allObservations.forEach(obs => {
        if (obs.geojson?.coordinates) {
            const [lng, lat] = obs.geojson.coordinates;
            const marker = L.marker([lat, lng]).bindPopup(`
        <strong>${obs.taxon?.name || 'Unknown'}</strong><br/>
        ${obs.place_guess || 'Unknown location'}
      `);
            markerClusterGroup.addLayer(marker);
            if (!firstCoord) firstCoord = [lat, lng];
        }
    });

    if (firstCoord) map.setView(firstCoord, 2);
    document.getElementById('pageInfo').textContent = `Page ${currentPage}`;
}

function showModal(obs) {
    document.getElementById('modalImage').src = obs.photos?.[0]?.url?.replace('square', 'large') || '';
    document.getElementById('modalScientificName').textContent = obs.taxon?.name || 'Unknown';
    document.getElementById('modalCommonName').textContent = obs.taxon?.preferred_common_name || '';
    document.getElementById('modalLocation').textContent = `Location: ${obs.place_guess || 'Unknown'}`;
    document.getElementById('modalDate').textContent = `Observed on: ${new Date(obs.observed_on).toDateString() || 'N/A'}`;
    new bootstrap.Modal(document.getElementById('observationModal')).show();
}

async function fetchSuggestions() {
    const input = document.getElementById('query');
    const list = document.getElementById('suggestions');
    const query = input.value.trim();
    if (query.length < 2) {
        list.innerHTML = '';
        return;
    }

    const res = await fetch(`https://api.inaturalist.org/v1/taxa/autocomplete?q=${encodeURIComponent(query)}`);
    const data = await res.json();
    list.innerHTML = '';
    data.results.slice(0, 10).forEach(taxon => {
        const li = document.createElement('li');
        li.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2';
        const img = taxon.default_photo?.square_url || 'https://via.placeholder.com/40?text=?';
        li.innerHTML = `
      <img src="${img}" alt="${taxon.name}" width="40" height="40" class="rounded" loading="lazy">
      <div class="text-start">
        <div><strong>${taxon.preferred_common_name || ''}</strong></div>
        <div class="text-muted"><small>${taxon.name}</small></div>
      </div>
    `;
        li.onclick = () => {
            input.value = taxon.name;
            list.innerHTML = '';
            startSearch();
        };
        list.appendChild(li);
    });
}

document.addEventListener('click', e => {
    if (!document.getElementById('query').contains(e.target)) {
        document.getElementById('suggestions').innerHTML = '';
    }
});

function resetMapView() {
    map.setView([12, 122], 3);
}

// Logs each search to your audit trail without triggering a full page reload
function logSpeciesSearch(query) {
    if (!query) return;
    fetch('/RFP/admin/Species-log.php?ajax=1', {   // fixed path + fixed target file
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `query=${encodeURIComponent(query)}`
    }).catch(() => { });
}