/**
 * Shared Leaflet map helper for CENRO polygons.
 *
 * Polygons arrive already in WGS 84 / Leaflet [lat, lng] order from
 * buildMapPayload() in PHP (the EPSG:32651 -> EPSG:4326 conversion happens
 * at import time in includes/geo/proj.php), so no coordinate juggling
 * happens here.
 *
 * PRIVACY LOCK (default)
 * The polygons are reforested areas covered by legal documents, so the map
 * only ever shows ONE polygon at a time:
 *   - it opens fitted to the current polygon;
 *   - the fitted zoom is the minimum zoom (cannot zoom out);
 *   - panning is restricted to the polygon's bounds;
 *   - imagery outside the polygon is masked;
 *   - Previous / Next buttons (and a selector) move to another polygon.
 *
 * Options: { preview: bool, locked: bool, interactive: bool }
 *   preview  - admin import preview: all polygons shown, not locked, so the
 *              operator can verify the placement against surrounding roads.
 *   locked   - force the privacy lock on/off (default: on unless preview).
 */
(function (global) {
    'use strict';

    // Used only when there is no polygon at all; no boundary data is implied.
    var EMPTY_CENTER = [8.37, 124.86];
    var EMPTY_ZOOM = 11;

    var MAX_ZOOM = 20;      // map zoom ceiling (tiles are over-zoomed past native)
    var FIT_PADDING = 16;   // px on every side when fitting a polygon
    var BOUNDS_PAD = 0.03;  // fraction of polygon size allowed as pan margin

    var BASEMAPS = {
        satellite: {
            label: 'Satellite',
            url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            options: {
                maxZoom: MAX_ZOOM,
                maxNativeZoom: 18,
                attribution: 'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics, '
                    + 'and the GIS User Community'
            }
        },
        street: {
            label: 'Street map',
            url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            options: {
                maxZoom: MAX_ZOOM,
                maxNativeZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }
        }
    };

    // Boundary colours chosen to stay visible on each basemap.
    var STYLES = {
        satellite: {
            Active: { color: '#fff176', weight: 3, fillColor: '#2e8b57', fillOpacity: 0.12 },
            Inactive: { color: '#d0d0d0', weight: 3, fillColor: '#999999', fillOpacity: 0.12 },
            Completed: { color: '#81d4fa', weight: 3, fillColor: '#1f78b4', fillOpacity: 0.14 }
        },
        street: {
            Active: { color: '#1a5c2e', weight: 3, fillColor: '#1a5c2e', fillOpacity: 0.20 },
            Inactive: { color: '#777777', weight: 3, fillColor: '#777777', fillOpacity: 0.15 },
            Completed: { color: '#1f4e79', weight: 3, fillColor: '#1f4e79', fillOpacity: 0.18 }
        },
        preview: { color: '#a1601a', weight: 3, fillColor: '#a1601a', fillOpacity: 0.20, dashArray: '6,5' }
    };

    var MASK_STYLE = {
        stroke: false,
        fillColor: '#0d1f14',
        fillOpacity: 0.9,
        interactive: false
    };

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildPopup(polygon, label) {
        var rows = [
            ['Area', label],
            ['Polygon', polygon.code],
            ['Name', polygon.name],
            ['Project', polygon.project],
            ['Location', polygon.location],
            ['Size', polygon.area],
            ['Steward', polygon.steward],
            ['Status', polygon.status]
        ];

        var html = '<div class="map-popup">';
        rows.forEach(function (row) {
            if (row[1] === null || row[1] === undefined || row[1] === '') {
                return;
            }
            html += '<div><strong>' + escapeHtml(row[0]) + ':</strong> '
                + escapeHtml(row[1]) + '</div>';
        });
        return html + '</div>';
    }

    /** Outer ring of every part of a polygon record. */
    function outerRings(polygon) {
        return (polygon.rings || [])
            .map(function (part) { return part[0]; })
            .filter(function (ring) { return ring && ring.length >= 3; });
    }

    function polygonBounds(polygon) {
        var all = [];
        outerRings(polygon).forEach(function (ring) { all = all.concat(ring); });
        return all.length ? L.latLngBounds(all) : null;
    }

    function labelFor(polygon, index, total) {
        if (polygon.label) {
            return polygon.label;
        }
        return total > 1 ? 'Area ' + (index + 1) : (polygon.code || 'Area 1');
    }

    function addBaseLayers(map) {
        var layers = {};
        Object.keys(BASEMAPS).forEach(function (key) {
            var def = BASEMAPS[key];
            layers[def.label] = L.tileLayer(def.url, def.options);
        });

        layers[BASEMAPS.satellite.label].addTo(map);

        L.control.layers(layers, null, { position: 'topright', collapsed: false }).addTo(map);
        L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(map);

        return { satellite: layers[BASEMAPS.satellite.label], street: layers[BASEMAPS.street.label] };
    }

    function buildNavBar(container) {
        var bar = document.createElement('div');
        bar.className = 'polygon-nav';
        bar.innerHTML =
            '<button type="button" class="btn polygon-nav-btn" data-nav="prev" aria-label="Previous area">&#8249; Previous</button>'
            + '<div class="polygon-nav-center">'
            + '<select class="polygon-nav-select" aria-label="Go to area"></select>'
            + '<span class="polygon-nav-count"></span>'
            + '</div>'
            + '<button type="button" class="btn polygon-nav-btn" data-nav="next" aria-label="Next area">Next &#8250;</button>';

        container.parentNode.insertBefore(bar, container);
        return bar;
    }

    function initPolygonMap(elementId, polygons, options) {
        var container = document.getElementById(elementId);
        if (!container || typeof L === 'undefined') {
            return null;
        }

        options = options || {};
        polygons = (polygons || []).filter(function (p) { return polygonBounds(p) !== null; });

        var locked = options.locked !== undefined ? !!options.locked : !options.preview;

        var map = L.map(elementId, {
            scrollWheelZoom: options.interactive !== false,
            zoomSnap: 0.25,
            zoomDelta: 0.5,
            maxZoom: MAX_ZOOM,
            maxBoundsViscosity: 1.0
        });

        var bases = addBaseLayers(map);
        var currentBase = 'satellite';

        // ---- Nothing to show -------------------------------------------------
        if (!polygons.length) {
            map.setView(EMPTY_CENTER, EMPTY_ZOOM);
            var note = document.createElement('div');
            note.className = 'map-empty-note';
            note.textContent = 'No CENRO polygons available to display.';
            container.appendChild(note);
            return map;
        }

        // A map needs a view before vector layers can be added to it. Start on
        // the first polygon so no other area is ever requested or shown.
        map.fitBounds(polygonBounds(polygons[0]), { animate: false });

        var total = polygons.length;
        var labels = polygons.map(function (p, i) { return labelFor(p, i, total); });
        var layerGroup = L.featureGroup().addTo(map);
        var maskLayer = null;
        var activeIndex = 0;

        function styleFor(polygon) {
            if (options.preview) {
                return STYLES.preview;
            }
            var set = STYLES[currentBase];
            return set[polygon.status] || set.Active;
        }

        function drawPolygon(index) {
            var polygon = polygons[index];
            var style = styleFor(polygon);

            polygon.rings.forEach(function (rings) {
                var layer = L.polygon(rings, style);
                layer.bindPopup(buildPopup(polygon, labels[index]));
                layer.bindTooltip(labels[index], {
                    permanent: true,
                    direction: 'center',
                    className: 'area-label'
                });
                layer.addTo(layerGroup);
            });
        }

        function drawMask(index) {
            if (maskLayer) {
                map.removeLayer(maskLayer);
                maskLayer = null;
            }
            var world = [[-85, -180], [-85, 180], [85, 180], [85, -180]];
            maskLayer = L.polygon([world].concat(outerRings(polygons[index])), MASK_STYLE).addTo(map);
            maskLayer.bringToBack();
        }

        function redraw() {
            layerGroup.clearLayers();
            if (locked) {
                drawPolygon(activeIndex);
            } else {
                polygons.forEach(function (p, i) { drawPolygon(i); });
            }
            layerGroup.eachLayer(function (l) { l.bringToFront(); });
        }

        // ---- Lock / fit ------------------------------------------------------
        function lockTo(bounds) {
            // Release the previous lock so fitBounds can move freely.
            map.setMinZoom(0);
            map.setMaxBounds(null);

            map.invalidateSize();
            map.fitBounds(bounds, { padding: [FIT_PADDING, FIT_PADDING], animate: false });

            // The fitted zoom becomes the floor (no zooming out) and the
            // polygon bounds become the pan limit.
            map.setMinZoom(map.getZoom());
            map.setMaxBounds(bounds.pad(BOUNDS_PAD));
        }

        function show(index) {
            activeIndex = index;

            if (locked) {
                drawMask(index);
                redraw();
                lockTo(polygonBounds(polygons[index]));
                updateNav();
            } else {
                redraw();
                var bounds = layerGroup.getBounds();
                if (bounds.isValid()) {
                    map.fitBounds(bounds, { padding: [20, 20], maxZoom: 18, animate: false });
                }
            }
        }

        // ---- Navigation ------------------------------------------------------
        var bar = null;
        var select = null;
        var counter = null;

        function updateNav() {
            if (!bar) {
                return;
            }
            select.value = String(activeIndex);
            counter.textContent = (activeIndex + 1) + ' of ' + total;
            bar.querySelector('[data-nav="prev"]').disabled = activeIndex <= 0;
            bar.querySelector('[data-nav="next"]').disabled = activeIndex >= total - 1;
        }

        if (locked && total > 1) {
            bar = buildNavBar(container);
            select = bar.querySelector('.polygon-nav-select');
            counter = bar.querySelector('.polygon-nav-count');

            polygons.forEach(function (p, i) {
                var opt = document.createElement('option');
                opt.value = String(i);
                opt.textContent = labels[i] + (p.name ? ' - ' + p.name : '');
                select.appendChild(opt);
            });

            bar.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-nav]');
                if (!btn || btn.disabled) {
                    return;
                }
                map.closePopup();
                show(activeIndex + (btn.getAttribute('data-nav') === 'next' ? 1 : -1));
            });

            select.addEventListener('change', function () {
                map.closePopup();
                show(parseInt(select.value, 10) || 0);
            });
        }

        // Boundary colours follow the active basemap.
        map.on('baselayerchange', function (e) {
            currentBase = (e.layer === bases.street) ? 'street' : 'satellite';
            if (!options.preview) {
                redraw();
            }
        });

        // Re-fit after the container changes size (rotation, window resize).
        var resizeTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                if (locked) {
                    lockTo(polygonBounds(polygons[activeIndex]));
                } else {
                    map.invalidateSize();
                }
            }, 200);
        });

        show(0);
        return map;
    }

    global.initPolygonMap = initPolygonMap;
}(window));
