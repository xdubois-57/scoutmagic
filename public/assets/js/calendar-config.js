/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Calendar configuration page (modules/calendar/views/config.html.twig):
// the event-defaults form, the multi-day reminder settings, the per-calendar
// visibility selects, the ICS "copy link" buttons, ICS token regeneration
// (per calendar and for the combined unit feed), calendar deletion and
// calendar creation. Extracted from the template's inline <script> so the
// Vitest suite can exercise the production code directly
// (tests/js/calendar-config.test.js).
//
// Fetches ride the site-wide ScoutMagicApi envelope ({ok, status, data}),
// which also owns the CSRF token this file used to read through a local
// csrf() helper. `ok` is HTTP-level only: the business verdict of every
// endpoint here is `data.success`, and the two are deliberately kept apart —
// an HTML 500 page arrives as data:null, which is a failure, never a
// success.
//
// The nine native dialogs this page used to open are gone (design.md §7.5):
// the three confirmations go through window.ScoutMagicConfirm and the six
// alert() failures through window.ScoutMagicToast. The three saves that stay
// on the page (defaults, notifications, visibility) now say so instead of
// succeeding in complete silence.
(function () {
    var defaultsForm = /** @type {HTMLFormElement|null} */ (document.getElementById('defaults-form'));
    var calendarList = document.getElementById('supplementary-calendar-list');
    // No-op on any page that is not this one.
    if (!defaultsForm && !calendarList) return;

    var api = window.ScoutMagicApi;

    /**
     * The current value of one input or select on this page.
     *
     * @param {string} id
     * @returns {string}
     */
    function valueOf(id) {
        var field = /** @type {HTMLInputElement|HTMLSelectElement|null} */ (document.getElementById(id));
        return field ? field.value : '';
    }

    /**
     * The business verdict of one envelope. HTTP `ok` is deliberately not
     * consulted: a 200 carrying {success:false} is a failure just the same,
     * and a non-JSON body (an error page) came back as data:null.
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {boolean}
     */
    function succeeded(res) {
        return !!(res.data && res.data.success);
    }

    /**
     * What to show for a failed call: the server's own French message when
     * it answered JSON, a generic line when it did not.
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {string}
     */
    function failureMessage(res) {
        return (res.data && res.data.error) || 'Erreur : réponse serveur invalide.';
    }

    /** @param {{ok: boolean, status: number, data: any}} res */
    function toastFailure(res) {
        window.ScoutMagicToast.show(failureMessage(res), { variant: 'error' });
    }

    /** @param {string} message */
    function toastSuccess(message) {
        window.ScoutMagicToast.show(message, { variant: 'success' });
    }

    // --- Event defaults ---

    if (defaultsForm) {
        defaultsForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            var res = await api.postJson('/config/calendar/defaults', {
                event_default_title: valueOf('default-title'),
                event_default_start_time: valueOf('default-start-time'),
                event_default_end_time: valueOf('default-end-time'),
                event_default_location: valueOf('default-location')
            });
            if (succeeded(res)) {
                toastSuccess('Valeurs par défaut enregistrées.');
            } else {
                toastFailure(res);
            }
        });
    }

    // --- Multi-day event reminders ---

    var notificationsForm = /** @type {HTMLFormElement|null} */ (document.getElementById('notifications-form'));
    if (notificationsForm) {
        var notificationsError = document.getElementById('notifications-error');
        var notifyEnabled = /** @type {HTMLInputElement|null} */ (document.getElementById('notify-enabled'));

        notificationsForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            notificationsError.classList.add('d-none');

            var res = await api.postJson('/config/calendar/notifications', {
                enabled: !!(notifyEnabled && notifyEnabled.checked),
                days_before: Number.parseInt(valueOf('notify-days-before'), 10)
            });
            if (succeeded(res)) {
                toastSuccess('Rappels enregistrés.');
                return;
            }
            // This failure is about one field (the delay), so it stays next
            // to that field rather than becoming a toast. textContent, never
            // innerHTML: the message comes from the server.
            notificationsError.textContent = failureMessage(res);
            notificationsError.classList.remove('d-none');
        });
    }

    // --- Visibility ---

    /** @type {NodeListOf<HTMLSelectElement>} */ (document.querySelectorAll('.visibility-select')).forEach(function (select) {
        select.addEventListener('change', async function () {
            var res = await api.postJson('/config/calendar/visibility', {
                calendar_id: Number.parseInt(select.dataset.calendarId, 10),
                visibility: select.value
            });
            if (succeeded(res)) {
                toastSuccess('Visibilité mise à jour.');
            } else {
                toastFailure(res);
            }
        });
    });

    // The other half of the pair: who may MODIFY the events, as opposed to
    // who sees the calendar. The server refuses a combination that would
    // let a role write in a calendar it cannot see, and says so — surface
    // that refusal rather than leaving the select showing a value the
    // server did not keep.
    /** @type {NodeListOf<HTMLSelectElement>} */ (document.querySelectorAll('.edit-role-select')).forEach(function (select) {
        var lastAccepted = select.value;
        select.addEventListener('change', async function () {
            var res = await api.postJson('/config/calendar/edit-role', {
                calendar_id: Number.parseInt(select.dataset.calendarId, 10),
                edit_role_min: select.value
            });
            if (succeeded(res)) {
                lastAccepted = select.value;
                toastSuccess('Droit de modification mis à jour.');
            } else {
                select.value = lastAccepted;
                toastFailure(res);
            }
        });
    });

    // --- ICS links ---

    /**
     * Selects a read-only link field and copies it. The selection is the
     * fallback: on a browser without the async clipboard API (or without
     * permission), the visitor still has the whole link highlighted and can
     * copy it by hand.
     *
     * @param {HTMLInputElement|null} input
     * @returns {void}
     */
    function copyLink(input) {
        if (!input) return;
        input.select();
        navigator.clipboard?.writeText(input.value);
    }

    /** @type {NodeListOf<HTMLButtonElement>} */ (document.querySelectorAll('.copy-link-btn')).forEach(function (btn) {
        btn.addEventListener('click', function () {
            var group = btn.closest('.input-group');
            copyLink(group ? /** @type {HTMLInputElement|null} */ (group.querySelector('.ics-link-input')) : null);
        });
    });

    document.getElementById('copy-unit-link')?.addEventListener('click', function () {
        copyLink(/** @type {HTMLInputElement|null} */ (document.getElementById('unit-ics-link')));
    });

    // --- Token regeneration ---

    // Regenerating destroys the link everyone already subscribed with, so
    // this keeps the danger variant window.ScoutMagicConfirm defaults to.
    var REGENERATE_MESSAGE = "Régénérer ce lien invalidera l'ancien : tous les abonnements existants cesseront de se mettre à jour. Continuer ?";

    /**
     * Regenerates one ICS token after confirmation, then reloads so the page
     * shows the new link instead of the invalidated one.
     *
     * @param {HTMLButtonElement} btn The control to keep disabled for the call.
     * @param {string} url
     * @param {Object} [body]
     * @returns {Promise<void>}
     */
    async function regenerateToken(btn, url, body) {
        var confirmed = await window.ScoutMagicConfirm.ask({
            message: REGENERATE_MESSAGE,
            confirmLabel: 'Régénérer'
        });
        if (!confirmed) return;

        var res = await api.withDisabled(btn, function () {
            return api.postJson(url, body || {});
        });
        if (succeeded(res)) {
            window.location.reload();
        } else {
            toastFailure(res);
        }
    }

    /** @type {NodeListOf<HTMLButtonElement>} */ (document.querySelectorAll('.regenerate-btn')).forEach(function (btn) {
        btn.addEventListener('click', function () {
            return regenerateToken(btn, '/config/calendar/regenerate', {
                calendar_id: Number.parseInt(btn.dataset.calendarId, 10)
            });
        });
    });

    var unitRegenerateBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('regenerate-unit-token'));
    if (unitRegenerateBtn) {
        unitRegenerateBtn.addEventListener('click', function () {
            return regenerateToken(unitRegenerateBtn, '/config/calendar/unit-token/regenerate', {});
        });
    }

    // --- Supplementary calendars ---

    /** @type {NodeListOf<HTMLButtonElement>} */ (document.querySelectorAll('.delete-calendar-btn')).forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var confirmed = await window.ScoutMagicConfirm.ask({
                message: 'Supprimer définitivement ce calendrier ?',
                confirmLabel: 'Supprimer'
            });
            if (!confirmed) return;

            var res = await api.withDisabled(btn, function () {
                return api.postJson('/config/calendar/delete', {
                    calendar_id: Number.parseInt(btn.dataset.calendarId, 10)
                });
            });
            if (!succeeded(res)) {
                toastFailure(res);
                return;
            }
            // The row carries the same data-calendar-id as the button, and
            // it is the first element in document order to do so.
            var row = calendarList
                ? calendarList.querySelector('[data-calendar-id="' + btn.dataset.calendarId + '"]')
                : null;
            if (row) row.remove();
        });
    });

    var addBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('add-calendar-btn'));
    if (addBtn) {
        addBtn.addEventListener('click', async function () {
            var res = await api.withDisabled(addBtn, function () {
                return api.postJson('/config/calendar/add', {
                    name: valueOf('new-calendar-name'),
                    visibility: valueOf('new-calendar-visibility')
                });
            });
            if (succeeded(res)) {
                window.location.reload();
            } else {
                toastFailure(res);
            }
        });
    }
})();
