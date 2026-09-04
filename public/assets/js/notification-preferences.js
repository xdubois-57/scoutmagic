/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Notification preferences page (Core\Notification, Lot 2) — auto-saves
// every toggle individually on change (no "Enregistrer" button). The Push
// column is gated on Notification.permission rather than requesting it
// from here — permission itself is only ever requested from "Mon compte"
// (public/assets/js/push-notifications.js), after its own explanatory
// sentence, never implicitly from a preference toggle.
(function () {
    var root = document.getElementById('notification-preferences');
    if (!root) return;

    // public/assets/js/nav.js's delegated 'change' listener already syncs
    // aria-checked for a real user click; the two reverts below flip
    // .checked back programmatically after a failed save, which fires no
    // 'change' event of its own.
    function syncAriaChecked(toggle) {
        if (window.ScoutMagicNav?.syncSwitchAriaChecked) {
            window.ScoutMagicNav.syncSwitchAriaChecked(toggle);
        }
    }

    // Gate the Push column on browser permission.
    var pushToggles = /** @type {NodeListOf<HTMLInputElement>} */ (root.querySelectorAll('.notification-channel-toggle[data-is-push]'));
    var permissionNotice = document.getElementById('push-permission-notice');
    var pushSupported = 'Notification' in window;
    var pushGranted = pushSupported && Notification.permission === 'granted';
    if (!pushGranted) {
        pushToggles.forEach(function (toggle) { toggle.disabled = true; });
        if (permissionNotice) permissionNotice.classList.remove('d-none');
    }

    var channelToggles = /** @type {NodeListOf<HTMLInputElement>} */ (root.querySelectorAll('.notification-channel-toggle'));
    channelToggles.forEach(function (toggle) {
        toggle.addEventListener('change', function () {
            var value = toggle.checked ? 'on' : 'off';
            window.ScoutMagicApi.postJson('/notifications/preferences', {
                type_id: toggle.dataset.typeId,
                channel: toggle.dataset.channel,
                value: value
            })
                .then(function (res) {
                    // A network failure or an HTTP error page arrives as a
                    // data-less envelope — same revert as a refused save.
                    if (!res.data?.success) {
                        toggle.checked = !toggle.checked;
                        syncAriaChecked(toggle);
                    }
                });
        });
    });

    // Quiet hours + discretion — auto-saved together on any change.
    var startInput = /** @type {HTMLInputElement | null} */ (document.getElementById('quiet-hours-start'));
    var endInput = /** @type {HTMLInputElement | null} */ (document.getElementById('quiet-hours-end'));
    var discretionToggle = /** @type {HTMLInputElement | null} */ (document.getElementById('notification-discretion'));

    function saveAccountSettings() {
        window.ScoutMagicApi.postJson('/notifications/quiet-hours', {
            quiet_hours_start: startInput.value,
            quiet_hours_end: endInput.value,
            discretion: discretionToggle.checked
        })
            .then(function (res) {
                if (res.data?.success) {
                    window.ScoutMagicToast.show('Enregistré.', { variant: 'success' });
                }
            });
    }

    [startInput, endInput, discretionToggle].forEach(function (el) {
        if (el) el.addEventListener('change', saveAccountSettings);
    });
})();
