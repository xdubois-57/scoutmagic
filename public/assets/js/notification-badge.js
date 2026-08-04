// Avatar notification badge (Core\Notification, Lot 2) — the count is
// server-rendered on page load (nav.html.twig), refreshed every 60s (same
// setInterval pattern as the backup-status/magic-link polls) and, when a
// push arrives while a tab is open, immediately via the service worker's
// postMessage (see public/sw.js's own 'push' handler) rather than waiting
// out the next poll.
(function () {
    var badges = document.querySelectorAll('.notification-badge');
    if (badges.length === 0) return;

    function render(count) {
        badges.forEach(function (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }
        });
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
