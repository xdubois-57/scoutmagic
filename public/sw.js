// Installable-PWA app shell (Lot 1) + Web Push (Lot 2) — hand-written, no
// Workbox/build tool. Must sit at the web root (public/ is the document
// root, so this resolves to /sw.js) — moving it under /assets/ would
// silently scope it to that subtree instead of the whole site.
//
// This is deliberately the ONLY service worker on the site. An earlier,
// separate push-notifications iteration registered its own worker at
// /sw-push.js — since both that file and this one resolve to the same
// (default, web-root) scope, whichever registered LAST on a given page
// load silently became the sole controller for that tab, and the other's
// handlers (push here, or the app-shell cache there) simply never fired.
// Lot 2 merges push handling into this single worker and retires
// /sw-push.js/push-notifications.js's own registration entirely — see
// public/assets/js/push-notifications.js, which now waits on the
// registration base.html.twig already performs instead of registering a
// second worker.
//
// Registered as /sw.js?v={appVersion}&iv={pwaIconVersion} (see
// base.html.twig) — appVersion comes from Core\Maintenance\VersionFile,
// the same file a GitHub-release install (§8.17) overwrites as its last
// step; pwaIconVersion is Core\Photo\UnitLogoService's independent
// counter, bumped on every unit logo upload/delete/re-derivation.
// self.location.search is how a plain static script reads that query
// string at runtime; there is no server-side templating here. The cache
// name derives from BOTH values, so either one changing is enough to
// invalidate: a release bump OR a standalone logo upload (which never
// touches appVersion) each make the browser treat this as a new worker
// (a byte-different script), which installs, precaches fresh copies
// under a new cache name, and activate() below deletes every cache whose
// name starts with the app-shell prefix and doesn't match it — NEVER any
// other cache (offline-config, content-*): see activate() itself for the
// real bug this used to cause.
//
// Scope of the app-shell cache (Lot 1): the shell ONLY (Bootstrap, the
// site's own CSS/JS, the icons, /offline). Cache-first for exactly those;
// everything else — including every authenticated page and the plain
// /files/{id} original — is network-only. No content caching here.
//
// Scope of push (Lot 2): receive a push, show a real, visible
// notification for it — Chrome silently substitutes "this site was
// updated in the background" for any push that doesn't call
// showNotification(), so a silent/data-only push is not an option — and
// update the installed app's icon badge (navigator.setAppBadge(), only
// from here: it must update while the app is closed, which a page-side
// call could never do). Deliberately no `tag` on any notification —
// several arriving the same evening must all remain visible, never
// collapse into one.
//
// Scope of content caching (Lot 3, #883): network-first, cache-fallback,
// for an exact LIST of addresses — GET /api/offline/manifest
// (Core\Offline\OfflineManifestService), the pages the person signed in
// holds offline — and nothing else: a page off the list is never written,
// visited or not. The plain, un-suffixed `/files/{id}` is never on it and
// is never reachable through this path — nothing here changes the
// "network-only" rule above for it. Cache name is
// `content-{accountScope}-{version}`: scoped to the signed-in account (or
// 'guest') so a different member on the same device never inherits the
// previous one's cache, and to the app version like the shell cache
// above — a new version takes the previous one's copies over
// (adoptPreviousVersion()). Config (staleness threshold, consent, account
// scope) arrives via postMessage from base.html.twig on every page load —
// never hardcoded here — and is persisted in its own small Cache Storage
// entry so it survives this worker being terminated and restarted between
// messages and fetches; the list and the progress of a pass live beside it.
//
// The list is downloaded and kept fresh HERE, by refreshOfflineCopies(),
// when offline-prefetch.js asks: pages, then the images they show, then
// the help, and a pass interrupted is resumed rather than restarted.
//
// Image-variant derivatives (GET /files/{id}/thumb|md — Core\Photo\
// ImageVariantService) get their own narrow branch further down:
// network-first, falling back to Cache Storage on a network failure, and
// written only when the page that shows them is on the list.

const params = new URLSearchParams(self.location.search);
const VERSION = params.get('v') || 'dev';
// pwa_icon_version (Core\Photo\UnitLogoService) — bumped on every unit
// logo upload/delete/re-derivation, entirely independent of VERSION
// above (a logo change is not a release). Folded into CACHE_NAME below so
// an icon-only change still gets its own purge-and-re-precache cycle
// instead of silently reusing whatever was cached under the last app
// version — this is the actual fix for icons never updating without a
// manual cache wipe (see the real bug this closes: ARCHITECTURE §8.23).
const ICON_VERSION = params.get('iv') || '1';
const CACHE_NAME = 'app-shell-' + VERSION + '-icons-' + ICON_VERSION;
const APP_SHELL_CACHE_PREFIX = 'app-shell-';

// Icon URLs carry their own server-issued `?v=` cache-buster
// (pwa_icon_version) baked into the precached Request's URL below, and
// are matched WITHOUT ignoreSearch in the fetch handler further down — a
// stale `?v=` cached from an old HTML response must reliably MISS this
// cache and fall through to the network rather than resolving to
// whatever version happens to be precached (that was the actual bug:
// ignoreSearch made every "?v=N" match the one bare-path entry
// regardless of N, so a re-upload never visibly changed anything without
// a manual cache wipe). /favicon.ico is deliberately NOT in this list —
// browsers request it implicitly at the document root with no query
// string to version at all, so it can never carry `?v=` and stays in the
// plain APP_SHELL_URLS list below instead, matched with ignoreSearch
// (harmless there, since it never legitimately carries one).
const ICON_URLS = [
    '/pwa/icon-192.png',
    '/pwa/icon-512.png',
    '/pwa/icon-512-maskable.png',
    '/pwa/icon-180.png',
    // Unit logo feature widened Core\Photo\UnitLogoService beyond the
    // original four PWA sizes above — favicons and the footer logo are
    // versioned/precached the exact same way.
    '/pwa/icon-16.png',
    '/pwa/icon-32.png',
    '/pwa/icon-48.png',
    '/pwa/icon-64.png',
];
const VERSIONED_ICON_URLS = ICON_URLS.map(function (path) { return path + '?v=' + ICON_VERSION; });

