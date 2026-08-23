// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked and the site-wide dialog toolboxes
// (window.ScoutMagicConfirm, window.ScoutMagicToast, loaded by
// base.html.twig on every page) are stubbed, so each answer — the yes/no and
// the typed « SUPPRIMER » — is something the test chooses rather than
// something a real modal has to be clicked for.
//
// Exercises the REAL public/assets/js/finance-config.js (imported below,
// never reimplemented) on top of the real api.js envelope. The file is an
// IIFE that wires itself against the DOM present at import time, so every
// test builds its fixture first and imports afterwards — the
// tests/js/config-badges.test.js pattern.
//
// This page is nothing but a danger zone: both of its actions delete
// permanently. What this suite owns is that neither one reaches the server
// without both guards being satisfied, that no native dialog is involved,
// and that a 500 error page is never mistaken for a completed deletion.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

async function settle() {
    // A macrotask hop drains the whole microtask queue first — the awaited
    // confirmation, the awaited prompt and the ScoutMagicApi envelope stack
    // more promise layers than a fixed number of awaits can follow.
    await new Promise((resolve) => setTimeout(resolve, 0));
}

// Mirrors what modules/finance/views/config/index.html.twig renders below
// « Zone de danger ».
const PAGE = `
    <select id="danger-movements-account">
        <option value="3">Compte courant</option>
        <option value="8">Caisse</option>
    </select>
    <button type="button" id="danger-movements-btn">Supprimer les mouvements</button>
    <button type="button" id="danger-receipts-btn">Supprimer tous les reçus</button>
`;

