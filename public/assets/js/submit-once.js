/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// One submission per submission, on a classic (non-fetch) form.
//
// `ScoutMagicApi.withDisabled` covers the fetch-based writes; a form that
// posts the ordinary way has nothing to await, so the button stays live
// for as long as the round trip takes — long enough on a phone for a
// second tap to send the whole thing twice. On a paying form that is a
// second response, a second receivable and a second ticket.
//
// Delegated on `document`, like confirm.js, so a form only has to carry
// `data-submit-once` — nothing to wire per page.
//
// Degradation is the point: without this script the form still submits,
// exactly as before, and the server's own de-duplication is what catches
// the second one. This makes the ordinary case impossible rather than
// merely repaired.
(function () {
    document.addEventListener('submit', function (event) {
        // confirm.js (also delegated on `document`) stops a first submit
        // and replays it once the visitor agrees, which fires this
        // listener again. Standing aside while the event is already
        // prevented keeps the confirmation in front of the action.
        if (event.defaultPrevented) return;

        var form = /** @type {HTMLFormElement|null} */ (event.target);
        if (form === null || typeof form.matches !== 'function' || !form.matches('form[data-submit-once]')) {
            return;
        }

        // The browser has accepted the submission by the time this runs
        // (a form failing its own validation never gets here), so the
        // controls can go now.
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (control) {
            var button = /** @type {HTMLButtonElement|HTMLInputElement} */ (control);
            // `disabled` on a submit button is not submitted, and this
            // form's action does not read it — but a `name`d one would
            // be lost, so it is only ever disabled AFTER the browser has
            // serialised the form.
            window.setTimeout(function () {
                button.disabled = true;
                if (button instanceof HTMLButtonElement) {
                    button.setAttribute('aria-busy', 'true');
                }
            }, 0);
        });
    });
})();