// Explicit whitelist — never a blacklist. Every entry here is public,
// static, and contains no personal data. ignoreSearch stays in effect for
// all of these (see the fetch handler) — none of them legitimately
// carries a query string in real traffic, unlike the icon URLs above.
const APP_SHELL_BASE_URLS = [
    '/assets/vendor/bootstrap/css/bootstrap.min.css',
    '/assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
    '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
    '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff',
    '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
    '/assets/css/app.css',
    '/assets/css/editable.css',
    '/assets/css/components.css',
    // The four toolboxes base.html.twig loads on every page, ahead of any
    // page's own scripts. They were missing here until a test started
    // comparing this list against base.html.twig: offline, the shell
    // rendered without window.ScoutMagicApi (every fetch helper gone),
    // without ScoutMagicToast and ScoutMagicConfirm (every confirmation
    // silently inert — a « Supprimer » that does nothing at all), and
    // without theme.js, which repaints the saved dark theme.
    '/assets/js/api.js',
    '/assets/js/toast.js',
    '/assets/js/confirm.js',
    '/assets/js/rich-text-link.js',
    '/assets/js/theme.js',
    '/assets/js/nav.js',
    '/assets/js/editable.js',
    '/assets/js/cookie-consent.js',
    '/assets/js/breadcrumb.js',
    // Le filet des liens de fichiers (assets/js/file-viewer.js). Hors
    // ligne il compte autant : un lien vers un fichier que personne n'a
    // décoré fait quitter la fenêtre de l'application installée, et c'est
    // hors ligne — un chef dans un pré sans réseau — que cette
    // application est le plus souvent la seule ouverte.
    '/assets/js/file-viewer.js',
    // The installed application's two guards (issue #502): the cookie
    // that tells the server this window is the app, and the external-link
    // hand-off to the phone's browser — the second matters offline too,
    // since a cached page still carries its links.
    '/assets/js/display-mode.js',
    '/assets/js/installed-app-links.js',
    // A cached page still shows its phone numbers, and calling one is
    // exactly what a chef without network may need.
    '/assets/js/phone-link.js',
    '/assets/js/select-bar.js',
    '/assets/js/nav-rail.js',
    '/assets/js/audit-timeline.js',
    '/assets/js/help-panel.js',
    '/assets/js/help-search.js',
    '/assets/js/help-assistant.js',
    '/assets/js/help-discovery.js',
    '/assets/js/push-subscribe.js',
    '/assets/js/push-invitation.js',
    '/assets/js/notification-badge.js',
    '/assets/js/offline-cache.js',
    '/assets/js/offline-nav.js',
    '/assets/js/offline-prefetch.js',
    '/assets/js/offline-page.js',
    '/assets/js/navigation-feedback.js',
    // Pull to refresh in the installed app (issue #842) — offline is
    // exactly when it matters: it is how a stranded app asks again.
    '/assets/js/pull-to-refresh.js',
    '/assets/img/lesscouts.png',
    '/assets/img/branches/logo_baladins.png',
    '/assets/img/branches/logo_louveteaux.png',
    '/assets/img/branches/logo_eclaireurs.png',
    '/assets/img/branches/logo_pionniers.png',
    '/assets/img/branches/logo_route.png',
    '/assets/img/branches/logo_iama.png',
    '/assets/img/branches/logo_staffdu.png',
    '/favicon.ico',
    '/offline',
];

const APP_SHELL_URLS = APP_SHELL_BASE_URLS.concat(VERSIONED_ICON_URLS);

// --- Lot 3: content caching config ---
// Not durable as a plain JS variable — the browser can terminate and
// restart this worker between the postMessage that delivers it and the
// next fetch event that needs it. Storing it as a synthetic Cache
// Storage entry (same API already used for everything else here, no new
// browser feature) survives that restart.
const CONFIG_CACHE_NAME = 'offline-config';
const CONFIG_URL = 'https://offline-config.internal/v1';
const CONTENT_CACHE_PREFIX = 'content-';

// --- #883: the exact list, and the pass that keeps it ---
//
// What is held offline is a LIST OF EXACT ADDRESSES for the person asking
// (GET /api/offline/manifest, Core\Offline\OfflineManifestService), kept
// here beside the configuration and for the same reason: this worker can
// be stopped and restarted between any two events. A page on the list is
// downloaded, kept fresh and served offline; a page off it is NEVER
// written, visited or not. There used to be patterns instead — « /members/
// plus one segment » — checked in three places, and they covered too much.
const LIST_URL = 'https://offline-config.internal/list';
// Where a pass is: when it started and whether it finished. A pass is
// marked done only once it has finished, help included; an interrupted
// one is resumed, and does again only what is missing (refreshOfflineCopies()).
const PASS_URL = 'https://offline-config.internal/pass';
// Stamped on every copy a pass stores: the device time it was stored at.
// A resumed pass skips what already carries a stamp from its own start —
// the cache is the memory of its progress. Device time on both sides, so
// a server clock that drifts cannot make a copy look done or undone.
const STORED_AT_HEADER = 'X-Offline-Stored-At';
const PASS_CONCURRENCY = 2;
const MANIFEST_URL = '/api/offline/manifest';
const IMAGE_VARIANT_PATH = /^\/files\/\d+\/(thumb|md)$/;

function storeOfflineConfig(data) {
    return caches.open(CONFIG_CACHE_NAME).then(function (cache) {
        return cache.put(CONFIG_URL, new Response(JSON.stringify(data), {
            headers: { 'Content-Type': 'application/json' },
        }));
    });
}

function getOfflineConfig() {
    return caches.open(CONFIG_CACHE_NAME).then(function (cache) {
        return cache.match(CONFIG_URL);
    }).then(function (response) {
        return response ? response.json() : null;
    });
}

function storeEntry(url, data) {
    return caches.open(CONFIG_CACHE_NAME).then(function (cache) {
        return cache.put(url, new Response(JSON.stringify(data), {
            headers: { 'Content-Type': 'application/json' },
        }));
    });
}

function readEntry(url) {
    return caches.open(CONFIG_CACHE_NAME).then(function (cache) {
        return cache.match(url);
    }).then(function (response) {
        return response ? response.json() : null;
    });
}

/**
 * The list last received for this account: `{scope, pages, last}`, or
 * null. A list stored for another account says nothing about this one.
 *
 * @param {{account_scope?: unknown}|null} config
 * @returns {Promise<{scope: string, pages: string[], last: string[]}|null>}
 */
function getOfflineList(config) {
    return readEntry(LIST_URL).catch(function () { return null; }).then(function (list) {
        if (!list || !config || String(list.scope) !== String(config.account_scope)) {
            return null;
        }
        return list;
    });
}

/**
 * Whether this URL is one of the list's exact addresses. A query string is
 * never on the list: `/news?page=2` is not `/news`.
 *
 * @param {URL} url
 * @param {{pages: string[], last: string[]}|null} list
 * @returns {boolean}
 */
function isListed(url, list) {
    if (!list || url.search !== '' || url.origin !== self.location.origin) {
        return false;
    }
    return list.pages.includes(url.pathname) || list.last.includes(url.pathname);
}

/**
 * The previous version's copies, carried over (#883).
 *
 * The content cache is named after the version, so each update started
 * from an empty one — offline just after an update, nothing — while the
 * previous version's copies sat there unused. They are copied into the new
 * cache, keeping their date (the « Version hors ligne du … » banner stays
 * true), and the old cache is deleted; the next pass refreshes them.
 *
 * @param {string} scope
 * @param {string} version
 * @returns {Promise<void>}
 */
function adoptPreviousVersion(scope, version) {
    const prefix = CONTENT_CACHE_PREFIX + scope + '-';
    const current = prefix + version;

    return caches.keys().then(function (names) {
        const previous = names.filter(function (name) {
            return name.startsWith(prefix) && name !== current;
        });
        if (previous.length === 0) {
            return undefined;
        }
        const copy = names.includes(current)
            ? Promise.resolve()
            : copyCache(previous[previous.length - 1], current);

        return copy.then(function () {
            return Promise.all(previous.map(function (name) { return caches.delete(name); }));
        });
    }).then(function () {});
}