describe('finance-config.js', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = {
            ask: vi.fn(() => Promise.resolve(true)),
            prompt: vi.fn(() => Promise.resolve('SUPPRIMER')),
        };
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/config/finance', reload: vi.fn() },
        });
    });

    async function boot() {
        // The real fetch toolbox — finance-config.js posts through
        // window.ScoutMagicApi (base.html.twig guarantees this load order in
        // production).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/finance-config.js');
    }

    function lastRequest() {
        const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
        return { url, opts, body: JSON.parse(opts.body) };
    }

    describe('entry guard', () => {
        it('does nothing at all on a page with no danger zone', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
            expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
        });

        it('wires the receipts button even when the movements block is absent', async () => {
            document.getElementById('danger-movements-btn').remove();
            document.getElementById('danger-movements-account').remove();
            await boot();

            document.getElementById('danger-receipts-btn').click();
            await settle();

            expect(lastRequest().body.action).toBe('delete_receipts');
        });
    });

    describe('deleting every movement of one account', () => {
        it('asks the shared confirmation — never the native box — with the exact French sentence', async () => {
            const nativeConfirm = vi.fn(() => true);
            const nativePrompt = vi.fn(() => 'SUPPRIMER');
            window.confirm = nativeConfirm;
            window.prompt = nativePrompt;
            await boot();

            document.getElementById('danger-movements-btn').click();
            await settle();

            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
                message: 'Supprimer tous les mouvements de ce compte ? Cette action est irréversible.',
                // No `variant` key: an irreversible deletion keeps the
                // dialog's destructive default (design.md §7.5).
                confirmLabel: 'Supprimer',
            });
            expect(nativeConfirm).not.toHaveBeenCalled();
            expect(nativePrompt).not.toHaveBeenCalled();
        });

        it('sends NOTHING and never asks for the typed word when the answer is no', async () => {
            window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
            await boot();

            document.getElementById('danger-movements-btn').click();
            await settle();

            expect(window.ScoutMagicConfirm.prompt).not.toHaveBeenCalled();
            expect(fetch).not.toHaveBeenCalled();
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('asks for the typed word in French once the yes/no is answered', async () => {
            await boot();

            document.getElementById('danger-movements-btn').click();
            await settle();

            expect(window.ScoutMagicConfirm.prompt).toHaveBeenCalledWith({
                message: 'Cette action est irréversible. Tapez SUPPRIMER pour confirmer :',
                confirmLabel: 'Supprimer',
                variant: 'danger',
            });
        });

        it('sends NOTHING when the typed word is not exactly SUPPRIMER', async () => {
            window.ScoutMagicConfirm.prompt = vi.fn(() => Promise.resolve('supprimer'));
            await boot();

            document.getElementById('danger-movements-btn').click();
            await settle();

            expect(fetch).not.toHaveBeenCalled();
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('sends NOTHING when the typed-word dialog is dismissed', async () => {
            window.ScoutMagicConfirm.prompt = vi.fn(() => Promise.resolve(null));
            await boot();

            document.getElementById('danger-movements-btn').click();
            await settle();

            expect(fetch).not.toHaveBeenCalled();
        });

        it('POSTs the selected account with the confirmation text and the CSRF token', async () => {
            await boot();
            document.getElementById('danger-movements-account').value = '8';

            document.getElementById('danger-movements-btn').click();
            await settle();

            const { url, opts, body } = lastRequest();
            expect(url).toBe('/config/finance/danger');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({
                action: 'delete_movements',
                confirmation_text: 'SUPPRIMER',
                account_id: 8,
                _csrf_token: 'tok-123',
            });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
            expect(window.location.reload).toHaveBeenCalled();
        });

        it('toasts a business refusal instead of reloading', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Exercice clôturé.' }));
            await boot();

            document.getElementById('danger-movements-btn').click();
            await settle();

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Exercice clôturé.', { variant: 'error' });
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('reads an HTTP 500 error page as a failure, never as a deletion (ok is not success)', async () => {
            global.fetch = vi.fn(() => Promise.resolve({
                ok: false,
                status: 500,
                json: () => Promise.reject(new SyntaxError('Unexpected token <')),
            }));
            await boot();

            document.getElementById('danger-movements-btn').click();
            await settle();

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                'Erreur : réponse serveur invalide.',
                { variant: 'error' },
            );
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('toasts a network failure instead of reloading', async () => {
            global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
            await boot();

            document.getElementById('danger-movements-btn').click();
            await settle();

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                'Erreur : réponse serveur invalide.',
                { variant: 'error' },
            );
            expect(window.location.reload).not.toHaveBeenCalled();
        });
    });

    describe('archiving every receipt', () => {
        it('asks the shared confirmation with the exact French sentence', async () => {
            const nativeConfirm = vi.fn(() => true);
            window.confirm = nativeConfirm;
            await boot();

            document.getElementById('danger-receipts-btn').click();
            await settle();

            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
                message: 'Supprimer (archiver) tous les reçus ? Cette action est irréversible.',
                confirmLabel: 'Supprimer',
            });
            expect(nativeConfirm).not.toHaveBeenCalled();
        });

        it('sends NOTHING when the answer is no', async () => {
            window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
            await boot();

            document.getElementById('danger-receipts-btn').click();
            await settle();

            expect(window.ScoutMagicConfirm.prompt).not.toHaveBeenCalled();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('sends NOTHING when the typed word is not exactly SUPPRIMER', async () => {
            window.ScoutMagicConfirm.prompt = vi.fn(() => Promise.resolve(''));
            await boot();

            document.getElementById('danger-receipts-btn').click();
            await settle();

            expect(fetch).not.toHaveBeenCalled();
        });

        it('POSTs the action with no account id and reloads on success', async () => {
            await boot();

            document.getElementById('danger-receipts-btn').click();
            await settle();

            const { url, body } = lastRequest();
            expect(url).toBe('/config/finance/danger');
            expect(body).toEqual({
                action: 'delete_receipts',
                confirmation_text: 'SUPPRIMER',
                _csrf_token: 'tok-123',
            });
            expect(window.location.reload).toHaveBeenCalled();
        });

        it('toasts a business refusal instead of reloading', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Aucun reçu à archiver.' }));
            await boot();

            document.getElementById('danger-receipts-btn').click();
            await settle();

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Aucun reçu à archiver.', { variant: 'error' });
            expect(window.location.reload).not.toHaveBeenCalled();
        });
    });

    describe('server text never becomes markup', () => {
        it('renders a script-carrying error message as text in the real toast', async () => {
            // The one place server-controlled text reaches the DOM. Run it
            // through the REAL toast.js rather than the stub, so the
            // escaping guarantee is the production one.
            delete window.ScoutMagicToast;
            await import('../../public/assets/js/toast.js');
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: '<img src=x onerror=alert(1)>' }));
            await boot();

            document.getElementById('danger-receipts-btn').click();
            await settle();

            expect(document.querySelector('.toast-body img')).toBeNull();
            expect(document.querySelector('.toast-body').textContent).toBe('<img src=x onerror=alert(1)>');
        });
    });
});
