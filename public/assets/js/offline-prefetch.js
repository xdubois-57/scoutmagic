/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
// Offline pre-download (Lot 4) — warms the offline-whitelisted app ONCE
// PER LAUNCH of the INSTALLED app ('display-mode: standalone'), with
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
// ONCE PER LAUNCH, and it has to be said twice because the first version
// of this file said it in its header and did it on EVERY PAGE: nothing
// gated the run, so each navigation in the installed app fetched the
// manifest and re-validated every whitelisted page — fifteen server
// renders, all at once, behind the page the visitor was actually waiting
// for (a conditional GET is rendered in full before the 304 is decided).
// Measured: 34 server-rendered documents per navigation against 4 in a
// browser tab. Now:
//
// - a launch is remembered in sessionStorage, which an installed app
//   keeps for as long as its window lives, and a run older than
//   REFRESH_AFTER_MS is allowed again so an app left open for days still
//   refreshes;
// - the run starts after `load`, in idle time, never in the way of the
//   page being read;
// - at most CONCURRENCY requests are in flight, pages first, then images
//   — the server has few workers and the visitor's next tap needs one;
// - nothing runs at all when the browser reports Data Saver.
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
    var LAUNCH_KEY = 'scoutmagic-offline-prefetch';
    var REFRESH_AFTER_MS = 24 * 60 * 60 * 1000;
    var CONCURRENCY = 2;
    var IDLE_FALLBACK_MS = 1500;

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
    var connection = /** @type {any} */ (navigator).connection;
    if (connection && connection.saveData === true) {
        return;
    }
    if (!claimThisLaunch()) {
        return;
    }

    var cacheName = 'content-' + config.accountScope + '-' + config.version;
    var IMAGE_URL_PATTERN = /^\/files\/\d+\/(thumb|md)$/;

    whenIdle(run);

    /**
     * True for the first page of a launch (or the first page after
     * REFRESH_AFTER_MS), false for every other page of the same launch.
     * A browser that refuses sessionStorage (private mode, quota) counts
     * as a fresh launch every time — the previous behaviour, never worse.
     * @returns {boolean}
     */
    function claimThisLaunch() {
        var scope = config.accountScope + '|' + config.version;
        try {
            var raw = window.sessionStorage.getItem(LAUNCH_KEY);
            if (raw) {
                var last = JSON.parse(raw);
                if (last && last.scope === scope && (Date.now() - Number(last.at)) < REFRESH_AFTER_MS) {
                    return false;
                }
            }
            window.sessionStorage.setItem(LAUNCH_KEY, JSON.stringify({ scope: scope, at: Date.now() }));
        } catch (e) {
            // No sessionStorage: run, as before.
        }
        return true;
    }

    /**
     * After the page has loaded and the browser has nothing better to do.
     * @param {() => void} task
     */
    function whenIdle(task) {
        var start = function () {
            if (typeof window.requestIdleCallback === 'function') {
                window.requestIdleCallback(function () { task(); }, { timeout: IDLE_FALLBACK_MS * 2 });
            } else {
                setTimeout(task, IDLE_FALLBACK_MS);
            }
        };
        if (document.readyState === 'complete') {
            start();
        } else {
            window.addEventListener('load', start, { once: true });
        }
    }

    function run() {
        fetch('/api/offline/manifest', { headers: { Accept: 'application/json' } })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (manifest) {
                if (!manifest) {
                    return null;
                }
                return caches.open(cacheName).then(function (cache) {
                    // Pages first — they are what "offline" means — then
                    // the images they show; never both at once.
                    return prefetchPages(cache, manifest.pages || []).then(function () {
                        return prefetchImages(cache, manifest.images || []);
                    });
                });
            })
            .catch(function () {
                // Best-effort at the top level too — a failed manifest fetch
                // (offline, or a transient error) simply skips this launch's
                // refresh; nothing to mark, nothing special to retry, the
                // next launch tries again from scratch.
            });
    }

    /**
     * Run `work` over `items`, CONCURRENCY at a time, in order.
     * @template T
     * @param {T[]} items
     * @param {(item: T) => Promise<any>} work
     * @returns {Promise<void>}
     */
    function inBatches(items, work) {
        var queue = items.slice();
        var lanes = [];
        var lane = function () {
            var next = queue.shift();
            if (next === undefined) {
                return Promise.resolve();
            }
            return Promise.resolve().then(function () { return work(next); }).catch(function () {}).then(lane);
        };
        for (var i = 0; i < CONCURRENCY; i++) {
            lanes.push(lane());
        }
        return Promise.all(lanes).then(function () {});
    }

    // Extracted out of prefetchImages() (rather than nested inline in its
    // already-deep .then/.map/.then chain) purely to keep function nesting
    // shallow — same fetch/put/catch behavior either way.
    /**
     * @param {Cache} cache
     * @param {string} url
     * @returns {Promise<void>}
     */
    function fetchAndCacheImage(cache, url) {
        return fetch(url).then(function (response) {
            if (response.ok) {
                return cache.put(url, response);
            }
            return undefined;
        }).catch(function () {
            // Best-effort per image — one bad fetch must
            // never stop the rest of the pre-download.
        });
    }

    /**
     * @param {Cache} cache
     * @param {string[]} imageUrls
     * @returns {Promise<any>}
     */
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
            return inBatches(imageUrls, function (url) {
                return cache.match(url).then(function (cached) {
                    if (cached) {
                        return undefined;
                    }
                    return fetchAndCacheImage(cache, url);
                });
            });
        });
    }

    /**
     * @param {Cache} cache
     * @param {string[]} pageUrls
     * @returns {Promise<any>}
     */
    function prefetchPages(cache, pageUrls) {
        return inBatches(pageUrls, function (url) {
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
                    return undefined;
                }).catch(function () {
                    // Best-effort per page.
                });
            });
        });
    }

    /**
     * @param {Cache} cache
     * @param {string} url
     * @param {Response} cached
     * @returns {Promise<void>}
     */
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
