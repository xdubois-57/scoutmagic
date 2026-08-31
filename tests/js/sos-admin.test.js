// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch is mocked, and so are the two site-wide dialog
// toolboxes (window.ScoutMagicToast, window.ScoutMagicConfirm) base.html.twig
// loads on every page. Exercises the REAL implementation in
// public/assets/js/sos-admin.js (imported below, never reimplemented here),
// on top of the real fetch toolbox public/assets/js/api.js — base.html.twig
// guarantees that load order in production.
//
// The page under test is the on-call planning grid
// (modules/sos_staff/views/admin.html.twig), which decides which Staff d'U
// member the unit's SOS number rings. What this file pins down:
//   - the month, its starting duty states and the ROSTER ORDER come from the
//     page's JSON island, not from an inline script Vitest cannot see;
//   - the desktop grid's three-state click cycle and the phone layout's three
//     NAMED buttons write the same state, repaint each other, and save the
//     whole month with the CSRF token;
//   - the day sheet is filled from the row that was tapped — one sheet for the
//     whole month, stamped with the open day, which is what replaced ~250
//     stacked buttons;
//   - a day row keeps naming whoever actually receives the calls after an
//     edit: the first roster member marked on call (module spec §2.6), the
//     server-rendered default sentence when nobody is, and a flag when several
//     are marked for nothing;
//   - a {success:false} body and an HTTP 500 that is not JSON are both
//     failures, never a silent "Enregistré.";
//   - a server message containing markup is shown as text;
//   - the transitions pagination never writes a failed response into the page.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Installs a page's server data the way the template does — a
 * `<script type="application/json">` island — rather than an inline
 * assignment to a window global. ScoutMagicApi.pageData() reads it.
 */
function installIsland(id, data) {
    document.getElementById(id)?.remove();
    if (data === undefined) {
        return;
    }
    const el = document.createElement('script');
    el.type = 'application/json';
    el.id = id;
    el.textContent = JSON.stringify(data);
    document.body.appendChild(el);
}

const STATES = {
    '2026-03-01': { 4: 'oncall' },
    '2026-03-02': { 7: 'unavailable' },
};

const TRANSITIONS_HTML = '<ul class="list-group"><li>page 2</li></ul>'
    + '<nav><a href="#" data-transitions-page="3">3</a></nav>';

/**
 * The page as the template renders it on a phone-sized screen, cut down to
 * what this file exercises: the settings accordion's controls, two day rows,
 * the single day sheet holding the whole roster, and the desktop table (both
 * layouts always ship — only CSS hides one).
 */
