// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked, so are
// window.ScoutMagicToast, window.location.reload (jsdom does not
// implement navigation) and Bootstrap's Modal (jsdom runs no Bootstrap;
// the fake records show/hide and lets a test fire `hidden.bs.modal`
// itself, which is the event the whole add flow hangs its cleanup on).
// Exercises the REAL implementation in
// public/assets/js/banner-config.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the
// module via vi.resetModules() + await import().
//
// The fixture mirrors what modules/banner/views/config.html.twig
// renders: the list editor's add button, two visibility selects, and the
// shared rich-text dialog (partials/rich_text_editor.html.twig).
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <div id="banner-list">
        <div class="list-editor-item" data-id="3">
            <select class="banner-role-min-select" data-id="3">
                <option value="">Tout le monde</option>
                <option value="member" selected>Membres</option>
            </select>
        </div>
        <button type="button" class="list-editor-add-btn" data-url="/config/banner/add"></button>
    </div>
    <div id="richTextEditorModal">
        <div id="richTextEditorContent" contenteditable="true">résidu du précédent</div>
        <button type="button" id="richTextEditorSave"></button>
    </div>`;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('banner-config.js', () => {
    let modalInstance;

    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true, banner_id: 42 }));
        window.ScoutMagicToast = { show: vi.fn() };
        modalInstance = { show: vi.fn(), hide: vi.fn() };
        window.bootstrap = { Modal: vi.fn(() => modalInstance) };
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/config/banner', reload: vi.fn() },
        });
    });

    async function boot() {
        // The real fetch toolbox — banner-config.js posts through
        // window.ScoutMagicApi (base.html.twig guarantees this load order
        // in production).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/banner-config.js');
    }

    function requestTo(target) {
        const call = fetch.mock.calls.find(([url]) => String(url) === target);
        if (!call) {
            return null;
        }
        const [url, opts] = call;
        return { url, opts, body: JSON.parse(opts.body) };
    }

    const addBtn = () => document.querySelector('.list-editor-add-btn');
    const saveBtn = () => document.getElementById('richTextEditorSave');
    const editor = () => document.getElementById('richTextEditorContent');
    const closeDialog = () =>
        document.getElementById('richTextEditorModal')
            .dispatchEvent(new Event('hidden.bs.modal'));

    describe('entry guard', () => {
        it('does nothing at all on a page with no banner list', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('still wires the selects when the rich-text dialog is absent', async () => {
            document.getElementById('richTextEditorModal').remove();
            await boot();
            document.querySelector('.banner-role-min-select').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(requestTo('/config/banner/role-min')).not.toBeNull();
        });
    });

    describe('visibility select', () => {
        it('POSTs the id as a number, the role, and the CSRF token', async () => {
            await boot();
            document.querySelector('.banner-role-min-select').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(requestTo('/config/banner/role-min')).not.toBeNull());
            const { opts, body } = requestTo('/config/banner/role-min');
            expect(opts.method).toBe('POST');
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
            expect(body).toEqual({ id: 3, role_min: 'member', _csrf_token: 'tok-123' });
        });

        it('says nothing on success', async () => {
            await boot();
            document.querySelector('.banner-role-min-select').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
        });

        it('shows the server\'s own message on a refusal answered with HTTP 200', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Rôle inconnu.' }));
            await boot();
            document.querySelector('.banner-role-min-select').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Rôle inconnu.', { variant: 'error' });
        });

        it('shows a generic error when the network dies', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
            await boot();
            document.querySelector('.banner-role-min-select').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Erreur.', { variant: 'error' });
        });
    });

    describe('add flow', () => {
        it('creates the row at the add_url the template put on the button', async () => {
            await boot();
            addBtn().click();

            await vi.waitFor(() => expect(requestTo('/config/banner/add')).not.toBeNull());
            expect(requestTo('/config/banner/add').body).toEqual({ _csrf_token: 'tok-123' });
        });

        it('opens the dialog on an EMPTY editor — never the previous banner\'s text', async () => {
            await boot();
            addBtn().click();

            await vi.waitFor(() => expect(modalInstance.show).toHaveBeenCalled());
            expect(editor().innerHTML).toBe('');
        });

        it('does not open the dialog when the row could not be created', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Quota atteint.' }));
            await boot();
            addBtn().click();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Quota atteint.', { variant: 'error' });
            expect(modalInstance.show).not.toHaveBeenCalled();
        });

        it('stores the text under the new banner\'s key and reloads', async () => {
            await boot();
            addBtn().click();
            await vi.waitFor(() => expect(modalInstance.show).toHaveBeenCalled());

            editor().innerHTML = '<p>Camp annulé</p>';
            saveBtn().click();

            await vi.waitFor(() => expect(requestTo('/api/rich-text-content')).not.toBeNull());
            expect(requestTo('/api/rich-text-content').body).toEqual({
                key: 'banner_content_42',
                value: '<p>Camp annulé</p>',
                type: 'rich_text',
                _csrf_token: 'tok-123',
            });
            await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
            expect(modalInstance.hide).toHaveBeenCalled();
        });

        it('keeps the dialog open and says why when the text could not be stored', async () => {
            await boot();
            addBtn().click();
            await vi.waitFor(() => expect(modalInstance.show).toHaveBeenCalled());

            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Contenu refusé.' }));
            saveBtn().click();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Contenu refusé.', { variant: 'error' });
            expect(modalInstance.hide).not.toHaveBeenCalled();
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('DELETES the row again when the dialog is dismissed without saving', async () => {
            await boot();
            addBtn().click();
            await vi.waitFor(() => expect(modalInstance.show).toHaveBeenCalled());

            closeDialog();

            await vi.waitFor(() => expect(requestTo('/config/banner/delete')).not.toBeNull());
            expect(requestTo('/config/banner/delete').body)
                .toEqual({ id: 42, _csrf_token: 'tok-123' });
        });

        it('does NOT delete the row when the dialog closes after a successful save', async () => {
            await boot();
            addBtn().click();
            await vi.waitFor(() => expect(modalInstance.show).toHaveBeenCalled());

            saveBtn().click();
            await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
            closeDialog();

            await new Promise((resolve) => setTimeout(resolve, 0));
            expect(requestTo('/config/banner/delete')).toBeNull();
        });

        it('unwires both listeners on close — a second « Ajouter » never writes into the first key', async () => {
            await boot();
            addBtn().click();
            await vi.waitFor(() => expect(modalInstance.show).toHaveBeenCalled());
            closeDialog();
            await vi.waitFor(() => expect(requestTo('/config/banner/delete')).not.toBeNull());

            // The first flow is over: its save handler must be gone.
            fetch.mockClear();
            saveBtn().click();
            await new Promise((resolve) => setTimeout(resolve, 0));
            expect(requestTo('/api/rich-text-content')).toBeNull();

            // And a second close must not delete the (already deleted) row twice.
            closeDialog();
            await new Promise((resolve) => setTimeout(resolve, 0));
            expect(requestTo('/config/banner/delete')).toBeNull();
        });

        it('writes into the SECOND banner\'s key when « Ajouter » is used twice', async () => {
            await boot();
            addBtn().click();
            await vi.waitFor(() => expect(modalInstance.show).toHaveBeenCalled());
            closeDialog();
            await vi.waitFor(() => expect(requestTo('/config/banner/delete')).not.toBeNull());

            global.fetch = vi.fn(() => jsonResponse({ success: true, banner_id: 43 }));
            addBtn().click();
            await vi.waitFor(() => expect(modalInstance.show).toHaveBeenCalledTimes(2));
            saveBtn().click();

            await vi.waitFor(() => expect(requestTo('/api/rich-text-content')).not.toBeNull());
            expect(requestTo('/api/rich-text-content').body.key).toBe('banner_content_43');
        });
    });
});
