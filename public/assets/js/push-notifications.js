/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// "Mon compte" push notification toggle (Core\Notification, Iteration 1).
// The toggle always reflects THIS device's actual subscription state
// (checked via PushManager.getSubscription() on load), never a stored
// preference — a different device always starts unsubscribed, per module
// spec. IIFE/var style matches public/assets/js/upload.js and friends.
//
// Subscribing and unsubscribing themselves live in
// /assets/js/push-subscribe.js, shared with the invitation the installed
// application offers (ARCHITECTURE.md §8.110). What is left here is the
// switch: which notice to show, and keeping the control honest about the
// device it describes.
(function () {
    var toggle = /** @type {HTMLInputElement | null} */ (document.getElementById('push-toggle'));
    if (!toggle) return;

    var unsupportedNotice = document.getElementById('push-unsupported-notice');
    var deniedNotice = document.getElementById('push-denied-notice');
    var errorNotice = document.getElementById('push-error-notice');
    var vapidPublicKey = toggle.dataset.vapidPublicKey || '';
    var push = window.ScoutMagicPush;

    function hideNotices() {
        [unsupportedNotice, deniedNotice, errorNotice].forEach(function (el) {
            if (el) el.classList.add('d-none');
        });
    }

    // public/assets/js/nav.js's delegated listener only reacts to a real
    // 'change' event; every .checked assignment below is programmatic
    // (async load, permission denied, save failure) and fires none, so
    // aria-checked would otherwise go stale for a screen reader.
    function syncAriaChecked() {
        if (window.ScoutMagicNav?.syncSwitchAriaChecked) {
            window.ScoutMagicNav.syncSwitchAriaChecked(toggle);
        }
    }

    /** @param {HTMLElement | null} notice */
    function revert(notice) {
        toggle.checked = false;
        syncAriaChecked();
        if (notice) notice.classList.remove('d-none');
    }

    if (!push || !push.isSupported(vapidPublicKey)) {
        toggle.disabled = true;
        if (unsupportedNotice) unsupportedNotice.classList.remove('d-none');
        return;
    }

    // Reflect this device's real subscription state on load.
    push.currentSubscription()
        .then(function (subscription) {
            toggle.checked = subscription !== null;
            syncAriaChecked();
        })
        .catch(function () {
            // Leave the toggle at its default (off) state — a transient SW
            // registration failure shouldn't block the rest of the page.
        });

    toggle.addEventListener('change', function () {
        hideNotices();
        if (toggle.checked) {
            push.enable(vapidPublicKey).then(function (status) {
                if (status === 'denied') {
                    revert(deniedNotice);
                } else if (status !== 'enabled') {
                    revert(errorNotice);
                }
            });
        } else {
            push.disable().then(function (status) {
                if (status === 'error' && errorNotice) {
                    errorNotice.classList.remove('d-none');
                }
            });
        }
    });
}());
