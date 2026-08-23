/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// SOS Staff d'U admin page (modules/sos_staff/views/admin.html.twig): the
// default-number / transition-hour / email-notification settings, the
// three-state duty grid (desktop table and mobile card list share the same
// cells), and the AJAX pagination of the planned-redirections list.
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/sos-admin.test.js) — an
// inline template script is invisible to both Vitest and `npm run typecheck`.
//
// The month being displayed and the duty states it starts from come from the
// server through the `sos-admin-data` JSON island the template renders,
// read with ScoutMagicApi.pageData() — the site-wide server-data-to-a-page
// pattern. Nothing in that payload is a phone number: it is dates, member ids
// and the two duty states (AGENTS.md § Security checklist, SECURITY.md — the
// members' mobile numbers this page shows are rendered server-side and stay
// there).
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
//
// Note for whoever revisits this page: « Numéro par défaut » is a <select>
// whose change event saves immediately, so moving through its options with a
// keyboard rewrites who receives the unit's SOS calls on unassigned days.
// That is the exact shape of the llm_connector bug where browsing the
// provider list switched the active sub-processor. It is left as-is here on
// purpose — changing it is a product decision, not a refactor.
(function () {
    var data = window.ScoutMagicApi.pageData('sos-admin-data') || {};

    var defaultNumberSelect = /** @type {HTMLSelectElement|null} */ (document.getElementById('default-number-member'));
    var transitionHourInput = /** @type {HTMLInputElement|null} */ (document.getElementById('transition-hour'));
    var emailToggle = /** @type {HTMLInputElement|null} */ (document.getElementById('email-notifications-toggle'));
    var transitionsList = document.getElementById('planned-transitions-list');
    var saveStatus = document.getElementById('oncall-save-status');

    // A no-op on every other page of the site: this file is a page script.
    if (!defaultNumberSelect && !transitionHourInput && !emailToggle && !transitionsList && !saveStatus) {
        return;
    }

    var api = window.ScoutMagicApi;

    /** The duty states of the displayed month, mutated as cells are clicked. */
    /** @type {Object.<string, Object.<string, string>>} */
    var cellStates = data.states || {};
    var currentYear = data.year;
    var currentMonth = data.month;
    var monthParam = data.monthParam || '';

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

    // --- Duty grid (3-state click-cycle, full-month auto-save) ---

    /**
     * The next state in the click cycle: on call → unavailable → nothing.
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

        if (saveStatus) {
            saveStatus.textContent = 'Enregistrement…';
        }

        var res = await api.postJson('/admin/sos/oncall', {
            year: currentYear,
            month: currentMonth,
            cells: cells
        });
        if (saveStatus) {
            saveStatus.textContent = succeeded(res) ? 'Enregistré.' : ('Erreur : ' + failureMessage(res));
        }
        if (!succeeded(res)) {
            toastFailure(res);
        }
    }

    /**
     * Repaints every control standing for one member/day pair — the desktop
     * table cell and the mobile button carry the same data attributes and the
     * same class, so both are updated from one place.
     *
     * @param {string} date       ISO date (YYYY-MM-DD)
     * @param {string} memberId
     * @param {string|null} state The new state, null for "nothing planned"
     */
    function repaintCells(date, memberId, state) {
        var selector = '.sos-oncall-cell[data-date="' + date + '"][data-member-id="' + memberId + '"]';
        document.querySelectorAll(selector).forEach(function (node) {
            var el = /** @type {HTMLElement} */ (node);
            el.classList.remove('state-oncall', 'state-unavailable', 'btn-success', 'btn-danger', 'btn-outline-secondary');

            if (el.tagName === 'TD') {
                el.textContent = '';
                if (state === 'oncall') {
                    el.classList.add('state-oncall');
                    el.textContent = '✓';
                } else if (state === 'unavailable') {
                    el.classList.add('state-unavailable');
                    el.textContent = '✗';
                }
                return;
            }

            // Mobile button: a first span holds the member's short name, the
            // last one holds the state glyph.
            var label = el.querySelector('.d-block');
            var stateSpan = el.lastElementChild !== label ? el.lastElementChild : null;
            if (state === 'oncall') {
                el.classList.add('btn-success');
                if (stateSpan) {
                    stateSpan.textContent = '✓';
                }
            } else if (state === 'unavailable') {
                el.classList.add('btn-danger');
                if (stateSpan) {
                    stateSpan.textContent = '✗';
                }
            } else {
                el.classList.add('btn-outline-secondary');
                if (stateSpan) {
                    stateSpan.textContent = '—';
                }
            }
        });
    }

    document.querySelectorAll('.sos-oncall-cell').forEach(function (node) {
        var cell = /** @type {HTMLElement} */ (node);
        cell.addEventListener('click', function () {
            var date = cell.dataset.date || '';
            var memberId = cell.dataset.memberId || '';
            if (!date || !memberId) {
                return;
            }

            var current = (cellStates[date] && cellStates[date][memberId]) || null;
            var next = cycle(current);

            if (!cellStates[date]) {
                cellStates[date] = {};
            }
            if (next === null) {
                delete cellStates[date][memberId];
            } else {
                cellStates[date][memberId] = next;
            }

            repaintCells(date, memberId, next);
            saveOnCall();
        });
    });

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
