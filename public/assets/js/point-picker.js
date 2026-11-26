/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The core's point picker (#1353) — window.ScoutMagicPointPicker. It
// drives every `[data-point-picker]` that core/View/templates/partials/
// point_picker.html.twig renders, and the form works without it: what the
// form meant is read back on the server by Core\Geo\PointSubmission.
//
// Born in the carpool organiser's form (covoiturage-organize.js, #570,
// #642, #692) and moved to the core so that every form with a point — the
// carpool, a camps place — behaves the same:
//
// 1. **The point.** Without JavaScript the page shows two coordinate
//    fields. With it, they are hidden and a map takes their place: the pin
//    is dragged, placed with a click or a touch, or removed, and the fields
//    follow. Touching the pin makes it a human's point, which the module
//    locks for ever (Core\Geo\GeoPointStore).
// 2. **The address on the map.** When the page names an address field
//    (`data-address-field`) and a lookup route (`data-locate-url`), leaving
//    the field looks the address up through the page's own route (never
//    Nominatim from here: the CSP says `connect-src 'self'`). The map
//    centres on it and a fixed ring shows it; a pin nobody touched moves
//    there too, a hand-placed one stays where it is. A lookup that finds
//    nothing changes nothing on the map, but says so under the field
//    (`data-address-status`): « introuvable » when the map does not know
//    the address, « pas pu chercher » when the lookup could not run. An
//    answer older than the last question is ignored.
//
// Nothing here knows a module: ids, routes and words all come from the
// partial's parameters. The map is drawn through the core's
// public/assets/js/map.js, the one script allowed to name the tile host.
(function () {
    'use strict';

    /**
     * @typedef {{locate: () => void, refresh: () => void}} PointPickerHandle
     */

    /** @type {WeakMap<HTMLElement, PointPickerHandle>} */
    var wired = new WeakMap();

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

    /**
     * The element an id in a data attribute names, or null.
     *
     * @param {string|undefined} id
     * @returns {HTMLElement|null}
     */
    function byId(id) {
        return id ? document.getElementById(id) : null;
    }

    /**
     * The address a point the page opens with was found for.
     *
     * @param {HTMLInputElement|null} pointAddress
     * @param {HTMLInputElement|null} address
     */
    function savedPinAddress(pointAddress, address) {
        if (pointAddress?.value) {
            return pointAddress.value;
        }
        return address ? address.value.trim() : '';
    }

    /**
     * Wires one picker. Null when there is no map to offer — no Leaflet,
     * no map.js, or markup missing a piece: the coordinate fields stay,
     * and work.
     *
     * @param {HTMLElement} box
     * @returns {PointPickerHandle|null}
     */
    function build(box) {
        if (typeof L === 'undefined' || !window.ScoutMagicMap) {
            return null;
        }
        var fields = /** @type {HTMLElement|null} */ (box.querySelector('[data-point-picker-fields]'));
        var mapBox = /** @type {HTMLElement|null} */ (box.querySelector('[data-point-picker-map-box]'));
        var mapElement = /** @type {HTMLElement|null} */ (box.querySelector('[data-point-picker-map]'));
        var placeButton = /** @type {HTMLButtonElement|null} */ (box.querySelector('[data-point-picker-place]'));
        var removeButton = /** @type {HTMLButtonElement|null} */ (box.querySelector('[data-point-picker-remove]'));
        var automatic = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-point-picker-automatic]'));
        var manualField = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-point-picker-manual]'));
        var pointAddress = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-point-picker-address]'));
        var line = box.querySelector('[data-point-picker-line]');
        var origin = box.querySelector('[data-point-picker-origin]');
        var lat = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-point-picker-latitude]'));
        var lng = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-point-picker-longitude]'));
        var address = /** @type {HTMLInputElement|null} */ (byId(box.dataset.addressField));
        var addressStatus = byId(box.dataset.addressStatus);
        if (!fields || !mapBox || !mapElement || !placeButton || !removeButton || !lat || !lng) {
            return null;
        }
        var latField = lat;
        var lngField = lng;
        var mapContainer = mapElement;
        var mapFrame = mapBox;
        var place = placeButton;

        var pinLabel = box.dataset.pinLabel || 'Point sur la carte';
        var addressLabel = box.dataset.addressLabel || 'Adresse';

        /**
         * The sentence under the address field (issue #692): the lookup
         * used to fail in silence, and a venue name the map does not know
         * looked exactly like a lookup that never ran. Empty text hides it.
         *
         * @param {string} text
         */
        function sayAddress(text) {
            if (!addressStatus) {
                return;
            }
            addressStatus.textContent = text;
            addressStatus.classList.toggle('d-none', text === '');
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
            latField.value = latitude === null ? '' : latitude.toFixed(6);
            lngField.value = longitude === null ? '' : longitude.toFixed(6);
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
                line.textContent = latitude === null ? '' : latField.value + ', ' + lngField.value;
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
                    title: pinLabel + ' — faites-le glisser pour le déplacer',
                    alt: pinLabel,
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
            mapFrame.classList.remove('d-none');
            place.classList.add('d-none');
            if (!map) {
                map = window.ScoutMagicMap?.create(mapContainer, center ? { center: center, zoom: 15 } : undefined);
                map.on('click', function (/** @type {any} */ event) {
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
            var label = document.createElement('span');
            label.className = 'visually-hidden';
            label.textContent = addressLabel;
            addressMarker = L.marker(position, {
                icon: L.divIcon({
                    className: 'point-picker-address-marker',
                    html: label.outerHTML,
                    iconSize: [18, 18],
                }),
                title: addressLabel,
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
                // The point was removed on purpose: the map stays closed,
                // and « Placer le point » will open on the address.
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
         * point goes back to the module's background task after saving.
         */
        function dropStalePin(/** @type {string} */ query) {
            if (manual || !marker || query === pinAddress) {
                return;
            }
            marker.remove();
            marker = null;
            write(null, null);
            mapFrame.classList.add('d-none');
            place.classList.remove('d-none');
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
                sayAddress('');
                forgetAddress();
                dropStalePin(query);
                return;
            }
            var res = await window.ScoutMagicApi.getJson(locateUrl + '?q=' + encodeURIComponent(query));
            if (mine !== asked) {
                return;
            }
            var data = res.data;
            if (data?.success && !data.found && data.reason === 'superseded') {
                // A newer request of this same person — another tab — is
                // the one that answers; this one changes nothing.
                return;
            }
            if (!data?.success || !data.found || !Number.isFinite(data.latitude) || !Number.isFinite(data.longitude)) {
                sayAddress(data?.success && data.reason !== 'unavailable'
                    ? 'Adresse introuvable sur la carte : vérifiez-la, ou placez le point à la main.'
                    : 'La carte n’a pas pu chercher cette adresse pour l’instant : placez le point à la main.');
                // Nothing found, over quota, or off: an old address's ring
                // would now be a lie, and so would its untouched pin. A pin
                // for THIS address (the page just opened) stays.
                forgetAddress();
                dropStalePin(query);
                return;
            }
            sayAddress('');
            if (!manual) {
                pinAddress = query;
            }
            placeAddress([data.latitude, data.longitude]);
        }

        var latitude = coordinate(latField.value);
        var longitude = coordinate(lngField.value);
        if (latitude !== null && longitude !== null) {
            // A form shown again after a refusal says which address its
            // point was found for — perhaps not the one now in the field.
            // Otherwise a saved automatic point belongs to the saved address.
            pinAddress = savedPinAddress(pointAddress, address);
            showMap([latitude, longitude]);
            pin([latitude, longitude]);
        } else {
            place.classList.remove('d-none');
        }

        place.addEventListener('click', function () {
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
            mapFrame.classList.add('d-none');
            place.classList.remove('d-none');
        });

        if (address) {
            // `change` fires when the field is left after an edit — once,
            // never per keystroke (Nominatim forbids autocompletion).
            address.addEventListener('change', function () {
                void locate();
            });
            if (address.value.trim() !== '') {
                void locate();
            }
        }

        return {
            /** Look the address up again — a page that filled the field itself calls it. */
            locate: function () {
                void locate();
            },
            /**
             * Tell the map its box changed size — a picker inside a block
             * the page shows later was drawn into nothing (Leaflet needs a
             * size to draw).
             */
            refresh: function () {
                if (!map) {
                    return;
                }
                map.invalidateSize();
                // The view the last lookup would have given, had the box
                // been visible: the address with the pin, or the pin alone.
                if (addressPosition) {
                    frame(addressPosition);
                } else if (marker) {
                    var here = marker.getLatLng();
                    map.setView([here.lat, here.lng], 15);
                }
            },
        };
    }

    /**
     * The handle of a picker, wiring it on first use. Null when the page
     * cannot draw a map.
     *
     * @param {HTMLElement|null} box
     * @returns {PointPickerHandle|null}
     */
    function wire(box) {
        if (!box) {
            return null;
        }
        var handle = wired.get(box);
        if (!handle) {
            // Not cached when the map could not be offered: a page whose
            // Leaflet or map.js came later asks again and gets one then.
            handle = build(box);
            if (handle) {
                wired.set(box, handle);
            }
        }
        return handle || null;
    }

    window.ScoutMagicPointPicker = { wire: wire };

    document.querySelectorAll('[data-point-picker]').forEach(function (box) {
        wire(/** @type {HTMLElement} */ (box));
    });
})();
