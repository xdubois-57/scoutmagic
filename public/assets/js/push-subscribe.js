/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The one place this site subscribes a device to Web Push, and the one
// place it unsubscribes it (Core\Notification, ARCHITECTURE.md §8.111).
//
// Two surfaces drive it: the « Notifications push » switch of « Mon
// compte » (/assets/js/push-notifications.js) and the invitation the
// installed application offers (/assets/js/push-invitation.js). They were
// one file's worth of logic copied into two until this one existed — the
// base64url conversion, the permission request, the subscribe call and
// the POST to /api/push-subscription are exactly the same act whoever
// asked for it, and a second copy is a second thing to keep correct when
// the endpoint, the key format or the error handling moves.
//
// Every function here resolves a STATUS STRING rather than throwing: both
// callers have to tell "the browser said no" apart from "something broke"
// in order to say the right sentence, and neither has anything to do with
// an exception. IIFE/var style matches the rest of public/assets/js/.
(function () {
    var ENDPOINT = '/api/push-subscription';

    /**
     * Whether this browser can be subscribed at all, key included — a
     * page rendered by an installation with no VAPID pair hands an empty
     * string, and there is nothing to subscribe to.
     *
     * @param {string} vapidPublicKey
     * @returns {boolean}
     */
    function isSupported(vapidPublicKey) {
        return 'serviceWorker' in navigator
            && 'PushManager' in window
            && 'Notification' in window
            && vapidPublicKey !== '';
    }

    // The single site-wide worker (public/sw.js) is registered by
    // base.html.twig on every page load. Registering a second one here (as
    // an earlier iteration did, at /sw-push.js) would silently fight it
    // for control of the page, since both resolve to the same (default,
    // web-root) scope. This just waits for it to be ready.
    function registration() {
        return navigator.serviceWorker.ready;
    }

    /**
     * This device's current subscription, or null — the truth about THIS
     * device, never a stored preference, which is why « Mon compte »
     * reads it on every load.
     *
     * @returns {Promise<PushSubscription | null>}
     */
    function currentSubscription() {
        return registration().then(function (reg) {
            return reg.pushManager.getSubscription();
        });
    }

    /**
     * Web Push needs a Uint8Array applicationServerKey, not the base64url
     * string the server exposes via data-vapid-public-key.
     *
     * The return type is deliberately left to inference: spelling
     * `Uint8Array` in JSDoc widens it to `Uint8Array<ArrayBufferLike>`,
     * which PushManager.subscribe()'s `BufferSource` does not accept.
     *
     * @param {string} base64String
     */
    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replaceAll('-', '+').replaceAll('_', '/');
        var rawData = atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; i++) {
            // Safe here: atob() yields a binary string, every code unit
            // 0-255, so no surrogate pair can make the two disagree.
            outputArray[i] = rawData.codePointAt(i);
        }
        return outputArray;
    }

    /**
     * @param {PushSubscription} subscription
     * @returns {Promise<{ok: boolean, status: number, data: any}>}
     */
    function postSubscription(subscription) {
        var json = subscription.toJSON();
        return window.ScoutMagicApi.postJson(ENDPOINT, {
            endpoint: json.endpoint,
            auth_key: json.keys.auth,
            p256dh_key: json.keys.p256dh
        });
    }

    /**
     * Asks the device, subscribes it, and registers the subscription.
     *
     * 'denied' is the browser's own refusal and is not an error: nothing
     * broke, and there is nothing to retry — the reader has to change
     * their mind in the browser's settings first. Everything else that
     * goes wrong — a transport failure, an HTTP error page, a server that
     * refused the endpoint — lands on 'error', because they are the same
     * outcome to whoever is looking at the screen.
     *
     * @param {string} vapidPublicKey
     * @returns {Promise<'enabled' | 'denied' | 'error'>}
     */
    function enable(vapidPublicKey) {
        return Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') {
                return 'denied';
            }

            return registration()
                .then(function (reg) {
                    return reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                    });
                })
                .then(postSubscription)
                .then(function (result) {
                    // A transport failure and an HTTP error page both land
                    // here as a data-less envelope — same outcome as a
                    // server-refused subscription.
                    return result.data?.success ? 'enabled' : 'error';
                });
        }).catch(function () {
            return 'error';
        });
    }

    /**
     * Unsubscribes this device and forgets the endpoint server-side.
     * Nothing to unsubscribe is 'disabled' and not an error — it is the
     * state the caller was asking for.
     *
     * @returns {Promise<'disabled' | 'error'>}
     */
    function disable() {
        return currentSubscription()
            .then(function (subscription) {
                if (!subscription) {
                    return 'disabled';
                }
                var endpoint = subscription.endpoint;

                return subscription.unsubscribe()
                    .then(function () {
                        return window.ScoutMagicApi.postJson(ENDPOINT, { endpoint: endpoint }, { method: 'DELETE' });
                    })
                    .then(function (result) {
                        return result && !result.ok ? 'error' : 'disabled';
                    });
            })
            .catch(function () {
                return 'error';
            });
    }

    window.ScoutMagicPush = {
        isSupported: isSupported,
        currentSubscription: currentSubscription,
        enable: enable,
        disable: disable
    };
}());
