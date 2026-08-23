// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked, and the two site-wide dialogs
// (window.ScoutMagicConfirm / window.ScoutMagicToast, loaded by
// base.html.twig in production) are stubbed so the answer to the
// confirmation is something the test chooses rather than something a real
// modal has to be clicked for.
//
// Exercises the REAL public/assets/js/unit-logo-notify-ios.js (imported
// below, never reimplemented). That file wires everything from a
// DOMContentLoaded listener, so each test builds its DOM and dispatches
// that event manually — the tests/js/unit-logo.test.js pattern.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const CONFIRM_MESSAGE =
    'Envoyer une notification à tous les comptes pour les inviter à réinstaller l\'application sur iOS ?';

describe('unit-logo-notify-ios.js — the iOS reinstall notification', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok">';
        document.body.innerHTML = '<button id="unit-logo-notify-ios-btn">Notifier</button>';
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
        window.ScoutMagicToast = { show: vi.fn() };
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
    });

    async function boot() {
        await import('../../public/assets/js/unit-logo-notify-ios.js');
        document.dispatchEvent(new Event('DOMContentLoaded'));
    }

    /** @returns {HTMLButtonElement} */
    function button() {
        return /** @type {HTMLButtonElement} */ (document.getElementById('unit-logo-notify-ios-btn'));
    }

    it('does nothing at all when the button is absent from the page', async () => {
        document.body.innerHTML = '<p>Une autre page.</p>';
        await boot();
        expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('asks the shared confirmation — never the native box — with the French message and « Envoyer »', async () => {
        const nativeConfirm = vi.fn(() => true);
        window.confirm = nativeConfirm;
        await boot();
        button().click();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: CONFIRM_MESSAGE,
            confirmLabel: 'Envoyer',
            // Notifying everyone destroys nothing: the primary button, not
            // the danger one a delete gets (design.md §7.5).
            variant: 'primary',
        });
        expect(nativeConfirm).not.toHaveBeenCalled();
    });

    it('sends nothing when the confirmation resolves false', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await boot();
        button().click();

        // The handler is async now — give its awaited answer a turn to land
        // before concluding that no request was made.
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
        expect(button().disabled).toBe(false);
    });

    it('POSTs the CSRF token and locks the button once the notification is away', async () => {
        await boot();
        button().click();

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/config/settings/logo-notify-ios');
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body)).toEqual({ _csrf_token: 'tok' });

        await vi.waitFor(() => expect(button().disabled).toBe(true));
        expect(button().textContent).toBe('Notification envoyée');
        expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
    });

    it('toasts the server error, and leaves the button usable, when the send is refused', async () => {
        global.fetch = vi.fn(() => Promise.resolve({
            json: () => Promise.resolve({ success: false, error: 'Aucun compte à notifier.' }),
        }));
        await boot();
        button().click();

        await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
            'Aucun compte à notifier.',
            { variant: 'error' },
        ));
        expect(button().disabled).toBe(false);
    });

    it('falls back to a generic French error line when the server names none', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false }) }));
        await boot();
        button().click();

        await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
            'Une erreur est survenue.',
            { variant: 'error' },
        ));
    });
});
