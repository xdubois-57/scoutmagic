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
// Scope of content caching (Lot 3): network-first, cache-fallback, for
// exactly the server-declared whitelist (Core\Offline\OfflineWhitelist —
// public pages, the calendar, the notification centre, the
// trombinoscope). The plain, un-suffixed `/files/{id}` is NEVER in that
// whitelist and is never reachable through this path — nothing here
// changes the "network-only" rule above for it. Cache name is
// `content-{accountScope}-{version}`: scoped to the signed-in account (or
// 'guest') so a different member on the same device never inherits the
// previous one's cache, and to the app version like the shell cache
// above. Config (staleness threshold, consent, the whitelist itself,
// account scope) arrives via postMessage from base.html.twig on every
// page load — never hardcoded here — and is persisted in its own small
// Cache Storage entry so it survives this worker being terminated and
// restarted between messages and fetches.
//
// Image-variant derivatives (GET /files/{id}/thumb|md — Core\Photo\
// ImageVariantService) get their own narrow, READ-ONLY branch further
// down: network-first, falling back to whatever's already in Cache
// Storage (written only by the pre-download script, public/assets/js/
// offline-prefetch.js) on a network failure. This branch never writes —
// see it for why.

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
    '/assets/js/select-bar.js',
    '/assets/js/nav-rail.js',
    '/assets/js/audit-timeline.js',
    '/assets/js/help-panel.js',
    '/assets/js/notification-badge.js',
    '/assets/js/offline-cache.js',
    '/assets/js/offline-nav.js',
    '/assets/js/offline-prefetch.js',
    '/assets/js/offline-page.js',
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

function purgeAllContentCaches() {
    return caches.keys().then(function (names) {
        return Promise.all(
            names
                .filter(function (name) { return name.indexOf(CONTENT_CACHE_PREFIX) === 0; })
                .map(function (name) { return caches.delete(name); })
        );
    });
}

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
        event.waitUntil(
            storeOfflineConfig(data).then(function () {
                return data.consent ? undefined : purgeAllContentCaches();
            })
        );
    } else if (data.type === 'purge-content-caches') {
        event.waitUntil(purgeAllContentCaches());
    }
});

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.addAll(APP_SHELL_URLS);
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
    event.waitUntil(
        caches.keys().then(function (names) {
            return Promise.all(
                names
                    .filter(function (name) { return name.indexOf(APP_SHELL_CACHE_PREFIX) === 0 && name !== CACHE_NAME; })
                    .map(function (name) { return caches.delete(name); })
            );
        })
    );
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
    if (ICON_URLS.indexOf(url.pathname) !== -1) {
        event.respondWith(
            caches.match(request).then(function (cached) {
                return cached || fetch(request);
            })
        );
        return;
    }

    // Rest of the app shell — cache-first, ignoreSearch so a request that
    // happens to carry a query string (none of these legitimately do)
    // still matches the precached bare-path entry.
    if (APP_SHELL_BASE_URLS.indexOf(url.pathname) !== -1) {
        event.respondWith(
            caches.match(request, { ignoreSearch: true }).then(function (cached) {
                return cached || fetch(request);
            })
        );
        return;
    }

    // Pre-generated image-variant derivatives (Core\Photo\ImageVariantService,
    // GET /files/{id}/thumb|md) — read-only here: network-first, falling
    // back to Cache Storage on a network failure ONLY. This branch never
    // calls cache.put() — writing is entirely the pre-download script's job
    // (public/assets/js/offline-prefetch.js), which owns the consent/
    // standalone-mode policy for what gets written and where. A 403/404
    // from a live network response (a real access-guard decision) is
    // returned as-is, never masked by a stale cached copy — only an actual
    // network failure (offline) falls through to the cache. Every OTHER
    // /files/{id} request — the original itself, /thumbnail, anything not
    // matching this exact pattern — falls through unmatched below and
    // stays strictly network-only, same as before: this is the one
    // regex that's allowed to touch Cache Storage for a /files/{id} URL,
    // and even it never writes.
    if (/^\/files\/\d+\/(thumb|md)$/.test(url.pathname)) {
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match(request);
            })
        );
        return;
    }

    // Every other GET (including every plain /files/{id} download) stays
    // strictly network-only — no exception, this is the one line that
    // keeps SECURITY.md's file-access rule true for the service worker.
    // Navigations get the one exception below.
    if (request.mode === 'navigate') {
        event.respondWith(handleNavigate(request, url));
    }
});

function handleNavigate(request, url) {
    return getOfflineConfig().then(function (config) {
        if (!config || !config.consent || !isWhitelisted(url.pathname, config.whitelist)) {
            // Lot 1 behavior, unchanged: a failed navigation falls back to
            // the precached offline page instead of the browser's own
            // network-error interstitial — the one thing an installed,
            // standalone app must never show, since there's no browser
            // chrome to explain it.
            return fetch(request).catch(function () {
                return caches.match('/offline');
            });
        }

        return networkFirstWithCacheFallback(request, url, config);
    });
}

