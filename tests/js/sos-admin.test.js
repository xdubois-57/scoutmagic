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
//   - the month and its starting duty states come from window.sosAdminData,
//     not from an inline script Vitest cannot see;
//   - the three-state click cycle repaints the desktop cell AND the mobile
//     button, and saves the whole month with the CSRF token;
//   - a {success:false} body and an HTTP 500 that is not JSON are both
//     failures, never a silent "Enregistré.";
//   - a server message containing markup is shown as text;
//   - the transitions pagination never writes a failed response into the page.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const STATES = {
    '2026-03-01': { 4: 'oncall' },
    '2026-03-02': { 7: 'unavailable' },
};

const TRANSITIONS_HTML = '<ul class="list-group"><li>page 2</li></ul>'
    + '<nav><a href="#" data-transitions-page="3">3</a></nav>';

const PAGE_DOM = `
    <select id="default-number-member">
        <option value="4" selected>Alice D. (+32 470 00 00 01)</option>
        <option value="7">Bruno T. (+32 470 00 00 02)</option>
    </select>
    <div class="text-danger small mt-1 d-none" id="default-number-error" role="alert"></div>
    <input type="time" id="transition-hour" value="18:00">
    <input class="form-check-input" type="checkbox" id="email-notifications-toggle" checked>

    <div id="sos-mobile-grid">
        <button type="button" class="btn btn-sm btn-success sos-oncall-cell" data-member-id="4" data-date="2026-03-01">
            <span class="d-block small">Alice</span><span>✓</span>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary sos-oncall-cell" data-member-id="7" data-date="2026-03-01">
            <span class="d-block small">Bruno</span><span>—</span>
        </button>
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

function changeControl(id) {
    document.getElementById(id).dispatchEvent(new Event('change', { bubbles: true }));
}

describe('sos-admin.js', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE_DOM;
        window.sosAdminData = {
            year: 2026,
            month: 3,
            monthParam: '2026-03',
            states: structuredClone(STATES),
        };
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
            delete window.sosAdminData;
            document.body.innerHTML = '<div id="oncall-save-status"></div>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('default number', () => {
        it('POSTs the chosen member and the CSRF token', async () => {
            await boot();
            document.getElementById('default-number-member').value = '7';

            changeControl('default-number-member');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const { url, opts, body } = lastRequest();
            expect(url).toBe('/admin/sos/default-number');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({ member_id: 7, _csrf_token: 'tok-123' });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
        });

        it('confirms the save — this select decides who the SOS number rings', async () => {
            await boot();

            changeControl('default-number-member');
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                'Numéro par défaut enregistré.',
                { variant: 'success' },
            );
        });

        it('never puts the member id or a number in the URL it builds', async () => {
            await boot();

            changeControl('default-number-member');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            expect(String(fetch.mock.calls[0][0])).toBe('/admin/sos/default-number');
        });

        it('shows the server error inline', async () => {
            global.fetch = mockFetch({ '/default-number': { success: false, error: 'Membre invalide.' } });
            await boot();

            changeControl('default-number-member');
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

            changeControl('default-number-member');
            await vi.waitFor(() => {
                expect(document.getElementById('default-number-error').textContent)
                    .toContain('<img src=x onerror=alert(1)>');
            });
            expect(document.querySelector('#default-number-error img')).toBeNull();
        });

        it('treats an HTTP 500 error page as a failure, not a success', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();

            changeControl('default-number-member');
            await vi.waitFor(() => {
                expect(document.getElementById('default-number-error').textContent)
                    .toBe('Erreur : réponse serveur invalide.');
            });
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

        it('repaints the desktop cell and the mobile button together', async () => {
            await boot();

            clickCell('td.sos-oncall-cell[data-member-id="7"]');

            const desktop = document.querySelector('td.sos-oncall-cell[data-member-id="7"]');
            expect(desktop.classList.contains('state-oncall')).toBe(true);
            expect(desktop.textContent).toBe('✓');

            const mobile = document.querySelector('button.sos-oncall-cell[data-member-id="7"]');
            expect(mobile.classList.contains('btn-success')).toBe(true);
            expect(mobile.classList.contains('btn-outline-secondary')).toBe(false);
            expect(mobile.lastElementChild.textContent).toBe('✓');
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
            expect(document.querySelector('button.sos-oncall-cell[data-member-id="4"]').lastElementChild.textContent)
                .toBe('—');
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
