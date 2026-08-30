// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked, and so is the toast
// toolbox. Exercises the REAL implementation in
// public/assets/js/config-super-admins.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the module
// via vi.resetModules() + await import().
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <table><tbody>
        <tr id="row-7">
            <td>sept@example.com</td>
            <td>
                <input class="form-check-input super-admin-active-toggle" type="checkbox"
                       role="switch" id="super-admin-active-7" data-account-id="7" checked>
                <span class="badge super-admin-state-badge text-bg-success">Actif</span>
            </td>
        </tr>
        <tr id="row-9" class="opacity-50">
            <td>neuf@example.com</td>
            <td>
                <input class="form-check-input super-admin-active-toggle" type="checkbox"
                       role="switch" id="super-admin-active-9" data-account-id="9">
                <span class="badge super-admin-state-badge text-bg-secondary">Désactivé</span>
            </td>
        </tr>
    </tbody></table>`;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('config-super-admins.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true, message: 'Le compte a été désactivé.' }));
        window.ScoutMagicToast = { show: vi.fn() };
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/config-super-admins.js');
    }

    const control = (id) => document.getElementById('super-admin-active-' + id);
    const row = (id) => document.getElementById('row-' + id);
    const badge = (id) => row(id).querySelector('.super-admin-state-badge');

    function flip(id, to) {
        control(id).checked = to;
        control(id).dispatchEvent(new Event('change'));
    }

    const lastBody = () => JSON.parse(fetch.mock.calls[0][1].body);

    describe('entry guard', () => {
        it('does nothing at all on a page with no switches', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('deactivating', () => {
        it('POSTs the account id, the new state and the CSRF token', async () => {
            await boot();
            flip(7, false);

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(fetch.mock.calls[0][0]).toBe('/config/superadmins/toggle-active');
            expect(lastBody()).toEqual({
                user_account_id: '7', is_active: false, _csrf_token: 'tok-123',
            });
        });

        it('confirms with a toast — there is no save button to press', async () => {
            await boot();
            flip(7, false);

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                'Le compte a été désactivé.',
                { variant: 'success' }
            );
        });

        it('greys the row and repaints the badge', async () => {
            await boot();
            flip(7, false);

            await vi.waitFor(() => expect(badge(7).textContent).toBe('Désactivé'));
            expect(row(7).classList.contains('opacity-50')).toBe(true);
            expect(badge(7).classList.contains('text-bg-secondary')).toBe(true);
            expect(badge(7).classList.contains('text-bg-success')).toBe(false);
        });
    });

    describe('reactivating', () => {
        it('ungreys the row and repaints the badge', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, message: 'Le compte a été réactivé.' }));
            await boot();
            flip(9, true);

            await vi.waitFor(() => expect(badge(9).textContent).toBe('Actif'));
            expect(row(9).classList.contains('opacity-50')).toBe(false);
            expect(badge(9).classList.contains('text-bg-success')).toBe(true);
        });
    });

    describe('a refusal from the server', () => {
        // The two refusals are decided server-side. This script's whole
        // job on a refusal is to stop the screen claiming a change that
        // did not happen.
        it('puts the switch back and shows the reason', async () => {
            global.fetch = vi.fn(() => jsonResponse(
                { success: false, error: 'Ce compte est le dernier accès superadmin actif du site.' },
                400
            ));
            await boot();
            flip(7, false);

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(control(7).checked).toBe(true);
            expect(badge(7).textContent).toBe('Actif');
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                'Ce compte est le dernier accès superadmin actif du site.',
                { variant: 'error' }
            );
        });

        it('falls back to a sentence of its own when the refusal carries no message', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false }, 400));
            await boot();
            flip(7, false);

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                "Le compte n'a pas pu être modifié.",
                { variant: 'error' }
            );
        });
    });

    describe('a network failure', () => {
        it('puts the switch back and says so', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
            await boot();
            flip(7, false);

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(control(7).checked).toBe(true);
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                'Erreur réseau.',
                { variant: 'error' }
            );
        });
    });
});