const PAGE_DOM = `
    <select id="default-number-member" data-saved-value="4">
        <option value="4" selected>Alice D. (+32 470 00 00 01)</option>
        <option value="7">Bruno T. (+32 470 00 00 02)</option>
    </select>
    <button type="button" id="default-number-save" disabled>Enregistrer</button>
    <div class="text-danger small mt-1 d-none" id="default-number-error" role="alert"></div>
    <input type="time" id="transition-hour" value="18:00">
    <input class="form-check-input" type="checkbox" id="email-notifications-toggle" checked>

    <div class="list-group" id="sos-day-list" data-default-target="Par défaut — Alice D.">
        <button type="button" class="list-group-item list-group-item-action sos-day-row"
                data-date="2026-03-01" data-date-label="dimanche 1 mars 2026" data-activity="Fête des Baladins">
            <span>dim 1</span>
            <span data-day-target>Alice D.</span>
            <i class="bi bi-people d-none" data-day-multiple></i>
        </button>
        <button type="button" class="list-group-item list-group-item-action sos-day-row"
                data-date="2026-03-02" data-date-label="lundi 2 mars 2026" data-activity="">
            <span>lun 2</span>
            <span class="text-body-secondary" data-day-target>Par défaut — Alice D.</span>
            <i class="bi bi-people d-none" data-day-multiple></i>
        </button>
    </div>

    <div class="offcanvas offcanvas-bottom" id="sos-day-sheet" data-date="">
        <h2 id="sos-day-sheet-title">Journée</h2>
        <p class="d-none" id="sos-day-sheet-activity"></p>
        <div data-sheet-member-id="4">
            <div data-member-name>Alice D.</div>
            <div class="btn-group">
                <button type="button" class="btn btn-sm sos-state-button btn-outline-secondary"
                        data-member-id="4" data-date="" data-state="oncall" aria-pressed="false">Garde</button>
                <button type="button" class="btn btn-sm sos-state-button btn-outline-secondary"
                        data-member-id="4" data-date="" data-state="unavailable" aria-pressed="false">Indispo</button>
                <button type="button" class="btn btn-sm sos-state-button btn-secondary"
                        data-member-id="4" data-date="" data-state="" aria-pressed="true">Rien</button>
            </div>
        </div>
        <div data-sheet-member-id="7">
            <div data-member-name>Bruno T.</div>
            <div class="btn-group">
                <button type="button" class="btn btn-sm sos-state-button btn-outline-secondary"
                        data-member-id="7" data-date="" data-state="oncall" aria-pressed="false">Garde</button>
                <button type="button" class="btn btn-sm sos-state-button btn-outline-secondary"
                        data-member-id="7" data-date="" data-state="unavailable" aria-pressed="false">Indispo</button>
                <button type="button" class="btn btn-sm sos-state-button btn-secondary"
                        data-member-id="7" data-date="" data-state="" aria-pressed="true">Rien</button>
            </div>
        </div>
        <div id="sos-day-sheet-status"></div>
    </div>

    <table class="sos-grid"><tbody>
        <tr>
            <td class="sos-day-label">dim 1</td>
            <td class="sos-oncall-cell state-oncall" data-member-id="4" data-date="2026-03-01">✓</td>
            <td class="sos-oncall-cell" data-member-id="7" data-date="2026-03-01"></td>
        </tr>
    </tbody></table>
    <div id="oncall-save-status" class="small text-body-secondary mb-4"></div>

    <div id="planned-transitions-list">
        <ul class="list-group"><li>page 1</li></ul>
        <nav><a href="#" data-transitions-page="2">2</a></nav>
    </div>`;

/** The site-wide envelope for a JSON answer the server really sent. */
function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

/** A 500 that is an HTML error page, not JSON — the shape api.js folds to data:null. */
function htmlErrorResponse() {
    return Promise.resolve({
        ok: false,
        status: 500,
        json: () => Promise.reject(new Error('not json')),
        text: () => Promise.resolve('<html>Erreur 500</html>'),
    });
}

/**
 * A fetch double: the transitions endpoint answers HTML, everything else
 * answers JSON from a map of bodies (defaulting to {success:true}).
 *
 * @param {Object.<string, any>} bodiesByUrlFragment
 */
function mockFetch(bodiesByUrlFragment) {
    return vi.fn((url) => {
        if (String(url).includes('/admin/sos/transitions')) {
            return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve(TRANSITIONS_HTML) });
        }
        const match = Object.keys(bodiesByUrlFragment).find((fragment) => String(url).includes(fragment));
        return jsonResponse(match ? bodiesByUrlFragment[match] : { success: true });
    });
}

function lastRequest() {
    const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
    return { url, opts, body: opts && opts.body ? JSON.parse(opts.body) : null };
}

/** @param {string} selector */
function clickCell(selector) {
    document.querySelector(selector).dispatchEvent(new Event('click', { bubbles: true }));
}

/**
 * Presses one of the phone layout's three named state buttons.
 *
 * @param {string} memberId
 * @param {string} state 'oncall' | 'unavailable' | '' (the « Rien » button)
 */
function pressStateButton(memberId, state) {
    document
        .querySelector(`#sos-day-sheet .sos-state-button[data-member-id="${memberId}"][data-state="${state}"]`)
        .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
}

/** Taps a day row, which fills and opens the single day sheet. */
function openDay(date) {
    document
        .querySelector(`.sos-day-row[data-date="${date}"]`)
        .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
}

