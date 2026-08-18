/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Offline pre-download (Lot 4) — warms the whole offline-whitelisted app
// on every launch of the INSTALLED app ('display-mode: standalone'), with
// functional consent, and never anonymously (config.accountScope ===
// 'guest' bails out immediately — deferred until the first launch after
// login otherwise). Fetches GET /api/offline/manifest
// (Core\Offline\OfflineManifestService) for the exact set of page and
// image URLs the caller's role currently entitles them to, then:
//
// - Images: skip any URL already in the cache — a derivative URL is
//   immutable for a given file id (Core\Photo\ImageVariantService: a
//   re-upload always creates a new file id), so an unchanged photo costs
//   zero bytes on every subsequent launch. Cached image entries no longer
//   in the manifest (a member no longer linked, a photo removed) are
//   deleted, so the cache doesn't grow unbounded across launches/staff
//   changes.
// - Pages: re-validate with `If-None-Match` built from the cached
//   response's own ETag (Core\Http\FrontController, whitelisted paths
//   only). A 304 means content didn't change — re-`put` the SAME cached
//   body with a refreshed `Date` header rather than re-downloading,
//   otherwise the staleness threshold and the "Version hors ligne du …"
//   banner (public/sw.js) would fire on a copy that was just confirmed
//   current a moment ago.
//
// Every entry is best-effort: one failed fetch must never abort the rest
// of the run. Writes go straight to the SAME content-{accountScope}-
// {version} cache public/sw.js's own fetch handler reads from — this
// script is the only writer for image derivatives (sw.js's own
// /files/{id}/{variant} branch is read-only, see that file) and, since it
// only ever runs in standalone mode, is automatically consistent with the
// "installed app only" write policy that gates public/sw.js's own page
// writes (config.standalone, offline-cache.js).
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

    var cacheName = 'content-' + config.accountScope + '-' + config.version;
    var IMAGE_URL_PATTERN = /^\/files\/\d+\/(thumb|md)$/;

    fetch('/api/offline/manifest', { headers: { Accept: 'application/json' } })
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (manifest) {
            if (!manifest) {
                return null;
            }
            return caches.open(cacheName).then(function (cache) {
                return Promise.all([
                    prefetchImages(cache, manifest.images || []),
                    prefetchPages(cache, manifest.pages || [])
                ]);
            });
        })
        .catch(function () {
            // Best-effort at the top level too — a failed manifest fetch
            // (offline, or a transient error) simply skips this launch's
            // refresh; nothing to mark, nothing special to retry, the
            // next launch tries again from scratch.
        });

    // Extracted out of prefetchImages() (rather than nested inline in its
    // already-deep .then/.map/.then chain) purely to keep function nesting
    // shallow — same fetch/put/catch behavior either way.
    function fetchAndCacheImage(cache, url) {
        return fetch(url).then(function (response) {
            if (response.ok) {
                return cache.put(url, response);
            }
        }).catch(function () {
            // Best-effort per image — one bad fetch must
            // never stop the rest of the pre-download.
        });
    }

    function prefetchImages(cache, imageUrls) {
        var wanted = {};
        imageUrls.forEach(function (url) { wanted[url] = true; });

        return cache.keys().then(function (requests) {
            var stale = requests.filter(function (request) {
                var path = new URL(request.url).pathname;
                return IMAGE_URL_PATTERN.test(path) && !wanted[path];
            });

            return Promise.all(stale.map(function (request) {
                return cache.delete(request).catch(function () {});
            }));
        }).then(function () {
            return Promise.all(imageUrls.map(function (url) {
                return cache.match(url).then(function (cached) {
                    if (cached) {
                        return undefined;
                    }
                    return fetchAndCacheImage(cache, url);
                });
            }));
        });
    }

    function prefetchPages(cache, pageUrls) {
        return Promise.all(pageUrls.map(function (url) {
            return cache.match(url).then(function (cached) {
                var headers = {};
                var etag = cached ? cached.headers.get('ETag') : null;
                if (etag) {
                    headers['If-None-Match'] = etag;
                }

                return fetch(url, { headers: headers }).then(function (response) {
                    if (response.status === 304 && cached) {
                        return refreshCachedDate(cache, url, cached);
                    }
                    if (response.ok) {
                        return cache.put(url, response);
                    }
                }).catch(function () {
                    // Best-effort per page.
                });
            });
        }));
    }

    function refreshCachedDate(cache, url, cached) {
        return cached.clone().blob().then(function (body) {
            var headers = new Headers(cached.headers);
            headers.set('Date', new Date().toUTCString());
            var refreshed = new Response(body, {
                status: cached.status,
                statusText: cached.statusText,
                headers: headers
            });
            return cache.put(url, refreshed);
        });
    }
})();
