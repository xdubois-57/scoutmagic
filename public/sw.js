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
// Scope of the app-shell cache (Lot 1): the shell ONLY (Bootstrap, the
// site's own CSS/JS, the icons, /offline). Cache-first for exactly those;
// everything else — including every authenticated page and every
// /files/{id} download — is network-only. No content caching.
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

self.addEventListener('push', function (event) {
    var data = {};
    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data = { title: 'Notification', body: event.data.text() };
        }
    }

    var title = data.title || 'Notification';
    var options = {
        body: data.body || '',
        data: { url: data.url || null }
    };

    event.waitUntil(
        self.registration.showNotification(title, options).then(function () {
            var tasks = [];

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

    var url = event.notification.data && event.notification.data.url;
    if (!url) {
        return;
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
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
