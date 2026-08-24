/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The camps map (ARCHITECTURE.md §8.67). Leaflet is vendored under
// /assets/vendor/leaflet/ — no npm, no build step, no CDN, same treatment
// as Bootstrap and Chart.js.
//
// Markers are PLACES, not stays: a place camped on four times is one pin,
// and clicking it opens a small card rather than navigating away, because
// on a phone a mis-tap that leaves the map costs the whole pan-and-zoom
// the reader just did.
//
// The map is collapsed by default and built on first open. Building it
// eagerly would fetch tiles — and therefore contact the tile provider,
// with the reader's IP — for every chief who opens the camps list without
// ever looking at the map.
(function () {
    var TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    var ATTRIBUTION = '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>';

    /** Wallonia, as a first view when there is nothing to fit. */
    var FALLBACK_CENTER = /** @type {[number, number]} */ ([50.45, 4.87]);
    var FALLBACK_ZOOM = 8;

    var built = false;

    function places(container) {
        try {
            return JSON.parse(container.dataset.places || '[]');
        } catch (e) {
            return [];
        }
    }

    function card(container, place) {
        var box = container.parentElement.querySelector('.camps-map-card');
        if (!box) {
            return;
        }
        box.innerHTML = '';

        var name = document.createElement('div');
        name.className = 'fw-semibold';
        name.textContent = place.name;
        box.appendChild(name);

        if (place.locality) {
            var locality = document.createElement('div');
            locality.className = 'small text-body-secondary';
            locality.textContent = place.locality;
            box.appendChild(locality);
        }

        var open = document.createElement('a');
        open.className = 'btn btn-sm btn-primary mt-2';
        open.href = place.url;
        open.textContent = 'Ouvrir la fiche';
        box.appendChild(open);
    }

    function build(container) {
        if (built || typeof L === 'undefined') {
            return;
        }
        built = true;

        var list = places(container);
        var map = L.map(container).setView(FALLBACK_CENTER, FALLBACK_ZOOM);
        L.tileLayer(TILE_URL, { attribution: ATTRIBUTION, maxZoom: 18 }).addTo(map);

        var markers = [];
        list.forEach(function (place) {
            var marker = L.marker(/** @type {[number, number]} */ ([place.lat, place.lng])).addTo(map);
            marker.on('click', function () {
                card(container, place);
            });
            markers.push(marker);
        });

        if (markers.length > 0) {
            map.fitBounds(L.featureGroup(markers).getBounds(), { padding: [32, 32], maxZoom: 13 });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var container = document.getElementById('camps-map');
        var toggle = document.querySelector('.camps-map-toggle');
        if (!container || !toggle) {
            return;
        }

        // Bootstrap's collapse fires this once the panel is fully open —
        // Leaflet cannot size a map inside a hidden element, and building
        // it before then produces a grey box until the next resize.
        var panel = document.getElementById('camps-map-panel');
        if (panel) {
            panel.addEventListener('shown.bs.collapse', function () {
                build(container);
                if (built && typeof L !== 'undefined') {
                    window.dispatchEvent(new Event('resize'));
                }
            });
        }
    });
})();
