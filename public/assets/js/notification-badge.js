/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Avatar notification badge (Core\Notification, Lot 2) — the count is
// server-rendered on page load (nav.html.twig), refreshed every 60s (same
// setInterval pattern as the backup-status/magic-link polls) and, when a
// push arrives while a tab is open, immediately via the service worker's
// postMessage (see public/sw.js's own 'push' handler) rather than waiting
// out the next poll.
//
// Also keeps the installed app icon's OS-level badge (navigator.
// setAppBadge()/clearAppBadge()) in sync with the same count. sw.js's own
// 'push' handler already sets it while the app is closed (the one place
// that still runs then) — but nothing used to clear or update it once the
// app was reopened and notifications were read via the plain POST+redirect
// mark-read/mark-all-read routes, so the OS badge could keep showing a
// stale count (or stay stuck) indefinitely after everything was read. The
// immediate refresh() call below (not just the 60s interval) closes that
// gap: it runs on every page load, including the one right after a
// mark-read redirect lands.
(function () {
    const badges = document.querySelectorAll('.notification-badge');

    function syncAppBadge(count) {
        if (!('setAppBadge' in navigator)) return;
        if (count > 0) {
            navigator.setAppBadge(count).catch(function () {});
        } else if ('clearAppBadge' in navigator) {
            navigator.clearAppBadge().catch(function () {});
        }
    }

    function render(count) {
        badges.forEach(function (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }
        });
        syncAppBadge(count);
    }

    function refresh() {
        fetch('/api/notifications/unread-count', { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (data) {
                if (data && typeof data.count === 'number') {
                    render(data.count);
                }
            })
            .catch(function () {
                // Best-effort — a transient failure just keeps the
                // last-known (server-rendered) count on screen.
            });
    }

    if (badges.length === 0) return;

    refresh();
    setInterval(refresh, 60000);

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', function (event) {
            if (event.data && event.data.type === 'push-received') {
                if (typeof event.data.unreadCount === 'number') {
                    render(event.data.unreadCount);
                } else {
                    refresh();
                }
            }
        });
    }
})();
