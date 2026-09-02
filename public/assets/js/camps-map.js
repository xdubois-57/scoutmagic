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
// The map is expanded by default ON A WIDE SCREEN, and folded away on a
// narrow one. It used to be expanded everywhere, and on a phone that was
// wrong in a way a desktop never shows: a full-width map that captures
// touch sits between the reader and the list they came for, and every
// attempt to scroll past it pans the map instead. « Où est-on déjà
// allés ? » is still the question this screen answers — on the screen
// where a map can answer it without taking the page hostage.
//
// The threshold is 992px, the same one app.css and components.css already
// use to mean « desktop ».
//
// Where the map IS shown it is built as the page loads, so the tile
// provider receives the reader's IP from the first visit — which the RGPD
// page states in those words (core/View/rgpd_default.html, « Fond de carte
// et géocodage »). Folded away on a phone, nothing is requested at all
// until the reader unfolds it, which is strictly less than before.
//
// The fold is REMEMBERED from one visit to the next, and remembering it is
// a functional preference — the same mechanism, for the same reasons, as
// public/assets/js/theme.js's colour scheme: localStorage under
// 'camps_map_collapsed', declared in modules/camps/module.json's
// `cookies` section under category "functional" — in the MODULE's
// manifest and not in core/Cookie/CookieRegistry.php, which holds the
// core's own keys (AGENTS.md § Cookie consent);
// Core\Cookie\CookieConsentService aggregates the two, so the consent
// banner and the cookie preferences page list this one either way.
//
// **Three states, and that is what the viewport default costs.** The key
// used to hold '1' or nothing, absence meaning « expanded, the default ».
// With a default that now depends on the screen, absence has to mean « no
// answer yet, ask the screen » — otherwise a reader who unfolds the map on
// their phone finds it folded again on every single visit, their choice
// silently unstorable. So '1' is folded, '0' is unfolded, and nothing at
// all is the viewport's answer.
//
// It is written ONLY once the visitor has given functional cookie consent,
// read from the client-readable `cookie_consent` JSON cookie. Without that
// consent the fold still works for the page in front of the reader,
// nothing is stored, and the screen decides again on the next visit.
(function () {
    'use strict';

    var TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    var ATTRIBUTION = '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>';

    /** localStorage key holding the fold — see the header. */
    var STORAGE_KEY = 'camps_map_collapsed';

    /**
     * Where « desktop » starts, in the same pixels app.css and
     * components.css already mean by it.
     */
    var DESKTOP_QUERY = '(min-width: 992px)';

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
     * What the reader decided on an earlier visit: true folded, false
     * unfolded, null never asked.
     *
     * Consent gates the READ as well as the write, and the read clears
     * what it may not use: a visitor who granted functional consent,
     * folded the map, then withdrew that consent must stop being
     * remembered — and nothing else on this page would ever come back to
     * remove what they left behind.
     *
     * @returns {boolean|null}
     */
    function readCollapsed() {
        if (!hasFunctionalConsent()) {
            forget();

            return null;
        }
        try {
            var stored = localStorage.getItem(STORAGE_KEY);

            return stored === null ? null : stored === '1';
        } catch (e) {
            // Storage disabled or private browsing — no answer, so the
            // screen gets to give one.
            return null;
        }
    }

    /**
     * Whether this screen is wide enough for the map to be worth opening
     * unasked.
     *
     * A browser without `matchMedia` — and jsdom, until a test says
     * otherwise — is treated as a wide one: showing a map to somebody who
     * did not need it is a smaller mistake than hiding it from somebody
     * who did.
     *
     * @returns {boolean}
     */
    function isDesktop() {
        if (typeof window.matchMedia !== 'function') {
            return true;
        }
        try {
            return window.matchMedia(DESKTOP_QUERY).matches;
        } catch (e) {
            return true;
        }
    }

    /**
     * Persist the fold, with functional consent and never without.
     *
     * Both states are written now, where unfolded used to be an absence:
     * see the header. A reader who unfolds the map on their phone is
     * making a choice, and a choice stored nowhere is a choice they have
     * to make again every visit.
     *
     * @param {boolean} collapsed
     */
    function remember(collapsed) {
        if (!hasFunctionalConsent()) {
            return;
        }
        try {
            localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
        } catch (e) {
            // Storage full or disabled — the fold the reader just made
            // still holds for this page, and that is the whole feature.
        }
    }

    /**
     * Fold the panel the markup opens on, when that is what the reader
     * asked for last time — or, when they never said, what the screen
     * asks for.
     *
     * The server always renders the panel OPEN, and it has to: it cannot
     * see the viewport, and a page whose script never ran must show the
     * map rather than hide it behind a button nothing can press. So the
     * narrow-screen default is a fold applied here, at parse time, before
     * the map is ever built.
     *
     * Bootstrap builds its Collapse instance on the first click, so at
     * this point the classes and `aria-expanded` are ours to set — and
     * must be set together, or the button announces a state that is not
     * the one on screen.
     *
     * @param {HTMLElement} panel
     */
    function applyInitialState(panel) {
        var stored = readCollapsed();
        var collapsed = stored === null ? !isDesktop() : stored;
        if (!collapsed) {
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

        applyInitialState(panel);

        // Folded or not, the panel's own class is the single truth about
        // what is on screen: reading it here is what keeps "build the map"
        // from drifting away from "the map is visible" — and it is what
        // stops a phone requesting a single tile.
        if (panel.classList.contains('show')) {
            build(container);
        }
    });

    // The <script> sits at the end of <body> (modules/camps/views/
    // list.html.twig), so the panel is already parsed and the fold can be
    // applied here rather than at DOMContentLoaded — a reader on a phone
    // does not watch the map flash open and collapse again behind
    // Leaflet's own script on every visit. Idempotent with the call above,
    // which is what covers a page where this script somehow runs early.
    var parsedPanel = document.getElementById('camps-map-panel');
    if (parsedPanel) {
        applyInitialState(parsedPanel);
    }
})();