function copyCache(from, to) {
    return Promise.all([caches.open(from), caches.open(to)]).then(function (both) {
        const source = both[0];
        const target = both[1];
        return source.keys().then(function (requests) {
            return Promise.all(requests.map(function (request) {
                return source.match(request).then(function (response) {
                    return response ? target.put(request, response) : undefined;
                });
            }));
        });
    });
}

function purgeAllContentCaches() {
    return purgeContentCachesExcept(null);
}

/**
 * Drop every content cache except the one belonging to `keepScope`.
 *
 * The content cache is named per account (`content-{scope}-{version}`),
 * which stops one account NAMING another's cache — but naming is not the
 * only way to reach it. A session that expires in silence never submits
 * the logout form, so nothing purged the previous account's caches, and
 * the worker went on answering navigations from the config the PREVIOUS
 * page delivered: the first navigation after a change of scope read, and
 * wrote, under the old one (issue #252).
 *
 * So a scope that is no longer the one being served has its copies
 * dropped, on the two occasions the worker can actually tell: a page
 * announcing a different scope (the message handler below) and a live
 * response saying so (the X-Offline-Scope header, in
 * networkFirstWithCacheFallback()).
 *
 * `null` keeps nothing — the plain purge, on logout or on functional
 * consent being withdrawn.
 *
 * @param {string|null} keepScope
 * @returns {Promise<any>}
 */
function purgeContentCachesExcept(keepScope) {
    const keptPrefix = keepScope === null ? null : CONTENT_CACHE_PREFIX + keepScope + '-';

    return caches.keys().then(function (names) {
        return Promise.all(
            names
                .filter(function (name) {
                    if (!name.startsWith(CONTENT_CACHE_PREFIX)) {
                        return false;
                    }
                    return keptPrefix === null || !name.startsWith(keptPrefix);
                })
                .map(function (name) { return caches.delete(name); })
        );
    });
}

/**
 * The configuration write the last 'offline-config' started, so that a
 * refresh asked right after it reads the configuration it delivered.
 *
 * @type {Promise<unknown>}
 */
let lastConfigWrite = Promise.resolve();

self.addEventListener('message', function (event) {
    // A service worker only ever receives a postMessage() from a client
    // legitimately controlled by it (same-origin, within scope) in
    // practice, but the type/consent payload below drives real
    // cache-purge and config-storage side effects — origin is checked
    // explicitly rather than trusting event.source implicitly.
    if (event.origin !== self.location.origin) {
        return;
    }

    const data = event.data || {};

    if (data.type === 'offline-config') {
        // A config delivering consent === false (withdrawn/refused) must
        // itself purge — this is the one path a plain page reload of the
        // cookie preferences page goes through (see offline-cache.js),
        // not just the explicit 'purge-content-caches' message below.
        //
        // And a config announcing a DIFFERENT account scope purges the
        // previous one's copies: a session that expires in silence never
        // submits the logout form, so this message is the first — and
        // often only — moment the worker is told the identity behind the
        // caches it holds has changed (issue #252).
        lastConfigWrite = storeOfflineConfig(data).then(function () {
            if (!data.consent) {
                return purgeAllContentCaches();
            }
            return purgeContentCachesExcept(String(data.account_scope)).then(function () {
                return adoptPreviousVersion(String(data.account_scope), String(data.version));
            });
        }).catch(function () {});
        event.waitUntil(lastConfigWrite);
    } else if (data.type === 'purge-content-caches') {
        event.waitUntil(purgeAllContentCaches());
    } else if (data.type === 'offline-refresh') {
        // Asked by offline-prefetch.js, which owns WHEN (an installed app,
        // with consent, once per launch). Run here rather than in the page
        // so that moving to another page of the app does not interrupt it.
        // The configuration that page just sent is written first.
        const client = event.source;
        event.waitUntil(lastConfigWrite.then(function () {
            return refreshOfflineCopies();
        }).then(function (done) {
            if (done && client && typeof client.postMessage === 'function') {
                client.postMessage({ type: 'offline-refresh-done' });
            }
        }).catch(function () {}));
    }
});


// --- #883: the pass that keeps the list offline ---

/**
 * The pass running now, if one is: a second request while it runs joins
 * it rather than starting another.
 *
 * @type {Promise<boolean>|null}
 */
let passInFlight = null;

/**
 * Brings the offline copies in line with the list, in the order the
 * maintainer decided (#883):
 *
 *  1. the pages of the list, help excepted — re-asked with their ETag, a
 *     « 304 non modifié » only refreshing the date;
 *  2. their images, read in the HTML just stored — `/files/{id}/thumb|md`
 *     only, never the original — downloaded if missing;
 *  3. the help (`last`);
 *  4. then what is no longer on the list goes: a page whose role changed,
 *     an article that left the first page, an image no page shows any more.
 *
 * Marked done only once all of it has run. Interrupted — the worker
 * stopped, the app closed — the next request resumes the same pass and
 * skips every copy already stamped since it started.
 *
 * Only ever for an installed app, with consent, signed in: the same gate
 * as every other write of this worker.
 *
 * @returns {Promise<boolean>} whether a pass ran to its end
 */
function refreshOfflineCopies() {
    if (passInFlight) {
        return passInFlight;
    }
    passInFlight = getOfflineConfig().catch(function () { return null; }).then(function (config) {
        if (!config?.consent || !config.standalone || !config.account_scope || config.account_scope === 'guest') {
            return false;
        }
        return runPass(config);
    }).catch(function () {
        return false;
    }).finally(function () {
        passInFlight = null;
    });

    return passInFlight;
}

function runPass(config) {
    const scope = String(config.account_scope);
    const version = String(config.version);
    const cacheName = CONTENT_CACHE_PREFIX + scope + '-' + version;

    return Promise.all([
        fetchManifest(scope),
        readEntry(PASS_URL).catch(function () { return null; }),
        caches.open(cacheName),
    ]).then(function (results) {
        const list = results[0];
        const previous = results[1];
        const cache = results[2];
        if (!list) {
            return false;
        }

        const resuming = previous && previous.scope === scope && previous.version === version && !previous.completedAt;
        const startedAt = resuming ? Number(previous.startedAt) : Date.now();
        const record = { scope: scope, version: version, startedAt: startedAt, completedAt: null };

        return storeEntry(LIST_URL, { scope: scope, pages: list.pages, last: list.last })
            .then(function () { return storeEntry(PASS_URL, record); })
            .then(function () { return refreshPages(cache, list.pages, startedAt, scope); })
            .then(function () { return imagesOf(cache, list.pages); })
            .then(function (images) { return fetchMissingImages(cache, images, scope); })
            .then(function () { return refreshPages(cache, list.last, startedAt, scope); })
            .then(function () { return pruneCopies(cache, list.pages.concat(list.last)); })
            .then(function () {
                record.completedAt = Date.now();
                return storeEntry(PASS_URL, record);
            })
            .then(function () { return true; });
    });
}