/** @param {string} date @param {string} memberId */
function sheetButton(date, memberId, state) {
    return document.querySelector(
        `#sos-day-sheet .sos-state-button[data-member-id="${memberId}"][data-state="${state}"]`,
    );
}

/** @param {string} date */
function dayRow(date) {
    return document.querySelector(`.sos-day-row[data-date="${date}"]`);
}

function changeControl(id) {
    document.getElementById(id).dispatchEvent(new Event('change', { bubbles: true }));
}

/**
 * Picks a member and presses « Enregistrer » — what saving the default
 * number takes since it stopped saving on `change`.
 *
 * @param {string} memberId
 */
function chooseAndSave(memberId) {
    /** @type {HTMLSelectElement} */ (document.getElementById('default-number-member')).value = memberId;
    changeControl('default-number-member');
    document.getElementById('default-number-save').dispatchEvent(new Event('click', { bubbles: true }));
}

describe('sos-admin.js', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE_DOM;
        installIsland('sos-admin-data', {
            year: 2026,
            month: 3,
            monthParam: '2026-03',
            states: structuredClone(STATES),
            orderedMemberIds: [4, 7],
        });
        global.fetch = mockFetch({});
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/sos-admin.js');
    }

    describe('entry guard', () => {
        it('does nothing at all on a page carrying none of its controls', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('survives a page with no server data at all', async () => {
            installIsland('sos-admin-data', undefined);
            document.body.innerHTML = '<div id="oncall-save-status"></div>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('default number', () => {
        it('POSTs the chosen member and the CSRF token', async () => {
            await boot();
            chooseAndSave('7');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const { url, opts, body } = lastRequest();
            expect(url).toBe('/admin/sos/default-number');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({ member_id: 7, _csrf_token: 'tok-123' });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
        });

        it('confirms the save — this select decides who the SOS number rings', async () => {
            await boot();

            chooseAndSave('7');
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                'Numéro par défaut enregistré.',
                { variant: 'success' },
            );
        });

        it('never puts the member id or a number in the URL it builds', async () => {
            await boot();

            chooseAndSave('7');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            expect(String(fetch.mock.calls[0][0])).toBe('/admin/sos/default-number');
        });

        it('shows the server error inline', async () => {
            global.fetch = mockFetch({ '/default-number': { success: false, error: 'Membre invalide.' } });
            await boot();

            chooseAndSave('7');
            await vi.waitFor(() => {
                expect(document.getElementById('default-number-error').textContent).toBe('Membre invalide.');
            });
            expect(document.getElementById('default-number-error').classList.contains('d-none')).toBe(false);
        });

        it('renders a server message containing markup as text, not as HTML', async () => {
            global.fetch = mockFetch({
                '/default-number': { success: false, error: '<img src=x onerror=alert(1)> invalide' },
            });
            await boot();

            chooseAndSave('7');
            await vi.waitFor(() => {
                expect(document.getElementById('default-number-error').textContent)
                    .toContain('<img src=x onerror=alert(1)>');
            });
            expect(document.querySelector('#default-number-error img')).toBeNull();
        });

        it('treats an HTTP 500 error page as a failure, not a success', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();

            chooseAndSave('7');
            await vi.waitFor(() => {
                expect(document.getElementById('default-number-error').textContent)
                    .toBe('Erreur : réponse serveur invalide.');
            });
        });
    });

    describe('the default number takes an explicit press', () => {
        it('saves nothing when the selection merely changes', async () => {
            // It used to save on `change`: a mis-click, or an arrow key on
            // a focused select, re-routed the unit's emergency line with
            // nothing to confirm and nothing to cancel.
            await boot();
            /** @type {HTMLSelectElement} */ (document.getElementById('default-number-member')).value = '7';

            changeControl('default-number-member');
            await new Promise((resolve) => setTimeout(resolve, 0));

            expect(fetch).not.toHaveBeenCalled();
        });

        it('offers the button only once the value actually differs', async () => {
            await boot();
            const save = /** @type {HTMLButtonElement} */ (document.getElementById('default-number-save'));

            // As rendered, the select already shows what is stored.
            expect(save.disabled).toBe(true);

            /** @type {HTMLSelectElement} */ (document.getElementById('default-number-member')).value = '7';
            changeControl('default-number-member');
            expect(save.disabled).toBe(false);

            // Back to the stored value: there is nothing to save again.
            /** @type {HTMLSelectElement} */ (document.getElementById('default-number-member')).value = '4';
            changeControl('default-number-member');
            expect(save.disabled).toBe(true);
        });

        it('settles once saved, so a second press cannot repeat it', async () => {
            await boot();
            chooseAndSave('7');
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

            expect(/** @type {HTMLButtonElement} */ (document.getElementById('default-number-save')).disabled)
                .toBe(true);
        });

        it('stays pressable after a failure, because the retry is the button', async () => {
            global.fetch = mockFetch({ '/default-number': { success: false, error: 'Membre invalide.' } });
            await boot();
            chooseAndSave('7');
            await vi.waitFor(() => {
                expect(document.getElementById('default-number-error').textContent).toBe('Membre invalide.');
            });

            expect(/** @type {HTMLButtonElement} */ (document.getElementById('default-number-save')).disabled)
                .toBe(false);
        });

        it('clears a previous error the moment the selection changes again', async () => {
            global.fetch = mockFetch({ '/default-number': { success: false, error: 'Membre invalide.' } });
            await boot();
            chooseAndSave('7');
            await vi.waitFor(() => {
                expect(document.getElementById('default-number-error').classList.contains('d-none')).toBe(false);
            });

            /** @type {HTMLSelectElement} */ (document.getElementById('default-number-member')).value = '4';
            changeControl('default-number-member');

            expect(document.getElementById('default-number-error').textContent).toBe('');
            expect(document.getElementById('default-number-error').classList.contains('d-none')).toBe(true);
        });
    });

    describe('settings that used to save in complete silence', () => {
        it('POSTs the transition hour and confirms the save', async () => {
            await boot();
            document.getElementById('transition-hour').value = '19:30';

            changeControl('transition-hour');
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

            const { url, body } = lastRequest();
            expect(url).toBe('/admin/sos/settings');
            expect(body).toEqual({ transition_hour: '19:30', _csrf_token: 'tok-123' });
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Heure de changement de garde enregistrée.', { variant: 'success' });
        });

        it('toasts a refused transition hour instead of looking saved', async () => {
            global.fetch = mockFetch({ '/settings': { success: false, error: 'Heure invalide.' } });
            await boot();

            changeControl('transition-hour');
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Heure invalide.', { variant: 'error' });
        });

        it('POSTs the email-notification switch state', async () => {
            await boot();
            document.getElementById('email-notifications-toggle').checked = false;

            changeControl('email-notifications-toggle');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            expect(lastRequest().body).toEqual({ email_notifications_enabled: false, _csrf_token: 'tok-123' });
        });

        it('reports an HTTP 500 error page on a settings save', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();

            changeControl('email-notifications-toggle');
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur : réponse serveur invalide.', { variant: 'error' });
        });
    });

    describe('duty grid', () => {
        it('cycles an empty cell to « de garde » and saves the whole month', async () => {
            await boot();

            clickCell('td.sos-oncall-cell[data-member-id="7"]');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const { url, body } = lastRequest();
            expect(url).toBe('/admin/sos/oncall');
            expect(body.year).toBe(2026);
            expect(body.month).toBe(3);
            expect(body._csrf_token).toBe('tok-123');
            expect(body.cells).toEqual(
                expect.arrayContaining([
                    { member_id: 4, date: '2026-03-01', state: 'oncall' },
                    { member_id: 7, date: '2026-03-02', state: 'unavailable' },
                    { member_id: 7, date: '2026-03-01', state: 'oncall' },
                ]),
            );
            expect(body.cells).toHaveLength(3);
        });

        it('repaints the desktop cell and the open sheet together', async () => {
            await boot();
            // The sheet only stands for a day once one has been opened —
            // that is what stamps `data-date` on its buttons.
            openDay('2026-03-01');

            clickCell('td.sos-oncall-cell[data-member-id="7"]');

            const desktop = document.querySelector('td.sos-oncall-cell[data-member-id="7"]');
            expect(desktop.classList.contains('state-oncall')).toBe(true);
            expect(desktop.textContent).toBe('✓');

            const garde = sheetButton('2026-03-01', '7', 'oncall');
            expect(garde.classList.contains('btn-success')).toBe(true);
            expect(garde.getAttribute('aria-pressed')).toBe('true');
            expect(sheetButton('2026-03-01', '7', '').getAttribute('aria-pressed')).toBe('false');
        });

        it('cycles « de garde » → « indisponible » → rien, dropping the cell from the payload', async () => {
            await boot();

            clickCell('td.sos-oncall-cell[data-member-id="4"]');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
            expect(lastRequest().body.cells)
                .toContainEqual({ member_id: 4, date: '2026-03-01', state: 'unavailable' });
            expect(document.querySelector('td.sos-oncall-cell[data-member-id="4"]').textContent).toBe('✗');

            clickCell('td.sos-oncall-cell[data-member-id="4"]');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
            expect(lastRequest().body.cells).toEqual([{ member_id: 7, date: '2026-03-02', state: 'unavailable' }]);

            const desktop = document.querySelector('td.sos-oncall-cell[data-member-id="4"]');
            expect(desktop.textContent).toBe('');
            expect(desktop.classList.contains('state-oncall')).toBe(false);
            expect(desktop.classList.contains('state-unavailable')).toBe(false);
        });

        it('says « Enregistré. » only on a business success', async () => {
            await boot();

            clickCell('td.sos-oncall-cell[data-member-id="7"]');
            await vi.waitFor(() => {
                expect(document.getElementById('oncall-save-status').textContent).toBe('Enregistré.');
            });
        });

        it('reports a {success:false} save as an error and toasts it', async () => {
            global.fetch = mockFetch({ '/oncall': { success: false, error: 'Mois invalide.' } });
            await boot();

            clickCell('td.sos-oncall-cell[data-member-id="7"]');
            await vi.waitFor(() => {
                expect(document.getElementById('oncall-save-status').textContent).toBe('Erreur : Mois invalide.');
            });
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Mois invalide.', { variant: 'error' });
        });

        it('reports an HTTP 500 error page instead of reading it as saved', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();

            clickCell('td.sos-oncall-cell[data-member-id="7"]');
            await vi.waitFor(() => {
                expect(document.getElementById('oncall-save-status').textContent)
                    .toBe('Erreur : Erreur : réponse serveur invalide.');
            });
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur : réponse serveur invalide.', { variant: 'error' });
        });
    });

    describe('the day sheet — one sheet for the whole month', () => {
        it('fills itself from the row that was tapped and stamps the open day on every button', async () => {
            await boot();

            openDay('2026-03-01');

            expect(document.getElementById('sos-day-sheet-title').textContent)
                .toBe('dimanche 1 mars 2026');
            expect(document.getElementById('sos-day-sheet').dataset.date).toBe('2026-03-01');
            document.querySelectorAll('#sos-day-sheet .sos-state-button').forEach((button) => {
                expect(button.dataset.date).toBe('2026-03-01');
            });
        });

        it('shows the day\'s activity, and hides the line when there is none', async () => {
            await boot();
            const activity = document.getElementById('sos-day-sheet-activity');

            openDay('2026-03-01');
            expect(activity.textContent).toBe('Fête des Baladins');
            expect(activity.classList.contains('d-none')).toBe(false);

            openDay('2026-03-02');
            expect(activity.textContent).toBe('');
            expect(activity.classList.contains('d-none')).toBe(true);
        });

        it('shows each member\'s state for the day it was opened on', async () => {
            await boot();

            // 2026-03-01: Alice (4) on call, Bruno (7) unmarked.
            openDay('2026-03-01');
            expect(sheetButton('2026-03-01', '4', 'oncall').classList.contains('btn-success')).toBe(true);
            expect(sheetButton('2026-03-01', '7', '').classList.contains('btn-secondary')).toBe(true);

            // 2026-03-02: Bruno unavailable, Alice unmarked. Same buttons.
            openDay('2026-03-02');
            expect(sheetButton('2026-03-02', '7', 'unavailable').classList.contains('btn-danger')).toBe(true);
            expect(sheetButton('2026-03-02', '4', '').classList.contains('btn-secondary')).toBe(true);
        });

        it('does nothing at all on a row carrying no date', async () => {
            await boot();
            const row = dayRow('2026-03-01');
            row.removeAttribute('data-date');

            row.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

            expect(document.getElementById('sos-day-sheet').dataset.date).toBe('');
        });
    });

    describe('the three named state buttons', () => {
        it('sets « Garde » outright, and saves the whole month', async () => {
            await boot();
            openDay('2026-03-02');

            pressStateButton('7', 'oncall');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const { url, body } = lastRequest();
            expect(url).toBe('/admin/sos/oncall');
            expect(body._csrf_token).toBe('tok-123');
            expect(body.cells).toContainEqual({ member_id: 7, date: '2026-03-02', state: 'oncall' });
        });

        it('sets « Indispo » without having to pass through « Garde » first', async () => {
            // The button this replaced cycled ✓ → ✗ → —, so reaching one
            // state meant pressing through the others (and saving each).
            await boot();
            openDay('2026-03-02');

            pressStateButton('4', 'unavailable');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));

            expect(lastRequest().body.cells)
                .toContainEqual({ member_id: 4, date: '2026-03-02', state: 'unavailable' });
        });

        it('« Rien » drops the pair from the posted month', async () => {
            await boot();
            openDay('2026-03-01');

            pressStateButton('4', '');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            expect(lastRequest().body.cells)
                .toEqual([{ member_id: 7, date: '2026-03-02', state: 'unavailable' }]);
            expect(sheetButton('2026-03-01', '4', '').getAttribute('aria-pressed')).toBe('true');
        });

        it('mirrors the change onto the desktop cell', async () => {
            await boot();
            openDay('2026-03-01');

            pressStateButton('7', 'unavailable');

            const desktop = document.querySelector('td.sos-oncall-cell[data-member-id="7"]');
            expect(desktop.classList.contains('state-unavailable')).toBe(true);
            expect(desktop.textContent).toBe('✗');
        });

        it('reports the save in the sheet as well as under the grid', async () => {
            await boot();
            openDay('2026-03-01');

            pressStateButton('7', 'oncall');
            await vi.waitFor(() => {
                expect(document.getElementById('sos-day-sheet-status').textContent).toBe('Enregistré.');
            });
            expect(document.getElementById('oncall-save-status').textContent).toBe('Enregistré.');
        });
    });

    describe('a day row keeps naming who really receives the calls', () => {
        it('names the first roster member marked on call, not the first one clicked', async () => {
            // Roster order is [4, 7]. Marking Bruno (7) on a day Alice (4)
            // already holds must not rename the row: module spec §2.6 uses
            // the FIRST roster member marked, and the extra mark changes
            // nothing.
            await boot();
            openDay('2026-03-01');

            pressStateButton('7', 'oncall');

            expect(dayRow('2026-03-01').querySelector('[data-day-target]').textContent).toBe('Alice D.');
        });

        it('flags a day several people are marked on', async () => {
            await boot();
            const flag = dayRow('2026-03-01').querySelector('[data-day-multiple]');
            expect(flag.classList.contains('d-none')).toBe(true);

            openDay('2026-03-01');
            pressStateButton('7', 'oncall');

            expect(flag.classList.contains('d-none')).toBe(false);
        });

        it('renames the row when the winner changes', async () => {
            await boot();
            openDay('2026-03-02');

            pressStateButton('7', 'oncall');

            const target = dayRow('2026-03-02').querySelector('[data-day-target]');
            expect(target.textContent).toBe('Bruno T.');
            expect(target.classList.contains('text-body-secondary')).toBe(false);
        });

        it('falls back to the server-rendered default sentence when nobody is left on call', async () => {
            await boot();
            openDay('2026-03-01');

            pressStateButton('4', '');

            const target = dayRow('2026-03-01').querySelector('[data-day-target]');
            expect(target.textContent).toBe('Par défaut — Alice D.');
            expect(target.classList.contains('text-body-secondary')).toBe(true);
        });
    });

    describe('« Ma disponibilité » — the same buttons, no sheet', () => {
        /** The tab as the template renders it: one row per day, one member. */
        const MY_TAB_DOM = `
            <div class="list-group" id="sos-my-availability">
                <div class="list-group-item" id="sos-day-2026-03-01">
                    <span>dim 1</span>
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm sos-state-button btn-success"
                                data-member-id="4" data-date="2026-03-01" data-state="oncall"
                                aria-pressed="true">Garde</button>
                        <button type="button" class="btn btn-sm sos-state-button btn-outline-secondary"
                                data-member-id="4" data-date="2026-03-01" data-state="unavailable"
                                aria-pressed="false">Indispo</button>
                        <button type="button" class="btn btn-sm sos-state-button btn-outline-secondary"
                                data-member-id="4" data-date="2026-03-01" data-state=""
                                aria-pressed="false">Rien</button>
                    </div>
                </div>
            </div>
            <div id="oncall-save-status"></div>`;

        it('saves the month from a row that never opens a sheet', async () => {
            document.body.innerHTML = MY_TAB_DOM;
            await boot();

            document
                .querySelector('.sos-state-button[data-state="unavailable"]')
                .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const { url, body } = lastRequest();
            expect(url).toBe('/admin/sos/oncall');
            expect(body.cells).toContainEqual({ member_id: 4, date: '2026-03-01', state: 'unavailable' });
            expect(document.querySelector('.sos-state-button[data-state="unavailable"]').classList
                .contains('btn-danger')).toBe(true);
            expect(document.getElementById('oncall-save-status').textContent).toBe('Enregistrement…');
        });
    });

    describe('planned redirections pagination', () => {
        it('GETs the requested page for the displayed month and swaps the list in', async () => {
            await boot();

            document.querySelector('[data-transitions-page="2"]').dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );
            await vi.waitFor(() => {
                expect(document.getElementById('planned-transitions-list').textContent).toContain('page 2');
            });

            const url = String(fetch.mock.calls[0][0]);
            expect(url).toContain('/admin/sos/transitions?');
            expect(url).toContain('month=2026-03');
            expect(url).toContain('transitions_page=2');
        });

        it('keeps working on the links the new page brought in', async () => {
            await boot();

            document.querySelector('[data-transitions-page="2"]').dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );
            await vi.waitFor(() => {
                expect(document.querySelector('[data-transitions-page="3"]')).not.toBeNull();
            });

            document.querySelector('[data-transitions-page="3"]').dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
            expect(String(fetch.mock.calls[1][0])).toContain('transitions_page=3');
        });

        it('never writes a failed response into the list', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();

            document.querySelector('[data-transitions-page="2"]').dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

            expect(document.getElementById('planned-transitions-list').textContent).toContain('page 1');
            expect(document.getElementById('planned-transitions-list').textContent).not.toContain('Erreur 500');
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur : réponse serveur invalide.', { variant: 'error' });
        });

        it('reports a network failure instead of throwing', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
            await boot();

            document.querySelector('[data-transitions-page="2"]').dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(document.getElementById('planned-transitions-list').textContent).toContain('page 1');
        });

        it('ignores a click that is not on a pagination link', async () => {
            await boot();

            document.querySelector('#planned-transitions-list li').dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );
            await Promise.resolve();

            expect(fetch).not.toHaveBeenCalled();
        });
    });
});
