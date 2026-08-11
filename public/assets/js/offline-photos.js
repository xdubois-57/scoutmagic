/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Offline trombinoscope photo pre-download (Lot 3) — every section's
// staff faces, not just the viewer's own, so the trombinoscope shows
// faces and not initials while offline. Fires once, on the first launch
// of the INSTALLED app (module spec) — 'display-mode: standalone' is how
// a launched-from-home-screen session is distinguished from a normal
// browser tab, since a regular tab visit has no offline expectation. If
// no one is logged in yet, this simply does nothing until the first
// launch after login (never fires anonymously) — the account-scoped
// localStorage marker below makes that "next launch after login" check
// free, no separate login-hook needed.
//
// Thumbnails land in the SAME content-{accountScope}-{version} cache
// public/sw.js's own fetch handler writes to for pages (see its
// '/api/offline/photo/' branch) — one cache, one purge-on-logout/
// consent-withdrawal path for everything this feature stores.
(function () {
    if (!('caches' in window)) {
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

    if (!config.consent || config.accountScope === 'guest') {
        return;
    }

    var isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
        || window.navigator.standalone === true;
    if (!isStandalone) {
        return;
    }

    var markerKey = 'offline-photos-predownloaded-' + config.accountScope + '-' + config.version;
    if (window.localStorage.getItem(markerKey) === '1') {
        return;
    }

    fetch('/api/offline/photo-manifest', { headers: { Accept: 'application/json' } })
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (data) {
            if (!data || !Array.isArray(data.photos) || data.photos.length === 0) {
                return null;
            }

            var cacheName = 'content-' + config.accountScope + '-' + config.version;
            return caches.open(cacheName).then(function (cache) {
                return Promise.all(data.photos.map(function (photo) {
                    return fetch(photo.url).then(function (response) {
                        if (response.ok) {
                            return cache.put(photo.url, response);
                        }
                    }).catch(function () {
                        // Best-effort per photo — one bad fetch must never
                        // stop the rest of the trombinoscope pre-download.
                    });
                }));
            });
        })
        .then(function () {
            window.localStorage.setItem(markerKey, '1');
        })
        .catch(function () {
            // Left unmarked on failure (e.g. the manifest fetch itself
            // failed) so the next launch retries rather than giving up
            // permanently on a transient error.
        });
})();