/**
 * The exact list for the person this worker serves, or null — and null
 * too when the session answering is not the account the configuration
 * belongs to: a list for somebody else must not be kept under this scope.
 *
 * @param {string} scope
 * @returns {Promise<{pages: string[], last: string[]}|null>}
 */
function fetchManifest(scope) {
    return fetch(MANIFEST_URL, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
        .then(function (response) {
            const served = response.headers.get('X-Offline-Scope');
            if (!response.ok || (served !== null && served !== scope)) {
                return null;
            }
            return response.json();
        })
        .then(function (data) {
            if (!data || !Array.isArray(data.pages)) {
                return null;
            }
            const plain = function (path) {
                return typeof path === 'string' && path.startsWith('/') && !path.startsWith('//');
            };
            return {
                pages: data.pages.filter(plain),
                last: Array.isArray(data.last) ? data.last.filter(plain) : [],
            };
        })
        .catch(function () { return null; });
}

/**
 * $response with the stamp of this moment, and a Date when it has none.
 *
 * @param {Response} response
 * @param {boolean} refreshDate a 304 confirmed the copy now: its date moves
 * @returns {Promise<Response>}
 */
function stamped(response, refreshDate) {
    return response.blob().then(function (body) {
        const headers = new Headers(response.headers);
        headers.set(STORED_AT_HEADER, String(Date.now()));
        if (refreshDate || !headers.get('Date')) {
            headers.set('Date', new Date().toUTCString());
        }
        return new Response(body, { status: response.status, statusText: response.statusText, headers: headers });
    });
}

function storedSince(cached, startedAt) {
    return !!cached && Number(cached.headers.get(STORED_AT_HEADER)) >= startedAt;
}

/** Runs $work over $items, PASS_CONCURRENCY at a time, never failing. */
function inLanes(items, work) {
    const queue = items.slice();
    const lanes = [];
    for (let i = 0; i < Math.min(PASS_CONCURRENCY, queue.length); i++) {
        lanes.push((function next() {
            if (queue.length === 0) {
                return Promise.resolve();
            }
            return Promise.resolve(work(queue.shift())).catch(function () {}).then(next);
        })());
    }
    return Promise.all(lanes);
}

/**
 * Each page, unless this pass already stored it: re-asked with the ETag
 * of the copy (Core\Http\FrontController), « 304 » refreshing its date,
 * anything else replacing it — and never a redirect, which is the login
 * page answering for a session that ended, nor a page served to another
 * account.
 */
function refreshPages(cache, pages, startedAt, scope) {
    return inLanes(pages, function (path) {
        return cache.match(path).then(function (cached) {
            if (storedSince(cached, startedAt)) {
                return undefined;
            }
            const headers = {};
            const etag = cached ? cached.headers.get('ETag') : null;
            if (etag) {
                headers['If-None-Match'] = etag;
            }
            return fetch(path, { credentials: 'same-origin', headers: headers }).then(function (response) {
                const served = response.headers.get('X-Offline-Scope');
                if (served !== null && served !== scope) {
                    return undefined;
                }
                if (response.status === 304 && cached) {
                    return stamped(cached, true).then(function (copy) { return cache.put(path, copy); });
                }
                if (response.ok && !response.redirected) {
                    return stamped(response, false).then(function (copy) { return cache.put(path, copy); });
                }
                return undefined;
            });
        });
    });
}

/**
 * Every image variant the stored pages show: `/files/{id}/thumb|md` in a
 * `src`, a `srcset` or a `url(…)`. What a page shows is what it keeps — no
 * list of images kept by hand, page by page.
 *
 * @returns {Promise<string[]>}
 */
function imagesOf(cache, pages) {
    const found = new Set();
    return inLanes(pages, function (path) {
        return cache.match(path).then(function (cached) {
            return cached ? cached.clone().text() : '';
        }).then(function (html) {
            imageVariantsIn(html).forEach(function (url) { found.add(url); });
        });
    }).then(function () { return Array.from(found); });
}

/**
 * @param {string} html
 * @returns {string[]}
 */
function imageVariantsIn(html) {
    const urls = [];
    const pattern = /\/files\/\d+\/(?:thumb|md)(?![\w/.-])/g;
    let match;
    while ((match = pattern.exec(html)) !== null) {
        urls.push(match[0]);
    }
    return urls;
}

/** A derivative URL never changes for a given file: one already held is kept. */
function fetchMissingImages(cache, images, scope) {
    return inLanes(images, function (url) {
        return cache.match(url).then(function (cached) {
            if (cached) {
                return undefined;
            }
            return fetch(url, { credentials: 'same-origin' }).then(function (response) {
                const served = response.headers.get('X-Offline-Scope');
                if (response.ok && !response.redirected && (served === null || served === scope)) {
                    return cache.put(url, response);
                }
                return undefined;
            });
        });
    });
}

/**
 * What the list no longer holds: a page off it, and an image no remaining
 * page shows.
 */
function pruneCopies(cache, kept) {
    return imagesOf(cache, kept).then(function (images) {
        const keep = new Set(kept.concat(images));
        return cache.keys().then(function (requests) {
            return Promise.all(requests.map(function (request) {
                const url = new URL(request.url);
                const key = url.search === '' ? url.pathname : null;
                return key !== null && keep.has(key) ? undefined : cache.delete(request);
            }));
        });
    });
}

// --- App-shell freshness: the two rules the `?v=` cannot enforce on its own ---
//
// Every APP_SHELL_BASE_URLS entry is matched with `ignoreSearch` (see the
// fetch handler), so the `?v=` that Twig's asset() appends to every
// `<link>` and `<script>` — Core\Maintenance\AssetVersion — busts the
// browser's own HTTP cache but can never bust THIS one: a cached copy
// answers whatever version the page asks for. CACHE_NAME is the only
// invalidation there is, and it only works if the copies precached under
// a new name are genuinely new, and if the worker holding the old ones
// stops answering for the new build. Neither was true, and production
// showed it: an install left a NEW page in front of the PREVIOUS
// /assets/js/api.js, Configuration > Maintenance threw
// « window.ScoutMagicApi.pollSlot is not a function » on load, and since
// an uncaught error ends the whole script, every block of maintenance.js
// after it — the update channel, the branch selector, the install form —
// simply never ran.
//
// Rule 1, here: precache over the network, never out of the HTTP cache.
// cache.addAll() fetches the BARE urls (no `?v=` — that is what the fetch
// handler matches on), and a bare /assets/js/api.js is a URL no page ever
// requests: the only thing that ever writes it into the HTTP cache is a
// previous precache. /assets/** is served to be cached — a far-future
// lifetime where the host configures one, heuristic freshness off
// Last-Modified where it does not — so that entry gets reused and the new
// cache generation is filled with the OLD bytes, which `ignoreSearch`
// then serves for as long as the HTTP entry lives. `cache: 'reload'` is
// what makes an install fetch the file rather than a memory of it.
function precacheRequests() {
    return APP_SHELL_URLS.map(function (url) {
        return new Request(url, { cache: 'reload' });
    });
}

// Rule 2: a shell asset asked for under a version this worker does not
// know is not this worker's to answer out of cache. That is the first
// page load after an install — the page is new, the controlling worker is
// still the previous one (a new worker installs and claims, but never
// before the requests the loading page has already issued), and
// cache-first hands the previous build's script to the new build's
// markup. Network-first for exactly that mismatch closes the window; the
// steady state is untouched, since `?v=` equal to VERSION — every request
// a settled install makes — stays cache-first.
/**
 * @param {URL} url
 * @returns {boolean}
 */
function isSupersededShellRequest(url) {
    const requested = url.searchParams.get('v');

    return requested !== null && requested !== VERSION;
}

/**
 * @param {Request} request
 * @returns {Promise<Response|undefined>}
 */
function cachedShell(request) {
    return caches.match(request, { ignoreSearch: true });
}

/**
 * The app-shell branch of the fetch handler: cache-first for the version
 * this worker precached, network-first with the cache as the offline
 * fallback for any other. Offline never regresses — a failed network
 * still ends on whatever copy is in hand.
 *
 * @param {Request} request
 * @param {URL} url
 * @returns {Promise<Response>}
 */
function appShellResponse(request, url) {
    if (!isSupersededShellRequest(url)) {
        return cachedShell(request).then(function (cached) {
            return cached || fetch(request);
        });
    }

    return fetch(request).then(
        function (response) {
            if (response?.ok) {
                return response;
            }

            // A 404 or a 5xx from a half-written deploy is worth less
            // than the copy already in hand.
            return cachedShell(request).then(function (cached) {
                return cached || response;
            });
        },
        function (error) {
            return cachedShell(request).then(function (cached) {
                if (cached) {
                    return cached;
                }

                throw error;
            });
        }
    );
}

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.addAll(precacheRequests());
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    // Only ever purges the app shell's OWN cache generations — a release
    // (VERSION bump) or a standalone unit-logo upload (ICON_VERSION bump)
    // must retire the shell cache it superseded, but NEVER offline-config
    // or any content-* cache (Lot 3): those have their own lifecycle
    // (per-account naming, purged on logout/consent-withdrawal only, see
    // purgeAllContentCaches()) and activate() wiping every cache whose name
    // differed from CACHE_NAME used to silently empty them on every
    // release or logo upload — a real bug, fixed here.
    event.waitUntil(Promise.all([
        caches.keys().then(function (names) {
            return Promise.all(
                names
                    .filter(function (name) { return name.startsWith(APP_SHELL_CACHE_PREFIX) && name !== CACHE_NAME; })
                    .map(function (name) { return caches.delete(name); })
            );
        }),
        // Navigation preload: the browser starts the network request for a
        // navigation the moment it happens, in parallel with waking this
        // worker up, instead of after it. Every navigation used to wait for
        // the worker to boot AND read its configuration from Cache Storage
        // before fetch() was even issued — an installed app is frozen by
        // the OS far more often than a tab, so it paid that on most taps.
        // handleNavigate() picks the preloaded response up through
        // event.preloadResponse. Optional: a browser without it is simply
        // where it was before.
        self.registration.navigationPreload
            ? self.registration.navigationPreload.enable().catch(function () {})
            : Promise.resolve()
    ]));
    self.clients.claim();
});

