/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

document.addEventListener('DOMContentLoaded', function () {
    var banner = document.getElementById('cookie-banner');
    if (!banner) return;

    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    document.getElementById('cookie-accept-all').addEventListener('click', function () {
        fetch('/cookies/accept-all', {
            method: 'POST',
            headers: { 'X-CSRF-Token': getCsrf() }
        }).then(function () {
            banner.remove();
        });
    });

    document.getElementById('cookie-reject-all').addEventListener('click', function () {
        fetch('/cookies/reject-all', {
            method: 'POST',
            headers: { 'X-CSRF-Token': getCsrf() }
        }).then(function () {
            banner.remove();
            // Withdrawing functional consent (Lot 3) must purge the
            // offline content caches immediately AND stop the service
            // worker writing to them again before the next page load —
            // a bare purge message alone wouldn't update its stored
            // consent flag (see public/assets/js/offline-cache.js and
            // public/sw.js's own 'message' handler for why this is a
            // full config update, not just a purge). Reads the same
            // #offline-config-data blob offline-cache.js does, so the
            // stored whitelist/version/account scope aren't clobbered by
            // a stub — only consent actually changes here.
            if ('serviceWorker' in navigator) {
                var configEl = document.getElementById('offline-config-data');
                var config = null;
                if (configEl) {
                    try {
                        config = JSON.parse(configEl.textContent);
                    } catch (e) {
                        config = null;
                    }
                }
                navigator.serviceWorker.ready.then(function (registration) {
                    if (registration.active) {
                        registration.active.postMessage({
                            type: 'offline-config',
                            consent: false,
                            staleness_days: config ? config.stalenessDays : undefined,
                            whitelist: config ? config.whitelist : [],
                            account_scope: config ? config.accountScope : 'guest',
                            version: config ? config.version : undefined
                        });
                    }
                });
            }
        });
    });

    document.getElementById('cookie-customize').addEventListener('click', function () {
        window.location.href = '/cookies';
    });
});
