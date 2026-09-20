// Isolated JavaScript unit test — jsdom only, fetch mocked, exercising
// the REAL public/assets/js/device-credentials.js (imported below, never
// reimplemented here). The file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the module
// via vi.resetModules() + await import().
//
// What is actually under test is one property, and it is a security
// property: the secret a new credential carries exists only in the JSON
// answer, and this script must put it on screen as TEXT, once, without
// ever turning it into markup or leaving a copy anywhere.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** core/View/templates/account/devices.html.twig, reduced to what the script reads. */
const PAGE = `
    <div id="device-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="device-secret-panel" class="alert alert-success d-none">
        <code id="device-secret-value" class="user-select-all"></code>
    </div>
    <div id="device-list"></div>
    <div id="device-add-section" data-csrf="tok-123">
        <input type="text" id="device-label" value="Téléphone">
        <button type="button" id="device-add-btn">Enregistrer un appareil</button>
    </div>`;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('device-credentials.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true, secret: 'a'.repeat(64) }));
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/device-credentials.js');
    }

    const el = (id) => document.getElementById(id);

    it('does nothing at all on a page without its button', async () => {
        document.body.innerHTML = '<p>Une autre page</p>';
        await expect(boot()).resolves.not.toThrow();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('posts the label the reader typed, through the shared CSRF-carrying toolbox', async () => {
        await boot();
        el('device-add-btn').click();

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        const [url, opts] = fetch.mock.calls[0];
        expect(url).toBe('/api/account/devices');
        expect(JSON.parse(opts.body)).toMatchObject({ label: 'Téléphone', _csrf_token: 'tok-123' });
    });

    it('shows the secret once, as text and never as markup', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: true, secret: '<img src=x onerror=alert(1)>' }));
        await boot();
        el('device-add-btn').click();

        await vi.waitFor(() => expect(el('device-secret-panel').classList.contains('d-none')).toBe(false));
        expect(el('device-secret-value').querySelector('img')).toBeNull();
        expect(el('device-secret-value').textContent).toBe('<img src=x onerror=alert(1)>');
    });

    /**
     * A second click would create a second credential and overwrite the
     * panel with its secret — losing the first one, which nothing can
     * show again.
     */
    it('cannot be clicked a second time once a secret is on screen', async () => {
        await boot();
        el('device-add-btn').click();

        await vi.waitFor(() => expect(el('device-add-btn').disabled).toBe(true));
        el('device-add-btn').click();

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('shows the server refusal and lets the reader try again', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Vous avez déjà 10 appareils.' }, 409));
        await boot();
        el('device-add-btn').click();

        await vi.waitFor(() => expect(el('device-error').classList.contains('d-none')).toBe(false));
        expect(el('device-error').textContent).toBe('Vous avez déjà 10 appareils.');
        expect(el('device-secret-panel').classList.contains('d-none')).toBe(true);
        expect(el('device-add-btn').disabled).toBe(false);
    });

    it('says so when the request never reached a server', async () => {
        global.fetch = vi.fn(() => Promise.reject(new TypeError('offline')));
        await boot();
        el('device-add-btn').click();

        await vi.waitFor(() => expect(el('device-error').textContent).toBe('Erreur réseau.'));
        expect(el('device-secret-panel').classList.contains('d-none')).toBe(true);
    });

    /** An HTTP 200 whose body is not the envelope is not a success. */
    it('never shows a panel for an answer carrying no secret', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        await boot();
        el('device-add-btn').click();

        await vi.waitFor(() => expect(el('device-error').classList.contains('d-none')).toBe(false));
        expect(el('device-secret-panel').classList.contains('d-none')).toBe(true);
    });
});
