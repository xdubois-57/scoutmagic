// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked and window.ScoutMagicConfirm is stubbed, so
// the one destructive action's answer is something the test chooses.
//
// Exercises the REAL public/assets/js/mass-mail-list-addresses.js on top of
// the real api.js envelope. The file is an IIFE that wires itself against
// the DOM present at import time, so every test builds its fixture first
// and imports afterwards — the tests/js/mass-mail-lists.test.js pattern.
//
// What this suite owns: the whole set is fetched ONCE and everything after
// that happens here — the accent-insensitive search, the « désinscrites
// seulement » filter and the slicing — plus the two guarantees a screen
// full of addresses has to make, that an unsubscribed row offers no way to
// change it and that a name somebody typed never becomes markup.
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
    await new Promise((resolve) => setTimeout(resolve, 0));
}

// Mirrors the panel modules/mass_mail/views/mailing_lists.html.twig renders
// inside each custom list's row.
const PAGE = `
    <details class="mml-addresses" data-list-id="7">
        <summary><span data-address-count>0 adresse</span></summary>
        <div>
            <input type="search" data-address-search>
            <input type="checkbox" data-address-unsubscribed-only>
            <div class="d-none" data-address-error></div>
            <ul data-address-rows></ul>
            <p class="d-none" data-address-empty>Aucune adresse ne correspond.</p>
            <button type="button" class="d-none" data-address-more></button>
            <form data-address-add-form>
                <input type="text" data-address-new-name>
                <input type="email" data-address-new-email>
                <button type="submit">Ajouter</button>
            </form>
            <a href="/admin/listes-de-diffusion/lists/7/addresses/export">Exporter en Excel</a>
            <input type="file" data-address-import-input>
            <div class="d-none" data-address-import-preview>
                <p data-address-import-summary></p>
                <ul class="d-none" data-address-import-errors></ul>
                <button type="button" data-address-import-confirm>Remplacer les adresses</button>
                <button type="button" data-address-import-cancel>Annuler</button>
            </div>
        </div>
    </details>
`;

function address(id, name, email, unsubscribedAt = null) {
    return { id, name, email, unsubscribed_at: unsubscribedAt };
}

