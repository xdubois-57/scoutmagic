/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Every map on the site draws through here — window.ScoutMagicMap.
//
// Born in the camps module (camps-map.js) and moved to the core when the
// carpool module needed a map too (docs/chantiers/covoiturage.md, IT-02).
// What is shared is what must agree everywhere: the tile provider, its
// attribution, and the first view of a map with nothing to fit.
//
// **The tile host is named here and in Core\Geo\MapTiles, and nowhere
// else.** Three places have to agree about it and they are in three
// languages — the CSP built in PHP, this URL, and the subprocessor list of
// the RGPD page. A host that changes in one and not the others gives a
// blocked map, or worse, a privacy notice that names the wrong company.
// Tests\Core\Geo\MapTilesTest reads this file to hold them together.
//
// Leaflet is vendored under /assets/vendor/leaflet/ — no npm, no build
// step, no CDN — and must be loaded before this file. A page whose Leaflet
// failed to load gets no map rather than an exception.
(function () {
    'use strict';

    var TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    var ATTRIBUTION = '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>';

    /** Wallonia, as a first view when there is nothing to fit. */
    var FALLBACK_CENTER = /** @type {[number, number]} */ ([50.45, 4.87]);
    var FALLBACK_ZOOM = 8;

    /**
     * A Leaflet map in $container, with the site's tiles, centred on
     * $center (or the fallback view). Null when Leaflet is not there.
     *
     * @param {HTMLElement} container
     * @param {{center?: [number, number], zoom?: number}} [options]
     * @returns {any}
     */
    function create(container, options) {
        if (typeof L === 'undefined') {
            return null;
        }
        var center = options?.center || FALLBACK_CENTER;
        var zoom = options?.zoom || FALLBACK_ZOOM;
        var map = L.map(container).setView(center, zoom);
        L.tileLayer(TILE_URL, { attribution: ATTRIBUTION, maxZoom: 18 }).addTo(map);

        return map;
    }

    window.ScoutMagicMap = {
        TILE_URL: TILE_URL,
        ATTRIBUTION: ATTRIBUTION,
        FALLBACK_CENTER: FALLBACK_CENTER,
        FALLBACK_ZOOM: FALLBACK_ZOOM,
        create: create,
    };
})();
