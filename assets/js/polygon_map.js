/**
 * Shared Leaflet map helper for CENRO polygons.
 *
 * Draws the official imported boundaries on the existing Leaflet map
 * setup used elsewhere in the platform. Polygons arrive already in
 * Leaflet's [lat, lng] order from buildMapPayload() in PHP, so no
 * coordinate juggling happens here.
 */
(function (global) {
    'use strict';

    var DEFAULT_CENTER = [8.365, 124.866]; // Northern Bukidnon State College
    var DEFAULT_ZOOM = 15;

    var STYLES = {
        Active:    { color: '#1a5c2e', weight: 2, fillColor: '#1a5c2e', fillOpacity: 0.20 },
        Inactive:  { color: '#777777', weight: 2, fillColor: '#777777', fillOpacity: 0.15 },
        Completed: { color: '#1f4e79', weight: 2, fillColor: '#1f4e79', fillOpacity: 0.18 },
        Preview:   { color: '#a1601a', weight: 2, fillColor: '#a1601a', fillOpacity: 0.20, dashArray: '5,4' }
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

    function buildPopup(polygon) {
        var rows = [
            ['Polygon', polygon.code],
            ['Name', polygon.name],
            ['Project', polygon.project],
            ['Area', polygon.area],
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
        html += '</div>';

        return html;
    }

    /**
     * Renders polygons on a map.
     *
     * @param {string} elementId  Map container id.
     * @param {Array}  polygons   Payload from buildMapPayload().
     * @param {Object} options    { preview: bool, interactive: bool }
     */
    function initPolygonMap(elementId, polygons, options) {
        var container = document.getElementById(elementId);
        if (!container || typeof L === 'undefined') {
            return null;
        }

        options = options || {};
        polygons = polygons || [];

        var map = L.map(elementId, {
            scrollWheelZoom: options.interactive !== false
        }).setView(DEFAULT_CENTER, DEFAULT_ZOOM);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        if (!polygons.length) {
            L.marker(DEFAULT_CENTER).addTo(map)
                .bindPopup('Northern Bukidnon State College');
            return map;
        }

        var layerGroup = L.featureGroup().addTo(map);

        polygons.forEach(function (polygon) {
            if (!polygon.rings || !polygon.rings.length) {
                return;
            }

            var style = options.preview
                ? STYLES.Preview
                : (STYLES[polygon.status] || STYLES.Active);

            // Each entry of rings is one polygon: [outerRing, hole, hole...].
            polygon.rings.forEach(function (rings) {
                var layer = L.polygon(rings, style);
                layer.bindPopup(buildPopup(polygon));
                layer.addTo(layerGroup);
            });
        });

        // Fit the view to the imported boundary rather than a guessed centre.
        try {
            var bounds = layerGroup.getBounds();
            if (bounds && bounds.isValid()) {
                map.fitBounds(bounds, { padding: [20, 20], maxZoom: 18 });
            }
        } catch (e) {
            map.setView(DEFAULT_CENTER, DEFAULT_ZOOM);
        }

        return map;
    }

    global.initPolygonMap = initPolygonMap;
}(window));