describe('mass-mail-list-addresses.js', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)), prompt: vi.fn() };
        global.fetch = vi.fn(() => jsonResponse({ success: true, addresses: [] }));
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/mass-mail-list-addresses.js');
    }

    const panel = () => document.querySelector('.mml-addresses');
    const rows = () => Array.from(document.querySelectorAll('[data-address-rows] li'));
    const rowTexts = () => rows().map((li) => li.querySelector('span').textContent);
    const searchEl = () => document.querySelector('[data-address-search]');
    const moreEl = () => document.querySelector('[data-address-more]');
    const countEl = () => document.querySelector('[data-address-count]');
    const errorEl = () => document.querySelector('[data-address-error]');

    /** Opens the panel the way a person does, and lets the fetch settle. */
    async function open() {
        panel().setAttribute('open', '');
        panel().dispatchEvent(new Event('toggle'));
        await settle();
    }

    function serve(addresses) {
        global.fetch = vi.fn(() => jsonResponse({ success: true, addresses }));
    }

    describe('loading', () => {
        it('fetches nothing at all until the panel is opened', async () => {
            serve([address(1, 'Commune', 'jeunesse@wavre.be')]);
            await boot();

            expect(fetch).not.toHaveBeenCalled();
        });

        it('fetches the whole set once, on the first opening only', async () => {
            serve([address(1, 'Commune', 'jeunesse@wavre.be')]);
            await boot();

            await open();
            expect(fetch).toHaveBeenCalledTimes(1);
            expect(fetch.mock.calls[0][0]).toBe('/admin/listes-de-diffusion/lists/7/addresses');
            expect(rowTexts()).toEqual(['Commune — jeunesse@wavre.be']);

            panel().removeAttribute('open');
            panel().dispatchEvent(new Event('toggle'));
            panel().setAttribute('open', '');
            panel().dispatchEvent(new Event('toggle'));
            await settle();

            expect(fetch).toHaveBeenCalledTimes(1);
        });

        it('says so when the set could not be read, rather than showing an empty list', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();

            await open();

            expect(errorEl().classList.contains('d-none')).toBe(false);
            expect(errorEl().textContent).toBe('Erreur : réponse serveur invalide.');
        });

        it('shows an address with no name as the address alone', async () => {
            serve([address(1, null, 'cure@paroisse.be')]);
            await boot();
            await open();

            expect(rowTexts()).toEqual(['cure@paroisse.be']);
        });
    });

    describe('searching, in the browser', () => {
        beforeEach(() => {
            serve([
                address(1, 'Commune de Wavre', 'jeunesse@wavre.be'),
                address(2, 'Curé', 'cure@paroisse.be'),
                address(3, 'Éric Dupont', 'eric@example.be'),
            ]);
        });

        it('ignores case and accents, both ways round', async () => {
            await boot();
            await open();

            searchEl().value = 'ERIC';
            searchEl().dispatchEvent(new Event('input'));
            expect(rowTexts()).toEqual(['Éric Dupont — eric@example.be']);

            searchEl().value = 'curé';
            searchEl().dispatchEvent(new Event('input'));
            expect(rowTexts()).toEqual(['Curé — cure@paroisse.be']);
        });

        it('searches the address as well as the name', async () => {
            await boot();
            await open();

            searchEl().value = 'wavre.be';
            searchEl().dispatchEvent(new Event('input'));

            expect(rowTexts()).toEqual(['Commune de Wavre — jeunesse@wavre.be']);
        });

        it('says the search matched nothing rather than showing an empty box', async () => {
            await boot();
            await open();

            searchEl().value = 'introuvable';
            searchEl().dispatchEvent(new Event('input'));

            expect(rows()).toHaveLength(0);
            expect(document.querySelector('[data-address-empty]').classList.contains('d-none')).toBe(false);
        });

        it('sends no request while searching — the set is already here', async () => {
            await boot();
            await open();
            const callsAfterLoad = fetch.mock.calls.length;

            searchEl().value = 'wavre';
            searchEl().dispatchEvent(new Event('input'));

            expect(fetch.mock.calls).toHaveLength(callsAfterLoad);
        });
    });

    describe('the « désinscrites seulement » filter', () => {
        beforeEach(() => {
            serve([
                address(1, 'Commune', 'jeunesse@wavre.be'),
                address(2, 'Curé', 'cure@paroisse.be', '2026-05-04 10:00:00'),
            ]);
        });

        it('narrows to the unsubscribed rows and back', async () => {
            await boot();
            await open();
            const filter = document.querySelector('[data-address-unsubscribed-only]');

            filter.checked = true;
            filter.dispatchEvent(new Event('change'));
            expect(rowTexts()).toEqual(['Curé — cure@paroisse.be']);

            filter.checked = false;
            filter.dispatchEvent(new Event('change'));
            expect(rows()).toHaveLength(2);
        });

        /**
         * D3, on screen: an unsubscribed row stays visible, says it is
         * unsubscribed, and offers no way to change it. « 312 contacts »
         * followed by « 304 envoyés » reads as a breakdown unless the
         * screen says which eight are out.
         */
        it('marks an unsubscribed row and offers neither Modifier nor Supprimer', async () => {
            await boot();
            await open();

            const unsubscribed = rows().find((li) => li.textContent.includes('Curé'));
            expect(unsubscribed.textContent).toContain('Désinscrite');
            expect(unsubscribed.querySelectorAll('button')).toHaveLength(0);

            const active = rows().find((li) => li.textContent.includes('Commune'));
            expect(Array.from(active.querySelectorAll('button')).map((b) => b.textContent))
                .toEqual(['Modifier', 'Supprimer']);
        });
    });

    describe('rendering in slices', () => {
        beforeEach(() => {
            serve(Array.from({ length: 120 }, (_, i) => address(i + 1, 'Contact ' + i, 'c' + i + '@test.be')));
        });

        it('draws fifty at a time and says how many are left', async () => {
            await boot();
            await open();

            expect(rows()).toHaveLength(50);
            expect(moreEl().classList.contains('d-none')).toBe(false);
            expect(moreEl().textContent).toBe('Afficher plus (70 restantes)');

            moreEl().click();
            expect(rows()).toHaveLength(100);

            moreEl().click();
            expect(rows()).toHaveLength(120);
            expect(moreEl().classList.contains('d-none')).toBe(true);
        });

        it('goes back to the first slice when the search changes', async () => {
            await boot();
            await open();
            moreEl().click();
            expect(rows()).toHaveLength(100);

            searchEl().value = 'contact';
            searchEl().dispatchEvent(new Event('input'));

            expect(rows()).toHaveLength(50);
        });
    });

    describe('adding, editing and removing without a reload', () => {
        it('POSTs a new address with the CSRF token and puts it in place', async () => {
            serve([]);
            await boot();
            await open();

            global.fetch = vi.fn(() => jsonResponse({
                success: true,
                address: address(9, 'Commune', 'jeunesse@wavre.be'),
                counts: { total: 1, unsubscribed: 0 },
            }));
            document.querySelector('[data-address-new-name]').value = 'Commune';
            document.querySelector('[data-address-new-email]').value = 'jeunesse@wavre.be';
            document.querySelector('[data-address-add-form]')
                .dispatchEvent(new Event('submit', { cancelable: true }));
            await settle();

            const [url, opts] = fetch.mock.calls[0];
            expect(url).toBe('/admin/listes-de-diffusion/lists/7/addresses');
            expect(JSON.parse(opts.body)).toEqual({
                name: 'Commune',
                email: 'jeunesse@wavre.be',
                _csrf_token: 'tok-123',
            });
            expect(rowTexts()).toEqual(['Commune — jeunesse@wavre.be']);
            expect(countEl().textContent).toBe('1 adresse');
            expect(document.querySelector('[data-address-new-email]').value).toBe('');
        });

        it('shows the server refusal and adds nothing', async () => {
            serve([]);
            await boot();
            await open();

            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Adresse email invalide.' }));
            document.querySelector('[data-address-new-email]').value = 'pas-une-adresse';
            document.querySelector('[data-address-add-form]')
                .dispatchEvent(new Event('submit', { cancelable: true }));
            await settle();

            expect(errorEl().textContent).toBe('Adresse email invalide.');
            expect(rows()).toHaveLength(0);
        });

        it('never lets the add form navigate away', async () => {
            serve([]);
            await boot();
            await open();

            global.fetch = vi.fn(() => jsonResponse({
                success: true,
                address: address(9, null, 'x@test.be'),
                counts: { total: 1, unsubscribed: 0 },
            }));
            const submitEvent = new Event('submit', { cancelable: true });
            document.querySelector('[data-address-add-form]').dispatchEvent(submitEvent);
            await settle();

            expect(submitEvent.defaultPrevented).toBe(true);
        });

        it('reads a success envelope with nothing in it as a failure, never as an add', async () => {
            serve([]);
            await boot();
            await open();

            global.fetch = vi.fn(() => jsonResponse({ success: true }));
            document.querySelector('[data-address-new-email]').value = 'x@test.be';
            document.querySelector('[data-address-add-form]')
                .dispatchEvent(new Event('submit', { cancelable: true }));
            await settle();

            expect(errorEl().classList.contains('d-none')).toBe(false);
            expect(rows()).toHaveLength(0);
        });

        it('PATCHes an edit made in the row itself', async () => {
            serve([address(4, 'Commune', 'ancienne@wavre.be')]);
            await boot();
            await open();

            rows()[0].querySelector('button').click();
            const inputs = rows()[0].querySelectorAll('input');
            expect(inputs[0].value).toBe('Commune');
            expect(inputs[1].value).toBe('ancienne@wavre.be');

            global.fetch = vi.fn(() => jsonResponse({
                success: true,
                address: address(4, 'Commune de Wavre', 'nouvelle@wavre.be'),
            }));
            inputs[0].value = 'Commune de Wavre';
            inputs[1].value = 'nouvelle@wavre.be';
            Array.from(rows()[0].querySelectorAll('button'))
                .find((b) => b.textContent === 'Enregistrer').click();
            await settle();

            const [url, opts] = fetch.mock.calls[0];
            expect(url).toBe('/admin/listes-de-diffusion/addresses/4');
            expect(opts.method).toBe('PATCH');
            expect(rowTexts()).toEqual(['Commune de Wavre — nouvelle@wavre.be']);
        });

        it('puts the row back untouched when the edit is cancelled', async () => {
            serve([address(4, 'Commune', 'ancienne@wavre.be')]);
            await boot();
            await open();

            rows()[0].querySelector('button').click();
            Array.from(rows()[0].querySelectorAll('button'))
                .find((b) => b.textContent === 'Annuler').click();

            expect(rowTexts()).toEqual(['Commune — ancienne@wavre.be']);
        });

        it('asks before removing, and sends nothing when the answer is no', async () => {
            serve([address(4, 'Commune', 'jeunesse@wavre.be')]);
            await boot();
            await open();
            const callsAfterLoad = fetch.mock.calls.length;

            window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
            Array.from(rows()[0].querySelectorAll('button'))
                .find((b) => b.textContent === 'Supprimer').click();
            await settle();

            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled();
            expect(fetch.mock.calls).toHaveLength(callsAfterLoad);
            expect(rows()).toHaveLength(1);
        });

        it('DELETEs the confirmed row and refreshes the count from the server', async () => {
            serve([address(4, 'Commune', 'jeunesse@wavre.be')]);
            await boot();
            await open();

            global.fetch = vi.fn(() => jsonResponse({ success: true, counts: { total: 0, unsubscribed: 0 } }));
            Array.from(rows()[0].querySelectorAll('button'))
                .find((b) => b.textContent === 'Supprimer').click();
            await settle();

            const [url, opts] = fetch.mock.calls[0];
            expect(url).toBe('/admin/listes-de-diffusion/addresses/4');
            expect(opts.method).toBe('DELETE');
            expect(rows()).toHaveLength(0);
            expect(countEl().textContent).toBe('0 adresse');
        });
    });

    describe('the Excel round trip', () => {
        const importInput = () => document.querySelector('[data-address-import-input]');
        const importPreview = () => document.querySelector('[data-address-import-preview]');
        const importSummary = () => document.querySelector('[data-address-import-summary]');
        const importErrors = () => document.querySelector('[data-address-import-errors]');

        /**
         * Presents a chosen file the way a browser does, then fires the
         * change event the script listens for.
         */
        function chooseFile() {
            Object.defineProperty(importInput(), 'files', {
                configurable: true,
                value: [new File(['x'], 'adresses.xlsx')],
            });
            importInput().dispatchEvent(new Event('change'));
        }

        it('offers the export as a plain link, never as a script-driven download', async () => {
            serve([]);
            await boot();
            await open();

            const link = document.querySelector('a[href$="/addresses/export"]');
            expect(link).not.toBeNull();
            expect(link.getAttribute('href'))
                .toBe('/admin/listes-de-diffusion/lists/7/addresses/export');
        });

        it('uploads for ANALYSIS and shows the counts without replacing anything', async () => {
            serve([address(1, 'Part', 'part@test.be')]);
            await boot();
            await open();

            global.fetch = vi.fn(() => jsonResponse({
                success: true,
                summary: { added: 12, unchanged: 284, removed: 5, kept_unsubscribed: 1 },
                addresses: [{ name: 'Nouvelle', email: 'nouvelle@test.be' }],
                errors: [],
                duplicates: 0,
            }));
            chooseFile();
            await settle();

            const [url, opts] = fetch.mock.calls[0];
            expect(url).toBe('/admin/listes-de-diffusion/lists/7/addresses/import');
            expect(opts.method).toBe('POST');
            expect(opts.body).toBeInstanceOf(FormData);
            expect(opts.body.get('_csrf_token')).toBe('tok-123');

            expect(importPreview().classList.contains('d-none')).toBe(false);
            expect(importSummary().textContent).toBe(
                '12 adresses ajoutées · 284 inchangées · 5 supprimées · '
                + '1 désinscrite — conservée, toujours exclue des envois',
            );
            // Nothing has been replaced: the list on screen is untouched.
            expect(rowTexts()).toEqual(['Part — part@test.be']);
        });

        it('leaves the unsubscribed clause out when there is none', async () => {
            serve([]);
            await boot();
            await open();

            global.fetch = vi.fn(() => jsonResponse({
                success: true,
                summary: { added: 1, unchanged: 0, removed: 0, kept_unsubscribed: 0 },
                addresses: [{ name: null, email: 'nouvelle@test.be' }],
                errors: [],
                duplicates: 0,
            }));
            chooseFile();
            await settle();

            expect(importSummary().textContent).toBe('1 adresse ajoutée · 0 inchangée · 0 supprimée');
        });

        it('lists the lines that will not be imported, as text', async () => {
            serve([]);
            await boot();
            await open();

            global.fetch = vi.fn(() => jsonResponse({
                success: true,
                summary: { added: 1, unchanged: 0, removed: 0, kept_unsubscribed: 0 },
                addresses: [{ name: null, email: 'ok@test.be' }],
                errors: ['Ligne 3 — « <img src=x> » n\'est pas une adresse email valide.'],
                duplicates: 0,
            }));
            chooseFile();
            await settle();

            expect(importErrors().classList.contains('d-none')).toBe(false);
            expect(importErrors().querySelector('img')).toBeNull();
            expect(importErrors().querySelector('li').textContent)
                .toBe('Ligne 3 — « <img src=x> » n\'est pas une adresse email valide.');
        });

        it('shows the structural refusal and offers nothing to confirm', async () => {
            serve([]);
            await boot();
            await open();

            global.fetch = vi.fn(() => jsonResponse(
                { success: false, errors: ['Le fichier doit contenir une colonne « Adresse ».'] },
                422,
            ));
            chooseFile();
            await settle();

            expect(errorEl().textContent).toBe('Le fichier doit contenir une colonne « Adresse ».');
            expect(importPreview().classList.contains('d-none')).toBe(true);
        });

        it('replaces nothing until the second, explicit click', async () => {
            serve([address(1, 'Part', 'part@test.be')]);
            await boot();
            await open();

            global.fetch = vi.fn(() => jsonResponse({
                success: true,
                summary: { added: 1, unchanged: 0, removed: 1, kept_unsubscribed: 0 },
                addresses: [{ name: 'Nouvelle', email: 'nouvelle@test.be' }],
                errors: [],
                duplicates: 0,
            }));
            chooseFile();
            await settle();
            const callsAfterAnalysis = fetch.mock.calls.length;

            document.querySelector('[data-address-import-cancel]').click();

            expect(importPreview().classList.contains('d-none')).toBe(true);
            expect(fetch.mock.calls).toHaveLength(callsAfterAnalysis);
            expect(rowTexts()).toEqual(['Part — part@test.be']);
        });

        it('confirms with the rows the analysis returned, then reloads the set from the server', async () => {
            serve([address(1, 'Part', 'part@test.be')]);
            await boot();
            await open();

            global.fetch = vi.fn(() => jsonResponse({
                success: true,
                summary: { added: 1, unchanged: 0, removed: 1, kept_unsubscribed: 0 },
                addresses: [{ name: 'Nouvelle', email: 'nouvelle@test.be' }],
                errors: [],
                duplicates: 0,
            }));
            chooseFile();
            await settle();

            const calls = [];
            global.fetch = vi.fn((url, opts) => {
                calls.push([url, opts]);
                if (String(url).endsWith('/import/confirm')) {
                    return jsonResponse({
                        success: true,
                        summary: { added: 1, unchanged: 0, removed: 1, kept_unsubscribed: 0 },
                        counts: { total: 1, unsubscribed: 0 },
                    });
                }
                return jsonResponse({ success: true, addresses: [address(2, 'Nouvelle', 'nouvelle@test.be')] });
            });
            document.querySelector('[data-address-import-confirm]').click();
            await settle();

            expect(calls[0][0]).toBe('/admin/listes-de-diffusion/lists/7/addresses/import/confirm');
            expect(JSON.parse(calls[0][1].body)).toEqual({
                addresses: [{ name: 'Nouvelle', email: 'nouvelle@test.be' }],
                _csrf_token: 'tok-123',
            });
            // The set on screen came back from the server, not from a guess.
            expect(calls[1][0]).toBe('/admin/listes-de-diffusion/lists/7/addresses');
            expect(rowTexts()).toEqual(['Nouvelle — nouvelle@test.be']);
            expect(countEl().textContent).toBe('1 adresse');
            expect(importPreview().classList.contains('d-none')).toBe(true);
        });

        it('keeps what is on screen when the confirmation is refused', async () => {
            serve([address(1, 'Part', 'part@test.be')]);
            await boot();
            await open();

            global.fetch = vi.fn(() => jsonResponse({
                success: true,
                summary: { added: 1, unchanged: 0, removed: 1, kept_unsubscribed: 0 },
                addresses: [{ name: 'Nouvelle', email: 'nouvelle@test.be' }],
                errors: [],
                duplicates: 0,
            }));
            chooseFile();
            await settle();

            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Rien n\'a été modifié.' }, 422));
            document.querySelector('[data-address-import-confirm]').click();
            await settle();

            expect(errorEl().textContent).toBe('Rien n\'a été modifié.');
            expect(rowTexts()).toEqual(['Part — part@test.be']);
        });
    });

    describe('server text never becomes markup', () => {
        it('renders a script-carrying name as text', async () => {
            serve([address(1, '<img src=x onerror=alert(1)>', 'x@test.be')]);
            await boot();
            await open();

            expect(document.querySelector('[data-address-rows] img')).toBeNull();
            expect(rowTexts()[0]).toBe('<img src=x onerror=alert(1)> — x@test.be');
        });
    });
});