// Third implementation of ONE rule, and the two others are the reference:
// Core\Offline\OfflineWhitelist::matches() (server) and
// public/assets/js/offline-nav.js's isWhitelisted() (page). There is no
// shared runtime between PHP, the page, and this worker, so the algorithm
// is necessarily reimplemented here — see OfflineWhitelist's own docblock
// on that point. It must stay semantically identical to both.
//
// `match: 'child'` means the entry's path plus EXACTLY one additional
// segment — '/members/' covers '/members/12' but not '/members/12/emails/5'
// (two extra segments). Anything else is an exact path match.
//
// This used to ignore entry.match entirely and compare paths for equality
// only. Because base.html.twig hands this worker the SAME role-filtered
// list it hands offline-nav.js — with `match` intact and no server-side
// expansion into concrete paths — every child-matched entry (/members/ is
// one, and modules may declare more) failed the check here while
// offline-nav.js counted it as available. The result: member pages were
// presented as offline-ready in the UI and then never cached or served
// from cache by this worker, so going offline showed the generic /offline
// page on a link that claimed to work.
function isWhitelisted(pathname, whitelist) {
    if (!whitelist) {
        return false;
    }
    for (let i = 0; i < whitelist.length; i++) {
        const entry = whitelist[i];
        if (entry.match === 'child') {
            if (pathname.indexOf(entry.path) !== 0) {
                continue;
            }
            const remainder = pathname.slice(entry.path.length).replace(/^\/+/, '').replace(/\/+$/, '');
            if (remainder !== '' && remainder.indexOf('/') === -1) {
                return true;
            }
        } else if (pathname === entry.path) {
            return true;
        }
    }
    return false;
}

function networkFirstWithCacheFallback(request, url, config) {
    const cacheName = CONTENT_CACHE_PREFIX + config.account_scope + '-' + config.version;

    return fetch(request).then(function (response) {
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
        if (response && response.ok && !response.redirected && config.standalone) {
            const copy = response.clone();
            caches.open(cacheName).then(function (cache) { cache.put(request, copy); });
        }
        return response;
    }).catch(function () {
        return caches.open(cacheName).then(function (cache) {
            return cache.match(request);
        }).then(function (cached) {
            if (!cached) {
                return caches.match('/offline');
            }

            const dateHeader = cached.headers.get('date');
            const ageMs = dateHeader ? Date.now() - new Date(dateHeader).getTime() : Infinity;
            const stalenessDays = config.staleness_days || 7;
            if (ageMs > stalenessDays * 24 * 60 * 60 * 1000) {
                return caches.match('/offline');
            }

            return injectOfflineBanner(cached, dateHeader);
        });
    });
}

// Not a badge — module spec is explicit this must be a readable sentence,
// visible at the top of the page, not silently implied by anything else
// on screen. Injected here (rather than server-rendered) because the same
// HTML is served whether the request succeeds live or is replayed from
// cache — only the service worker, at serve time, knows which one this
// is and how old the cached copy actually is.
function injectOfflineBanner(response, dateHeader) {
    return response.text().then(function (html) {
        const banner = '<div class="alert alert-warning text-center small rounded-0 mb-0 border-start-0 border-end-0" role="status">'
            + formatOfflineTimestamp(dateHeader) + '</div>';
        const injected = html.replace(/<body([^>]*)>/i, '<body$1>' + banner);

        return new Response(injected, {
            status: response.status,
            statusText: response.statusText,
            headers: response.headers,
        });
    });
}

function formatOfflineTimestamp(dateHeader) {
    if (!dateHeader) {
        return 'Version hors ligne';
    }

    const months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    const d = new Date(dateHeader);
    const minutes = String(d.getMinutes()).padStart(2, '0');

    return 'Version hors ligne du ' + d.getDate() + ' ' + months[d.getMonth()] + ', ' + d.getHours() + ' h ' + minutes;
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

    const url = event.notification.data && event.notification.data.url;
    if (!url) {
        return;
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (let i = 0; i < clientList.length; i++) {
                if (clientList[i].url === url && 'focus' in clientList[i]) {
                    return clientList[i].focus();
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
    isWhitelisted: isWhitelisted,
    formatOfflineTimestamp: formatOfflineTimestamp,
    injectOfflineBanner: injectOfflineBanner,
    networkFirstWithCacheFallback: networkFirstWithCacheFallback,
    handleNavigate: handleNavigate,
    storeOfflineConfig: storeOfflineConfig,
    getOfflineConfig: getOfflineConfig,
    purgeAllContentCaches: purgeAllContentCaches,
    CONTENT_CACHE_PREFIX: CONTENT_CACHE_PREFIX,
};
