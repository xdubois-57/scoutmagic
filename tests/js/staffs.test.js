// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch is mocked, and so are the two site-wide
// dialog toolboxes (window.ScoutMagicToast, window.ScoutMagicConfirm) and
// window.SelectBar, all of which base.html.twig / select-bar.js provide in
// production. Exercises the REAL implementation in
// public/assets/js/staffs.js (imported below, never reimplemented here).
// That file is an IIFE that reads the DOM at import time, so each test
// builds its fixture first and then imports the module via vi.resetModules()
// + await import().
//
// The fixture mirrors what core/View/templates/chefs/staffs.html.twig
// renders for a chief who can edit the section: the per-document
// title/description fields, the add-document file input, and one
// .badge-picker wrapping a select bar.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Installs a page's server data the way the template does — a
 * `<script type="application/json">` island — rather than an inline
 * assignment to a window global. ScoutMagicApi.pageData() reads it.
 */
function installIsland(id, data) {
    document.getElementById(id)?.remove();
    if (data === undefined) {
        return;
    }
    const el = document.createElement('script');
    el.type = 'application/json';
    el.id = id;
    el.textContent = JSON.stringify(data);
    document.body.appendChild(el);
}

const DOCUMENT_ROW = `
    <div class="section-document-row" data-id="12">
        <input type="text" class="section-document-title-input" data-id="12" value="Charte de section">
        <textarea class="section-document-description-input" data-id="12">Version 2026</textarea>
    </div>`;

const FILE_INPUT = '<input type="file" id="section-document-file-input">';

const BADGE_PICKER = `
    <div class="badge-picker" data-member-year-id="77">
        <div class="select-bar" id="badge-picker-77" data-mode="multi">
            <button class="select-bar-item" data-id="5" data-selected="true">Nage</button>
            <button class="select-bar-item" data-id="7" data-selected="false">Feu</button>
        </div>
    </div>`;

const PAGE = DOCUMENT_ROW + FILE_INPUT + BADGE_PICKER;

/** The site-wide envelope for a JSON answer the server really sent. */
function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

/** An HTML error page: the 500 that is not JSON, and never a save. */
function htmlErrorResponse(status = 500) {
    return Promise.resolve({
        ok: false,
        status,
        json: () => Promise.reject(new SyntaxError('Unexpected token <')),
    });
}

/**
 * A file input jsdom will report a chosen file for. `value` is trapped
 * rather than assigned: clearing a file input is the only observable effect
 * of refusing the oversize dialog.
 */
function attachFile(input, sizeBytes) {
    Object.defineProperty(input, 'files', {
        configurable: true,
        value: [{ name: 'charte.pdf', size: sizeBytes }],
    });
    const cleared = { count: 0 };
    Object.defineProperty(input, 'value', {
        configurable: true,
        get: () => '',
        set: (v) => { if (v === '') cleared.count++; },
    });
    return cleared;
}

