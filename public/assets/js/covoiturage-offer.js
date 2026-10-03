/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The offer form of the carpool module (modules/covoiturage/views/
// offer_form.html.twig), issue #703. Two helpers, and the form works
// without either: the server already wrote a first suggestion.
//
// 1. **The suggested departure.** When the meeting point changes VALUE —
//    the `change` event, never per keystroke: Nominatim forbids
//    autocompletion — ask the site's own route (GET …/trajet?depuis=…,
//    never a third party from here: the CSP says `connect-src 'self'`).
//    Its answer carries the suggested times and the sentence saying how
//    they were obtained. The sentence is always updated; the hour itself
//    only while the driver has not typed one of their own.
// 2. **The return field.** Shown only while « Je propose aussi des places
//    au retour » is ticked.
(function () {
    'use strict';

    var form = /** @type {HTMLFormElement|null} */ (document.getElementById('offer-form'));
    if (!form) {
        return;
    }

    var api = window.ScoutMagicApi;
    var outbound = form.dataset.direction !== 'return';
    var endpoint = /** @type {HTMLInputElement|null} */ (document.getElementById('offer-endpoint'));
    var time = /** @type {HTMLInputElement|null} */ (document.getElementById('offer-time'));
    var timeLine = document.getElementById('offer-time-suggestion');
    var alsoReturn = /** @type {HTMLInputElement|null} */ (document.getElementById('offer-also-return'));
    var returnBlock = document.getElementById('offer-return-block');
    var returnTime = /** @type {HTMLInputElement|null} */ (document.getElementById('offer-return-time'));
    var returnLine = document.getElementById('offer-return-suggestion');

    // An hour the driver typed is theirs: a later suggestion must not
    // overwrite it.
    var touched = { time: false, returnTime: false };
    if (time) {
        time.addEventListener('input', function () { touched.time = true; });
    }
    if (returnTime) {
        returnTime.addEventListener('input', function () { touched.returnTime = true; });
    }

    /**
     * @param {HTMLElement|null} line
     * @param {HTMLInputElement|null} field
     * @param {boolean} fieldTouched
     * @param {{time: string, line: string}|null} suggestion
     */
    function show(line, field, fieldTouched, suggestion) {
        if (!line) {
            return;
        }
        line.hidden = !suggestion;
        line.textContent = suggestion ? suggestion.line : '';
        if (suggestion && field && !fieldTouched) {
            field.value = suggestion.time;
        }
    }

    var asked = 0;

    function refresh() {
        if (!endpoint) {
            return;
        }
        // Every change makes a new question, so an answer still on its way
        // for the previous meeting point never lands after it was cleared.
        var mine = ++asked;
        if (endpoint.value.trim() === '') {
            return;
        }
        var url = (form.dataset.travelUrl || '') + '?depuis=' + encodeURIComponent(endpoint.value.trim());
        api.getJson(url).then(function (res) {
            // Only the latest question's answer counts.
            if (mine !== asked || !res.ok || !res.data) {
                return;
            }
            show(timeLine, time, touched.time, outbound ? res.data.outbound : res.data.return);
            if (outbound) {
                show(returnLine, returnTime, touched.returnTime, res.data.return);
            }
        });
    }

    if (endpoint) {
        endpoint.addEventListener('change', refresh);
        // A meeting point pre-filled with the unit's premises is a value
        // already: its route is asked for once, on arrival.
        refresh();
    }

    if (alsoReturn && returnBlock) {
        alsoReturn.addEventListener('change', function () {
            returnBlock.hidden = !alsoReturn.checked;
        });
    }
})();