self.addEventListener('fetch', function (event) {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Icons: exact match INCLUDING their `?v=` cache-buster — never
    // ignoreSearch. A request for an old `?v=` (a stale HTML page still
    // referencing it) must genuinely miss this cache rather than resolve
    // to whichever version happens to be precached; a fresh `?v=` after a
    // logo upload must equally miss until CACHE_NAME's own icon-version
    // bump has re-precached it. This is the actual cache-busting fix —
    // ignoreSearch here would silently undo it (see ARCHITECTURE §8.23).
    if (ICON_URLS.includes(url.pathname)) {
        event.respondWith(
            caches.match(request).then(function (cached) {
                return cached || fetch(request);
            })
        );
        return;
    }

    // Rest of the app shell — ignoreSearch so a request that carries a
    // query string still matches the precached bare-path entry, and
    // cache-first for everything except a `?v=` this worker does not
    // recognise (appShellResponse, and the two rules above it).
    if (APP_SHELL_BASE_URLS.includes(url.pathname)) {
        event.respondWith(appShellResponse(request, url));
        return;
    }

    // Pre-generated image-variant derivatives (Core\Photo\ImageVariantService,
    // GET /files/{id}/thumb|md): network-first, falling back to Cache
    // Storage on a network failure ONLY. A 403/404 from a live response (a
    // real access-guard decision) is returned as-is, never masked by a
    // stale cached copy. Every OTHER /files/{id} request — the original
    // itself, anything not matching this exact pattern — falls through
    // unmatched below and stays strictly network-only: this is the one
    // pattern allowed to touch Cache Storage for a /files/{id} URL.
    //
    // Since #883 it may also WRITE what it fetched, and only when the page
    // asking for the image is on the list: an image shown by a listed page
    // is one the next pass would have downloaded anyway (variantResponse()).
    if (IMAGE_VARIANT_PATH.test(url.pathname)) {
        event.respondWith(variantResponse(request, event));
        return;
    }

    // Every other GET (including every plain /files/{id} download) stays
    // strictly network-only — no exception, this is the one line that
    // keeps SECURITY.md's file-access rule true for the service worker.
    // Navigations get the one exception below.
    if (request.mode === 'navigate') {
        // The event itself travels down: when the timeout below serves a
        // cached copy, the live request that is still running has to be
        // kept alive with waitUntil() or the worker may be terminated the
        // moment respondWith() settles — see networkFirstWithCacheFallback().
        event.respondWith(handleNavigate(request, url, event.preloadResponse, event));
    }
});

/**
 * How long a navigation waits for the network before a cached copy of the
 * SAME page is served instead (networkFirstWithCacheFallback() below).
 *
 * Only ever reached when there IS such a copy, and it never cancels the
 * network request: that one keeps running and refreshes the cache for
 * next time. So the choice is not "fresh or stale", it is "this page,
 * labelled « Version hors ligne du … », now" against "a blank screen
 * until a bad link finally answers". Five seconds is long enough that a
 * merely slow-but-working connection still wins the race and serves the
 * live page.
 */
const NETWORK_TIMEOUT_MS = 5000;

/**
 * How far a cached copy may appear to come from the future before it stops
 * counting as fresh. Ordinary device/server clock drift, not a real age.
 */
const CLOCK_SKEW_TOLERANCE_MS = 5 * 60 * 1000;

