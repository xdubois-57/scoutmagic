/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// SOS Staff d'U admin page (modules/sos_staff/views/admin.html.twig): the
// default-number / transition-hour / notification settings, the duty roster
// in its two layouts, and the AJAX pagination of the planned-redirections
// list. Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/sos-admin.test.js) — an
// inline template script is invisible to both Vitest and `npm run typecheck`.
//
// TWO LAYOUTS, ONE STATE
// ----------------------------------------------------------------------------
// The desktop grid is a table of one-glyph cells (`.sos-oncall-cell`, a <td>)
// whose click CYCLES on call → indisponible → rien. The phone layout has no
// grid at all: a list of days, and three named buttons per member
// (`.sos-state-button`, carrying `data-state`) that SET a state outright. The
// phone list used to render every member inside every day — roughly 250
// stacked buttons for a month of eight people, first names truncated to one
// word at 0.65rem, with ✓/✗/— and no legend. Moving the members into a single
// day sheet is what fixed that; this file is the half of it that has to keep
// both renderings of a member/day pair in step.
//
// Both shapes carry `data-member-id` and `data-date`, and both are repainted
// from repaintCells(). The day sheet renders ONE copy of the roster for the
// whole month, so its buttons start with an empty `data-date` that
// openDaySheet() stamps — repainting is therefore naturally scoped to the day
// currently open.
//
// The month being displayed, the duty states it starts from and the ROSTER
// ORDER come from the server through the `sos-admin-data` JSON island the
// template renders, read with ScoutMagicApi.pageData() — the site-wide
// server-data-to-a-page pattern. Nothing in that payload is a phone number,
// and nothing in it is a member's NAME either: it is dates, member ids and
// the two duty states (AGENTS.md § Security checklist, SECURITY.md). The
// names this file writes into a day row are read back out of the DOM, where
// the template rendered them server-side.
//
// WHY THE ROSTER ORDER IS HERE AT ALL
// ----------------------------------------------------------------------------
// A day row names the person who ACTUALLY receives the calls, which is the
// first roster member marked on call that day (module spec §2.6 — several
// people can be marked, only the first is used). The server resolves it
// through OnCallService::resolveTargetsForMonth() for the initial render;
// this file re-resolves it after an edit, from the same ordered id list, so
// the row stops being a lie the moment a state changes. Both implementations
// read the same rule off the same order — and the browser's one never decides
// anything: the month is posted whole and the server resolves it again for
// the redirection itself.
//
// Fetches ride the site-wide ScoutMagicApi envelope ({ok, status, data}),
// which also owns the CSRF token this file used to read through a local
// csrf() helper. `ok` is HTTP-level only: the business verdict of every JSON
// endpoint here is `data.success`, and the two are deliberately kept apart.
// The one non-JSON call — the transitions list, which answers with the same
// server-rendered partial as the initial page load — is a plain guarded
// fetch, because the toolbox has no HTML envelope: it now checks the HTTP
// status before writing the response into the page, where an unchecked 500
// error page used to be injected as the list itself.
(function () {
    var data = window.ScoutMagicApi.pageData('sos-admin-data') || {};

    var defaultNumberSelect = /** @type {HTMLSelectElement|null} */ (document.getElementById('default-number-member'));
    var transitionHourInput = /** @type {HTMLInputElement|null} */ (document.getElementById('transition-hour'));
    var emailToggle = /** @type {HTMLInputElement|null} */ (document.getElementById('email-notifications-toggle'));
    var transitionsList = document.getElementById('planned-transitions-list');
    var saveStatus = document.getElementById('oncall-save-status');
    var sheet = document.getElementById('sos-day-sheet');
    var dayList = document.getElementById('sos-day-list');

    // A no-op on every other page of the site: this file is a page script.
    if (!defaultNumberSelect && !transitionHourInput && !emailToggle && !transitionsList && !saveStatus) {
        return;
    }

    var api = window.ScoutMagicApi;

    /** The duty states of the displayed month, mutated as states are set. */
    /** @type {Object.<string, Object.<string, string>>} */
    var cellStates = data.states || {};
    var currentYear = data.year;
    var currentMonth = data.month;
    var monthParam = data.monthParam || '';
    /** Roster order — decides who "wins" a day several people are marked on. */
    /** @type {number[]} */
    var orderedMemberIds = data.orderedMemberIds || [];

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

    // --- Settings (each control saves itself) ---

    // This select decides which phone the unit's SOS number rings on every
    // day nobody is on duty. It used to save on `change`: a mis-click — or
    // an arrow key on a focused select — re-routed the unit's emergency
    // line with nothing to confirm and nothing to cancel. It now takes an
    // explicit press, and the button stays disabled until the value
    // actually differs from what is stored, so an accidental change is
    // visible as a button that just lit up rather than as nothing at all.
    var defaultNumberSave = /** @type {HTMLButtonElement|null} */ (
        document.getElementById('default-number-save')
    );

    if (defaultNumberSelect && defaultNumberSave) {
        var defaultNumberError = document.getElementById('default-number-error');
        var savedNumber = defaultNumberSelect.dataset.savedValue || '';

        var syncSaveState = function () {
            defaultNumberSave.disabled = defaultNumberSelect.value === savedNumber;
        };

        defaultNumberSelect.addEventListener('change', function () {
            if (defaultNumberError) {
                defaultNumberError.textContent = '';
                defaultNumberError.classList.add('d-none');
            }
            syncSaveState();
        });
        syncSaveState();

        defaultNumberSave.addEventListener('click', async function () {
            if (defaultNumberError) {
                defaultNumberError.textContent = '';
                defaultNumberError.classList.add('d-none');
            }

            var chosen = defaultNumberSelect.value;
            var res = await api.withDisabled(defaultNumberSave, function () {
                return api.postJson('/admin/sos/default-number', {
                    member_id: parseInt(chosen, 10)
                });
            });
            if (succeeded(res)) {
                savedNumber = chosen;
                syncSaveState();
                window.ScoutMagicToast.show('Numéro par défaut enregistré.', { variant: 'success' });
                return;
            }
            // The save failed, so the stored value is still the old one and
            // the button must stay pressable — re-enabling it is how the
            // admin retries.
            syncSaveState();
            // Never the member's number, only the server's own message: this
            // line is next to a control listing mobile numbers.
            if (defaultNumberError) {
                defaultNumberError.textContent = failureMessage(res);
                defaultNumberError.classList.remove('d-none');
            } else {
                toastFailure(res);
            }
        });
    }

    if (transitionHourInput) {
        transitionHourInput.addEventListener('change', async function () {
            var res = await api.withDisabled(transitionHourInput, function () {
                return api.postJson('/admin/sos/settings', { transition_hour: transitionHourInput.value });
            });
            // Both settings saves used to discard the answer entirely — a
            // refused value looked saved until the page was reloaded.
            if (succeeded(res)) {
                window.ScoutMagicToast.show('Heure de changement de garde enregistrée.', { variant: 'success' });
            } else {
                toastFailure(res);
            }
        });
    }

    if (emailToggle) {
        emailToggle.addEventListener('change', async function () {
            var res = await api.withDisabled(emailToggle, function () {
                return api.postJson('/admin/sos/settings', { email_notifications_enabled: emailToggle.checked });
            });
            if (succeeded(res)) {
                window.ScoutMagicToast.show('Préférence de notification enregistrée.', { variant: 'success' });
            } else {
                toastFailure(res);
            }
        });
    }

    // --- Duty roster (full-month auto-save on every change) ---

    /**
     * The next state in the desktop grid's click cycle: on call →
     * unavailable → nothing. The phone layout has no cycle — its three
     * buttons each name the state they set.
     *
     * @param {string|null} state
     * @returns {string|null}
     */
    function cycle(state) {
        if (state === 'oncall') {
            return 'unavailable';
        }
        if (state === 'unavailable') {
            return null;
        }
        return 'oncall';
    }

    /**
     * @param {string} date
     * @param {string} memberId
     * @returns {string|null}
     */
    function stateOf(date, memberId) {
        return (cellStates[date] && cellStates[date][memberId]) || null;
    }

    /** @param {string} text */
    function showSaveStatus(text) {
        [saveStatus, document.getElementById('sos-day-sheet-status')].forEach(function (node) {
            if (node) {
                node.textContent = text;
            }
        });
    }

    /** Saves the whole displayed month — the endpoint replaces it wholesale. */
    async function saveOnCall() {
        /** @type {Array<{member_id: number, date: string, state: string}>} */
        var cells = [];
        Object.keys(cellStates).forEach(function (date) {
            Object.keys(cellStates[date]).forEach(function (memberId) {
                cells.push({
                    member_id: parseInt(memberId, 10),
                    date: date,
                    state: cellStates[date][memberId]
                });
            });
        });

        showSaveStatus('Enregistrement…');

        var res = await api.postJson('/admin/sos/oncall', {
            year: currentYear,
            month: currentMonth,
            cells: cells
        });
        showSaveStatus(succeeded(res) ? 'Enregistré.' : ('Erreur : ' + failureMessage(res)));
        if (!succeeded(res)) {
            toastFailure(res);
        }
    }

    /**
     * Repaints every control standing for one member/day pair — the desktop
     * table cell and the phone layout's three named buttons carry the same
     * data attributes, so both are updated from one place.
     *
     * @param {string} date       ISO date (YYYY-MM-DD)
     * @param {string} memberId
     * @param {string|null} state The new state, null for "nothing planned"
     */
    function repaintCells(date, memberId, state) {
        var pair = '[data-date="' + date + '"][data-member-id="' + memberId + '"]';

        document.querySelectorAll('.sos-oncall-cell' + pair).forEach(function (node) {
            var cell = /** @type {HTMLElement} */ (node);
            cell.classList.remove('state-oncall', 'state-unavailable');
            cell.textContent = '';
            if (state === 'oncall') {
                cell.classList.add('state-oncall');
                cell.textContent = '✓';
            } else if (state === 'unavailable') {
                cell.classList.add('state-unavailable');
                cell.textContent = '✗';
            }
        });

        document.querySelectorAll('.sos-state-button' + pair).forEach(function (node) {
            var button = /** @type {HTMLElement} */ (node);
            // `data-state=""` is the "Rien" button; null is that same state.
            var pressed = (button.dataset.state || null) === state;
            button.classList.remove('btn-success', 'btn-danger', 'btn-secondary', 'btn-outline-secondary');
            if (!pressed) {
                button.classList.add('btn-outline-secondary');
            } else if (state === 'oncall') {
                button.classList.add('btn-success');
            } else if (state === 'unavailable') {
                button.classList.add('btn-danger');
            } else {
                button.classList.add('btn-secondary');
            }
            button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        });
    }

    /**
     * Who actually receives the calls on $date: the FIRST roster member
     * marked on call (module spec §2.6). Null means nobody is, and the
     * default number governs the day.
     *
     * @param {string} date
     * @returns {number|null}
     */
    function targetForDate(date) {
        for (var i = 0; i < orderedMemberIds.length; i++) {
            if (stateOf(date, String(orderedMemberIds[i])) === 'oncall') {
                return orderedMemberIds[i];
            }
        }
        return null;
    }

    /**
     * How many people are marked on call on $date — one is the normal case,
     * more than one is what the row flags, since the extra marks change
     * nothing.
     *
     * @param {string} date
     * @returns {number}
     */
    function onCallCountForDate(date) {
        var states = cellStates[date] || {};
        return Object.keys(states).filter(function (memberId) {
            return states[memberId] === 'oncall';
        }).length;
    }

    /**
     * A member's display name, read back out of the day sheet where the
     * template rendered it server-side. Never out of the JSON island: that
     * payload carries ids only, deliberately.
     *
     * @param {number} memberId
     * @returns {string|null}
     */
    function memberNameFor(memberId) {
        if (!sheet) {
            return null;
        }
        var block = sheet.querySelector('[data-sheet-member-id="' + memberId + '"] [data-member-name]');
        return block ? block.textContent.trim() : null;
    }

    /**
     * Re-labels one day row after its states changed: who receives the
     * calls, and whether several people are marked for nothing.
     *
     * @param {string} date
     */
    function refreshDayRow(date) {
        if (!dayList) {
            return;
        }
        var row = dayList.querySelector('.sos-day-row[data-date="' + date + '"]');
        if (!row) {
            return;
        }

        var target = targetForDate(date);
        var name = target === null ? null : memberNameFor(target);
        var label = /** @type {HTMLElement|null} */ (row.querySelector('[data-day-target]'));
        if (label) {
            label.textContent = name === null ? (dayList.dataset.defaultTarget || '') : name;
            label.classList.toggle('text-body-secondary', name === null);
        }

        var flag = /** @type {HTMLElement|null} */ (row.querySelector('[data-day-multiple]'));
        if (flag) {
            flag.classList.toggle('d-none', onCallCountForDate(date) <= 1);
        }
    }

    /**
     * Records one member/day state, repaints every rendering of it, keeps
     * the day row honest, and saves the month. The endpoint replaces the
     * whole month on every call (module spec §2.6) — there is no per-cell
     * delta, and this is deliberately not batched.
     *
     * @param {string} date
     * @param {string} memberId
     * @param {string|null} state
     */
    function applyState(date, memberId, state) {
        if (!cellStates[date]) {
            cellStates[date] = {};
        }
        if (state === null) {
            delete cellStates[date][memberId];
        } else {
            cellStates[date][memberId] = state;
        }

        repaintCells(date, memberId, state);
        refreshDayRow(date);
        saveOnCall();
    }

    // Desktop grid: one cell, three states, cycled by clicking.
    document.querySelectorAll('.sos-oncall-cell').forEach(function (node) {
        var cell = /** @type {HTMLElement} */ (node);
        cell.addEventListener('click', function () {
            var date = cell.dataset.date || '';
            var memberId = cell.dataset.memberId || '';
            if (!date || !memberId) {
                return;
            }
            applyState(date, memberId, cycle(stateOf(date, memberId)));
        });
    });

    // Phone layout: three named buttons, each setting its own state. Bound
    // directly rather than by delegation — every one of them is on the page
    // at load (the sheet renders the roster once for the whole month, the
    // « Ma disponibilité » rows are server-rendered), so nothing appears
    // later that a delegated listener would be needed for.
    document.querySelectorAll('.sos-state-button').forEach(function (node) {
        var button = /** @type {HTMLElement} */ (node);
        button.addEventListener('click', function () {
            // Read at click time, never at bind time: the sheet's buttons
            // are stamped with a new `data-date` each time a day is opened.
            var date = button.dataset.date || '';
            var memberId = button.dataset.memberId || '';
            if (!date || !memberId) {
                return;
            }
            applyState(date, memberId, button.dataset.state || null);
        });
    });

    // --- The day sheet (one for the whole month) ---

    /**
     * Fills the sheet with one day and opens it. Stamping `data-date` on
     * every state button is what scopes repaintCells() to the open day —
     * the roster inside the sheet is rendered once, not once per day, which
     * is the whole reason this screen stopped being ~250 buttons long.
     *
     * @param {HTMLElement} row the tapped day row
     */
    function openDaySheet(row) {
        if (!sheet) {
            return;
        }
        var date = row.dataset.date || '';
        if (!date) {
            return;
        }

        sheet.dataset.date = date;

        var title = document.getElementById('sos-day-sheet-title');
        if (title) {
            title.textContent = row.dataset.dateLabel || date;
        }

        var activity = document.getElementById('sos-day-sheet-activity');
        if (activity) {
            var text = row.dataset.activity || '';
            activity.textContent = text;
            activity.classList.toggle('d-none', text === '');
        }

        var status = document.getElementById('sos-day-sheet-status');
        if (status) {
            status.textContent = '';
        }

        sheet.querySelectorAll('.sos-state-button').forEach(function (node) {
            /** @type {HTMLElement} */ (node).dataset.date = date;
        });
        sheet.querySelectorAll('[data-sheet-member-id]').forEach(function (node) {
            var memberId = /** @type {HTMLElement} */ (node).dataset.sheetMemberId || '';
            repaintCells(date, memberId, stateOf(date, memberId));
        });

        // Guarded because Bootstrap's bundle is a separate <script>: with it
        // absent (an isolated test, a blocked asset) the sheet's content is
        // still correct and the page still saves — only the animation is
        // missing.
        if (typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
            bootstrap.Offcanvas.getOrCreateInstance(sheet).show();
        }
    }

    if (dayList) {
        dayList.addEventListener('click', function (e) {
            var target = /** @type {HTMLElement|null} */ (e.target);
            var row = target ? /** @type {HTMLElement|null} */ (target.closest('.sos-day-row')) : null;
            if (row) {
                openDaySheet(row);
            }
        });
    }

    // --- Planned redirections list (AJAX pagination, no page reload) ---

    /**
     * Replaces the list with one page of it. The endpoint answers with the
     * same server-rendered partial as the initial page load, so this is the
     * one call here that is HTML rather than JSON — hence a raw fetch, with
     * the status check the original was missing (an error page used to be
     * written into the list as if it were the list).
     *
     * @param {number} page
     * @returns {Promise<void>}
     */
    async function loadTransitionsPage(page) {
        if (!transitionsList) {
            return;
        }
        var params = new URLSearchParams({ month: monthParam, transitions_page: String(page) });

        try {
            var res = await fetch('/admin/sos/transitions?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok) {
                window.ScoutMagicToast.show('Erreur : réponse serveur invalide.', { variant: 'error' });
                return;
            }
            transitionsList.innerHTML = await res.text();
        } catch (err) {
            window.ScoutMagicToast.show('Erreur : réponse serveur invalide.', { variant: 'error' });
        }
    }

    if (transitionsList) {
        transitionsList.addEventListener('click', function (e) {
            var target = /** @type {HTMLElement|null} */ (e.target);
            var link = target ? /** @type {HTMLElement|null} */ (target.closest('[data-transitions-page]')) : null;
            if (!link) {
                return;
            }
            e.preventDefault();

            var page = parseInt(link.dataset.transitionsPage || '', 10);
            if (!Number.isFinite(page) || page < 1) {
                return;
            }
            loadTransitionsPage(page);
        });
    }
})();
