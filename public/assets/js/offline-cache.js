/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Offline content caching (Lot 3) — hands the server-declared whitelist
// (Core\Offline\OfflineWhitelist), the staleness setting, and the current
// functional-consent/account-scope state to the service worker on every
// page load (see public/sw.js's own 'message' handler) — the SW never
// hardcodes any of this itself, it's read from a #offline-config-data
// JSON blob base.html.twig renders from server-side Twig globals.
(function () {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    var configEl = document.getElementById('offline-config-data');
    if (!configEl) {
        return;
    }

    var config;
    try {
        config = JSON.parse(configEl.textContent);
    } catch (e) {
        return;
    }

    function postToServiceWorker(message) {
        navigator.serviceWorker.ready.then(function (registration) {
            if (registration.active) {
                registration.active.postMessage(message);
            }
        });
    }

    function sendConfig(consent) {
        postToServiceWorker({
            type: 'offline-config',
            staleness_days: config.stalenessDays,
            consent: consent,
            whitelist: config.whitelist,
            account_scope: config.accountScope,
            version: config.version
        });
    }

    sendConfig(config.consent);

    // Logout: "the app shell stays, every content cache is purged"
    // (module spec) — the cache name is already scoped per-account, so
    // this isn't strictly needed for correctness (a different login gets
    // a different cache name), but it guarantees no residue of the
    // previous identity's cached pages is left sitting in Cache Storage
    // on a shared device. There can be more than one logout form on a
    // page (desktop + mobile nav).
    document.querySelectorAll('form[action="/logout"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            postToServiceWorker({ type: 'purge-content-caches' });
        });
    });

    // Cookie preferences page: withdrawing functional consent must purge
    // immediately AND stop new pages being cached from this point on —
    // a bare purge message alone would empty the caches but leave the
    // service worker's own stored config still saying consent is granted,
    // so it would start writing to the cache again on the very next
    // whitelisted page visited in this same session. Sending a full
    // 'offline-config' update with consent: false does both (see
    // public/sw.js's 'message' handler). The accept-all/reject-all
    // banner buttons are handled in cookie-consent.js itself, right where
    // that fetch already happens.
    var cookiesForm = document.querySelector('form[action="/cookies/save"]');
    if (cookiesForm) {
        cookiesForm.addEventListener('submit', function () {
            var functionalCheckbox = document.getElementById('cookie-functional');
            if (functionalCheckbox && !functionalCheckbox.checked) {
                sendConfig(false);
            }
        });
    }
})();