/**
 * The network request for a navigation, started NOW — before the offline
 * configuration has been read — so that reading it costs the navigation
 * nothing. `preloadResponse` (event.preloadResponse, see activate) is
 * the same request already made by the browser while this worker was
 * waking up; it resolves to undefined where preload is unsupported or
 * was not used, in which case a plain fetch() takes over. The rejection
 * is observed once here so an early failure never surfaces as an
 * unhandled rejection; every consumer attaches its own handling.
 *
 * A REJECTED preload is retried as a plain fetch() rather than taken as
 * proof of being offline. The preload is a request the browser made
 * before this worker was even awake, and it fails for reasons that have
 * nothing to do with connectivity — the browser cancelling it, an
 * HTTP/2 stream reset, an installed app resuming from frozen with the
 * radio not yet up. Every one of those used to surface as "Pas de
 * connexion" on a device that was online, which is the worst possible
 * lie for this page to tell.
 *
 * @param {Request} request
 * @param {Promise<Response|undefined>|undefined} preloadResponse
 * @returns {Promise<Response>}
 */
function startNetworkRequest(request, preloadResponse) {
    // What the catch below needs to know is not whether a preload was
    // OFFERED but whether the fallback fetch has already been made. A
    // preload promise that resolves undefined (no preload for this
    // navigation) sends us into fetch() in the `then`; if THAT fetch
    // rejects, `preloadResponse !== undefined` was still true and the
    // catch fired a second, identical request — one extra round trip on
    // exactly the connection that just failed, before retryOnce() gets
    // its turn.
    let fetched = false;
    const network = Promise.resolve(preloadResponse).then(function (preloaded) {
        if (preloaded) {
            return preloaded;
        }

        fetched = true;
        return fetch(request);
    }).catch(function (error) {
        if (fetched || preloadResponse === undefined) {
            throw error;
        }

        return fetch(request);
    });
    network.catch(function () {});
    return network;
}

/**
 * A last attempt before this navigation is declared offline.
 *
 * One retry, and only when the browser itself still believes there is a
 * connection (navigator.onLine is a reliable NO and an unreliable yes —
 * which is exactly the way round this needs it: a device in flight mode
 * skips the retry and gets the offline page instantly, while a device
 * that thinks it is online gets one more chance before being told
 * otherwise).
 *
 * The case this exists for is the installed app resuming from frozen: the
 * first fetch after the OS thaws it can fail outright, milliseconds
 * before the same request would have succeeded. Showing "Pas de
 * connexion" for that is a bug, not a network condition.
 *
 * @param {Request} request
 * @returns {Promise<Response>}
 */
function retryOnce(request) {
    // `self.navigator?.onLine === false` rather than the `X && X.y` form,
    // and the two are genuinely equivalent HERE — which is not something
    // to assume in this file. `X && X.y` may only become `X?.y` where the
    // result is tested for truth; in front of a comparison `undefined`
    // satisfies (`!== '1'`, say) they differ exactly where it matters,
    // and that mistake has already shipped once in this codebase. The
    // comparison here is `=== false`, which `undefined` does not satisfy,
    // so a missing navigator takes the same branch either way: not known
    // to be offline, so try.
    if (self.navigator?.onLine === false) {
        return Promise.reject(new Error('offline'));
    }

    return fetch(request);
}

/**
 * The minimal offline page, built here rather than read from anywhere.
 * What offlinePage() below falls back to when the shell cache has been
 * purged out from under it, or cannot be read at all.
 *
 * @returns {Response}
 */
function generatedOfflinePage(stalenessDays) {
    return new Response(
        '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
            + '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            + '<title>Hors ligne</title></head><body><h1>Pas de connexion</h1>'
            + '<p>Cette page n\'est pas disponible hors ligne. Vérifiez votre connexion puis réessayez.</p>'
            + '<p>' + offlineConditions(stalenessDays) + '</p>'
            + '</body></html>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=UTF-8' } }
    );
}

/**
 * Why a page is or is not available offline (#883) — the sentence of
 * pwa/offline.html.twig, for the page built here when that one is gone.
 * The number of days is the setting the page sent with its configuration;
 * without one, « récemment » rather than a figure that may be wrong.
 *
 * @param {unknown} stalenessDays
 * @returns {string}
 */
function offlineConditions(stalenessDays) {
    const days = Number(stalenessDays);
    let when = 'récemment';
    if (Number.isInteger(days) && days > 0) {
        when = 'il y a moins de ' + days + (days === 1 ? ' jour' : ' jours');
    }

    return 'Une page n\'est disponible hors ligne que si elle fait partie des pages que l\'application garde, '
        + 'si les cookies fonctionnels sont activés (depuis le pied de page, en ligne) et si '
        + 'l\'application a été ouverte avec une connexion ' + when + '.';
}

/**
 * The precached offline page — the fallback of last resort, and the one
 * thing an installed, standalone app must be able to show instead of the
 * browser's own network-error interstitial, since there is no browser
 * chrome to explain that one.
 *
 * Never resolves to undefined: respondWith(undefined) is a TypeError and
 * the app would fall back to exactly the interstitial this avoids, so a
 * shell cache that has been purged — or denied — still gets a real
 * (minimal, French) Response out of generatedOfflinePage() above.
 *
 * @returns {Promise<Response>}
 */
function offlinePage() {
    // caches.match() does not only resolve to undefined when nothing is
    // stored: it REJECTS outright when Cache Storage itself is denied
    // (private browsing, site data blocked, a quota error). A rejection
    // here would propagate all the way to respondWith() and show the
    // browser's network-error interstitial — precisely what this function
    // exists to replace — so a broken cache is read as an empty one.
    return caches.match('/offline')
        .catch(function () { return null; })
        .then(function (cached) { return cached || configuredOfflinePage(); });
}

/**
 * generatedOfflinePage() with the number of days the page sent in its
 * configuration (#883). That configuration lives in Cache Storage too, so
 * a cache that could not give back the page may not give this back
 * either: then the sentence says « récemment ».
 *
 * @returns {Promise<Response>}
 */
function configuredOfflinePage() {
    return Promise.resolve()
        .then(getOfflineConfig)
        .catch(function () { return null; })
        .then(function (config) { return generatedOfflinePage(config?.staleness_days); });
}

function handleNavigate(request, url, preloadResponse, event) {
    const network = startNetworkRequest(request, preloadResponse);

    return getOfflineConfig().catch(function () {
        // Cache Storage can be denied or evicted outright, and both
        // caches.open() and cache.match() reject when it is. Letting that
        // rejection through would reject respondWith() and land the
        // installed app on the browser's own network-error interstitial —
        // the one thing offlinePage() exists to replace. No config simply
        // means no cached copy to offer, which the branch below already
        // handles.
        return null;
    }).then(function (config) {
        if (!config?.consent) {
            // Lot 1 behavior: without consent nothing is held, so a failed
            // navigation falls back to the precached offline page, after
            // trying the request itself once more.
            return network
                .catch(function () { return retryOnce(request); })
                .catch(function () { return offlinePage(); });
        }

        // Offline, a page is available if and only if it is really in the
        // cache (#883) — whatever put it there. Writing is another matter:
        // only an address on the list ever is (networkFirstWithCacheFallback()).
        return getOfflineList(config).then(function (list) {
            return networkFirstWithCacheFallback(request, url, config, network, event, isListed(url, list));
        });
    });
}

