// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch is mocked, and so are the two site-wide
// dialog toolboxes (window.ScoutMagicToast, window.ScoutMagicConfirm) that
// base.html.twig loads on every page. Exercises the REAL implementation in
// public/assets/js/config-functions.js (imported below, never reimplemented
// here). That file is an IIFE that reads the DOM at import time, so each
// test builds its fixture first and then imports the module via
// vi.resetModules() + await import().
//
// The fixture mirrors what core/View/templates/config/functions.html.twig
// renders: .function-row/.role-select, .flags-group/.flag-lead,
// .section-row with its four controls, and .branch-row.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const FUNCTION_ROW = `
    <div class="function-row" data-id="7">
        <span class="badge text-bg-warning">Non confirmée</span>
        <select class="role-select" data-id="7">
            <option value="animated">Animé</option>
            <option value="chief" selected>Chef</option>
        </select>
    </div>`;

const FLAGS_GROUP = `
    <div class="flags-group" data-id="7">
        <input type="checkbox" class="flag-lead">
    </div>`;

const SECTION_ROW = `
    <div class="section-row" data-id="10">
        <input type="text" class="section-name-input" value="Louveteaux">
        <input type="email" class="section-email-input" value="lou@example.be">
        <input type="color" class="section-color-input" value="#112233" data-has-override="0">
        <button type="button" class="section-color-reset" disabled></button>
        <input type="checkbox" class="section-visible-input" role="switch"
               id="section-visible-10" checked aria-checked="true">
    </div>`;

const BRANCH_ROW = `
    <div class="branch-row" data-id="3">
        <input type="url" class="branch-url-input" value="https://lesscouts.be/lou">
    </div>`;

const PAGE = FUNCTION_ROW + FLAGS_GROUP + SECTION_ROW + BRANCH_ROW;

/** The site-wide envelope for a JSON answer the server really sent. */
function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('config-functions.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    });

    async function boot() {
        // The real fetch toolbox — config-functions.js posts through
        // window.ScoutMagicApi (base.html.twig guarantees this load order
        // in production).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/config-functions.js');
    }

    function lastRequest() {
        const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
        return { url, opts, body: JSON.parse(opts.body) };
    }

    describe('entry guard', () => {
        it('does nothing at all on a page carrying none of its controls', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('wires the sections even when the functions list is empty (no import yet)', async () => {
            document.body.innerHTML = SECTION_ROW;
            await boot();
            document.querySelector('.section-name-input').dispatchEvent(new Event('blur'));
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().url).toBe('/config/functions/section-name');
        });
    });

    describe('function role', () => {
        it('POSTs the role to /config/functions/update with the CSRF token in the body AND the header', async () => {
            await boot();
            const select = document.querySelector('.role-select');
            select.value = 'animated';
            select.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, opts, body } = lastRequest();
            expect(url).toBe('/config/functions/update');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({ function_id: 7, role: 'animated', _csrf_token: 'tok-123' });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
        });

        it('disables the select for the round trip and re-enables it afterwards', async () => {
            await boot();
            const select = document.querySelector('.role-select');
            select.dispatchEvent(new Event('change'));
            expect(select.disabled).toBe(true);
            await vi.waitFor(() => expect(select.disabled).toBe(false));
        });

        it('drops the « Non confirmée » badge and flashes the row on success', async () => {
            await boot();
            const row = document.querySelector('.function-row');
            row.querySelector('.role-select').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(row.querySelector('.badge.text-bg-warning')).toBeNull());
            expect(row.style.backgroundColor).toBe('var(--bs-success-bg-subtle)');
        });

        it('surfaces a business failure as an error toast and keeps the badge', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Rôle invalide.' }));
            await boot();
            const row = document.querySelector('.function-row');
            row.querySelector('.role-select').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Rôle invalide.', { variant: 'error' });
            expect(row.querySelector('.badge.text-bg-warning')).not.toBeNull();
        });

        it('reads an HTTP 500 error page as a failure, never as a save (ok is not success)', async () => {
            // The bug this extraction exists to prevent: res.ok (or an
            // unchecked res.json()) standing in for data.success.
            global.fetch = vi.fn(() => Promise.resolve({
                ok: false,
                status: 500,
                json: () => Promise.reject(new SyntaxError('Unexpected token <')),
            }));
            await boot();
            const row = document.querySelector('.function-row');
            row.querySelector('.role-select').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Erreur : réponse serveur invalide.', { variant: 'error' });
            expect(row.querySelector('.badge.text-bg-warning')).not.toBeNull();
        });

        it('toasts a network failure instead of failing silently', async () => {
            global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
            await boot();
            document.querySelector('.role-select').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Erreur : réponse serveur invalide.', { variant: 'error' }));
        });
    });

    describe('per-function flags', () => {
        it('POSTs the lead flag as a boolean to /config/functions/flags', async () => {
            await boot();
            const lead = document.querySelector('.flag-lead');
            lead.checked = true;
            lead.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/functions/flags');
            expect(body).toEqual({ function_id: 7, lead: true, _csrf_token: 'tok-123' });
        });

        it('toasts the server error when the flag is refused', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Fonction introuvable.' }));
            await boot();
            document.querySelector('.flag-lead').dispatchEvent(new Event('change'));
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Fonction introuvable.', { variant: 'error' }));
        });
    });

    describe('sections', () => {
        it('saves the name on blur', async () => {
            await boot();
            const input = document.querySelector('.section-name-input');
            input.value = 'Louveteaux Saint-Michel';
            input.dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/functions/section-name');
            expect(body).toEqual({ section_id: 10, name: 'Louveteaux Saint-Michel', _csrf_token: 'tok-123' });
        });

        it('saves the organisational email on blur', async () => {
            await boot();
            const input = document.querySelector('.section-email-input');
            input.value = 'louveteaux@unite-exemple.be';
            input.dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/functions/section-email');
            expect(body).toEqual({ section_id: 10, email: 'louveteaux@unite-exemple.be', _csrf_token: 'tok-123' });
        });

        it('saves the visibility switch as a boolean', async () => {
            await boot();
            const input = document.querySelector('.section-visible-input');
            input.checked = false;
            input.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/functions/section-visibility');
            expect(body).toEqual({ section_id: 10, visible: false, _csrf_token: 'tok-123' });
        });

        it('adopts the effective colour the server answers with and enables the reset button', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, color: '#445566' }));
            await boot();
            const colorInput = document.querySelector('.section-color-input');
            colorInput.value = '#445566';
            colorInput.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/functions/section-color');
            expect(body).toEqual({ section_id: 10, color: '#445566', _csrf_token: 'tok-123' });
            await vi.waitFor(() => expect(document.querySelector('.section-color-reset').disabled).toBe(false));
            expect(colorInput.dataset.hasOverride).toBe('1');
        });

        it('clears the override with a null colour and disables the reset button again', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, color: '#00aa00' }));
            await boot();
            const reset = document.querySelector('.section-color-reset');
            reset.disabled = false;
            reset.click();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().body).toEqual({ section_id: 10, color: null, _csrf_token: 'tok-123' });
            const colorInput = document.querySelector('.section-color-input');
            await vi.waitFor(() => expect(colorInput.value).toBe('#00aa00'));
            expect(colorInput.dataset.hasOverride).toBe('0');
            expect(reset.disabled).toBe(true);
        });

        it('leaves the colour picker untouched when the save fails', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Couleur invalide.' }));
            await boot();
            const colorInput = document.querySelector('.section-color-input');
            colorInput.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Couleur invalide.', { variant: 'error' }));
            expect(colorInput.dataset.hasOverride).toBe('0');
        });
    });

    describe('branches', () => {
        it('saves the explanation link on blur', async () => {
            await boot();
            const input = document.querySelector('.branch-url-input');
            input.value = 'https://lesscouts.be/baladins';
            input.dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/functions/branch-url');
            expect(body).toEqual({ branch_id: 3, url: 'https://lesscouts.be/baladins', _csrf_token: 'tok-123' });
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
            document.querySelector('.role-select').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
            expect(document.querySelector('.toast-body img')).toBeNull();
            expect(document.querySelector('.toast-body').textContent).toBe('<img src=x onerror=alert(1)>');
        });
    });
});
