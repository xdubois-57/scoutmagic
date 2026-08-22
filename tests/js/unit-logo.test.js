// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch is mocked throughout. Exercises the REAL
// implementation in public/assets/js/unit-logo.js (imported below, never
// reimplemented here). That file wires everything from a DOMContentLoaded
// listener, so each test builds its DOM and then dispatches that event
// manually, the same way tests/js/settings.test.js does.
import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('unit-logo.js — custom logo deletion', () => {
    beforeEach(async () => {
        vi.resetModules();
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok">';
        document.body.innerHTML = '<button id="unit-logo-delete-btn"></button>';
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/config/settings', reload: vi.fn() },
        });
        // The real toolboxes — unit-logo.js posts through
        // window.ScoutMagicApi and reports failure with a
        // window.ScoutMagicToast toast (base.html.twig guarantees this
        // load order in production).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/toast.js');
    });

    async function boot() {
        await import('../../public/assets/js/unit-logo.js');
        document.dispatchEvent(new Event('DOMContentLoaded'));
    }

    it('does nothing at all when the delete button is absent from the page', async () => {
        document.body.innerHTML = '<p>Une autre page.</p>';
        global.fetch = vi.fn();
        window.confirm = vi.fn();
        await boot();
        expect(fetch).not.toHaveBeenCalled();
        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('sends nothing when the user declines the confirm() dialog', async () => {
        global.fetch = vi.fn();
        window.confirm = vi.fn(() => false);
        await boot();
        document.getElementById('unit-logo-delete-btn').click();
        expect(window.confirm).toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('POSTs the CSRF token and reloads the page on a successful deletion', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) }));
        window.confirm = vi.fn(() => true);
        await boot();
        document.getElementById('unit-logo-delete-btn').click();

        await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/config/settings/logo-delete');
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body)).toEqual({ _csrf_token: 'tok' });
        expect(document.querySelector('.toast-body')).toBeNull();
    });

    it('toasts the server error and does not reload when the deletion fails', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: false, error: 'Aucun logo personnalisé.' }) }));
        window.confirm = vi.fn(() => true);
        await boot();
        document.getElementById('unit-logo-delete-btn').click();

        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(document.querySelector('.toast-body').textContent).toBe('Aucun logo personnalisé.');
        expect(document.querySelector('.toast').className).toContain('text-bg-danger');
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('toasts a generic message on a network failure', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        window.confirm = vi.fn(() => true);
        await boot();
        document.getElementById('unit-logo-delete-btn').click();

        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(document.querySelector('.toast-body').textContent).toBe('Une erreur est survenue.');
        expect(window.location.reload).not.toHaveBeenCalled();
    });
});
