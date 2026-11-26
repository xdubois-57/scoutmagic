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
//    one destination. The same answer carries the events' dates, which
//    fill the date fields still empty (issue #692).
// 2. **The point** is the core's point picker (partials/point_picker.html.twig,
//    public/assets/js/point-picker.js, #1353): the pin, the address on the
//    map and the sentence under the address field all live there. This
//    script only tells it when the address was filled from the events.
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
            fillDates(res.data?.success ? res.data.dates : null);
        });
    }

    /**
     * The chosen events' dates, into the date fields still empty (issue
     * #692): the earliest start for the way there, the latest end for the
     * way back. A date somebody typed is never overwritten.
     *
     * @param {any} dates `{outbound, return}` as `Y-m-d`, or null
     */
    function fillDates(dates) {
        if (!dates) {
            return;
        }
        var pairs = /** @type {Array<[string, unknown]>} */ ([
            ['carpool-outbound', dates.outbound],
            ['carpool-return', dates.return],
        ]);
        pairs.forEach(function (pair) {
            var field = /** @type {HTMLInputElement|null} */ (document.getElementById(pair[0]));
            var value = typeof pair[1] === 'string' ? pair[1] : '';
            if (field?.value === '' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
                field.value = value;
            }
        });
    }

    /** What the page does when there is no map to centre. */
    function nothing() {
        // No map on this page: a change of address has nothing to move.
    }

    var picker = window.ScoutMagicPointPicker?.wire(
        /** @type {HTMLElement|null} */ (document.querySelector('[data-point-picker]'))
    );
    wireLocations(picker ? picker.locate : nothing);
})();
