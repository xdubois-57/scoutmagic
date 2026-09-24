/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The carpool organiser's form (modules/covoiturage/views/organize/
// form.html.twig). Two helpers, and the form works without either: the
// server re-checks everything they show (Modules\Covoiturage\Service\
// CarpoolService).
//
// 1. **The place of the chosen events.** On every change of the event
//    picker (`search-picker:change`, public/assets/js/search-picker.js),
//    ask the server which places those events announce: fill the address
//    when it is still empty, and warn when they disagree — a carpool has
//    one destination.
// 2. **The point.** Without JavaScript the page shows two coordinate
//    fields. With it, they are hidden and a map takes their place: the pin
//    is dragged, placed with a click, or removed, and the fields follow.
//    Touching the pin makes it a human's point, which the server locks for
//    ever (Core\Geo\GeoPointStore).
//
// The map is drawn through the core's public/assets/js/map.js, the one
// script allowed to name the tile host.
(function () {
    'use strict';

    /**
     * @param {HTMLElement} warning
     * @param {string[]} locations
     */
    function showLocations(warning, locations) {
        var list = warning.querySelector('[data-carpool-locations]');
        if (locations.length > 1) {
            if (list) {
                list.textContent = ' (' + locations.join(' ; ') + ')';
            }
            warning.classList.remove('d-none');
        } else {
            warning.classList.add('d-none');
        }
    }

    function wireLocations() {
        var picker = document.getElementById('carpool-events');
        var address = /** @type {HTMLInputElement|null} */ (document.getElementById('carpool-address'));
        var warning = document.getElementById('carpool-location-warning');
        if (!picker || !address || !warning) {
            return;
        }

        var asked = 0;
        picker.addEventListener('search-picker:change', async function (event) {
            var detail = /** @type {CustomEvent} */ (event).detail || {};
            var ids = (detail.selected || []).map(function (/** @type {{id: number}} */ row) {
                return String(row.id);
            });
            var mine = ++asked;
            if (ids.length === 0) {
                showLocations(warning, []);
                return;
            }
            var res = await window.ScoutMagicApi.getJson(
                '/covoiturage/organiser/lieux?ids=' + encodeURIComponent(ids.join(','))
            );
            if (mine !== asked) {
                return;
            }
            var locations = res.data?.success && Array.isArray(res.data.locations) ? res.data.locations : [];
            if (address.value.trim() === '' && locations.length > 0) {
                address.value = locations[0];
            }
            showLocations(warning, locations);
        });
    }

    /**
     * @param {string} value
     * @returns {number|null}
     */
    function coordinate(value) {
        var number = parseFloat(String(value).replace(',', '.'));
        return Number.isFinite(number) ? number : null;
    }

    function wirePoint() {
        var box = /** @type {HTMLElement|null} */ (document.querySelector('[data-carpool-point]'));
        if (!box || typeof L === 'undefined' || !window.ScoutMagicMap) {
            // No map to offer: the two coordinate fields stay, and work.
            return;
        }
        var fields = /** @type {HTMLElement|null} */ (box.querySelector('[data-carpool-point-fields]'));
        var mapBox = /** @type {HTMLElement|null} */ (box.querySelector('[data-carpool-point-map-box]'));
        var mapElement = /** @type {HTMLElement|null} */ (box.querySelector('[data-carpool-point-map]'));
        var placeButton = /** @type {HTMLButtonElement|null} */ (box.querySelector('[data-carpool-point-place]'));
        var removeButton = /** @type {HTMLButtonElement|null} */ (box.querySelector('[data-carpool-point-remove]'));
        var line = box.querySelector('[data-carpool-point-line]');
        var origin = box.querySelector('[data-carpool-point-origin]');
        var lat = /** @type {HTMLInputElement|null} */ (document.getElementById('carpool-latitude'));
        var lng = /** @type {HTMLInputElement|null} */ (document.getElementById('carpool-longitude'));
        if (!fields || !mapBox || !mapElement || !placeButton || !removeButton || !lat || !lng) {
            return;
        }

        var manual = box.dataset.manual === '1';
        /** @type {any} */
        var map = null;
        /** @type {any} */
        var marker = null;

        fields.classList.add('d-none');

        /**
         * @param {number|null} latitude
         * @param {number|null} longitude
         */
        function write(latitude, longitude) {
            lat.value = latitude === null ? '' : latitude.toFixed(6);
            lng.value = longitude === null ? '' : longitude.toFixed(6);
            if (line) {
                line.textContent = latitude === null ? '' : lat.value + ', ' + lng.value;
            }
            if (origin) {
                origin.textContent = manual ? 'point placé à la main' : 'trouvé depuis l\'adresse';
            }
        }

        /** @param {[number, number]} position */
        function pin(position) {
            if (marker) {
                marker.setLatLng(position);
            } else {
                marker = L.marker(position, { draggable: true }).addTo(map);
                marker.on('dragend', function () {
                    var moved = marker.getLatLng();
                    manual = true;
                    write(moved.lat, moved.lng);
                });
            }
            write(position[0], position[1]);
        }

        function showMap(/** @type {[number, number]|null} */ center) {
            mapBox.classList.remove('d-none');
            placeButton.classList.add('d-none');
            if (!map) {
                map = window.ScoutMagicMap.create(mapElement, center ? { center: center, zoom: 15 } : undefined);
                map.on('click', function (event) {
                    manual = true;
                    pin([event.latlng.lat, event.latlng.lng]);
                });
            }
            map.invalidateSize();
        }

        var latitude = coordinate(lat.value);
        var longitude = coordinate(lng.value);
        if (latitude !== null && longitude !== null) {
            showMap([latitude, longitude]);
            pin([latitude, longitude]);
        } else {
            placeButton.classList.remove('d-none');
        }

        placeButton.addEventListener('click', function () {
            showMap(null);
        });

        removeButton.addEventListener('click', function () {
            if (marker) {
                marker.remove();
                marker = null;
            }
            manual = true;
            write(null, null);
            mapBox.classList.add('d-none');
            placeButton.classList.remove('d-none');
        });
    }

    wireLocations();
    wirePoint();
})();
