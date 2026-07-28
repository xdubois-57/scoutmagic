// Web Push service worker (RFC 8030) — Core\Notification, Iteration 1.
// Deliberately minimal: this worker exists ONLY to receive push events and
// handle notification clicks. It must never be extended for caching,
// offline support, or any other service-worker capability — a separate
// concern that would need its own review (module spec: "Ne pas utiliser
// le SW pour du cache, du offline, ou quoi que ce soit d'autre que les
// push notifications").

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

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var url = event.notification.data && event.notification.data.url;
    if (!url) {
        return;
    }

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
                if (clientList[i].url === url && 'focus' in clientList[i]) {
                    return clientList[i].focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});
