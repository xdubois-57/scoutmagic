// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked and the two site-wide dialog toolboxes
// (window.ScoutMagicConfirm, window.ScoutMagicToast, loaded by
// base.html.twig on every page) are stubbed, so the deletion's answer is
// something the test chooses rather than something a real modal has to be
// clicked for.
//
// Exercises the REAL public/assets/js/mass-mail-lists.js (imported below,
// never reimplemented) on top of the real api.js envelope. The file is an
// IIFE that wires itself against the DOM present at import time, so every
// test builds its fixture first and imports afterwards — the
// tests/js/config-badges.test.js pattern.
//
// What this suite owns above all: the one destructive action asks before it
// sends anything, every request carries the CSRF token to the right URL and
// with the right verb, and the two failure shapes stay apart — a business
// refusal ({success:false}) and an HTTP 500 that is not JSON at all must
// both read as failures, never as success.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

function htmlErrorResponse(status = 500) {
    return Promise.resolve({
        ok: false,
        status,
        json: () => Promise.reject(new SyntaxError('Unexpected token <')),
    });
}

async function settle() {
    // A macrotask hop drains the whole microtask queue first — the
    // ScoutMagicApi envelope plus the awaited confirmation add promise
    // layers a fixed number of Promise.resolve() awaits kept undercounting.
    await new Promise((resolve) => setTimeout(resolve, 0));
}

// Mirrors what modules/mass_mail/views/mailing_lists.html.twig renders: two
// custom lists, one active and one not, each with its two buttons.
const PAGE = `
    <ul id="cfg-custom-lists">
        <li data-id="7">
            <button type="button" class="cfg-toggle-list-btn" data-id="7" data-active="1"></button>
            <button type="button" class="cfg-delete-list-btn" data-id="7"></button>
        </li>
        <li data-id="9">
            <button type="button" class="cfg-toggle-list-btn" data-id="9" data-active="0"></button>
            <button type="button" class="cfg-delete-list-btn" data-id="9"></button>
        </li>
    </ul>
`;

describe('mass-mail-lists.js', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)), prompt: vi.fn() };
        delete window.bootstrap;
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/admin/listes-de-diffusion', reload: vi.fn() },
        });
    });

    async function boot() {
        // The real fetch toolbox — base.html.twig guarantees this load
        // order in production (api.js ships before {% block scripts %}).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/mass-mail-lists.js');
    }

    function lastRequest() {
        const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
        return { url, opts, body: JSON.parse(opts.body) };
    }

    const deleteBtn = () => document.querySelector('.cfg-delete-list-btn[data-id="7"]');

    describe('entry guard', () => {
        it('does nothing at all on a page without the lists', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('deleting a list — the one destructive action', () => {
        it('asks the shared confirmation, never the native box, in French and naming the action', async () => {
            const nativeConfirm = vi.fn(() => true);
            window.confirm = nativeConfirm;
            await boot();

            deleteBtn().click();
            await settle();

            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
                message: 'Supprimer cette liste ?',
                // No `variant` key: a permanent deletion keeps the dialog's
                // destructive default (design.md §7.5).
                confirmLabel: 'Supprimer',
            });
            expect(nativeConfirm).not.toHaveBeenCalled();
        });

        it('sends NOTHING when the answer is no', async () => {
            window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
            await boot();

            deleteBtn().click();
            await settle();

            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled();
            expect(fetch).not.toHaveBeenCalled();
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('DELETEs the clicked list with the CSRF token and reloads on success', async () => {
            await boot();

            document.querySelector('.cfg-delete-list-btn[data-id="9"]').click();
            await settle();

            const { url, opts, body } = lastRequest();
            expect(url).toBe('/admin/listes-de-diffusion/lists/9');
            expect(opts.method).toBe('DELETE');
            expect(body).toEqual({ _csrf_token: 'tok-123' });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
            expect(window.location.reload).toHaveBeenCalled();
        });

        it('toasts the server refusal instead of reloading', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Liste utilisée par un envoi en cours.' }));
            await boot();

            deleteBtn().click();
            await settle();

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                'Liste utilisée par un envoi en cours.',
                { variant: 'error' },
            );
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('reads an HTTP 500 error page as a failure, never as a deletion (ok is not success)', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();

            deleteBtn().click();
            await settle();

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                'Erreur : réponse serveur invalide.',
                { variant: 'error' },
            );
            expect(window.location.reload).not.toHaveBeenCalled();
        });
    });

    describe('activating / deactivating a list', () => {
        it('posts the flipped state without asking — deactivating destroys nothing', async () => {
            await boot();

            document.querySelector('.cfg-toggle-list-btn[data-id="7"]').click();
            await settle();

            const { url, opts, body } = lastRequest();
            expect(url).toBe('/admin/listes-de-diffusion/lists/7/toggle');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({ active: false, _csrf_token: 'tok-123' });
            expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
            expect(window.location.reload).toHaveBeenCalled();
        });

        it('re-activates an inactive list', async () => {
            await boot();

            document.querySelector('.cfg-toggle-list-btn[data-id="9"]').click();
            await settle();

            expect(lastRequest().body).toEqual({ active: true, _csrf_token: 'tok-123' });
        });

        it('toasts a business failure rather than reloading', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Liste introuvable.' }));
            await boot();

            document.querySelector('.cfg-toggle-list-btn[data-id="7"]').click();
            await settle();

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Liste introuvable.', { variant: 'error' });
            expect(window.location.reload).not.toHaveBeenCalled();
        });
    });

    describe('server text never becomes markup', () => {
        it('renders a script-carrying error message as text in the real toast', async () => {
            // The one place server-controlled text reaches the DOM through a
            // toast. Run it through the REAL toast.js rather than the stub,
            // so the escaping guarantee is the production one.
            delete window.ScoutMagicToast;
            await import('../../public/assets/js/toast.js');
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: '<img src=x onerror=alert(1)>' }));
            await boot();

            deleteBtn().click();
            await settle();

            expect(document.querySelector('.toast-body img')).toBeNull();
            expect(document.querySelector('.toast-body').textContent).toBe('<img src=x onerror=alert(1)>');
        });
    });
});
