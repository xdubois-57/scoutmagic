// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked and the two site-wide dialog toolboxes
// (window.ScoutMagicConfirm, window.ScoutMagicToast, loaded by
// base.html.twig on every page) are stubbed, so the deletion's answer is
// something the test chooses rather than something a real modal has to be
// clicked for.
//
// Exercises the REAL public/assets/js/mass-mail-config.js (imported below,
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

// Mirrors what modules/mass_mail/views/config.html.twig renders: two custom
// lists (one active, one not), the shared modal the create/edit flow drives,
// and the site-wide sending-speed form.
const PAGE = `
    <button type="button" id="cfg-new-list-btn">Nouvelle liste</button>
    <ul id="cfg-custom-lists">
        <li data-id="7">
            <button type="button" class="cfg-edit-list-btn" data-id="7" data-name="Parents louveteaux"
                    data-description="Les parents de la meute" data-function-ids="2,3" data-section-ids="5"></button>
            <button type="button" class="cfg-toggle-list-btn" data-id="7" data-active="1"></button>
            <button type="button" class="cfg-delete-list-btn" data-id="7"></button>
        </li>
        <li data-id="9">
            <button type="button" class="cfg-toggle-list-btn" data-id="9" data-active="0"></button>
            <button type="button" class="cfg-delete-list-btn" data-id="9"></button>
        </li>
    </ul>

    <form id="cfg-settings-form">
        <input type="number" id="cfg-batch-size" value="40">
        <input type="number" id="cfg-batch-interval">
        <button type="submit">Enregistrer</button>
    </form>

    <div id="cfg-list-modal">
        <h2 id="cfg-list-modal-title">Nouvelle liste</h2>
        <div id="cfg-list-error" class="d-none"></div>
        <input type="text" id="cfg-list-name" value="">
        <textarea id="cfg-list-description"></textarea>
        <input class="cfg-function-checkbox" type="checkbox" value="2" id="cfg-fn-2">
        <input class="cfg-function-checkbox" type="checkbox" value="3" id="cfg-fn-3">
        <input class="cfg-section-checkbox" type="checkbox" value="5" id="cfg-sec-5">
        <input class="cfg-section-checkbox" type="checkbox" value="6" id="cfg-sec-6">
        <button type="button" id="cfg-list-save-btn">Enregistrer</button>
    </div>
`;

