/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Logging out releases this device's push subscription (issue #1123,
// SECURITY.md §19). A subscription belongs to the browser, not to the
// session: left alone, a shared phone or computer kept receiving the
// previous account's notifications — titles, excerpts — on its lock
// screen long after that person had logged out.
//
// On every logout form (there can be several on a page: the side menu,
// the desktop bar, the « compléter mon profil » page), the submit is held
// just long enough to:
//
//   1. find this device's subscription, if it has one;
//   2. put its endpoint in a `push_endpoint` field of the logout POST, so
//      the server forgets it in the same request, whichever account it
//      was registered for (AuthController::logout()) — a field rather
//      than a separate DELETE so nothing depends on which of two requests
//      lands first;
//   3. unsubscribe it in the browser.
//
// Then the form is sent. Nothing here may keep anybody logged in: no
// service worker, no permission, a browser that never answers — after
// RELEASE_BUDGET_MS the form goes anyway, with whatever was gathered.
//
// The service worker is told that nobody is signed in any more by
// offline-cache.js, which already hooks the same forms for the offline
// cache purge. IIFE/var style matches the rest of public/assets/js/.
(function () {
    var RELEASE_BUDGET_MS = 1500;
    var FIELD_NAME = 'push_endpoint';

    /**
     * @param {HTMLFormElement} form
     * @param {string} endpoint
     */
    function setEndpoint(form, endpoint) {
        var field = /** @type {HTMLInputElement | null} */ (form.querySelector('input[name="' + FIELD_NAME + '"]'));
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = FIELD_NAME;
            form.appendChild(field);
        }
        field.value = endpoint;
    }

    /**
     * getRegistration() rather than `ready`: `ready` never settles on a
     * page no worker controls, and the budget below would then be spent
     * on every logout for nothing.
     *
     * @param {HTMLFormElement} form
     * @returns {Promise<void>}
     */
    async function releaseDevice(form) {
        if (!('serviceWorker' in navigator)) {
            return;
        }
        var registration = await navigator.serviceWorker.getRegistration();
        if (!registration?.pushManager) {
            return;
        }
        var subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            return;
        }
        setEndpoint(form, subscription.endpoint);
        await subscription.unsubscribe();
    }

    /**
     * Resolves once the device is released or the budget is spent,
     * whichever comes first, and never rejects.
     *
     * @param {HTMLFormElement} form
     * @returns {Promise<void>}
     */
    function releaseWithinBudget(form) {
        var released = releaseDevice(form).catch(function () {});
        var budget = new Promise(function (resolve) {
            setTimeout(resolve, RELEASE_BUDGET_MS);
        });
        return Promise.race([released, budget]).then(function () {});
    }

    document.querySelectorAll('form[action="/logout"]').forEach(function (element) {
        var form = /** @type {HTMLFormElement} */ (element);
        var sending = false;

        form.addEventListener('submit', function (event) {
            // Refused earlier — offline-nav.js, in the capture phase, while
            // offline. Nothing is being sent, so nothing is released.
            if (event.defaultPrevented) {
                return;
            }
            event.preventDefault();
            // A second tap while the first is being released.
            if (sending) {
                return;
            }
            sending = true;

            void releaseWithinBudget(form).then(function () {
                form.submit();
            });
        });
    });
}());
