/**
 * assets/js/PhotoPlotterJS.js
 *
 * Logic for user/Photo-plotter.php.
 * Handles EXIF GPS extraction, Leaflet map rendering, OSRM road-route
 * generation (with a straight-line fallback), the photo gallery, and
 * activity logging via /RFP/user/Photo-log.php.
 */

(function () {
    "use strict";

    /* ============================================================
       GLOBAL STATE
    ============================================================ */

    let myMap;
    let markers = [];
    let photoData = [];
    let pathPolyline = null;
    let totalDistance = 0;

    /* ============================================================
       DOM ELEMENTS
    ============================================================ */

    const imageInput = document.getElementById("imageInput");
    const loadingSpinner = document.getElementById("loadingSpinner");
    const photoCards = document.getElementById("photoCards");
    const pathInfo = document.getElementById("pathInfo");
    const timeInfo = document.getElementById("timeInfo");
    const totalDistanceElement = document.getElementById("totalDistance");
    const timeDifferenceElement = document.getElementById("timeDifference");
    const photoCount = document.getElementById("photoCount");
    const statusMessage = document.getElementById("statusMessage");

    /* ============================================================
       ACTIVITY LOGGING
       Mirrors the Species Indicator logging pattern: a small POST
       to a dedicated *-log.php endpoint, fire-and-forget from the
       UI's point of view (errors are only logged to console so a
       logging hiccup never blocks the user's workflow).
    ============================================================ */

    function logActivityEvent(action, description, status) {
        fetch("/RFP/user/Photo-log.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                csrf_token: window.PP_CSRF_TOKEN || "",
                action: action,
                description: description,
                status: status || "success"
            })
        }).catch(function (error) {
            console.error("Activity log request failed:", error);
        });
    }

    /* ============================================================
       INITIALIZE MAP
    ============================================================ */

    function initMap() {
        // Default view - adjust to your reforestation area as needed.
        myMap = L.map("map").setView([8.3536, 124.8695], 13);

        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(myMap);

        console.log("Map initialized.");
    }

    /* ============================================================
       FILE INPUT EVENT
    ============================================================ */

    imageInput.addEventListener("change", function () {
        handleImageInput(this);
    });

    /* ============================================================
       HANDLE IMAGE UPLOAD
    ============================================================ */

    async function handleImageInput(input) {
        if (!input.files || input.files.length === 0) {
            return;
        }

        setLoading(true);
        hideStatus();

        let processedCount = 0;
        let skippedCount = 0;

        try {
            for (let i = 0; i < input.files.length; i++) {
                const file = input.files[i];
                console.log("Processing:", file.name);

                const result = await processImageFile(file);

                if (result === true) {
                    processedCount++;
                } else {
                    skippedCount++;
                }
            }

            updatePhotoCards();
            await drawPaths();
            updateTimeDifferences();

            if (processedCount > 0 && skippedCount === 0) {
                showStatus(`${processedCount} photo(s) successfully added.`, "success");

                logActivityEvent(
                    "Upload Photos",
                    `Uploaded ${processedCount} geotagged photo(s).`,
                    "success"
                );

            } else if (processedCount > 0 && skippedCount > 0) {
                showStatus(
                    `${processedCount} photo(s) added. ${skippedCount} photo(s) skipped because GPS data was missing.`,
                    "warning"
                );

                logActivityEvent(
                    "Upload Photos",
                    `Uploaded ${processedCount} photo(s), skipped ${skippedCount} without GPS data.`,
                    "success"
                );

            } else {
                showStatus(
                    "No photos were added. Make sure the images contain GPS EXIF metadata.",
                    "warning"
                );

                logActivityEvent(
                    "Upload Photos",
                    `Attempted upload of ${skippedCount} photo(s); none had usable GPS data.`,
                    "failed"
                );
            }

        } catch (error) {
            console.error("Upload error:", error);
            showStatus("An unexpected error occurred while processing the photos.", "danger");

            logActivityEvent(
                "Upload Photos",
                "Unexpected error while processing uploaded photos.",
                "failed"
            );

        } finally {
            setLoading(false);
            input.value = "";
        }
    }

    /* ============================================================
       PROCESS SINGLE IMAGE
    ============================================================ */

    function processImageFile(file) {
        return new Promise((resolve) => {
            const reader = new FileReader();

            reader.onload = function (e) {
                try {
                    EXIF.getData(file, function () {
                        try {
                            const lat = EXIF.getTag(this, "GPSLatitude");
                            const lon = EXIF.getTag(this, "GPSLongitude");
                            const latRef = EXIF.getTag(this, "GPSLatitudeRef") || "N";
                            const lonRef = EXIF.getTag(this, "GPSLongitudeRef") || "E";

                            if (!lat || !lon || lat.length < 3 || lon.length < 3) {
                                console.warn(`${file.name}: GPS data not found.`);
                                resolve(false);
                                return;
                            }

                            const calculatedLat = convertDMSToDecimal(lat, latRef);
                            const calculatedLon = convertDMSToDecimal(lon, lonRef);

                            if (!isFinite(calculatedLat) || !isFinite(calculatedLon)) {
                                console.warn(`${file.name}: Invalid GPS coordinates.`);
                                resolve(false);
                                return;
                            }

                            const date = EXIF.getTag(this, "DateTimeOriginal");
                            const make = EXIF.getTag(this, "Make") || "Unknown";
                            const model = EXIF.getTag(this, "Model") || "Unknown";

                            let dateObj = null;
                            let formattedDate = "Unknown date";

                            if (date) {
                                try {
                                    const parts = date.split(" ");

                                    if (parts.length >= 2) {
                                        const dateString = parts[0].replace(/:/g, "-") + "T" + parts[1];
                                        dateObj = new Date(dateString);

                                        formattedDate = !isNaN(dateObj.getTime())
                                            ? dateObj.toLocaleString()
                                            : date;
                                    } else {
                                        formattedDate = date;
                                    }
                                } catch (error) {
                                    console.warn("Date parsing error:", error);
                                    formattedDate = date;
                                }
                            }

                            const photo = {
                                id: Date.now() + Math.random().toString(36).substring(2, 9),
                                file: file,
                                src: e.target.result,
                                lat: calculatedLat,
                                lon: calculatedLon,
                                date: date,
                                dateObj: dateObj,
                                formattedDate: formattedDate,
                                make: make,
                                model: model,
                                filename: file.name
                            };

                            photoData.push(photo);
                            console.log("Photo added:", photo);
                            resolve(true);

                        } catch (error) {
                            console.error(`EXIF error for ${file.name}:`, error);
                            resolve(false);
                        }
                    });

                } catch (error) {
                    console.error(`Error reading EXIF from ${file.name}:`, error);
                    resolve(false);
                }
            };

            reader.onerror = function (error) {
                console.error(`FileReader error for ${file.name}:`, error);
                resolve(false);
            };

            reader.readAsDataURL(file);
        });
    }

    /* ============================================================
       CONVERT GPS DMS TO DECIMAL
    ============================================================ */

    function convertDMSToDecimal(dms, reference) {
        const degrees = Number(dms[0]);
        const minutes = Number(dms[1]);
        const seconds = Number(dms[2]);

        let decimal = degrees + minutes / 60 + seconds / 3600;

        if (reference === "S" || reference === "W") {
            decimal *= -1;
        }

        return decimal;
    }

    /* ============================================================
       UPDATE PHOTO GALLERY
    ============================================================ */

    function updatePhotoCards() {
        photoCards.innerHTML = "";
        photoCount.textContent = photoData.length;

        if (photoData.length === 0) {
            photoCards.innerHTML = `
                <div class="pp-empty-gallery">
                    <div class="pp-empty-icon" aria-hidden="true">&#128247;</div>
                    <div>No photos uploaded</div>
                </div>
            `;
            return;
        }

        const sortedPhotos = sortByDate(photoData);

        sortedPhotos.forEach((photo) => {
            const card = document.createElement("div");
            card.className = "pp-photo-card";
            card.dataset.photoId = photo.id;

            card.innerHTML = `
                <img src="${photo.src}" alt="${escapeHtml(photo.filename)}">
                <div class="pp-photo-card-content">
                    <div class="pp-photo-title">${escapeHtml(photo.filename)}</div>
                    <div class="pp-photo-info">
                        <strong>Date:</strong> ${escapeHtml(photo.formattedDate)}<br>
                        <strong>GPS:</strong> ${photo.lat.toFixed(6)}, ${photo.lon.toFixed(6)}
                    </div>
                </div>
            `;

            card.addEventListener("click", function () {
                focusOnPhoto(photo.id, card);
            });

            photoCards.appendChild(card);
        });
    }

    /* ============================================================
       FOCUS ON PHOTO
    ============================================================ */

    function focusOnPhoto(photoId, cardElement) {
        const photo = photoData.find((p) => p.id === photoId);
        if (!photo) {
            return;
        }

        document.querySelectorAll(".pp-photo-card").forEach((card) => {
            card.classList.remove("is-active");
        });

        cardElement.classList.add("is-active");
        myMap.setView([photo.lat, photo.lon], 17);

        const marker = markers.find((m) => m.photoId === photoId);
        if (marker) {
            marker.openPopup();
        }
    }

    /* ============================================================
       DRAW PATHS
    ============================================================ */

    async function drawPaths() {
        markers.forEach((marker) => myMap.removeLayer(marker));
        markers = [];

        if (pathPolyline) {
            myMap.removeLayer(pathPolyline);
            pathPolyline = null;
        }

        if (photoData.length === 0) {
            pathInfo.hidden = true;
            return;
        }

        const sortedPhotos = sortByDate(photoData);

        sortedPhotos.forEach((photo) => {
            const marker = L.marker([photo.lat, photo.lon]).addTo(myMap);

            marker.bindPopup(`
                <div class="pp-popup" style="min-width:220px">
                    <strong>${escapeHtml(photo.filename)}</strong>
                    <hr>
                    <b>Date:</b><br>${escapeHtml(photo.formattedDate)}<br><br>
                    <b>Coordinates:</b><br>${photo.lat.toFixed(6)}, ${photo.lon.toFixed(6)}<br><br>
                    <b>Camera:</b><br>${escapeHtml(photo.make)} ${escapeHtml(photo.model)}<br><br>
                    <img src="${photo.src}" style="width:100%;max-height:200px;object-fit:cover;border-radius:5px;">
                </div>
            `);

            marker.photoId = photo.id;
            markers.push(marker);
        });

        if (sortedPhotos.length < 2) {
            pathInfo.hidden = true;
            fitPhotoBounds();
            return;
        }

        const coordinates = sortedPhotos.map((photo) => `${photo.lon},${photo.lat}`).join(";");

        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);

        try {
            console.log("Requesting road route...");

            const response = await fetch(
                `https://router.project-osrm.org/route/v1/driving/${coordinates}?overview=full&geometries=geojson`,
                { signal: controller.signal }
            );

            clearTimeout(timeout);

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const data = await response.json();

            if (data.code !== "Ok") {
                throw new Error("OSRM could not create a route.");
            }

            const routeCoordinates = data.routes[0].geometry.coordinates.map((coord) => [coord[1], coord[0]]);

            pathPolyline = L.polyline(routeCoordinates, {
                color: "#1B5E3A",
                weight: 5,
                opacity: 0.75,
                dashArray: "10, 10"
            }).addTo(myMap);

            totalDistance = data.routes[0].distance / 1000;
            totalDistanceElement.textContent = `${totalDistance.toFixed(2)} km (road distance)`;
            pathInfo.hidden = false;

            myMap.fitBounds(pathPolyline.getBounds(), { padding: [40, 40] });

            console.log(`Route created: ${totalDistance.toFixed(2)} km`);

            logActivityEvent(
                "Generate Route",
                `Plotted road route across ${sortedPhotos.length} photos (${totalDistance.toFixed(2)} km).`,
                "success"
            );

        } catch (error) {
            clearTimeout(timeout);
            console.error("OSRM routing error:", error);

            const straightLineCoords = sortedPhotos.map((photo) => [photo.lat, photo.lon]);

            pathPolyline = L.polyline(straightLineCoords, {
                color: "#b3261e",
                weight: 3,
                dashArray: "5, 5"
            }).addTo(myMap);

            totalDistance = calculateTotalDistance(straightLineCoords);
            totalDistanceElement.textContent = `${totalDistance.toFixed(2)} km (straight-line distance)`;
            pathInfo.hidden = false;

            myMap.fitBounds(pathPolyline.getBounds(), { padding: [40, 40] });

            showStatus("Road routing was unavailable. A straight-line path was displayed instead.", "warning");

            logActivityEvent(
                "Generate Route",
                `Road routing unavailable; used straight-line fallback across ${sortedPhotos.length} photos (${totalDistance.toFixed(2)} km).`,
                "success"
            );
        }
    }

    /* ============================================================
       FIT PHOTO BOUNDS
    ============================================================ */

    function fitPhotoBounds() {
        if (photoData.length === 0) {
            return;
        }

        const bounds = L.latLngBounds(photoData.map((photo) => [photo.lat, photo.lon]));
        myMap.fitBounds(bounds, { padding: [40, 40] });
    }

    /* ============================================================
       DISTANCE HELPERS
    ============================================================ */

    function calculateTotalDistance(coords) {
        let distance = 0;

        for (let i = 1; i < coords.length; i++) {
            distance += calculateDistance(coords[i - 1][0], coords[i - 1][1], coords[i][0], coords[i][1]);
        }

        return distance;
    }

    function calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371;
        const dLat = ((lat2 - lat1) * Math.PI) / 180;
        const dLon = ((lon2 - lon1) * Math.PI) / 180;

        const a =
            Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos((lat1 * Math.PI) / 180) *
            Math.cos((lat2 * Math.PI) / 180) *
            Math.sin(dLon / 2) *
            Math.sin(dLon / 2);

        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

        return R * c;
    }

    /* ============================================================
       TIME DIFFERENCES
    ============================================================ */

    function updateTimeDifferences() {
        if (photoData.length < 2) {
            timeInfo.hidden = true;
            return;
        }

        const sortedPhotos = photoData
            .filter((photo) => photo.dateObj && !isNaN(photo.dateObj.getTime()))
            .sort((a, b) => a.dateObj - b.dateObj);

        if (sortedPhotos.length < 2) {
            timeInfo.hidden = true;
            return;
        }

        const firstDate = sortedPhotos[0].dateObj;
        const lastDate = sortedPhotos[sortedPhotos.length - 1].dateObj;
        const totalTime = formatTimeDifference(firstDate, lastDate);

        const timeDiffs = [];
        for (let i = 1; i < sortedPhotos.length; i++) {
            timeDiffs.push(formatTimeDifference(sortedPhotos[i - 1].dateObj, sortedPhotos[i].dateObj));
        }

        timeDifferenceElement.innerHTML = `
            <div><strong>Total:</strong> ${totalTime}</div>
            <div class="pp-mt-1"><strong>Between photos:</strong> ${timeDiffs.join(", ")}</div>
        `;

        timeInfo.hidden = false;
    }

    function formatTimeDifference(date1, date2) {
        const diffMs = Math.abs(date2 - date1);
        const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
        const diffHours = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const diffMinutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
        const diffSeconds = Math.floor((diffMs % (1000 * 60)) / 1000);

        const parts = [];

        if (diffDays > 0) parts.push(`${diffDays} day${diffDays !== 1 ? "s" : ""}`);
        if (diffHours > 0) parts.push(`${diffHours} hour${diffHours !== 1 ? "s" : ""}`);
        if (diffMinutes > 0) parts.push(`${diffMinutes} minute${diffMinutes !== 1 ? "s" : ""}`);
        if (diffSeconds > 0 && parts.length < 2) parts.push(`${diffSeconds} second${diffSeconds !== 1 ? "s" : ""}`);

        return parts.join(" ") || "less than a second";
    }

    /* ============================================================
       CLEAR MAP
    ============================================================ */

    function clearMap() {
        const clearedCount = photoData.length;

        markers.forEach((marker) => myMap.removeLayer(marker));
        markers = [];

        if (pathPolyline) {
            myMap.removeLayer(pathPolyline);
            pathPolyline = null;
        }

        photoData = [];
        totalDistance = 0;

        photoCards.innerHTML = `
            <div class="pp-empty-gallery">
                <div class="pp-empty-icon" aria-hidden="true">&#128247;</div>
                <div>No photos uploaded</div>
            </div>
        `;

        photoCount.textContent = "0";
        pathInfo.hidden = true;
        timeInfo.hidden = true;
        imageInput.value = "";

        myMap.setView([8.3536, 124.8695], 13);
        hideStatus();

        console.log("Map cleared.");

        if (clearedCount > 0) {
            logActivityEvent("Clear Map", `Cleared ${clearedCount} plotted photo(s) from the map.`, "success");
        }
    }

    document.getElementById("fitButton").addEventListener("click", fitPhotoBounds);
    document.getElementById("clearButton").addEventListener("click", clearMap);

    /* ============================================================
       UI HELPERS
    ============================================================ */

    function setLoading(isLoading) {
        loadingSpinner.classList.toggle("is-active", isLoading);
    }

    function showStatus(message, type) {
        statusMessage.className = `pp-alert pp-alert-${type}`;
        statusMessage.textContent = message;
        statusMessage.hidden = false;
    }

    function hideStatus() {
        statusMessage.hidden = true;
    }

    function sortByDate(photos) {
        return [...photos].sort((a, b) => {
            if (a.dateObj && b.dateObj) {
                return a.dateObj - b.dateObj;
            }
            return 0;
        });
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return "";
        }

        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    /* ============================================================
       INITIALIZE
    ============================================================ */

    document.addEventListener("DOMContentLoaded", function () {
        initMap();
        console.log("Photo Plotter initialized successfully.");
    });

})();