describe('mass-mail-config.js', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)), prompt: vi.fn() };
        window.massMailConfigData = { batchIntervalMinutes: 15 };
        delete window.bootstrap;
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/config/mass-mail', reload: vi.fn() },
        });
    });

    async function boot() {
        // The real fetch toolbox — mass-mail-config.js posts through
        // window.ScoutMagicApi (base.html.twig guarantees this load order in
        // production).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/mass-mail-config.js');
    }

    function lastRequest() {
        const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
        return { url, opts, body: JSON.parse(opts.body) };
    }

    const deleteBtn = () => document.querySelector('.cfg-delete-list-btn[data-id="7"]');

    describe('entry guard', () => {
        it('does nothing at all on a page with neither the modal nor the settings form', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('wires the list rows even when the settings form is absent', async () => {
            document.getElementById('cfg-settings-form').remove();
            await boot();

            document.querySelector('.cfg-toggle-list-btn').click();
            await settle();

            expect(lastRequest().url).toBe('/config/mass-mail/lists/7/toggle');
        });
    });

    describe('server data', () => {
        it('pre-fills the interval field from window.massMailConfigData', async () => {
            await boot();
            expect(document.getElementById('cfg-batch-interval').value).toBe('15');
        });

        it('leaves the field alone when the page shipped no data block', async () => {
            delete window.massMailConfigData;
            await boot();
            expect(document.getElementById('cfg-batch-interval').value).toBe('');
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
            expect(url).toBe('/config/mass-mail/lists/9');
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
            expect(url).toBe('/config/mass-mail/lists/7/toggle');
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

    describe('creating and editing a list', () => {
        it('POSTs a new list with the checked functions and sections', async () => {
            await boot();

            document.getElementById('cfg-new-list-btn').click();
            document.getElementById('cfg-list-name').value = 'Anciens';
            document.getElementById('cfg-list-description').value = 'Les anciens de l\'unité';
            document.getElementById('cfg-fn-3').checked = true;
            document.getElementById('cfg-sec-6').checked = true;
            document.getElementById('cfg-list-save-btn').click();
            await settle();

            const { url, opts, body } = lastRequest();
            expect(url).toBe('/config/mass-mail/lists');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({
                name: 'Anciens',
                description: 'Les anciens de l\'unité',
                function_ids: [3],
                section_ids: [6],
                _csrf_token: 'tok-123',
            });
            expect(window.location.reload).toHaveBeenCalled();
        });

        it('PATCHes the edited list to its own URL, pre-filled from the row', async () => {
            await boot();

            document.querySelector('.cfg-edit-list-btn').click();
            expect(document.getElementById('cfg-list-modal-title').textContent).toBe('Modifier la liste');
            expect(document.getElementById('cfg-list-name').value).toBe('Parents louveteaux');
            expect(document.getElementById('cfg-fn-2').checked).toBe(true);
            expect(document.getElementById('cfg-fn-3').checked).toBe(true);
            expect(document.getElementById('cfg-sec-5').checked).toBe(true);
            expect(document.getElementById('cfg-sec-6').checked).toBe(false);

            document.getElementById('cfg-list-save-btn').click();
            await settle();

            const { url, opts, body } = lastRequest();
            expect(url).toBe('/config/mass-mail/lists/7');
            expect(opts.method).toBe('PATCH');
            expect(body.name).toBe('Parents louveteaux');
            expect(body.function_ids).toEqual([2, 3]);
            expect(body.section_ids).toEqual([5]);
        });

        it('shows a refusal inside the still-open modal rather than in a toast', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Une liste porte déjà ce nom.' }));
            await boot();

            document.getElementById('cfg-list-save-btn').click();
            await settle();

            const box = document.getElementById('cfg-list-error');
            expect(box.textContent).toBe('Une liste porte déjà ce nom.');
            expect(box.classList.contains('d-none')).toBe(false);
            expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('shows the invalid-response line in the modal when the server answers a 500 error page', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();

            document.getElementById('cfg-list-save-btn').click();
            await settle();

            expect(document.getElementById('cfg-list-error').textContent).toBe('Erreur : réponse serveur invalide.');
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('starts a new list from a clean modal after an edit', async () => {
            await boot();

            document.querySelector('.cfg-edit-list-btn').click();
            document.getElementById('cfg-new-list-btn').click();

            expect(document.getElementById('cfg-list-modal-title').textContent).toBe('Nouvelle liste');
            expect(document.getElementById('cfg-list-name').value).toBe('');
            expect(document.getElementById('cfg-fn-2').checked).toBe(false);

            document.getElementById('cfg-list-save-btn').click();
            await settle();

            // Back to the collection URL: the edited id must not linger.
            expect(lastRequest().url).toBe('/config/mass-mail/lists');
            expect(lastRequest().opts.method).toBe('POST');
        });
    });

    describe('sending speed', () => {
        it('POSTs both numbers as integers and confirms with a success toast', async () => {
            await boot();
            document.getElementById('cfg-batch-size').value = '50';
            document.getElementById('cfg-batch-interval').value = '20';

            document.getElementById('cfg-settings-form').dispatchEvent(new Event('submit', { cancelable: true }));
            await settle();

            const { url, body } = lastRequest();
            expect(url).toBe('/config/mass-mail/settings');
            expect(body).toEqual({ batch_size: 50, batch_interval_minutes: 20, _csrf_token: 'tok-123' });
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Réglages enregistrés.', { variant: 'success' });
        });

        it('never lets the form navigate away', async () => {
            await boot();
            const submitEvent = new Event('submit', { cancelable: true });
            document.getElementById('cfg-settings-form').dispatchEvent(submitEvent);
            expect(submitEvent.defaultPrevented).toBe(true);
        });

        it('toasts a business failure instead of announcing a save', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Intervalle invalide.' }));
            await boot();

            document.getElementById('cfg-settings-form').dispatchEvent(new Event('submit', { cancelable: true }));
            await settle();

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Intervalle invalide.', { variant: 'error' });
            expect(window.ScoutMagicToast.show).not.toHaveBeenCalledWith('Réglages enregistrés.', { variant: 'success' });
        });

        it('toasts a network failure instead of announcing a save', async () => {
            global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
            await boot();

            document.getElementById('cfg-settings-form').dispatchEvent(new Event('submit', { cancelable: true }));
            await settle();

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                'Erreur : réponse serveur invalide.',
                { variant: 'error' },
            );
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
