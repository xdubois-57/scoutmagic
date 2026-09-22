// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked, and so are the
// toast toolbox and window.location.reload (jsdom does not implement
// navigation). Exercises the REAL implementation in
// public/assets/js/config-modules.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the
// module via vi.resetModules() + await import().
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <input class="form-check-input module-toggle" type="checkbox"
           data-module="finance" id="module-toggle-finance" checked>
    <input class="form-check-input module-toggle" type="checkbox"
           data-module="rental" id="module-toggle-rental">`;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('config-modules.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/config/modules', reload: vi.fn() },
        });
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/config-modules.js');
    }

    const toggle = (id) => document.getElementById('module-toggle-' + id);
    function flip(id, to) {
        toggle(id).checked = to;
        toggle(id).dispatchEvent(new Event('change'));
    }
    const lastBody = () => JSON.parse(fetch.mock.calls[0][1].body);

    describe('entry guard', () => {
        it('does nothing at all on a page with no module switches', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('turning a module on', () => {
        it('POSTs the module id, the new state, and the CSRF token', async () => {
            await boot();
            flip('rental', true);

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(fetch.mock.calls[0][0]).toBe('/config/modules/toggle');
            expect(lastBody()).toEqual({
                module_id: 'rental', enabled: true, _csrf_token: 'tok-123',
            });
        });

        it('reloads — a new module adds menus, routes and pages', async () => {
            await boot();
            flip('rental', true);

            await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
        });

        it('posts `enabled: false` when a module is turned off', async () => {
            await boot();
            flip('finance', false);

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastBody().enabled).toBe(false);
        });
    });

    describe('when the server refuses', () => {
        it('puts the switch back — the screen must not claim an activation that did not happen', async () => {
            global.fetch = vi.fn(() =>
                jsonResponse({ success: false, error: 'Activez d\'abord le module Finances.' }));
            await boot();
            flip('rental', true);

            await vi.waitFor(() => expect(toggle('rental').checked).toBe(false));
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith("Activez d'abord le module Finances.", { variant: 'error' });
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('puts a deactivation back too', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Non.' }));
            await boot();
            flip('finance', false);

            await vi.waitFor(() => expect(toggle('finance').checked).toBe(true));
        });

        it('has a sentence of its own when the refusal carries none — never « undefined »', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false }));
            await boot();
            flip('rental', true);

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith("Le module n'a pas pu être modifié.", { variant: 'error' });
        });

        it('distinguishes a network failure, and re-enables the switch', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
            await boot();
            flip('rental', true);

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur réseau.', { variant: 'error' });
            expect(toggle('rental').checked).toBe(false);
            expect(toggle('rental').disabled).toBe(false);
        });
    });

    // The Tous / Actifs / Inactifs filter — its own block in the
    // implementation, and its own here, because it must keep working on
    // a page whose modules all failed validation and therefore render no
    // switch at all.
    describe('the Tous / Actifs / Inactifs filter', () => {
        const SHELVES = `
            <button type="button" data-module-filter="all" class="active" aria-pressed="true">Tous (3)</button>
            <button type="button" data-module-filter="on" aria-pressed="false">Actifs (1)</button>
            <button type="button" data-module-filter="off" aria-pressed="false">Inactifs (2)</button>
            <section data-module-shelf="argent">
                <div data-module-row data-module-enabled="yes" id="row-finance"></div>
            </section>
            <section data-module-shelf="services">
                <div data-module-row data-module-enabled="no" id="row-rental"></div>
                <div data-module-row data-module-enabled="no" id="row-sos"></div>
            </section>
            <p data-module-empty class="d-none">Aucun module ne correspond à ce filtre.</p>`;

        const row = (id) => document.getElementById('row-' + id);
        const shelf = (id) => document.querySelector(`[data-module-shelf="${id}"]`);
        const chip = (f) => document.querySelector(`[data-module-filter="${f}"]`);
        const hidden = (el) => el.classList.contains('d-none');

        beforeEach(() => {
            document.body.innerHTML = SHELVES;
        });

        it('shows every module under « Tous »', async () => {
            await boot();
            chip('on').click();
            chip('all').click();

            expect(hidden(row('finance'))).toBe(false);
            expect(hidden(row('rental'))).toBe(false);
            expect(hidden(shelf('argent'))).toBe(false);
            expect(hidden(shelf('services'))).toBe(false);
        });

        it('keeps only the active ones under « Actifs »', async () => {
            await boot();
            chip('on').click();

            expect(hidden(row('finance'))).toBe(false);
            expect(hidden(row('rental'))).toBe(true);
            expect(hidden(row('sos'))).toBe(true);
        });

        it('hides a shelf the filter empties, rather than leaving a title alone', async () => {
            await boot();
            chip('on').click();

            expect(hidden(shelf('argent'))).toBe(false);
            expect(hidden(shelf('services'))).toBe(true);
        });

        it('says so when a filter matches nothing at all', async () => {
            document.body.innerHTML = SHELVES.replace('data-module-enabled="yes"', 'data-module-enabled="no"');
            await boot();
            chip('on').click();

            expect(hidden(document.querySelector('[data-module-empty]'))).toBe(false);
        });

        it('carries the pressed state on the chip, for a screen reader', async () => {
            await boot();
            chip('off').click();

            expect(chip('off').getAttribute('aria-pressed')).toBe('true');
            expect(chip('all').getAttribute('aria-pressed')).toBe('false');
            expect(chip('all').classList.contains('active')).toBe(false);
        });

        it('never asks the server anything — the list is already rendered', async () => {
            await boot();
            chip('on').click();
            chip('off').click();
            chip('all').click();

            expect(fetch).not.toHaveBeenCalled();
        });

        it('still filters on a page whose modules render no switch', async () => {
            // Every module invalid: no `.module-toggle` anywhere, so the
            // toggle block returns early. The filter must not go with it.
            await boot();
            chip('on').click();

            expect(hidden(row('rental'))).toBe(true);
        });
    });
});
