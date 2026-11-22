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
// The fixture mirrors what core/View/templates/admin/badges/configuration.html.twig
// renders: one custom badge (renameable, deletable) and one default badge
// (readonly name, no delete button), the add form, and the
// <template id="badge-row-template"> the script clones for a badge created
// without a reload (#1337). Nothing reloads the page any more: every test
// below that used to expect location.reload() now expects the DOM to change.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

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

// What admin/badges/_row.html.twig renders for a custom, active, unassigned badge.
const ROW_TEMPLATE = `
    <template id="badge-row-template">
        <div class="badge-row" data-id="0" data-default="0">
            <input type="text" class="badge-name-input" value="">
            <div class="form-check form-switch">
                <input type="checkbox" class="badge-active-input" role="switch"
                       id="badge-active-0" checked aria-checked="true">
                <label for="badge-active-0">Actif</label>
            </div>
            <button type="button" class="badge-delete-btn"></button>
        </div>
    </template>`;

const PAGE = `<div id="badge-list">${CUSTOM_ROW}${DEFAULT_ROW}</div>${ROW_TEMPLATE}${ADD_FORM}`;

/** `blur` does not bubble; the script listens to `focusout`, which does. */
function leave(input) {
    input.dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
}

/** `change` bubbles in a browser; a bare `new Event('change')` does not. */
function changed(input) {
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('config-badges.js', () => {
    // The script delegates from `document`, which outlives a test: without
    // this, every boot() would stack another set of listeners on top of the
    // previous tests' and one click would be handled several times. In the
    // browser the module loads once.
    const documentListeners = [];
    const realAddEventListener = document.addEventListener.bind(document);

    beforeEach(() => {
        document.addEventListener = (type, listener, options) => {
            documentListeners.push([type, listener, options]);
            realAddEventListener(type, listener, options);
        };
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
        window.ScoutMagicNav = { syncSwitchAriaChecked: vi.fn() };
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/admin/badges/configuration', reload: vi.fn() },
        });
    });

    afterEach(() => {
        documentListeners.splice(0).forEach(([type, listener, options]) => {
            document.removeEventListener(type, listener, options);
        });
        document.addEventListener = realAddEventListener;
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
            document.querySelector('.badge-active-input').dispatchEvent(new Event('change', { bubbles: true }));
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().url).toBe('/admin/badges/toggle-active');
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
            expect(url).toBe('/admin/badges/delete');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({ badge_id: 4, _csrf_token: 'tok-123' });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
            await vi.waitFor(() => expect(customRow()).toBeNull());
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Badge supprimé.', { variant: 'success' });
        });

        it('says nothing at all when the answer to the confirmation is no', async () => {
            window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
            await boot();
            customRow().querySelector('.badge-delete-btn').click();

            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            await Promise.resolve();
            expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
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
            input.dispatchEvent(new Event('change', { bubbles: true }));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/admin/badges/toggle-active');
            expect(body).toEqual({ badge_id: 4, active: false, _csrf_token: 'tok-123' });
            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Enregistré.', { variant: 'success' }));
        });

        it('reverts the switch AND its aria-checked when the server refuses', async () => {
            // A programmatic revert fires no `change`, so nav.js's delegated
            // listener never runs — the script has to sync aria-checked
            // itself or a screen reader keeps announcing the refused state.
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Badge introuvable.' }));
            await boot();
            const input = customRow().querySelector('.badge-active-input');
            input.checked = false;
            input.dispatchEvent(new Event('change', { bubbles: true }));

            await vi.waitFor(() => expect(input.checked).toBe(true));
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Badge introuvable.', { variant: 'error' });
            expect(window.ScoutMagicNav.syncSwitchAriaChecked).toHaveBeenCalledWith(input);
        });

        it('leaves the switch alone on success, and confirms it with a toast', async () => {
            await boot();
            const input = customRow().querySelector('.badge-active-input');
            input.checked = false;
            input.dispatchEvent(new Event('change', { bubbles: true }));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            await vi.waitFor(() => expect(input.disabled).toBe(false));
            expect(input.checked).toBe(false);
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Enregistré.', { variant: 'success' });
            expect(window.ScoutMagicToast.show).not.toHaveBeenCalledWith(expect.anything(), { variant: 'error' });
        });

        it('disables the switch for the round trip', async () => {
            await boot();
            const input = customRow().querySelector('.badge-active-input');
            input.dispatchEvent(new Event('change', { bubbles: true }));
            expect(input.disabled).toBe(true);
            await vi.waitFor(() => expect(input.disabled).toBe(false));
        });
    });

    describe('rename', () => {
        function rename(input, name) {
            input.value = name;
            leave(input);
        }

        it('saves a custom badge name when the field loses focus, and stays on the page', async () => {
            await boot();
            const input = customRow().querySelector('.badge-name-input');
            rename(input, 'Infirmière');

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/admin/badges/update');
            expect(body).toEqual({ badge_id: 4, name: 'Infirmière', _csrf_token: 'tok-123' });
            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Enregistré.', { variant: 'success' }));
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('shows the name the SERVER answered, not the one that was typed', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, badge: { id: 4, name: 'Infirmière', is_default: false, is_active: true } }));
            await boot();
            const input = customRow().querySelector('.badge-name-input');
            rename(input, '  Infirmière  ');

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(input.value).toBe('Infirmière');
            expect(input.defaultValue).toBe('Infirmière');
        });

        it('sends nothing when the name did not change, or only by its spaces', async () => {
            await boot();
            const input = customRow().querySelector('.badge-name-input');
            leave(input);
            rename(input, '  Infirmier ');
            await Promise.resolve();

            expect(fetch).not.toHaveBeenCalled();
            expect(input.value).toBe('Infirmier');
            expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
        });

        it('does not save the same name twice after a success', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, badge: { id: 4, name: 'Infirmière' } }));
            await boot();
            const input = customRow().querySelector('.badge-name-input');
            rename(input, 'Infirmière');
            await vi.waitFor(() => expect(input.disabled).toBe(false));
            leave(input);
            await Promise.resolve();

            expect(fetch).toHaveBeenCalledTimes(1);
        });

        it('locks the field for the round trip, so a second blur cannot send a second request', async () => {
            let release;
            global.fetch = vi.fn(() => new Promise((resolve) => {
                release = () => resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) });
            }));
            await boot();
            const input = customRow().querySelector('.badge-name-input');
            rename(input, 'Infirmière');
            expect(input.disabled).toBe(true);
            leave(input);
            leave(input);
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(fetch).toHaveBeenCalledTimes(1);

            release();
            await vi.waitFor(() => expect(input.disabled).toBe(false));
        });

        it('never saves a default badge name — the server owns it', async () => {
            await boot();
            const input = document.querySelector('.badge-row[data-id="1"] .badge-name-input');
            input.removeAttribute('readonly');
            rename(input, 'Autre');
            await Promise.resolve();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('toasts the error and puts the previous name back when the rename is refused', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Nom déjà utilisé.' }));
            await boot();
            const input = customRow().querySelector('.badge-name-input');
            rename(input, 'Trésorier');

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Nom déjà utilisé.', { variant: 'error' }));
            expect(input.value).toBe('Infirmier');
            expect(input.defaultValue).toBe('Infirmier');
            expect(window.location.reload).not.toHaveBeenCalled();
            expect(window.ScoutMagicToast.show).not.toHaveBeenCalledWith('Enregistré.', expect.anything());
        });

        it('puts the previous name back and unlocks the field on a network failure', async () => {
            global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
            await boot();
            const input = customRow().querySelector('.badge-name-input');
            rename(input, 'Infirmière');

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Erreur : réponse serveur invalide.', { variant: 'error' }));
            expect(input.value).toBe('Infirmier');
            expect(input.disabled).toBe(false);
        });
    });

    describe('add', () => {
        const created = { success: true, badge: { id: 9, name: 'Communication', is_default: false, is_active: true } };

        async function addCommunication(response = created) {
            global.fetch = vi.fn(() => jsonResponse(response));
            await boot();
            document.getElementById('new-badge-name').value = 'Communication';
            document.getElementById('badge-add-btn').click();
        }

        const newRow = () => document.querySelector('.badge-row[data-id="9"]');

        it('POSTs the new name, inserts the row from the server answer and does not reload', async () => {
            await addCommunication();

            await vi.waitFor(() => expect(newRow()).not.toBeNull());
            const { url, body } = lastRequest();
            expect(url).toBe('/admin/badges/add');
            expect(body).toEqual({ name: 'Communication', _csrf_token: 'tok-123' });
            expect(window.location.reload).not.toHaveBeenCalled();
            expect(newRow().querySelector('.badge-name-input').value).toBe('Communication');
        });

        const names = () => [...document.querySelectorAll('#badge-list .badge-name-input')].map((input) => input.value);

        it('puts the new row where the server lists it — by name, not at the end', async () => {
            await addCommunication();

            await vi.waitFor(() => expect(newRow()).not.toBeNull());
            // « Communication » sorts before « Infirmier » and « Trésorier ».
            expect(names()).toEqual(['Communication', 'Infirmier', 'Trésorier']);
        });

        it('compares names without regard to case or accents, the way the database does', async () => {
            await addCommunication({ success: true, badge: { id: 9, name: 'tresorier adjoint', is_active: true } });

            await vi.waitFor(() => expect(newRow()).not.toBeNull());
            // After « Trésorier » (accent and case ignored), at the end of the list.
            expect(names()).toEqual(['Infirmier', 'Trésorier', 'tresorier adjoint']);
        });

        it('puts a row in the middle between its neighbours', async () => {
            await addCommunication({ success: true, badge: { id: 9, name: 'Jardinage', is_active: true } });

            await vi.waitFor(() => expect(newRow()).not.toBeNull());
            expect(names()).toEqual(['Infirmier', 'Jardinage', 'Trésorier']);
        });

        it('moves a renamed row to its new place, and leaves it where it is when the order does not change', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, badge: { id: 4, name: 'Zèbre', is_active: true } }));
            await boot();
            const input = customRow().querySelector('.badge-name-input');
            input.value = 'Zèbre';
            leave(input);

            await vi.waitFor(() => expect(names()).toEqual(['Trésorier', 'Zèbre']));
            expect(customRow().querySelector('.badge-name-input').value).toBe('Zèbre');

            global.fetch = vi.fn(() => jsonResponse({ success: true, badge: { id: 4, name: 'Zèbre 2', is_active: true } }));
            input.value = 'Zèbre 2';
            leave(input);
            await vi.waitFor(() => expect(input.defaultValue).toBe('Zèbre 2'));
            expect(names()).toEqual(['Trésorier', 'Zèbre 2']);
        });

        it('does not put a deleted row back when the rename answer arrives after the deletion', async () => {
            let release;
            global.fetch = vi.fn(() => new Promise((resolve) => {
                release = () => resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true, badge: { id: 4, name: 'Zèbre', is_active: true } }) });
            }));
            await boot();
            const input = customRow().querySelector('.badge-name-input');
            input.value = 'Zèbre';
            leave(input);
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            // The badge is deleted while the rename is still in flight.
            customRow().remove();
            release();
            await vi.waitFor(() => expect(input.disabled).toBe(false));

            expect(customRow()).toBeNull();
            expect(names()).toEqual(['Trésorier']);
            expect(window.ScoutMagicToast.show).not.toHaveBeenCalledWith('Enregistré.', expect.anything());
        });

        it('keeps a row in place when it is renamed to a name sorting around the same spot', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, badge: { id: 4, name: 'Infirmière', is_active: true } }));
            await boot();
            const input = customRow().querySelector('.badge-name-input');
            input.value = 'Infirmière';
            leave(input);

            await vi.waitFor(() => expect(input.defaultValue).toBe('Infirmière'));
            expect(names()).toEqual(['Infirmière', 'Trésorier']);
        });

        it('empties the add field and confirms with a toast', async () => {
            await addCommunication();

            await vi.waitFor(() => expect(newRow()).not.toBeNull());
            expect(document.getElementById('new-badge-name').value).toBe('');
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Badge ajouté.', { variant: 'success' });
        });

        it('gives the new row its own id, so its switch and its label belong together', async () => {
            await addCommunication();

            await vi.waitFor(() => expect(newRow()).not.toBeNull());
            const input = newRow().querySelector('.badge-active-input');
            expect(input.id).toBe('badge-active-9');
            expect(newRow().querySelector('label').getAttribute('for')).toBe('badge-active-9');
            expect(input.getAttribute('aria-checked')).toBe('true');
            expect(document.getElementById('badge-active-0')).toBeNull();
        });

        it('shows a name carrying markup as text, never as markup', async () => {
            await addCommunication({ success: true, badge: { id: 9, name: '<img src=x onerror=alert(1)>', is_active: true } });

            await vi.waitFor(() => expect(newRow()).not.toBeNull());
            expect(newRow().querySelector('img')).toBeNull();
            expect(newRow().querySelector('.badge-name-input').value).toBe('<img src=x onerror=alert(1)>');
        });

        it('lets the new row be renamed at once, without a reload', async () => {
            await addCommunication();
            await vi.waitFor(() => expect(newRow()).not.toBeNull());

            global.fetch = vi.fn(() => jsonResponse({ success: true, badge: { id: 9, name: 'Com', is_active: true } }));
            const input = newRow().querySelector('.badge-name-input');
            input.value = 'Com';
            leave(input);

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().url).toBe('/admin/badges/update');
            expect(lastRequest().body.badge_id).toBe(9);
            await vi.waitFor(() => expect(input.defaultValue).toBe('Com'));
        });

        it('lets the new row be switched off at once', async () => {
            await addCommunication();
            await vi.waitFor(() => expect(newRow()).not.toBeNull());

            global.fetch = vi.fn(() => jsonResponse({ success: true }));
            const input = newRow().querySelector('.badge-active-input');
            input.checked = false;
            changed(input);

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().url).toBe('/admin/badges/toggle-active');
            expect(lastRequest().body).toEqual({ badge_id: 9, active: false, _csrf_token: 'tok-123' });
            // nav.js is not loaded here, so the aria-checked sync is the one the
            // revert test below covers; the request itself is the point.
        });

        it('lets the new row be deleted at once, with its toast', async () => {
            await addCommunication();
            await vi.waitFor(() => expect(newRow()).not.toBeNull());

            global.fetch = vi.fn(() => jsonResponse({ success: true }));
            newRow().querySelector('.badge-delete-btn').click();

            await vi.waitFor(() => expect(newRow()).toBeNull());
            expect(lastRequest().url).toBe('/admin/badges/delete');
            expect(lastRequest().body.badge_id).toBe(9);
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Badge supprimé.', { variant: 'success' });
        });

        it('inserts nothing and toasts the error when the name is refused', async () => {
            await addCommunication({ success: false, error: 'Nom invalide.' });

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Nom invalide.', { variant: 'error' }));
            expect(newRow()).toBeNull();
            expect(document.querySelectorAll('.badge-row')).toHaveLength(2);
            expect(document.getElementById('new-badge-name').value).toBe('Communication');
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('does not call a success without a badge an addition', async () => {
            await addCommunication({ success: true });

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Erreur : réponse serveur invalide.', { variant: 'error' }));
            expect(document.querySelectorAll('.badge-row')).toHaveLength(2);
            expect(window.ScoutMagicToast.show).not.toHaveBeenCalledWith('Badge ajouté.', expect.anything());
        });

        it('toasts a network failure instead of failing silently, and frees the button', async () => {
            global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
            await boot();
            document.getElementById('badge-add-btn').click();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Erreur : réponse serveur invalide.', { variant: 'error' }));
            expect(document.querySelectorAll('.badge-row')).toHaveLength(2);
            expect(document.getElementById('badge-add-btn').disabled).toBe(false);
        });

        it('does not insert the badge twice when the button is pressed during the request', async () => {
            let release;
            global.fetch = vi.fn(() => new Promise((resolve) => {
                release = () => resolve({ ok: true, status: 200, json: () => Promise.resolve(created) });
            }));
            await boot();
            const button = document.getElementById('badge-add-btn');
            button.click();
            button.click();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(fetch).toHaveBeenCalledTimes(1);

            release();
            await vi.waitFor(() => expect(newRow()).not.toBeNull());
            expect(document.querySelectorAll('.badge-row[data-id="9"]')).toHaveLength(1);
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