/**
 * An image variant: the network, else the copy a cache holds — and, from
 * the installed app with consent, the network's answer stored when the
 * page that asked for it is on the list (#883). A page off the list
 * stores nothing, its images neither.
 *
 * @param {Request} request
 * @param {FetchEvent|undefined} event
 * @returns {Promise<Response>}
 */
function variantResponse(request, event) {
    return fetch(request).then(function (response) {
        keepAlive(event, storeVariantIfListed(request, response.clone()).catch(function () {}));
        return response;
    }, function () {
        return caches.match(request);
    });
}

function storeVariantIfListed(request, response) {
    if (!response.ok || response.redirected || !request.referrer) {
        return Promise.resolve();
    }
    let referrer;
    try {
        referrer = new URL(request.referrer);
    } catch {
        return Promise.resolve();
    }

    return getOfflineConfig().then(function (config) {
        if (!config?.consent || !config.standalone) {
            return undefined;
        }
        const served = response.headers.get('X-Offline-Scope');
        if (served !== null && served !== String(config.account_scope)) {
            return undefined;
        }
        return getOfflineList(config).then(function (list) {
            if (!isListed(referrer, list)) {
                return undefined;
            }
            const cacheName = CONTENT_CACHE_PREFIX + config.account_scope + '-' + config.version;
            return caches.open(cacheName).then(function (cache) {
                return cache.put(request, response);
            });
        });
    });
}

/**
 * Keep this fetch event alive until the given promise settles.
 *
 * Optional and defensive on both counts. The event is absent in the unit
 * tests, and `waitUntil()` throws InvalidStateError once an event is no
 * longer extendable — neither is a reason to fail the navigation that
 * has already been answered.
 *
 * @param {FetchEvent|undefined} event
 * @param {Promise<unknown>} promise
 */
function keepAlive(event, promise) {
    if (!event || typeof event.waitUntil !== 'function') {
        return;
    }

    try {
        event.waitUntil(promise);
    } catch (e) {
        // Already settled — nothing left to extend.
    }
}

/**
 * The cached copy of this page, banner and all — or null when there is
 * none, or the one there is has aged past the configured threshold (a
 * copy older than that is worse than the offline page: it looks current
 * and is not).
 *
 * @param {Request} request
 * @param {string} cacheName
 * @param {{staleness_days?: number}} config
 * @param {string} reason 'offline' | 'slow' — what the banner says
 * @returns {Promise<Response|null>}
 */
function cachedCopy(request, cacheName, config, reason) {
    // Same reason as offlinePage(): both caches.open() and cache.match()
    // reject when Cache Storage is unavailable, and every caller of this
    // function feeds respondWith(). A cache that cannot be read holds no
    // copy — which is what "no cached copy" already means here.
    return caches.open(cacheName).then(function (cache) {
        return cache.match(request);
    }).catch(function () {
        return null;
    }).then(function (cached) {
        if (!cached) {
            return null;
        }

        const dateHeader = cached.headers.get('date');
        const ageMs = dateHeader ? Date.now() - new Date(dateHeader).getTime() : Infinity;
        const stalenessDays = config.staleness_days || 7;
        // Freshness is stated positively, and that is what makes it
        // right: an unparseable Date header gives NaN and a future-dated
        // one a negative age, and NEITHER is greater than the threshold —
        // so a bare `ageMs > threshold` staleness test called both of them
        // fresh and served a copy of unknown age as if it were current.
        // Every comparison below is false for NaN, so only a real,
        // non-negative age inside the window survives.
        // `ageMs` subtracts a SERVER clock (the Date header) from a DEVICE
        // clock, so a phone running a few minutes slow makes a copy cached
        // seconds ago look negative-aged. Rejecting every negative age
        // therefore threw away perfectly good copies and showed the offline
        // page instead — on exactly the devices where clock drift is
        // ordinary. The guard is bounded rather than dropped: a copy dated
        // days ahead is still of unknown age and still refused.
        const fresh = ageMs >= -CLOCK_SKEW_TOLERANCE_MS
            && ageMs <= stalenessDays * 24 * 60 * 60 * 1000;
        if (!fresh) {
            return null;
        }

        // The banner injection reads the body, and `cached.text()` can
        // reject for the same reasons the open/match guard above exists
        // for — a storage error part-way through, a revoked quota. That
        // read is part of reading the cache, so it falls under the same
        // invariant: a copy that cannot be read is not a copy.
        return injectOfflineBanner(cached, dateHeader, reason)
            .catch(function () { return null; });
    });
}

function networkFirstWithCacheFallback(request, url, config, network, event, listed) {
    const cacheName = CONTENT_CACHE_PREFIX + config.account_scope + '-' + config.version;

    // The live attempt, with its cache write attached. It is NEVER
    // cancelled by the timeout below: when a cached copy is served in its
    // place, this one keeps running and refreshes that copy for next
    // time, so a slow connection still makes progress instead of only
    // making the reader wait.
    const live = (network || fetch(request)).catch(function () {
        return retryOnce(request);
    }).then(function (response) {
        // response.redirected (e.g. an identified-only page bounced to
        // /login because the session had actually expired) must never be
        // cached under the original whitelisted URL — that would silently
        // serve the LOGIN page back as if it were the calendar the next
        // time this is read from cache while offline. config.standalone
        // (delivered by the SAME page that made this navigation, via
        // offline-cache.js) is the write gate: personal-data pages (Mon
        // compte, a member's own page) are cacheable now, so a plain
        // browser tab visiting one must never write it — only the
        // installed app does. READS below stay unconditional regardless:
        // a single tab visit must not blind the installed app's own
        // already-cached copy until its next page load.
        // The scope the response was actually SERVED under, straight
        // from the session that served it (public/index.php sets the
        // header on every response). `config.account_scope` came from
        // the PREVIOUS page's postMessage, so on the first navigation
        // after a silent expiry the two disagree — and writing then
        // would put this account's page into the other's cache, which a
        // later offline read would hand back to the wrong reader (issue
        // #252). A response with no header at all (an older build still
        // in flight, a cross-origin opaque response) says nothing and is
        // treated as agreeing, exactly as before this existed.
        const servedScope = response?.headers?.get
            ? response.headers.get('X-Offline-Scope')
            : null;
        const scopeMatches = servedScope === null || servedScope === String(config.account_scope);

        if (!scopeMatches) {
            // Not merely « do not write »: the copies stored under the
            // stale scope are the ones a later offline navigation would
            // serve, so they go now, while the worker knows they are
            // stale. The scope now being served keeps its own.
            keepAlive(event, purgeContentCachesExcept(servedScope).catch(function () {}));
        }

        // `listed` (#883): only an exact address of the list is ever written.
        // A page off it — another member's fiche, an older article — is
        // served from the network and never kept, visited or not.
        if (listed && response?.ok && !response.redirected && config.standalone && scopeMatches) {
            const copy = response.clone();
            // Kept alive for the same reason the slow path's refresh is:
            // on the fast path respondWith() settles the instant this
            // response is returned, and a worker whose event is done may
            // be terminated before caches.open() and cache.put() finish —
            // so the page is never cached and the next offline visit
            // finds nothing.
            keepAlive(event, caches.open(cacheName).then(function (cache) {
                return cache.put(request, copy);
            }).catch(function () {}));
        }
        return response;
    });
    live.catch(function () {});

    // Three outcomes, and the third is the one this race exists for.
    // The timer is cleared as soon as the race settles: a pending one
    // would keep this worker awake for five seconds after every single
    // navigation it already answered.
    let timer = null;

    return Promise.race([
        live.then(
            function (response) { return { state: 'live', response: response }; },
            function () { return { state: 'failed' }; }
        ),
        new Promise(function (resolve) {
            timer = setTimeout(function () { resolve({ state: 'slow' }); }, NETWORK_TIMEOUT_MS);
        }),
    ]).then(function (outcome) {
        if (timer !== null) {
            clearTimeout(timer);
        }

        if (outcome.state === 'live') {
            return outcome.response;
        }

        if (outcome.state === 'failed') {
            return cachedCopy(request, cacheName, config, 'offline').then(function (cached) {
                return cached || offlinePage();
            });
        }

        // Still waiting after NETWORK_TIMEOUT_MS. A cached copy of this
        // very page is worth more than a blank screen that may still be
        // blank in another ten seconds — it says what it is, and the live
        // request above is still running to replace it. With nothing
        // cached there is nothing better to do than keep waiting.
        return cachedCopy(request, cacheName, config, 'slow').then(function (cached) {
            if (cached) {
                // Answering from the cache settles respondWith(), and a
                // worker whose event is done may be terminated at once —
                // which would abort the live request mid-flight and lose
                // the cache.put() it was going to make. The promise the
                // reader is no longer waiting for is exactly the one that
                // has to outlive their navigation, or every later visit
                // finds the same stale copy and refreshes nothing.
                keepAlive(event, live);

                return cached;
            }

            return live.catch(function () { return offlinePage(); });
        });
    });
}

