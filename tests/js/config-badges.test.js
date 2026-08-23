// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch is mocked, and so are the two site-wide
// dialog toolboxes (window.ScoutMagicConfirm, window.ScoutMagicToast) that
// base.html.twig loads on every page, plus window.location.reload (jsdom
// does not implement navigation). Exercises the REAL implementation in
// public/assets/js/config-badges.js (imported below, never reimplemented
// here). That file is an IIFE that reads the DOM at import time, so each
// test builds its fixture first and then imports the module via
// vi.resetModules() + await import().
//
// The fixture mirrors what core/View/templates/config/badges.html.twig
// renders: one custom badge (renameable, deletable) and one default badge
// (readonly name, no delete button), plus the add form.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const CUSTOM_ROW = `
    <div class="badge-row" data-id="4" data-default="0">
        <input type="text" class="badge-name-input" value="Infirmier">
        <input type="checkbox" class="badge-active-input" role="switch"
               id="badge-active-4" checked aria-checked="true">
        <button type="button" class="badge-delete-btn"></button>
    </div>`;

const DEFAULT_ROW = `
    <div class="badge-row" data-id="1" data-default="1">
        <input type="text" class="badge-name-input" value="Trésorier" readonly>
        <input type="checkbox" class="badge-active-input" role="switch"
               id="badge-active-1" aria-checked="false">
    </div>`;

const ADD_FORM = `
    <div id="badge-add-form">
        <input type="text" id="new-badge-name" value="">
        <button type="button" id="badge-add-btn"></button>
    </div>`;

const PAGE = `<div id="badge-list">${CUSTOM_ROW}${DEFAULT_ROW}</div>${ADD_FORM}`;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('config-badges.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
        window.ScoutMagicNav = { syncSwitchAriaChecked: vi.fn() };
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/config/badges', reload: vi.fn() },
        });
    });

    async function boot() {
        // The real fetch toolbox — config-badges.js posts through
        // window.ScoutMagicApi (base.html.twig guarantees this load order
        // in production).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/config-badges.js');
    }

    function lastRequest() {
        const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
        return { url, opts, body: JSON.parse(opts.body) };
    }

    const customRow = () => document.querySelector('.badge-row[data-id="4"]');

    describe('entry guard', () => {
        it('does nothing at all on a page with no badge list and no add button', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('wires the rows even when the add form is absent', async () => {
            document.body.innerHTML = CUSTOM_ROW;
            await boot();
            document.querySelector('.badge-active-input').dispatchEvent(new Event('change'));
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().url).toBe('/config/badges/toggle-active');
        });
    });

    describe('deletion — the one destructive action', () => {
        it('asks for confirmation in French, naming the action on the button', async () => {
            await boot();
            customRow().querySelector('.badge-delete-btn').click();

            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
                message: 'Supprimer définitivement ce badge ?',
                confirmLabel: 'Supprimer',
            });
        });

        it('sends NOTHING and keeps the row when the answer is no', async () => {
            window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
            await boot();
            customRow().querySelector('.badge-delete-btn').click();

            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            expect(fetch).not.toHaveBeenCalled();
            expect(customRow()).not.toBeNull();
        });

        it('POSTs the deletion with the CSRF token and removes the row on success', async () => {
            await boot();
            customRow().querySelector('.badge-delete-btn').click();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, opts, body } = lastRequest();
            expect(url).toBe('/config/badges/delete');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({ badge_id: 4, _csrf_token: 'tok-123' });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
            await vi.waitFor(() => expect(customRow()).toBeNull());
        });

        it('toasts the server error and keeps the row when the deletion is refused', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Badge attribué à un membre.' }));
            await boot();
            customRow().querySelector('.badge-delete-btn').click();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Badge attribué à un membre.', { variant: 'error' }));
            expect(customRow()).not.toBeNull();
        });

        it('reads an HTTP 500 error page as a failure, never as a deletion (ok is not success)', async () => {
            global.fetch = vi.fn(() => Promise.resolve({
                ok: false,
                status: 500,
                json: () => Promise.reject(new SyntaxError('Unexpected token <')),
            }));
            await boot();
            customRow().querySelector('.badge-delete-btn').click();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Erreur : réponse serveur invalide.', { variant: 'error' }));
            expect(customRow()).not.toBeNull();
        });

        it('offers no delete control on a default badge', async () => {
            await boot();
            expect(document.querySelector('.badge-row[data-id="1"] .badge-delete-btn')).toBeNull();
        });
    });

    describe('activation switch', () => {
        it('POSTs the new state as a boolean', async () => {
            await boot();
            const input = customRow().querySelector('.badge-active-input');
            input.checked = false;
            input.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/badges/toggle-active');
            expect(body).toEqual({ badge_id: 4, active: false, _csrf_token: 'tok-123' });
        });

        it('reverts the switch AND its aria-checked when the server refuses', async () => {
            // A programmatic revert fires no `change`, so nav.js's delegated
            // listener never runs — the script has to sync aria-checked
            // itself or a screen reader keeps announcing the refused state.
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Badge introuvable.' }));
            await boot();
            const input = customRow().querySelector('.badge-active-input');
            input.checked = false;
            input.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(input.checked).toBe(true));
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Badge introuvable.', { variant: 'error' });
            expect(window.ScoutMagicNav.syncSwitchAriaChecked).toHaveBeenCalledWith(input);
        });

        it('leaves the switch alone on success', async () => {
            await boot();
            const input = customRow().querySelector('.badge-active-input');
            input.checked = false;
            input.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            await vi.waitFor(() => expect(input.disabled).toBe(false));
            expect(input.checked).toBe(false);
            expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
        });

        it('disables the switch for the round trip', async () => {
            await boot();
            const input = customRow().querySelector('.badge-active-input');
            input.dispatchEvent(new Event('change'));
            expect(input.disabled).toBe(true);
            await vi.waitFor(() => expect(input.disabled).toBe(false));
        });
    });

    describe('rename', () => {
        it('saves a custom badge name on blur and reloads on success', async () => {
            await boot();
            const input = customRow().querySelector('.badge-name-input');
            input.value = 'Infirmière';
            input.dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/badges/update');
            expect(body).toEqual({ badge_id: 4, name: 'Infirmière', _csrf_token: 'tok-123' });
            await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
        });

        it('never saves a default badge name — the server owns it', async () => {
            await boot();
            document.querySelector('.badge-row[data-id="1"] .badge-name-input').dispatchEvent(new Event('blur'));
            await Promise.resolve();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('toasts the error and does not reload when the rename is refused', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Nom déjà utilisé.' }));
            await boot();
            customRow().querySelector('.badge-name-input').dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Nom déjà utilisé.', { variant: 'error' }));
            expect(window.location.reload).not.toHaveBeenCalled();
        });
    });

    describe('add', () => {
        it('POSTs the new name and reloads on success', async () => {
            await boot();
            document.getElementById('new-badge-name').value = 'Communication';
            document.getElementById('badge-add-btn').click();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/badges/add');
            expect(body).toEqual({ name: 'Communication', _csrf_token: 'tok-123' });
            await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
        });

        it('toasts the error and does not reload when the name is refused', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Nom invalide.' }));
            await boot();
            document.getElementById('badge-add-btn').click();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Nom invalide.', { variant: 'error' }));
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('toasts a network failure instead of failing silently', async () => {
            global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
            await boot();
            document.getElementById('badge-add-btn').click();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Erreur : réponse serveur invalide.', { variant: 'error' }));
            expect(window.location.reload).not.toHaveBeenCalled();
        });
    });

    describe('server text never becomes markup', () => {
        it('renders a script-carrying error message as text in the real toast', async () => {
            // The one place server-controlled text reaches the DOM: the
            // error toast. Run it through the REAL toast.js rather than the
            // stub, so the escaping guarantee is the production one.
            delete window.ScoutMagicToast;
            await import('../../public/assets/js/toast.js');
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: '<img src=x onerror=alert(1)>' }));
            await boot();
            customRow().querySelector('.badge-delete-btn').click();

            await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
            expect(document.querySelector('.toast-body img')).toBeNull();
            expect(document.querySelector('.toast-body').textContent).toBe('<img src=x onerror=alert(1)>');
        });
    });
});
