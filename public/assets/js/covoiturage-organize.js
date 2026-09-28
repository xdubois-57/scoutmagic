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
// 3. **The address on the map** (issue #642). When the address field is
//    left — or filled from the events — the site's own route looks it up
//    (never Nominatim from here: the CSP says `connect-src 'self'`). The
//    map centres on it and a fixed marker shows it; a pin nobody touched
//    moves there too, and a hand-placed one stays where it is. There is no
//    button for any of this. A lookup that finds nothing changes nothing.
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

    /** @param {() => void} located called once the address was filled */
    function wireLocations(located) {
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
                located();
            }
            showLocations(warning, locations);
        });
    }

    /**
     * @param {string} value
     * @returns {number|null}
     */
    function coordinate(value) {
        var number = Number.parseFloat(String(value).replace(',', '.'));
        return Number.isFinite(number) ? number : null;
    }

    /**
     * @param {any} a Leaflet LatLng
     * @param {[number, number]} b
     */
    function samePlace(a, b) {
        return Math.abs(a.lat - b[0]) < 1e-6 && Math.abs(a.lng - b[1]) < 1e-6;
    }

    /**
     * @param {HTMLInputElement|null} field a hidden field the page may lack
     * @param {string} text
     */
    function fill(field, text) {
        if (field) {
            field.value = text;
        }
    }

    /** What wirePoint() hands back when there is no map to centre. */
    function nothing() {
        // No map on this page: a change of address has nothing to move.
    }

    /** @returns {() => void} what to call when the address changes */
    function wirePoint() {
        var box = /** @type {HTMLElement|null} */ (document.querySelector('[data-carpool-point]'));
        if (!box || typeof L === 'undefined' || !window.ScoutMagicMap) {
            // No map to offer: the two coordinate fields stay, and work.
            return nothing;
        }
        var fields = /** @type {HTMLElement|null} */ (box.querySelector('[data-carpool-point-fields]'));
        var mapBox = /** @type {HTMLElement|null} */ (box.querySelector('[data-carpool-point-map-box]'));
        var mapElement = /** @type {HTMLElement|null} */ (box.querySelector('[data-carpool-point-map]'));
        var placeButton = /** @type {HTMLButtonElement|null} */ (box.querySelector('[data-carpool-point-place]'));
        var removeButton = /** @type {HTMLButtonElement|null} */ (box.querySelector('[data-carpool-point-remove]'));
        var automatic = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-carpool-point-automatic]'));
        var manualField = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-carpool-point-manual]'));
        var pointAddress = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-carpool-point-address]'));
        var line = box.querySelector('[data-carpool-point-line]');
        var origin = box.querySelector('[data-carpool-point-origin]');
        var lat = /** @type {HTMLInputElement|null} */ (document.getElementById('carpool-latitude'));
        var lng = /** @type {HTMLInputElement|null} */ (document.getElementById('carpool-longitude'));
        var address = /** @type {HTMLInputElement|null} */ (document.getElementById('carpool-address'));
        if (!fields || !mapBox || !mapElement || !placeButton || !removeButton || !lat || !lng) {
            return nothing;
        }

        var manual = box.dataset.manual === '1';
        var locateUrl = box.dataset.locateUrl || '';
        /** @type {any} */
        var map = null;
        /** @type {any} */
        var marker = null;
        /** @type {any} the address's fixed marker, never draggable */
        var addressMarker = null;
        /** @type {[number, number]|null} */
        var addressPosition = null;
        /** The address an automatic pin was found for. */
        var pinAddress = '';

        fields.classList.add('d-none');

        /**
         * @param {number|null} latitude
         * @param {number|null} longitude
         */
        function write(latitude, longitude) {
            lat.value = latitude === null ? '' : latitude.toFixed(6);
            lng.value = longitude === null ? '' : longitude.toFixed(6);
            // « The point — present or absent — is the address's, not a
            // human's »: an automatic pin dropped for a stale address posts
            // empty coordinates that the server must not lock.
            fill(automatic, manual ? '0' : '1');
            fill(manualField, manual ? '1' : '0');
            // The address this automatic pin was found for: a form sent
            // before the lookup of a new address answers still carries the
            // old pin, and the server must see it is not the new one.
            fill(pointAddress, manual ? '' : pinAddress);
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
                marker = L.marker(position, {
                    draggable: true,
                    title: 'Point de rendez-vous — faites-le glisser pour le déplacer',
                    alt: 'Point de rendez-vous',
                }).addTo(map);
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

        /** The address's marker: a fixed ring, unmistakable for the pin. */
        function markAddress(/** @type {[number, number]} */ position) {
            if (addressMarker) {
                addressMarker.setLatLng(position);
                return;
            }
            addressMarker = L.marker(position, {
                icon: L.divIcon({
                    className: 'carpool-address-marker',
                    html: '<span class="visually-hidden">Adresse du covoiturage</span>',
                    iconSize: [18, 18],
                }),
                title: 'Adresse du covoiturage',
                interactive: false,
                keyboard: false,
            }).addTo(map);
        }

        function forgetAddress() {
            addressPosition = null;
            if (addressMarker) {
                addressMarker.remove();
                addressMarker = null;
            }
        }

        /** The address, and the pin with it when they are apart. */
        function frame(/** @type {[number, number]} */ position) {
            if (marker && !samePlace(marker.getLatLng(), position)) {
                var here = marker.getLatLng();
                map.fitBounds([[here.lat, here.lng], position], { padding: [32, 32], maxZoom: 16 });
            } else {
                map.setView(position, 15);
            }
        }

        /** @param {[number, number]} position */
        function placeAddress(position) {
            addressPosition = position;
            if (!marker && manual) {
                // The chief removed the point on purpose: the map stays
                // closed, and « Placer le point » will open on the address.
                return;
            }
            showMap(position);
            markAddress(position);
            if (!manual) {
                pin(position);
            }
            frame(position);
        }

        /**
         * A pin nobody touched, found for an address the field no longer
         * holds, would be saved as the new address's point. Dropped, the
         * point goes back to the background task after saving.
         */
        function dropStalePin(/** @type {string} */ query) {
            if (manual || !marker || query === pinAddress) {
                return;
            }
            marker.remove();
            marker = null;
            write(null, null);
            mapBox.classList.add('d-none');
            placeButton.classList.remove('d-none');
        }

        var asked = 0;
        async function locate() {
            if (!address) {
                return;
            }
            if (!locateUrl) {
                // Nothing can confirm the new address: an untouched pin
                // from the old one must not be saved as its point.
                dropStalePin(address.value.trim());
                return;
            }
            var mine = ++asked;
            var query = address.value.trim();
            if (query.length < 4) {
                forgetAddress();
                dropStalePin(query);
                return;
            }
            var res = await window.ScoutMagicApi.getJson(locateUrl + '?q=' + encodeURIComponent(query));
            if (mine !== asked) {
                return;
            }
            var data = res.data;
            if (!data?.success || !data.found || !Number.isFinite(data.latitude) || !Number.isFinite(data.longitude)) {
                // Nothing found, over quota, or off: an old address's marker
                // would now be a lie, and so would its untouched pin. A pin
                // for THIS address (the page just opened) stays.
                forgetAddress();
                dropStalePin(query);
                return;
            }
            if (!manual) {
                pinAddress = query;
            }
            placeAddress([data.latitude, data.longitude]);
        }

        var latitude = coordinate(lat.value);
        var longitude = coordinate(lng.value);
        if (latitude !== null && longitude !== null) {
            // A saved automatic point belongs to the saved address.
            pinAddress = address ? address.value.trim() : '';
            showMap([latitude, longitude]);
            pin([latitude, longitude]);
        } else {
            placeButton.classList.remove('d-none');
        }

        placeButton.addEventListener('click', function () {
            showMap(addressPosition);
            if (addressPosition) {
                markAddress(addressPosition);
                map.setView(addressPosition, 15);
            }
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

        if (address) {
            // `change` fires when the field is left after an edit — once,
            // never per keystroke (Nominatim forbids autocompletion).
            address.addEventListener('change', function () {
                locate();
            });
            if (address.value.trim() !== '') {
                locate();
            }
        }

        return function () {
            locate();
        };
    }

    wireLocations(wirePoint());
})();