// Not a badge — module spec is explicit this must be a readable sentence,
// visible at the top of the page, not silently implied by anything else
// on screen. Injected here (rather than server-rendered) because the same
// HTML is served whether the request succeeds live or is replayed from
// cache — only the service worker, at serve time, knows which one this
// is and how old the cached copy actually is.
function injectOfflineBanner(response, dateHeader, reason) {
    return response.text().then(function (html) {
        const banner = '<div class="alert alert-warning text-center small rounded-0 mb-0 border-start-0 border-end-0" role="status">'
            + formatOfflineTimestamp(dateHeader, reason) + '</div>';
        const injected = html.replace(/<body([^>]*)>/i, '<body$1>' + banner);

        return new Response(injected, {
            status: response.status,
            statusText: response.statusText,
            headers: response.headers,
        });
    });
}

function formatOfflineTimestamp(dateHeader, reason) {
    // Two different things happened and the reader deserves to be told
    // which: the connection is gone, or it is merely too slow to wait for
    // (the live request is still running behind this copy — see
    // networkFirstWithCacheFallback()). "Version hors ligne" on a device
    // that is plainly online reads as a bug in the app.
    const lead = reason === 'slow' ? 'Réseau lent — version enregistrée' : 'Version hors ligne';

    if (!dateHeader) {
        return lead;
    }

    const months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    const d = new Date(dateHeader);
    const minutes = String(d.getMinutes()).padStart(2, '0');

    return lead + ' du ' + d.getDate() + ' ' + months[d.getMonth()] + ', ' + d.getHours() + ' h ' + minutes;
}

self.addEventListener('push', function (event) {
    let data = {};
    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data = { title: 'Notification', body: event.data.text() };
        }
    }

    const title = data.title || 'Notification';
    const options = {
        body: data.body || '',
        data: { url: data.url || null }
    };

    event.waitUntil(
        self.registration.showNotification(title, options).then(function () {
            const tasks = [];

            // Progressive enhancement only — works on installed iOS >= 16.4
            // and desktop Chrome, does nothing on Android (the launcher
            // shows its own unread dot instead). Must run here, not from
            // the page: this is the one place that still executes while
            // the app itself is closed.
            if ('setAppBadge' in navigator && typeof data.unread_count === 'number') {
                tasks.push(navigator.setAppBadge(data.unread_count).catch(function () {}));
            }

            // Nudge any open tab to refresh its own avatar badge
            // immediately, rather than waiting for its next 60s poll —
            // see public/assets/js/notification-badge.js.
            tasks.push(
                self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
                    clientList.forEach(function (client) {
                        client.postMessage({ type: 'push-received', unreadCount: data.unread_count });
                    });
                })
            );

            return Promise.all(tasks);
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    const url = event.notification.data?.url;
    if (!url) {
        return;
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (const client of clientList) {
                if (client.url === url && 'focus' in client) {
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});

// This file is registered as a CLASSIC service-worker script (see
// base.html.twig — navigator.serviceWorker.register('/sw.js?...'), no
// `type: 'module'`), so every top-level `function` declaration above is
// already a property of the worker's global scope. These lines change
// nothing at runtime. They exist so tests/js/sw.test.js can `import` this exact file (an ES
// module under Vitest, where top-level declarations are module-scoped
// rather than global) and call the real implementations directly instead
// of reimplementing their logic in a test-only copy.
globalThis.ScoutMagicServiceWorkerInternals = {
    precacheRequests: precacheRequests,
    isSupersededShellRequest: isSupersededShellRequest,
    appShellResponse: appShellResponse,
    VERSION: VERSION,
    APP_SHELL_URLS: APP_SHELL_URLS,
    isListed: isListed,
    getOfflineList: getOfflineList,
    adoptPreviousVersion: adoptPreviousVersion,
    refreshOfflineCopies: refreshOfflineCopies,
    imageVariantsIn: imageVariantsIn,
    variantResponse: variantResponse,
    LIST_URL: LIST_URL,
    PASS_URL: PASS_URL,
    STORED_AT_HEADER: STORED_AT_HEADER,
    formatOfflineTimestamp: formatOfflineTimestamp,
    cachedCopy: cachedCopy,
    keepAlive: keepAlive,
    offlinePage: offlinePage,
    offlineConditions: offlineConditions,
    retryOnce: retryOnce,
    NETWORK_TIMEOUT_MS: NETWORK_TIMEOUT_MS,
    injectOfflineBanner: injectOfflineBanner,
    networkFirstWithCacheFallback: networkFirstWithCacheFallback,
    handleNavigate: handleNavigate,
    startNetworkRequest: startNetworkRequest,
    storeOfflineConfig: storeOfflineConfig,
    getOfflineConfig: getOfflineConfig,
    purgeAllContentCaches: purgeAllContentCaches,
    purgeContentCachesExcept: purgeContentCachesExcept,
    CONTENT_CACHE_PREFIX: CONTENT_CACHE_PREFIX,
};
