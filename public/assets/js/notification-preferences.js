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

    function csrf() {
        var meta = /** @type {HTMLMetaElement | null} */ (document.querySelector('meta[name="csrf-token"]'));
        return meta ? meta.content : '';
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
            fetch('/notifications/preferences', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type_id: toggle.dataset.typeId,
                    channel: toggle.dataset.channel,
                    value: value,
                    _csrf_token: csrf()
                })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success) {
                        toggle.checked = !toggle.checked;
                    }
                })
                .catch(function () {
                    toggle.checked = !toggle.checked;
                });
        });
    });

    // Quiet hours + discretion — auto-saved together on any change.
    var startInput = /** @type {HTMLInputElement | null} */ (document.getElementById('quiet-hours-start'));
    var endInput = /** @type {HTMLInputElement | null} */ (document.getElementById('quiet-hours-end'));
    var discretionToggle = /** @type {HTMLInputElement | null} */ (document.getElementById('notification-discretion'));
    var savedNotice = document.getElementById('account-settings-saved-notice');

    function saveAccountSettings() {
        fetch('/notifications/quiet-hours', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                quiet_hours_start: startInput.value,
                quiet_hours_end: endInput.value,
                discretion: discretionToggle.checked,
                _csrf_token: csrf()
            })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success && savedNotice) {
                    savedNotice.classList.remove('d-none');
                    setTimeout(function () { savedNotice.classList.add('d-none'); }, 2000);
                }
            });
    }

    [startInput, endInput, discretionToggle].forEach(function (el) {
        if (el) el.addEventListener('change', saveAccountSettings);
    });
})();
