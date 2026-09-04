/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Chief calendar page (modules/calendar/views/chief.html.twig): the
// add/edit event dialog opened from the month grid, its create/update
// submit and its delete action. Extracted from the template's inline
// <script> so the Vitest suite can exercise the production code directly
// (tests/js/calendar-chief.test.js and
// tests/js/calendar-chief-retro-link-order.test.js).
//
// The event defaults the dialog pre-fills come from the server through
// the `calendar-chief-data` JSON island the template renders, read with
// ScoutMagicApi.pageData() — data to the parser rather than code, which
// is what a page-scoped `|json_encode|raw` should become once the script
// it feeds lives in a real file.
//
// Fetches ride the site-wide ScoutMagicApi envelope ({ok, status, data}),
// which also owns the CSRF token this file used to read through a local
// csrf() helper. `ok` is HTTP-level only: the business verdict is
// `data.success`, and the two are deliberately kept apart — an HTML 500
// page arrives as data:null, i.e. a failure whose message is a French line
// of ours rather than the "undefined" the old `data.error` read printed.
//
// The one native confirmation is gone: deleting an event asks through
// window.ScoutMagicConfirm (design.md §7.5).
(function () {
    var eventModalEl = document.getElementById('eventModal');
    var form = /** @type {HTMLFormElement|null} */ (document.getElementById('event-form'));
    // No-op on any page that is not this one.
    if (!eventModalEl || !form) return;

    var api = window.ScoutMagicApi;

    var idInput = /** @type {HTMLInputElement} */ (document.getElementById('event-id'));
    var titleInput = /** @type {HTMLInputElement} */ (document.getElementById('event-title'));
    var calendarSelect = /** @type {HTMLSelectElement} */ (document.getElementById('event-calendar'));
    var startDateInput = /** @type {HTMLInputElement} */ (document.getElementById('event-start-date'));
    var endDateInput = /** @type {HTMLInputElement} */ (document.getElementById('event-end-date'));
    var startTimeInput = /** @type {HTMLInputElement} */ (document.getElementById('event-start-time'));
    var endTimeInput = /** @type {HTMLInputElement} */ (document.getElementById('event-end-time'));
    var locationInput = /** @type {HTMLInputElement} */ (document.getElementById('event-location'));
    var descriptionInput = /** @type {HTMLTextAreaElement} */ (document.getElementById('event-description'));
    // The shared modal embed names its title '{id}-title'.
    var modalTitle = document.getElementById('eventModal-title');
    var submitBtn = /** @type {HTMLButtonElement} */ (document.getElementById('event-form-submit'));
    var deleteBtn = /** @type {HTMLButtonElement} */ (document.getElementById('event-form-delete'));
    var errorBox = document.getElementById('event-form-error');
    var retroAutoCreateWrap = document.getElementById('event-retro-auto-create-wrap');
    var retroAutoCreateInput = /** @type {HTMLInputElement} */ (document.getElementById('event-retro-auto-create'));
    var retroLinkWrap = document.getElementById('event-retro-link-wrap');
    var retroLinkAnchor = /** @type {HTMLAnchorElement} */ (document.getElementById('event-retro-link'));

    var pageData = window.ScoutMagicApi.pageData('calendar-chief-data') || {};
    var defaultTitle = pageData.defaultTitle || '';
    var defaultStartTime = pageData.defaultStartTime || '';
    var defaultEndTime = pageData.defaultEndTime || '';
    var defaultLocation = pageData.defaultLocation || '';
    var defaultCalendarId = pageData.defaultCalendarId == null ? null : pageData.defaultCalendarId;
    // The calendars this account may WRITE to — a subset of what the grid
    // shows, since an animateur sees the whole unit but files events only
    // in their own sections. The server refuses either way; this keeps the
    // dialog from opening on an event it could never save.
    var editableCalendarIds = (pageData.editableCalendarIds || []).map(Number);

    /**
     * @param {string|number|null|undefined} calendarId
     * @returns {boolean}
     */
    function isEditableCalendar(calendarId) {
        return calendarId != null && editableCalendarIds.includes(Number(calendarId));
    }

    var modal = window.bootstrap ? new window.bootstrap.Modal(eventModalEl) : null;

    function showModal() {
        // Without the Bootstrap bundle (a stripped page, or jsdom) the
        // dialog is shown by hand rather than not at all.
        if (modal) {
            modal.show();
        } else {
            eventModalEl.classList.add('show');
        }
    }

    /**
     * The message to show for a failed call: the server's own French error
     * when it answered JSON, a generic line when it did not.
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {string}
     */
    function failureMessage(res) {
        return (res.data && res.data.error) || 'Erreur : réponse serveur invalide.';
    }

    /**
     * Shows a failure inside the dialog, next to the form it is about.
     * textContent, never innerHTML: the message comes from the server.
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {void}
     */
    function showError(res) {
        errorBox.textContent = failureMessage(res);
        errorBox.classList.remove('d-none');
    }

    /**
     * Opens the dialog on a blank event pre-filled with the unit's defaults.
     *
     * @param {string} dateStr The clicked day, YYYY-MM-DD.
     * @returns {void}
     */
    function openAddModal(dateStr) {
        idInput.value = '';
        titleInput.value = defaultTitle;
        if (defaultCalendarId !== null) {
            calendarSelect.value = String(defaultCalendarId);
        }
        startDateInput.value = dateStr;
        endDateInput.value = dateStr;
        startTimeInput.value = defaultStartTime;
        endTimeInput.value = defaultEndTime;
        locationInput.value = defaultLocation;
        descriptionInput.value = '';
        modalTitle.textContent = 'Ajouter un évènement';
        submitBtn.textContent = 'Ajouter';
        deleteBtn.classList.add('d-none');
        errorBox.classList.add('d-none');
        if (retroAutoCreateWrap) {
            retroAutoCreateWrap.classList.remove('d-none');
            retroAutoCreateInput.checked = false;
        }
        if (retroLinkWrap) retroLinkWrap.classList.add('d-none');
        showModal();
    }

    /**
     * Opens the dialog on an existing event, read from the clicked bar's
     * data-* attributes (partials/month_grid.html.twig renders them).
     *
     * @param {DOMStringMap} dataset
     * @returns {void}
     */
    function openEditModal(dataset) {
        idInput.value = dataset.id;
        calendarSelect.value = dataset.calendarId;
        titleInput.value = dataset.title;
        startDateInput.value = dataset.startDate;
        endDateInput.value = dataset.endDate || '';
        startTimeInput.value = dataset.startTime || '';
        endTimeInput.value = dataset.endTime || '';
        locationInput.value = dataset.location;
        descriptionInput.value = dataset.description;
        modalTitle.textContent = "Modifier l'évènement";
        submitBtn.textContent = 'Enregistrer';
        deleteBtn.classList.remove('d-none');
        errorBox.classList.add('d-none');
        if (retroAutoCreateWrap) {
            var hasLinkedRetro = dataset.hasLinkedRetro === '1';
            retroAutoCreateWrap.classList.toggle('d-none', hasLinkedRetro);
            retroAutoCreateInput.checked = dataset.autoCreateRetro === '1';
        }
        if (retroLinkWrap) {
            if (dataset.retroLink) {
                // Populate before revealing: the wrap and the anchor inside it
                // share no other content, so removing d-none before the href/
                // text are set would briefly expose an empty, non-navigable
                // link — every other populate+reveal pair in this codebase
                // (e.g. modules/sos_staff/views/config.html.twig's
                // consumer-key-validation-link) follows this same order.
                retroLinkAnchor.href = dataset.retroLink;
                retroLinkAnchor.textContent = dataset.retroLinkTitle || dataset.retroLink;
                retroLinkWrap.classList.remove('d-none');
            } else {
                retroLinkWrap.classList.add('d-none');
            }
        }
        showModal();
    }

    // Both are real <button> elements (partials/month_grid.html.twig renders them
    // as buttons whenever the caller marks them clickable), so Enter and Space
    // already fire a click — no keyboard handler of our own, and none of the
    // inline onKeyDown attribute this site's CSP would refuse to run anyway.
    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.calendar-day-cell--clickable')).forEach(function (cell) {
        cell.addEventListener('click', function () {
            // Nowhere to file it: an animateur with no section of their own.
            // The page says so in its own words; opening an empty dialog on
            // top of that would only be a second, worse way of saying it.
            if (!editableCalendarIds.length) return;
            openAddModal(cell.dataset.date);
        });
    });

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.calendar-event-bar--clickable')).forEach(function (bar) {
        bar.addEventListener('click', function (e) {
            // The bar sits on top of the day cell in the same grid, so without
            // this a click on a bar would also open the "add an event" modal.
            e.stopPropagation();
            // Another section's event: the dialog's calendar list does not
            // contain its calendar, so opening it would silently offer to
            // MOVE the event into one of ours — and the server would refuse
            // on submit. Do nothing instead.
            if (!isEditableCalendar(bar.dataset.calendarId)) return;
            openEditModal(bar.dataset);
        });
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var isUpdate = idInput.value !== '';
        var payload = /** @type {Object.<string, any>} */ ({
            calendar_id: Number.parseInt(calendarSelect.value, 10),
            title: titleInput.value,
            start_date: startDateInput.value,
            end_date: endDateInput.value,
            start_time: startTimeInput.value,
            end_time: endTimeInput.value,
            location: locationInput.value,
            description: descriptionInput.value
        });
        if (retroAutoCreateWrap && !retroAutoCreateWrap.classList.contains('d-none')) {
            payload.auto_create_retro = retroAutoCreateInput.checked;
        }
        if (isUpdate) {
            payload.event_id = Number.parseInt(idInput.value, 10);
        }

        var res = await api.withDisabled(submitBtn, function () {
            return api.postJson(isUpdate ? '/chefs/calendar/event-update' : '/chefs/calendar/event-create', payload);
        });
        if (res.data && res.data.success) {
            window.location.reload();
        } else {
            showError(res);
        }
    });

    deleteBtn.addEventListener('click', async function () {
        var confirmed = await window.ScoutMagicConfirm.ask({
            message: 'Supprimer cet évènement ?',
            confirmLabel: 'Supprimer'
        });
        if (!confirmed) return;

        var res = await api.withDisabled(deleteBtn, function () {
            return api.postJson('/chefs/calendar/event-delete', {
                event_id: Number.parseInt(idInput.value, 10)
            });
        });
        if (res.data && res.data.success) {
            window.location.reload();
        } else {
            showError(res);
        }
    });
})();
