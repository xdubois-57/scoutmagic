/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// "Mon compte" push notification toggle (Core\Notification, Iteration 1).
// The toggle always reflects THIS device's actual subscription state
// (checked via PushManager.getSubscription() on load), never a stored
// preference — a different device always starts unsubscribed, per module
// spec. IIFE/var style matches public/assets/js/upload.js and friends.
(function () {
    var toggle = document.getElementById('push-toggle');
    if (!toggle) return;

    var unsupportedNotice = document.getElementById('push-unsupported-notice');
    var deniedNotice = document.getElementById('push-denied-notice');
    var errorNotice = document.getElementById('push-error-notice');
    var vapidPublicKey = toggle.dataset.vapidPublicKey || '';

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function hideNotices() {
        [unsupportedNotice, deniedNotice, errorNotice].forEach(function (el) {
            if (el) el.classList.add('d-none');
        });
    }

    // Web Push needs a Uint8Array applicationServerKey, not the base64url
    // string the server exposes via data-vapid-public-key.
    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; i++) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window) || vapidPublicKey === '') {
        toggle.disabled = true;
        if (unsupportedNotice) unsupportedNotice.classList.remove('d-none');
        return;
    }

    // The single site-wide worker (public/sw.js) is already registered by
    // base.html.twig on every page load — registering a second one here
    // (as an earlier iteration did, at /sw-push.js) would silently fight
    // it for control of the page, since both resolve to the same
    // (default, web-root) scope. This just waits for it to be ready.
    function getRegistration() {
        return navigator.serviceWorker.ready;
    }

    // Reflect this device's real subscription state on load.
    getRegistration()
        .then(function (registration) { return registration.pushManager.getSubscription(); })
        .then(function (subscription) {
            toggle.checked = subscription !== null;
        })
        .catch(function () {
            // Leave the toggle at its default (off) state — a transient SW
            // registration failure shouldn't block the rest of the page.
        });

    function postSubscription(subscription) {
        var json = subscription.toJSON();
        return fetch('/api/push-subscription', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                endpoint: json.endpoint,
                auth_key: json.keys.auth,
                p256dh_key: json.keys.p256dh,
                _csrf_token: csrf()
            })
        }).then(function (res) { return res.json(); });
    }

    function deleteSubscription(endpoint) {
        return fetch('/api/push-subscription', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ endpoint: endpoint, _csrf_token: csrf() })
        }).then(function (res) { return res.json(); });
    }

    function enable() {
        return Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') {
                toggle.checked = false;
                if (deniedNotice) deniedNotice.classList.remove('d-none');
                return;
            }

            return getRegistration()
                .then(function (registration) {
                    return registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                    });
                })
                .then(postSubscription)
                .then(function (result) {
                    if (!result.success) {
                        toggle.checked = false;
                        if (errorNotice) errorNotice.classList.remove('d-none');
                    }
                })
                .catch(function () {
                    toggle.checked = false;
                    if (errorNotice) errorNotice.classList.remove('d-none');
                });
        });
    }

    function disable() {
        return getRegistration()
            .then(function (registration) { return registration.pushManager.getSubscription(); })
            .then(function (subscription) {
                if (!subscription) return;
                var endpoint = subscription.endpoint;
                return subscription.unsubscribe().then(function () {
                    return deleteSubscription(endpoint);
                });
            })
            .catch(function () {
                if (errorNotice) errorNotice.classList.remove('d-none');
            });
    }

    toggle.addEventListener('change', function () {
        hideNotices();
        if (toggle.checked) {
            enable();
        } else {
            disable();
        }
    });
})();