describe('staffs.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
        window.SelectBar = { setSelected: vi.fn() };
        installIsland('staffs-data', undefined);
    });

    async function boot() {
        // The real fetch toolbox — staffs.js posts through
        // window.ScoutMagicApi (base.html.twig guarantees this load order
        // in production).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/staffs.js');
    }

    function lastRequest() {
        const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
        return { url, opts, body: JSON.parse(opts.body) };
    }

    function fireChipChange(selectedIds) {
        document.getElementById('badge-picker-77')
            .dispatchEvent(new CustomEvent('select-bar:change', { detail: { selectedIds } }));
    }

    describe('entry guard', () => {
        it('does nothing at all on a page carrying none of its controls', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('wires the documents even when the chief has no badge picker', async () => {
            document.body.innerHTML = DOCUMENT_ROW;
            await boot();
            document.querySelector('.section-document-title-input').dispatchEvent(new Event('blur'));
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().url).toBe('/chefs/staffs/documents/12');
        });
    });

    describe('section document auto-save', () => {
        it('POSTs both fields to the document endpoint with the CSRF token in the body AND the header', async () => {
            await boot();
            const title = document.querySelector('.section-document-title-input');
            title.value = 'Charte 2026-2027';
            title.dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, opts, body } = lastRequest();
            expect(url).toBe('/chefs/staffs/documents/12');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({
                title: 'Charte 2026-2027',
                description: 'Version 2026',
                _csrf_token: 'tok-123',
            });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
        });

        it('saves from the description field too, carrying the current title along', async () => {
            await boot();
            const description = document.querySelector('.section-document-description-input');
            description.value = 'Relue par le staff';
            description.dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().body).toEqual({
                title: 'Charte de section',
                description: 'Relue par le staff',
                _csrf_token: 'tok-123',
            });
        });

        it('says nothing when the save succeeds', async () => {
            await boot();
            document.querySelector('.section-document-title-input').dispatchEvent(new Event('blur'));
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
        });

        it('surfaces a business failure as an error toast', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Document introuvable.' }));
            await boot();
            document.querySelector('.section-document-title-input').dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Document introuvable.', { variant: 'error' }));
        });

        it('reads an HTTP 500 error page as a failure, never as a save (ok is not success)', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();
            document.querySelector('.section-document-title-input').dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur.', { variant: 'error' }));
        });

        it('toasts a network failure instead of failing silently', async () => {
            global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
            await boot();
            document.querySelector('.section-document-title-input').dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur.', { variant: 'error' }));
        });
    });

    describe('oversize upload warning', () => {
        const EXPECTED_MESSAGE = 'Ce fichier fait 8.0 Mo. Aucun outil de compression n\'est disponible sur ce serveur pour le réduire automatiquement.\n\n'
            + 'Vous pouvez le réduire vous-même avant de l\'ajouter : en ligne via iLovePDF (ilovepdf.com), ou hors ligne avec Aperçu (macOS) '
            + 'ou un outil équivalent sous Windows/Linux.\n\n'
            + 'Attention si ce document contient des données personnelles avant de le transmettre à un service en ligne tiers.';

        function enableWarning(mb = 5) {
            installIsland('staffs-data', { oversizeWarningEnabled: true, oversizeWarningMb: mb });
        }

        it('never asks when the server has a compression backend of its own', async () => {
            // The template omits oversizeWarningEnabled in that case, so a
            // 8 Mo file must go through unremarked.
            await boot();
            const input = document.getElementById('section-document-file-input');
            attachFile(input, 8 * 1024 * 1024);
            input.dispatchEvent(new Event('change'));

            await Promise.resolve();
            expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
        });

        it('never asks for a file under the threshold', async () => {
            enableWarning();
            await boot();
            const input = document.getElementById('section-document-file-input');
            attachFile(input, 2 * 1024 * 1024);
            input.dispatchEvent(new Event('change'));

            await Promise.resolve();
            expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
        });

        it('asks with the page\'s own French wording, named buttons and no danger styling', async () => {
            enableWarning();
            await boot();
            const input = document.getElementById('section-document-file-input');
            attachFile(input, 8 * 1024 * 1024);
            input.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
                message: EXPECTED_MESSAGE,
                confirmLabel: 'Ajouter quand même',
                cancelLabel: 'Choisir un autre fichier',
                variant: 'primary',
            });
        });

        it('keeps the chosen file when the chief confirms', async () => {
            enableWarning();
            await boot();
            const input = document.getElementById('section-document-file-input');
            const cleared = attachFile(input, 8 * 1024 * 1024);
            input.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            await Promise.resolve();
            expect(cleared.count).toBe(0);
        });

        it('clears the chosen file when the chief declines', async () => {
            enableWarning();
            window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(false)) };
            await boot();
            const input = document.getElementById('section-document-file-input');
            const cleared = attachFile(input, 8 * 1024 * 1024);
            input.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(cleared.count).toBe(1));
        });

        it('honours the server-configured threshold rather than a hardcoded one', async () => {
            enableWarning(20);
            await boot();
            const input = document.getElementById('section-document-file-input');
            attachFile(input, 8 * 1024 * 1024);
            input.dispatchEvent(new Event('change'));

            await Promise.resolve();
            expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
        });
    });

    describe('badge picker', () => {
        it('POSTs the one badge that was just added, as integers', async () => {
            await boot();
            fireChipChange(['5', '7']);

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, opts, body } = lastRequest();
            expect(url).toBe('/chefs/staffs/badge-toggle');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({ member_year_id: 77, badge_id: 7, _csrf_token: 'tok-123' });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
        });

        it('POSTs the one badge that was just removed', async () => {
            await boot();
            fireChipChange([]);

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().body).toEqual({ member_year_id: 77, badge_id: 5, _csrf_token: 'tok-123' });
        });

        it('sends nothing when the selection did not actually change', async () => {
            await boot();
            fireChipChange(['5']);

            await Promise.resolve();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('adopts the new selection on success, so the next toggle diffs against it', async () => {
            await boot();
            fireChipChange(['5', '7']);
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
            // Let the success path run to the end: the picker adopts the
            // new selection only once the server has confirmed it.
            await new Promise((resolve) => setTimeout(resolve, 0));

            fireChipChange(['7']);
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
            expect(lastRequest().body).toEqual({ member_year_id: 77, badge_id: 5, _csrf_token: 'tok-123' });
            expect(window.SelectBar.setSelected).not.toHaveBeenCalled();
        });

        it('reverts the optimistic row and toasts the server error on a business failure', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Badge non attribuable.' }));
            await boot();
            fireChipChange(['5', '7']);

            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled());
            await vi.waitFor(() => expect(window.SelectBar.setSelected)
                .toHaveBeenCalledWith('badge-picker-77', '7', false));
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Badge non attribuable.', { variant: 'error' });
        });

        it('reverts a removal the same way, putting the row back', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Badge non attribuable.' }));
            await boot();
            fireChipChange([]);

            await vi.waitFor(() => expect(window.SelectBar.setSelected)
                .toHaveBeenCalledWith('badge-picker-77', '5', true));
        });

        it('reads an HTTP 500 error page as a failure, never as a toggle', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();
            fireChipChange(['5', '7']);

            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur.', { variant: 'error' }));
            expect(window.SelectBar.setSelected).toHaveBeenCalledWith('badge-picker-77', '7', false);
        });
    });

    describe('server text never becomes markup', () => {
        it('renders a script-carrying error message as text in the real toast', async () => {
            // The one place server-controlled text reaches the DOM here.
            // Run it through the REAL toast.js rather than the stub, so the
            // escaping guarantee is the production one.
            delete window.ScoutMagicToast;
            await import('../../public/assets/js/toast.js');
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: '<img src=x onerror=alert(1)>' }));
            await boot();
            document.querySelector('.section-document-title-input').dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
            expect(document.querySelector('.toast-body img')).toBeNull();
            expect(document.querySelector('.toast-body').textContent).toBe('<img src=x onerror=alert(1)>');
        });
    });
});
