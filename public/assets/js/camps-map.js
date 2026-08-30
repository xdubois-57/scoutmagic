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
// The map is EXPANDED by default, and therefore built as the page loads:
// the tile provider receives the reader's IP from the first visit, which
// the RGPD page states in those words (core/View/rgpd_default.html,
// « Fond de carte et géocodage »). The panel that used to hide it is now
// a fold the reader owns rather than a default they have to undo.
//
// That fold is REMEMBERED from one visit to the next, and remembering it
// is a functional preference — the same mechanism, for the same reasons,
// as public/assets/js/theme.js's colour scheme: localStorage under
// 'camps_map_collapsed', declared in modules/camps/module.json's
// `cookies` section under category "functional" — in the MODULE's
// manifest and not in core/Cookie/CookieRegistry.php, which holds the
// core's own keys (AGENTS.md § Cookie consent);
// Core\Cookie\CookieConsentService aggregates the two, so the consent
// banner and the cookie preferences page list this one either way.
//
// It is written ONLY once the visitor has given functional cookie
// consent, read from the client-readable `cookie_consent` JSON cookie.
// Without that consent the fold still works for the page in front of the
// reader, nothing is stored, and the map is expanded again on the next
// visit. Expanded being the default, it is stored as an absence:
// unfolding REMOVES the key.
(function () {
    'use strict';

    var TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    var ATTRIBUTION = '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>';

    /** localStorage key holding the fold — see the header. */
    var STORAGE_KEY = 'camps_map_collapsed';

    /** Wallonia, as a first view when there is nothing to fit. */
    var FALLBACK_CENTER = /** @type {[number, number]} */ ([50.45, 4.87]);
    var FALLBACK_ZOOM = 8;

    var built = false;

    /**
     * Functional cookie consent, read from the client-readable
     * `cookie_consent` cookie (httponly:false on purpose — see
     * Core\Cookie\CookieConsentService::writeCookie()). No cookie, or
     * unparseable content, means no consent.
     *
     * Deliberately its own copy of the reader in
     * public/assets/js/theme.js rather than a call into that file's
     * global: these are two unbundled classic scripts, one of them a
     * module's, and a four-line cookie parse is not worth a cross-script
     * dependency that would silently make the map's memory depend on the
     * colour scheme's script having loaded.
     *
     * @returns {boolean}
     */
    function hasFunctionalConsent() {
        var cookies = document.cookie ? document.cookie.split(';') : [];
        for (var i = 0; i < cookies.length; i++) {
            var pair = cookies[i];
            var eq = pair.indexOf('=');
            if (eq === -1) continue;
            if (pair.slice(0, eq).trim() !== 'cookie_consent') continue;
            try {
                var data = JSON.parse(decodeURIComponent(pair.slice(eq + 1).trim()));
                return !!(data && data.functional);
            } catch (e) {
                return false;
            }
        }
        return false;
    }

    /** Drop the stored fold, whatever it was. */
    function forget() {
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            // Storage disabled or private browsing — nothing was stored.
        }
    }

    /**
     * Whether the reader folded the map away on an earlier visit.
     *
     * Consent gates the READ as well as the write, and the read clears
     * what it may not use: a visitor who granted functional consent,
     * folded the map, then withdrew that consent must stop being
     * remembered — and nothing else on this page would ever come back to
     * remove what they left behind.
     *
     * @returns {boolean}
     */
    function readCollapsed() {
        if (!hasFunctionalConsent()) {
            forget();

            return false;
        }
        try {
            return localStorage.getItem(STORAGE_KEY) === '1';
        } catch (e) {
            // Storage disabled or private browsing — behave as default.
            return false;
        }
    }

    /**
     * Persist the fold, with functional consent and never without.
     *
     * @param {boolean} collapsed
     */
    function remember(collapsed) {
        if (!hasFunctionalConsent()) {
            return;
        }
        if (!collapsed) {
            forget();

            return;
        }
        try {
            localStorage.setItem(STORAGE_KEY, '1');
        } catch (e) {
            // Storage full or disabled — the fold the reader just made
            // still holds for this page, and that is the whole feature.
        }
    }

    /**
     * Fold the panel the markup opens on, when that is what the reader
     * asked for last time.
     *
     * Bootstrap builds its Collapse instance on the first click, so at
     * this point the classes and `aria-expanded` are ours to set — and
     * must be set together, or the button announces a state that is not
     * the one on screen.
     *
     * @param {HTMLElement} panel
     */
    function applyStoredState(panel) {
        if (!readCollapsed()) {
            return;
        }
        panel.classList.remove('show');
        var toggles = document.querySelectorAll('.camps-map-toggle');
        for (var i = 0; i < toggles.length; i++) {
            toggles[i].setAttribute('aria-expanded', 'false');
            toggles[i].classList.add('collapsed');
        }
    }

    /**
     * The places island the template wrote, or an empty list: a blank map
     * beats a page whose script died halfway through.
     *
     * @param {HTMLElement} container
     * @returns {Array<{name: string, locality: string|null, url: string, lat: number, lng: number}>}
     */
    function places(container) {
        try {
            return JSON.parse(container.dataset.places || '[]');
        } catch (e) {
            return [];
        }
    }

    /**
     * @param {HTMLElement} container
     * @param {{name: string, locality: string|null, url: string}} place
     */
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

    /**
     * @param {HTMLElement} container
     */
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
        var panel = document.getElementById('camps-map-panel');
        if (!container || !panel) {
            return;
        }

        // Bootstrap's collapse fires these once the panel has finished
        // opening or closing. Building on `shown` rather than on the
        // click matters: Leaflet cannot size a map inside a hidden
        // element, and building it earlier produces a grey box until the
        // next resize.
        panel.addEventListener('shown.bs.collapse', function () {
            remember(false);
            build(container);
            if (built && typeof L !== 'undefined') {
                window.dispatchEvent(new Event('resize'));
            }
        });
        panel.addEventListener('hidden.bs.collapse', function () {
            remember(true);
        });

        applyStoredState(panel);

        // Expanded is the default and, folded or not, the panel's own
        // class is the single truth about what is on screen: reading it
        // here is what keeps "build the map" from drifting away from "the
        // map is visible".
        if (panel.classList.contains('show')) {
            build(container);
        }
    });

    // The <script> sits at the end of <body> (modules/camps/views/
    // list.html.twig), so the panel is already parsed and the fold can be
    // applied here rather than at DOMContentLoaded — a reader who folded
    // the map does not watch it flash open again behind Leaflet's own
    // script on every visit. Idempotent with the call above, which is
    // what covers a page where this script somehow runs early.
    var parsedPanel = document.getElementById('camps-map-panel');
    if (parsedPanel) {
        applyStoredState(parsedPanel);
    }
})();
