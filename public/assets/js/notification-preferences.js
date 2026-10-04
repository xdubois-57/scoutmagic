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
//
// Every save answers with a ScoutMagicToast, success and failure alike
// (design.md §7.13, issue #739).
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

    /** @param {{status: number, data: any}} res */
    function toastFailure(res) {
        var message = res.status === 0
            ? 'Erreur réseau.'
            : res.data?.error || "Erreur lors de l'enregistrement.";
        window.ScoutMagicToast.show(message, { variant: 'error' });
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
                    if (res.data?.success) {
                        window.ScoutMagicToast.show('Enregistré.', { variant: 'success' });
                        return;
                    }
                    toggle.checked = !toggle.checked;
                    syncAriaChecked(toggle);
                    toastFailure(res);
                });
        });
    });

    // Quiet hours + discretion — auto-saved together on any change.
    var startInput = /** @type {HTMLInputElement | null} */ (document.getElementById('quiet-hours-start'));
    var endInput = /** @type {HTMLInputElement | null} */ (document.getElementById('quiet-hours-end'));
    var discretionToggle = /** @type {HTMLInputElement | null} */ (document.getElementById('notification-discretion'));

    // What the server last recorded: a refused save puts all three back,
    // the discretion switch included, so nothing on screen claims a
    // setting the server does not have.
    var saved = {
        start: startInput ? startInput.value : '',
        end: endInput ? endInput.value : '',
        discretion: discretionToggle ? discretionToggle.checked : false
    };

    // Answers can cross: an older one must neither undo a newer change nor
    // put back a baseline older than what the server already confirmed.
    var saveSequence = 0;
    var savedSequence = 0;
    /** The newest save whose own answer has come back, accepted or not. */
    var settledSequence = 0;
    // The discretion value of the newest request sent, answered or not: a
    // flip back while the first one is in flight still differs from it,
    // where it would match the value the server last confirmed.
    var lastSentDiscretion = saved.discretion;

    function paintSaved() {
        startInput.value = saved.start;
        endInput.value = saved.end;
        discretionToggle.checked = saved.discretion;
        syncAriaChecked(discretionToggle);
    }

    function saveAccountSettings() {
        var sent = {
            start: startInput.value,
            end: endInput.value,
            discretion: discretionToggle.checked
        };
        // Quiet hours are a pair: half of one is not a setting yet, and
        // the server would refuse it. The recorded pair goes instead, so a
        // discretion flip meanwhile still reaches the server.
        if ((sent.start === '') !== (sent.end === '')) {
            if (sent.discretion === lastSentDiscretion) {
                return;
            }
            sent.start = saved.start;
            sent.end = saved.end;
        }
        var sequence = ++saveSequence;
        lastSentDiscretion = sent.discretion;
        void window.ScoutMagicApi.postJson('/notifications/quiet-hours', {
            quiet_hours_start: sent.start,
            quiet_hours_end: sent.end,
            discretion: sent.discretion
        })
            .then(function (res) {
                var recorded = !!res.data?.success;
                if (recorded && sequence > savedSequence) {
                    saved = sent;
                    savedSequence = sequence;
                    // The newest save was already refused and put the
                    // controls back on what was confirmed then. This older
                    // one is confirmed now: show it, or the screen lags.
                    if (sequence !== saveSequence && settledSequence === saveSequence) {
                        paintSaved();
                        window.ScoutMagicToast.show('Enregistré.', { variant: 'success' });
                    }
                }
                if (sequence !== saveSequence) {
                    return;
                }
                settledSequence = sequence;
                if (recorded) {
                    window.ScoutMagicToast.show('Enregistré.', { variant: 'success' });
                    return;
                }
                paintSaved();
                lastSentDiscretion = saved.discretion;
                toastFailure(res);
            });
    }

    [startInput, endInput, discretionToggle].forEach(function (el) {
        if (el) el.addEventListener('change', saveAccountSettings);
    });
})();
