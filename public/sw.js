// Installable-PWA app shell (Lot 1) — hand-written, no Workbox/build tool.
// Must sit at the web root (public/ is the document root, so this
// resolves to /sw.js) — moving it under /assets/ would silently scope it
// to that subtree instead of the whole site.
//
// Registered as /sw.js?v={appVersion} (see base.html.twig) — appVersion
// comes from Core\Maintenance\VersionFile, the same file a GitHub-release
// install (§8.17) overwrites as its last step. self.location.search is
// how a plain static script reads that query string at runtime; there is
// no server-side templating here. The cache name derives from it, so a
// version bump is the entire update/invalidation mechanism: a new query
// string means the browser treats this as a new worker (a byte-different
// script), which installs, precaches fresh copies under a new cache name,
// and activate() below deletes every cache that doesn't match it.
//
// Scope of this lot: precache the app shell ONLY (Bootstrap, the site's
// own CSS/JS, the icons, /offline). Cache-first for exactly those URLs;
// everything else — including every authenticated page and every
// /files/{id} download — is network-only. No content caching, no
// notifications; those are explicitly out of scope here.

const params = new URLSearchParams(self.location.search);
const VERSION = params.get('v') || 'dev';
const CACHE_NAME = 'app-shell-' + VERSION;

// Explicit whitelist — never a blacklist. Every entry here is public,
// static, and contains no personal data.
const APP_SHELL_URLS = [
    '/assets/vendor/bootstrap/css/bootstrap.min.css',
    '/assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
    '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
    '/assets/css/app.css',
    '/assets/css/editable.css',
    '/assets/js/nav.js',
    '/assets/js/editable.js',
    '/assets/js/cookie-consent.js',
    '/pwa/icon-192.png',
    '/pwa/icon-512.png',
    '/pwa/icon-512-maskable.png',
    '/pwa/icon-180.png',
    '/offline',
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.addAll(APP_SHELL_URLS);
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (names) {
            return Promise.all(
                names
                    .filter(function (name) { return name !== CACHE_NAME; })
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

    // Cache-first for the app shell — ignoreSearch so a cache-busting
    // "?v=..." on an icon URL still matches the precached entry.
    if (APP_SHELL_URLS.indexOf(url.pathname) !== -1) {
        event.respondWith(
            caches.match(request, { ignoreSearch: true }).then(function (cached) {
                return cached || fetch(request);
            })
        );
        return;
    }

    // Network-only for everything else in this lot. The one exception:
    // a failed page navigation (airplane mode, etc.) falls back to the
    // precached offline page instead of the browser's own network-error
    // interstitial — this is the one thing an installed, standalone app
    // must never show, since there is no browser chrome to explain it.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match('/offline');
            })
        );
    }
});
