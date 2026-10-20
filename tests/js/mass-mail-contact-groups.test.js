// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked and Bootstrap is absent, so the dialog is a
// plain block of markup the test drives directly.
//
// Exercises the REAL public/assets/js/mass-mail-contact-groups.js on top of
// the real api.js envelope. The file is an IIFE that wires itself against
// the DOM present at import time, so every test builds its fixture first
// and imports afterwards.
//
// What this suite owns (CHANTIER-listes-de-diffusion-blocs.md, lot C):
// choosing a file previews it without writing anything, « Annuler » sends
// nothing, « Enregistrer » sends the name and the contacts together, the
// refused lines are rendered as text, the search ignores accents, the
// contacts arrive in slices of fifty, an unsubscribed contact is greyed,
// and a save emits `mass-mail:blocks-changed` for the list's page.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

async function settle() {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

// Mirrors modules/mass_mail/views/partials/_contact_group_dialog.html.twig
// (its hooks are pinned by Tests\Modules\MassMail\
// ContactGroupDialogRenderingTest), plus the two openers the list's page
// renders by contract.
const PAGE = `
    <button type="button" data-contact-group-open id="opener-new">Groupe de contacts</button>
    <div data-contact-group-open data-block-id="12" id="opener-12">Partenaires</div>
    <div data-contact-group-open data-block-id="13" id="opener-13">Commune</div>
    <div class="modal" id="mm-contact-group-modal">
        <div data-contact-group-dialog data-list-id="7">
            <div class="alert d-none" data-cg-error></div>
            <input type="text" id="mm-cg-name" data-cg-name>
            <div data-cg-new>
                <input type="file" data-cg-file-new>
                <span data-cg-file-name></span>
                <a href="/admin/listes-de-diffusion/contact-groups/template" data-cg-template>Télécharger un modèle vide</a>
            </div>
            <div class="d-none" data-cg-existing>
                <p data-cg-count></p>
                <input type="search" data-cg-search>
                <input type="checkbox" data-cg-unsubscribed-only>
                <ul data-cg-rows></ul>
                <p class="d-none" data-cg-empty>Aucun contact ne correspond.</p>
                <button type="button" class="d-none" data-cg-more>Afficher plus</button>
                <a data-cg-export href="#">Exporter en Excel</a>
                <input type="file" data-cg-file-existing>
            </div>
            <div class="d-none" data-cg-preview>
                <p data-cg-summary></p>
                <ul class="d-none" data-cg-line-errors></ul>
            </div>
        </div>
        <button type="button" data-bs-dismiss="modal" id="cancel">Annuler</button>
        <button type="button" data-cg-save disabled>Enregistrer</button>
    </div>
`;

function contact(id, name, email, unsubscribedAt = null) {
    return { id, name, email, unsubscribed_at: unsubscribedAt };
}

function group(overrides = {}) {
    return {
        id: 12,
        list_id: 7,
        kind: 'contact_group',
        name: 'Partenaires',
        file_replaced_at: '2026-09-12 10:00:00',
        file_replaced_label: '12 septembre 2026',
        count: 0,
        unsubscribed: 0,
        ...overrides,
    };
}

function preview(overrides = {}) {
    return {
        success: true,
        summary: { added: 2, unchanged: 0, removed: 0, kept_unsubscribed: 0 },
        contacts: [
            { name: 'Commune', email: 'jeunesse@wavre.be' },
            { name: null, email: 'cure@paroisse.be' },
        ],
        errors: [],
        duplicates: 0,
        ...overrides,
    };
}

describe('mass-mail-contact-groups.js', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        window.ScoutMagicToast = { show: vi.fn() };
        delete window.bootstrap;
        global.fetch = vi.fn(() => jsonResponse({ success: false }));
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/mass-mail-contact-groups.js');
    }

    const $ = (selector) => document.querySelector(selector);
    const hook = (name) => $('[data-cg-' + name + ']');
    const rows = () => Array.from(document.querySelectorAll('[data-cg-rows] li'));
    const rowTexts = () => rows().map((li) => li.textContent);
    const saveButton = () => hook('save');
    const hidden = (el) => el.classList.contains('d-none');

    function chooseFile(input, name = 'partenaires-2026.xlsx') {
        const file = new File(['xlsx'], name);
        Object.defineProperty(input, 'files', { value: [file], configurable: true });
        input.dispatchEvent(new Event('change'));
    }

    function typeName(value) {
        hook('name').value = value;
        hook('name').dispatchEvent(new Event('input'));
    }

    /** Routes each URL to its answer; anything else is a test failure. */
    function serve(routes) {
        global.fetch = vi.fn((url) => {
            const answer = routes[url];
            if (answer === undefined) {
                throw new Error('unexpected request: ' + url);
            }
            return typeof answer === 'function' ? answer() : jsonResponse(answer);
        });
    }

    describe('a new group', () => {
        it('opens from the page with the file half shown and asks the server nothing', async () => {
            await boot();

            $('#opener-new').click();

            expect(hidden(hook('new'))).toBe(false);
            expect(hidden(hook('existing'))).toBe(true);
            expect(saveButton().disabled).toBe(true);
            expect(fetch).not.toHaveBeenCalled();
        });

        it('analyses the file as soon as it is chosen, and shows the preview without writing', async () => {
            serve({ '/admin/listes-de-diffusion/lists/7/contact-groups/analyse': preview({ duplicates: 2 }) });
            await boot();
            $('#opener-new').click();

            chooseFile(hook('file-new'));
            await settle();

            expect(fetch).toHaveBeenCalledTimes(1);
            const [url, init] = fetch.mock.calls[0];
            expect(url).toBe('/admin/listes-de-diffusion/lists/7/contact-groups/analyse');
            expect(init.method).toBe('POST');
            expect(init.body).toBeInstanceOf(FormData);
            expect(init.body.get('_csrf_token')).toBe('tok-123');
            expect(init.body.get('file').name).toBe('partenaires-2026.xlsx');

            expect(hidden(hook('preview'))).toBe(false);
            expect(hook('summary').textContent).toBe('2 contacts trouvés, 2 doublons fusionnés.');
            expect(hook('file-name').textContent).toBe('partenaires-2026.xlsx');
        });

        it('keeps « Enregistrer » disabled until the group has both a name and a file', async () => {
            serve({ '/admin/listes-de-diffusion/lists/7/contact-groups/analyse': preview() });
            await boot();
            $('#opener-new').click();

            typeName('Partenaires');
            expect(saveButton().disabled).toBe(true);

            chooseFile(hook('file-new'));
            await settle();
            expect(saveButton().disabled).toBe(false);

            typeName('   ');
            expect(saveButton().disabled).toBe(true);
        });

        it('renders the refused lines as text, never as markup', async () => {
            serve({
                '/admin/listes-de-diffusion/lists/7/contact-groups/analyse': preview({
                    errors: ['Ligne 14 — « <img src=x onerror=alert(1)> » n\'est pas une adresse email valide.'],
                }),
            });
            await boot();
            $('#opener-new').click();

            chooseFile(hook('file-new'));
            await settle();

            expect(hook('summary').textContent).toBe('2 contacts trouvés, 1 ligne ignorée.');
            const items = Array.from(document.querySelectorAll('[data-cg-line-errors] li'));
            expect(items).toHaveLength(1);
            expect(items[0].textContent).toContain('<img src=x onerror=alert(1)>');
            expect(document.querySelector('[data-cg-line-errors] img')).toBeNull();
            expect(hidden(hook('line-errors'))).toBe(false);
        });

        it('shows a refused file\'s problems and offers nothing to save', async () => {
            serve({
                '/admin/listes-de-diffusion/lists/7/contact-groups/analyse': () => jsonResponse({
                    success: false,
                    errors: ['Le fichier doit contenir une colonne « Adresse ».'],
                }, 422),
            });
            await boot();
            $('#opener-new').click();
            typeName('Partenaires');

            chooseFile(hook('file-new'));
            await settle();

            expect(hook('error').textContent).toContain('colonne « Adresse »');
            expect(hidden(hook('preview'))).toBe(true);
            expect(saveButton().disabled).toBe(true);
        });

        it('sends nothing at all on « Annuler », and forgets the analysed file', async () => {
            serve({ '/admin/listes-de-diffusion/lists/7/contact-groups/analyse': preview() });
            await boot();
            $('#opener-new').click();
            typeName('Partenaires');
            chooseFile(hook('file-new'));
            await settle();
            expect(fetch).toHaveBeenCalledTimes(1);

            $('#cancel').click();
            // What Bootstrap fires once the dialog is gone.
            $('#mm-contact-group-modal').dispatchEvent(new Event('hidden.bs.modal'));
            await settle();

            expect(fetch).toHaveBeenCalledTimes(1);
            expect(hidden(hook('preview'))).toBe(true);
            expect(hook('name').value).toBe('');
            expect(saveButton().disabled).toBe(true);
        });

        it('sends the name and the contacts together on « Enregistrer », then tells the page', async () => {
            serve({
                '/admin/listes-de-diffusion/lists/7/contact-groups/analyse': preview(),
                '/admin/listes-de-diffusion/lists/7/contact-groups': { success: true, group: group({ id: 31 }) },
            });
            const changed = vi.fn();
            document.addEventListener('mass-mail:blocks-changed', changed);
            await boot();
            $('#opener-new').click();
            typeName('Partenaires');
            chooseFile(hook('file-new'));
            await settle();

            saveButton().click();
            await settle();

            expect(fetch).toHaveBeenCalledTimes(2);
            const [url, init] = fetch.mock.calls[1];
            expect(url).toBe('/admin/listes-de-diffusion/lists/7/contact-groups');
            expect(init.method).toBe('POST');
            expect(JSON.parse(init.body)).toEqual({
                name: 'Partenaires',
                contacts: preview().contacts,
                _csrf_token: 'tok-123',
            });
            expect(changed).toHaveBeenCalledTimes(1);
            expect(changed.mock.calls[0][0].detail).toEqual({ listId: '7', blockId: '31', kind: 'contact_group' });
            // The dialog is reset for the next opening.
            expect(hidden(hook('preview'))).toBe(true);
            expect(saveButton().disabled).toBe(true);
            document.removeEventListener('mass-mail:blocks-changed', changed);
        });

        it('keeps the dialog and says why when the save is refused, and tells the page nothing', async () => {
            serve({
                '/admin/listes-de-diffusion/lists/7/contact-groups/analyse': preview(),
                '/admin/listes-de-diffusion/lists/7/contact-groups': () => jsonResponse({
                    success: false,
                    error: 'Le nom du groupe de contacts est obligatoire.',
                }, 422),
            });
            const changed = vi.fn();
            document.addEventListener('mass-mail:blocks-changed', changed);
            await boot();
            $('#opener-new').click();
            typeName('Partenaires');
            chooseFile(hook('file-new'));
            await settle();

            saveButton().click();
            await settle();

            expect(hook('error').textContent).toBe('Le nom du groupe de contacts est obligatoire.');
            expect(hidden(hook('preview'))).toBe(false);
            expect(saveButton().disabled).toBe(false);
            expect(changed).not.toHaveBeenCalled();
            document.removeEventListener('mass-mail:blocks-changed', changed);
        });
    });

    describe('an existing group', () => {
        it('renders a contact name and address as text, never as markup', async () => {
            serve({
                '/admin/listes-de-diffusion/contact-groups/12/contacts': {
                    success: true,
                    group: group({ count: 1 }),
                    contacts: [contact(1, '<img src=x onerror=alert(1)>', 'a@test.be')],
                },
            });
            await boot();

            $('#opener-12').click();
            await settle();

            expect(rowTexts()[0]).toContain('<img src=x onerror=alert(1)>');
            expect(document.querySelector('[data-cg-rows] img')).toBeNull();
        });

        it('loads its contacts once, with its name, its count line and its export', async () => {
            serve({
                '/admin/listes-de-diffusion/contact-groups/12/contacts': {
                    success: true,
                    group: group({ count: 2, unsubscribed: 1 }),
                    contacts: [
                        contact(1, 'Abbé Lambert', 'paroisse@skynet.be'),
                        contact(2, 'Brasserie du Coin', 'contact@brasserie.be', '2026-09-01 10:00:00'),
                    ],
                },
            });
            await boot();

            $('#opener-12').click();
            await settle();

            expect(fetch).toHaveBeenCalledTimes(1);
            expect(hidden(hook('existing'))).toBe(false);
            expect(hidden(hook('new'))).toBe(true);
            expect(hook('name').value).toBe('Partenaires');
            expect(hook('count').textContent)
                .toBe('2 contacts, dont 1 désinscrit · fichier remplacé le 12 septembre 2026');
            expect(hook('export').getAttribute('href'))
                .toBe('/admin/listes-de-diffusion/contact-groups/12/export');
            expect(rowTexts()).toEqual([
                'Abbé Lambertparoisse@skynet.be',
                'Brasserie du Coincontact@brasserie.be · désinscrite',
            ]);
            expect(saveButton().disabled).toBe(false);
        });

        it('greys an unsubscribed contact, and isolates them on « Désinscrites seulement »', async () => {
            serve({
                '/admin/listes-de-diffusion/contact-groups/12/contacts': {
                    success: true,
                    group: group(),
                    contacts: [
                        contact(1, 'Abbé Lambert', 'paroisse@skynet.be'),
                        contact(2, 'Brasserie du Coin', 'contact@brasserie.be', '2026-09-01 10:00:00'),
                    ],
                },
            });
            await boot();
            $('#opener-12').click();
            await settle();

            expect(rows()[0].classList.contains('text-body-secondary')).toBe(false);
            expect(rows()[1].classList.contains('text-body-secondary')).toBe(true);
            expect(rows()[1].dataset.unsubscribed).toBe('1');

            hook('unsubscribed-only').checked = true;
            hook('unsubscribed-only').dispatchEvent(new Event('change'));

            expect(rows()).toHaveLength(1);
            expect(rows()[0].dataset.contactId).toBe('2');
        });

        it('searches names and addresses without regard to accents or case', async () => {
            serve({
                '/admin/listes-de-diffusion/contact-groups/12/contacts': {
                    success: true,
                    group: group(),
                    contacts: [
                        contact(1, 'Élodie Ç. Müller', 'elodie@test.be'),
                        contact(2, 'Commune', 'jeunesse@wavre.be'),
                    ],
                },
            });
            await boot();
            $('#opener-12').click();
            await settle();

            hook('search').value = 'ELODIE c muller';
            hook('search').dispatchEvent(new Event('input'));
            expect(rows().map((li) => li.dataset.contactId)).toEqual(['1']);

            hook('search').value = 'wavre';
            hook('search').dispatchEvent(new Event('input'));
            expect(rows().map((li) => li.dataset.contactId)).toEqual(['2']);

            hook('search').value = 'personne';
            hook('search').dispatchEvent(new Event('input'));
            expect(rows()).toHaveLength(0);
            expect(hidden(hook('empty'))).toBe(false);
        });

        it('renders in slices of fifty, with « Afficher plus » for the rest', async () => {
            const many = Array.from({ length: 120 }, (_, i) => contact(i + 1, 'Contact ' + i, 'c' + i + '@test.be'));
            serve({
                '/admin/listes-de-diffusion/contact-groups/12/contacts': {
                    success: true, group: group({ count: 120 }), contacts: many,
                },
            });
            await boot();
            $('#opener-12').click();
            await settle();

            expect(rows()).toHaveLength(50);
            expect(hidden(hook('more'))).toBe(false);
            expect(hook('more').textContent).toBe('Afficher plus (70 restants)');

            hook('more').click();
            expect(rows()).toHaveLength(100);

            hook('more').click();
            expect(rows()).toHaveLength(120);
            expect(hidden(hook('more'))).toBe(true);
        });

        it('previews a corrected file in the counts « Enregistrer » would produce', async () => {
            serve({
                '/admin/listes-de-diffusion/contact-groups/12/contacts': {
                    success: true, group: group(), contacts: [],
                },
                '/admin/listes-de-diffusion/contact-groups/12/analyse': preview({
                    summary: { added: 4, unchanged: 32, removed: 1, kept_unsubscribed: 0 },
                    duplicates: 2,
                }),
            });
            await boot();
            $('#opener-12').click();
            await settle();

            chooseFile(hook('file-existing'), 'partenaires-2026-v2.xlsx');
            await settle();

            expect(fetch.mock.calls[1][0]).toBe('/admin/listes-de-diffusion/contact-groups/12/analyse');
            expect(hook('summary').textContent).toBe(
                'partenaires-2026-v2.xlsx — à l\'enregistrement : 4 ajoutés, 1 retiré, 32 inchangés, '
                + '2 doublons fusionnés.'
            );
        });

        it('keeps « Enregistrer » off while a corrected file is still being analysed', async () => {
            let answer;
            serve({
                '/admin/listes-de-diffusion/contact-groups/12/contacts': {
                    success: true, group: group(), contacts: [],
                },
                '/admin/listes-de-diffusion/contact-groups/12/analyse': () => new Promise((resolve) => {
                    answer = resolve;
                }),
            });
            await boot();
            $('#opener-12').click();
            await settle();
            expect(saveButton().disabled).toBe(false);

            chooseFile(hook('file-existing'), 'v2.xlsx');
            await settle();
            // Saving now would send the name alone and drop the file.
            expect(saveButton().disabled).toBe(true);

            answer(await jsonResponse(preview({
                summary: { added: 1, unchanged: 0, removed: 0, kept_unsubscribed: 0 },
            })));
            await settle();
            expect(saveButton().disabled).toBe(false);
        });

        it('names the unsubscribed contacts a file keeps, and only when there are some', async () => {
            serve({
                '/admin/listes-de-diffusion/contact-groups/12/contacts': {
                    success: true, group: group(), contacts: [],
                },
                '/admin/listes-de-diffusion/contact-groups/12/analyse': preview({
                    summary: { added: 0, unchanged: 1, removed: 0, kept_unsubscribed: 2 },
                }),
            });
            await boot();
            $('#opener-12').click();
            await settle();

            chooseFile(hook('file-existing'), 'v3.xlsx');
            await settle();

            expect(hook('summary').textContent)
                .toBe('v3.xlsx — à l\'enregistrement : 0 ajouté, 0 retiré, 1 inchangé, 2 désinscrits conservés.');
        });

        it('saves the name and the analysed contacts together in one PATCH', async () => {
            serve({
                '/admin/listes-de-diffusion/contact-groups/12/contacts': {
                    success: true, group: group(), contacts: [],
                },
                '/admin/listes-de-diffusion/contact-groups/12/analyse': preview(),
                '/admin/listes-de-diffusion/contact-groups/12': { success: true, group: group(), summary: null },
            });
            const changed = vi.fn();
            document.addEventListener('mass-mail:blocks-changed', changed);
            await boot();
            $('#opener-12').click();
            await settle();
            typeName('Partenaires de la fête');
            chooseFile(hook('file-existing'));
            await settle();

            saveButton().click();
            await settle();

            const [url, init] = fetch.mock.calls[2];
            expect(url).toBe('/admin/listes-de-diffusion/contact-groups/12');
            expect(init.method).toBe('PATCH');
            expect(JSON.parse(init.body)).toEqual({
                name: 'Partenaires de la fête',
                contacts: preview().contacts,
                _csrf_token: 'tok-123',
            });
            expect(changed).toHaveBeenCalledTimes(1);
            document.removeEventListener('mass-mail:blocks-changed', changed);
        });

        it('saves the name alone when no file was chosen — no contacts key at all', async () => {
            serve({
                '/admin/listes-de-diffusion/contact-groups/12/contacts': {
                    success: true, group: group(), contacts: [contact(1, 'A', 'a@test.be')],
                },
                '/admin/listes-de-diffusion/contact-groups/12': { success: true, group: group(), summary: null },
            });
            await boot();
            $('#opener-12').click();
            await settle();
            typeName('Renommé');

            saveButton().click();
            await settle();

            const body = JSON.parse(fetch.mock.calls[1][1].body);
            expect(body).toEqual({ name: 'Renommé', _csrf_token: 'tok-123' });
            expect('contacts' in body).toBe(false);
        });

        /**
         * One dialog, reopened on another group: a group of three hundred
         * contacts answers slowly, and its answer must never land in the
         * dialog of the group opened after it.
         */
        it('drops a slow answer for a group that is no longer the one open', async () => {
            let releaseSlow;
            const slow = new Promise((resolve) => { releaseSlow = resolve; });
            serve({
                '/admin/listes-de-diffusion/contact-groups/12/contacts': () => slow.then(() => ({
                    ok: true,
                    status: 200,
                    json: () => Promise.resolve({
                        success: true,
                        group: group({ name: 'Lent' }),
                        contacts: [contact(1, 'Lent', 'lent@test.be')],
                    }),
                })),
                '/admin/listes-de-diffusion/contact-groups/13/contacts': {
                    success: true,
                    group: group({ id: 13, name: 'Commune' }),
                    contacts: [contact(9, 'Rapide', 'rapide@test.be')],
                },
            });
            await boot();

            $('#opener-12').click();
            $('#opener-13').click();
            await settle();
            releaseSlow();
            await settle();

            expect(hook('name').value).toBe('Commune');
            expect(rows().map((li) => li.dataset.contactId)).toEqual(['9']);
            expect(hook('export').getAttribute('href'))
                .toBe('/admin/listes-de-diffusion/contact-groups/13/export');
        });

        it('ignores an opener whose group id is not a number', async () => {
            document.body.insertAdjacentHTML(
                'beforeend',
                '<a data-contact-group-open data-block-id="javascript:alert(1)" id="opener-bad"></a>'
            );
            await boot();
            $('#mm-contact-group-modal [data-contact-group-dialog]').dataset.listId = '';

            $('#opener-bad').click();
            await settle();

            expect(fetch).not.toHaveBeenCalled();
        });
    });
});
