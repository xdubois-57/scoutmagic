// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked and the site-wide dialog toolboxes
// (window.ScoutMagicConfirm, window.ScoutMagicToast, loaded by
// base.html.twig on every page) are stubbed, so a confirmation's answer
// is something the test chooses rather than something a real modal has to
// be clicked for.
//
// Exercises the REAL public/assets/js/mass-mail-lists.js (imported below,
// never reimplemented) on top of the real api.js envelope. The file is an
// IIFE that wires itself against the DOM present at import time, so every
// test builds its fixture first and imports afterwards — the
// tests/js/config-badges.test.js pattern.
//
// Two pages: the page of the lists (toggle, delete, « Nouvelle liste »)
// and the page of one list (its blocks, the choice of a kind, the
// member-group dialog, the removal of a block, « Voir les
// destinataires », and the redraw the contact-group dialog asks for with
// `mass-mail:blocks-changed`).
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
    // A macrotask hop drains the whole microtask queue first.
    await new Promise((resolve) => setTimeout(resolve, 0));
}

// The name-and-description dialog, as partials/_list_identity_dialog.html.twig
// renders it — for a creation (listId '') or for an existing list.
function identityDialog(listId = '', name = '', description = '') {
    return `
        <div class="modal" id="mm-list-identity-modal">
            <div class="alert d-none" data-list-identity-error></div>
            <input id="mm-list-name" value="${name}">
            <textarea id="mm-list-description">${description}</textarea>
            <button type="button" data-list-identity-save data-list-id="${listId}">Enregistrer</button>
        </div>
    `;
}

// Mirrors modules/mass_mail/views/mailing_lists.html.twig: two custom
// lists, one active and one not, each with its two buttons.
const LISTS_PAGE = `
    <button type="button" data-list-identity-open>Nouvelle liste</button>
    <div id="mm-custom-lists">
        <div data-list-id="7">
            <button type="button" class="mm-toggle-list-btn" data-id="7" data-active="1"></button>
            <button type="button" class="mm-delete-list-btn" data-id="7" data-name="Staff élargi"></button>
        </div>
        <div data-list-id="9">
            <button type="button" class="mm-toggle-list-btn" data-id="9" data-active="0"></button>
            <button type="button" class="mm-delete-list-btn" data-id="9" data-name="Secouristes"></button>
        </div>
    </div>
    ${identityDialog()}
`;

function picker(id, items) {
    return `<div class="select-bar" id="${id}">${items.map((item) =>
        `<button type="button" class="select-bar-item" data-id="${item}" data-selected="false"></button>`).join('')}</div>`;
}

const MEMBER_GROUP = {
    id: 11,
    kind: 'member_group',
    name: null,
    sentence: [
        { text: 'Les membres qui ont la fonction ', strong: false },
        { text: 'Animateur', strong: true },
        { text: '.', strong: false },
    ],
    count: 31,
    unsubscribed: 0,
    file_replaced_at: null,
    file_replaced_label: null,
    function_ids: [1],
    section_ids: [],
    badge_ids: [],
};

const CONTACT_GROUP = {
    id: 12,
    kind: 'contact_group',
    name: 'Partenaires de la fête',
    sentence: [],
    count: 37,
    unsubscribed: 2,
    file_replaced_at: '2026-09-12 10:00:00',
    file_replaced_label: '12 septembre 2026',
    function_ids: [],
    section_ids: [],
    badge_ids: [],
};

function payload(blocks, summary = { total: 67, members: 32, contacts: 37, contacts_unsubscribed: 2 }) {
    return { success: true, scout_year_label: '2026-2027', summary, blocks };
}

// Mirrors modules/mass_mail/views/mailing_list.html.twig.
function listPage(blocks = [MEMBER_GROUP, CONTACT_GROUP], summary = undefined) {
    const island = JSON.stringify({ list_id: 5, effective_year_id: 3, blocks: payload(blocks, summary) });
    return `
        <button type="button" data-list-identity-open aria-label="Modifier le nom et la description"></button>
        <div data-list-total></div>
        <div data-list-blocks></div>
        <p class="d-none" data-list-empty-text>Ajoutez des groupes…</p>
        <button type="button" data-kind-chooser-open>Ajouter des destinataires</button>
        <p class="d-none" data-list-year-note></p>
        <script type="application/json" id="mm-list-data">${island}</script>
        ${identityDialog('5', 'Staff élargi', 'Les animateurs.')}
        <div class="modal" id="mm-kind-chooser-modal">
            <button type="button" data-kind-choice="member_group">Groupe de membres</button>
            <button type="button" data-contact-group-open>Groupe de contacts</button>
        </div>
        <div class="modal" id="mm-member-group-modal">
            <div class="alert d-none" data-member-group-error></div>
            ${picker('mm-function-picker', [1, 2])}
            ${picker('mm-section-picker', [4])}
            ${picker('mm-badge-picker', [6])}
            <p data-member-group-sentence></p>
            <p data-member-group-count></p>
            <button type="button" data-member-group-save disabled>Enregistrer</button>
        </div>
        <div class="modal" id="mm-recipients-modal">
            <h2 id="mm-recipients-modal-title">Destinataires</h2>
            <select data-recipients-year>
                <option value="3" selected>2026-2027</option><option value="2">2025-2026</option>
            </select>
            <input type="search" data-recipients-search>
            <div class="d-none" data-recipients-error></div>
            <ul data-recipients-rows></ul>
            <p class="d-none" data-recipients-empty></p>
        </div>
    `;
}

describe('mass-mail-lists.js', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = LISTS_PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)), prompt: vi.fn() };
        // The select bar's own escape hatch, reduced to what it does to an
        // item: the real one also redraws the trigger summary.
        window.SelectBar = {
            setSelected: vi.fn((pickerId, id, selected) => {
                document.querySelector(`#${pickerId} .select-bar-item[data-id="${id}"]`)
                    .dataset.selected = selected ? 'true' : 'false';
            }),
        };
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
        return { url, opts, body: opts?.body ? JSON.parse(opts.body) : null };
    }

    const isShown = (id) => document.getElementById(id).classList.contains('show');

    describe('entry guard', () => {
        it('does nothing at all on a page without the lists', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('the page of the lists', () => {
        const deleteBtn = () => document.querySelector('.mm-delete-list-btn[data-id="7"]');

        it('asks before deleting, naming the list and saying no e-mail leaves', async () => {
            const nativeConfirm = vi.fn(() => true);
            window.confirm = nativeConfirm;
            await boot();

            deleteBtn().click();
            await settle();

            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
                title: 'Supprimer cette liste ?',
                message: '« Staff élargi » est supprimée avec tous ses groupes. Aucun e-mail n\'est envoyé.',
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

            expect(fetch).not.toHaveBeenCalled();
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('DELETEs the clicked list with the CSRF token and reloads on success', async () => {
            await boot();

            document.querySelector('.mm-delete-list-btn[data-id="9"]').click();
            await settle();

            const { url, opts, body } = lastRequest();
            expect(url).toBe('/admin/listes-de-diffusion/lists/9');
            expect(opts.method).toBe('DELETE');
            expect(body).toEqual({ _csrf_token: 'tok-123' });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
            expect(window.location.reload).toHaveBeenCalled();
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

        it('posts the flipped state without asking — deactivating destroys nothing', async () => {
            await boot();

            document.querySelector('.mm-toggle-list-btn[data-id="7"]').click();
            await settle();

            const { url, body } = lastRequest();
            expect(url).toBe('/admin/listes-de-diffusion/lists/7/toggle');
            expect(body).toEqual({ active: false, _csrf_token: 'tok-123' });
            expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
            expect(window.location.reload).toHaveBeenCalled();
        });

        it('toasts a refused toggle rather than reloading', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Liste introuvable.' }));
            await boot();

            document.querySelector('.mm-toggle-list-btn[data-id="9"]').click();
            await settle();

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Liste introuvable.', { variant: 'error' });
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('« Nouvelle liste » creates the list and opens its page', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, list: { id: 42, name: 'Staff', is_active: true } }));
            await boot();

            document.querySelector('[data-list-identity-open]').click();
            expect(isShown('mm-list-identity-modal')).toBe(true);
            document.getElementById('mm-list-name').value = 'Staff';
            document.getElementById('mm-list-description').value = 'Les animateurs.';
            document.querySelector('[data-list-identity-save]').click();
            await settle();

            const { url, opts, body } = lastRequest();
            expect(url).toBe('/admin/listes-de-diffusion/lists');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({ name: 'Staff', description: 'Les animateurs.', _csrf_token: 'tok-123' });
            expect(window.location.href).toBe('/admin/listes-de-diffusion/42');
        });

        it('creates the list once on a double click', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, list: { id: 42, name: 'Staff', is_active: true } }));
            await boot();

            const save = document.querySelector('[data-list-identity-save]');
            save.click();
            save.click();
            await settle();

            expect(fetch).toHaveBeenCalledTimes(1);
        });

        it('says a refused creation inside the dialog, and stays', async () => {
            global.fetch = vi.fn(() => jsonResponse(
                { success: false, error: 'La description de la liste est obligatoire.' },
                422,
            ));
            await boot();

            document.querySelector('[data-list-identity-save]').click();
            await settle();

            const box = document.querySelector('[data-list-identity-error]');
            expect(box.textContent).toBe('La description de la liste est obligatoire.');
            expect(box.classList.contains('d-none')).toBe(false);
            expect(window.location.href).toBe('/admin/listes-de-diffusion');
        });

        it('renders a script-carrying error message as text in the real toast', async () => {
            // Run it through the REAL toast.js rather than the stub, so the
            // escaping guarantee is the production one.
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

    describe('the page of one list', () => {
        beforeEach(() => {
            document.body.innerHTML = listPage();
        });

        it('draws the total, then the member groups, then the contact groups', async () => {
            await boot();

            const total = document.querySelector('[data-list-total]').textContent;
            expect(total).toContain('67 destinataires en 2026-2027');
            expect(total).toContain('32 membres pour 2026-2027 et 37 contacts, dont 2 désinscrits.');
            expect(total).toContain('Une personne désignée par plusieurs blocs ne reçoit l\'e-mail qu\'une fois.');
            expect(total).toContain('Voir les destinataires');

            const headings = [...document.querySelectorAll('[data-list-blocks] h2')].map((h) => h.textContent);
            expect(headings).toEqual(['Groupes de membres', 'Groupes de contacts']);

            const member = document.querySelector('[data-member-group-open][data-block-id="11"]');
            expect(member.querySelector('b').textContent).toBe('Animateur');
            expect(member.textContent).toContain('31 membres en 2026-2027');

            // The contract with mass-mail-contact-groups.js.
            const contact = document.querySelector('[data-list-blocks] [data-contact-group-open][data-block-id="12"]');
            expect(contact.textContent).toContain('Partenaires de la fête');
            expect(contact.textContent)
                .toContain('37 contacts, dont 2 désinscrits · fichier remplacé le 12 septembre 2026');
            expect(document.querySelector('[data-block-remove="12"]').getAttribute('aria-label'))
                .toBe('Retirer le groupe Partenaires de la fête');
            expect(document.querySelector('[data-list-empty-text]').classList.contains('d-none')).toBe(true);
        });

        it('says « 0 destinataire » and shows only the button on an empty list', async () => {
            document.body.innerHTML = listPage([], { total: 0, members: 0, contacts: 0, contacts_unsubscribed: 0 });
            await boot();

            const total = document.querySelector('[data-list-total]').textContent;
            expect(total).toContain('0 destinataire');
            expect(total).toContain('Personne ne reçoit encore les e-mails de cette liste.');
            expect(total).not.toContain('Voir les destinataires');
            expect(document.querySelector('[data-list-blocks]').children).toHaveLength(0);
            expect(document.querySelector('[data-list-empty-text]').classList.contains('d-none')).toBe(false);
        });

        it('leaves out the heading of a kind the list does not hold', async () => {
            document.body.innerHTML = listPage([CONTACT_GROUP], {
                total: 35, members: 0, contacts: 37, contacts_unsubscribed: 2,
            });
            await boot();

            const headings = [...document.querySelectorAll('[data-list-blocks] h2')].map((h) => h.textContent);
            expect(headings).toEqual(['Groupes de contacts']);
            expect(document.querySelector('[data-list-total]').textContent)
                .toContain('37 contacts, dont 2 désinscrits.');
        });

        it('redraws the blocks and the total when the contact-group dialog saved', async () => {
            await boot();
            global.fetch = vi.fn(() => jsonResponse(payload([MEMBER_GROUP], {
                total: 31, members: 31, contacts: 0, contacts_unsubscribed: 0,
            })));

            document.dispatchEvent(new CustomEvent('mass-mail:blocks-changed'));
            await settle();

            expect(lastRequest().url).toBe('/admin/listes-de-diffusion/lists/5/blocks');
            expect(document.querySelector('[data-block-id="12"]')).toBeNull();
            expect(document.querySelector('[data-list-total]').textContent).toContain('31 destinataires en 2026-2027');
        });

        it('toasts a failed redraw and keeps what it drew', async () => {
            await boot();
            global.fetch = vi.fn(() => htmlErrorResponse());

            document.dispatchEvent(new CustomEvent('mass-mail:blocks-changed'));
            await settle();

            expect(window.ScoutMagicToast.show).toHaveBeenCalled();
            expect(document.querySelector('[data-block-id="12"]')).not.toBeNull();
        });

        it('the pencil reopens the dialog with the saved name, and PATCHes it', async () => {
            await boot();

            document.getElementById('mm-list-name').value = 'Brouillon abandonné';
            document.querySelector('[data-list-identity-open]').click();
            expect(document.getElementById('mm-list-name').value).toBe('Staff élargi');

            document.querySelector('[data-list-identity-save]').click();
            await settle();

            const { url, opts } = lastRequest();
            expect(url).toBe('/admin/listes-de-diffusion/lists/5');
            expect(opts.method).toBe('PATCH');
            expect(window.location.reload).toHaveBeenCalled();
        });

        describe('choosing the kind of block', () => {
            it('opens the chooser, and « Groupe de membres » opens an empty member-group dialog', async () => {
                await boot();

                document.querySelector('[data-kind-chooser-open]').click();
                expect(isShown('mm-kind-chooser-modal')).toBe(true);

                document.querySelector('[data-kind-choice="member_group"]').click();
                await settle();

                expect(isShown('mm-kind-chooser-modal')).toBe(false);
                expect(isShown('mm-member-group-modal')).toBe(true);
                expect(document.querySelectorAll('.select-bar-item[data-selected="true"]')).toHaveLength(0);
            });

            it('gets out of the way of the contact-group dialog, which is another script\'s to open', async () => {
                await boot();
                document.querySelector('[data-kind-chooser-open]').click();

                document.querySelector('#mm-kind-chooser-modal [data-contact-group-open]').click();
                await settle();

                expect(isShown('mm-kind-chooser-modal')).toBe(false);
                expect(isShown('mm-member-group-modal')).toBe(false);
                expect(fetch).not.toHaveBeenCalled();
            });
        });

        describe('the member-group dialog', () => {
            async function openNew() {
                await boot();
                document.querySelector('[data-kind-choice="member_group"]').click();
                await settle();
            }

            function choose(pickerId, id) {
                const item = document.querySelector(`#${pickerId} .select-bar-item[data-id="${id}"]`);
                item.dataset.selected = item.dataset.selected === 'true' ? 'false' : 'true';
                document.getElementById(pickerId).dispatchEvent(new CustomEvent('select-bar:change'));
            }

            it('asks for one choice and keeps « Enregistrer » disabled without one', async () => {
                await openNew();

                expect(document.querySelector('[data-member-group-sentence]').textContent)
                    .toBe('Choisissez au moins une fonction, une section ou un badge.');
                expect(document.querySelector('[data-member-group-save]').disabled).toBe(true);
                expect(fetch).not.toHaveBeenCalled();
            });

            it('counts and words the group as soon as one axis is chosen', async () => {
                global.fetch = vi.fn(() => jsonResponse({
                    success: true,
                    count: 31,
                    scout_year_label: '2026-2027',
                    sentence: MEMBER_GROUP.sentence,
                }));
                await openNew();

                choose('mm-function-picker', 1);
                await settle();

                const { url, body } = lastRequest();
                expect(url).toBe('/admin/listes-de-diffusion/preview-count');
                expect(body).toEqual({ function_ids: [1], section_ids: [], badge_ids: [], _csrf_token: 'tok-123' });
                expect(document.querySelector('[data-member-group-sentence]').innerHTML)
                    .toBe('Les membres qui ont la fonction <b>Animateur</b>.');
                expect(document.querySelector('[data-member-group-count]').textContent).toBe('31 membres en 2026-2027');
                expect(document.querySelector('[data-member-group-save]').disabled).toBe(false);
            });

            it('going back to no choice at all disables « Enregistrer » again', async () => {
                global.fetch = vi.fn(() => jsonResponse({ success: true, count: 1, scout_year_label: 'x', sentence: [] }));
                await openNew();

                choose('mm-function-picker', 1);
                await settle();
                choose('mm-function-picker', 1);
                await settle();

                expect(document.querySelector('[data-member-group-save]').disabled).toBe(true);
                expect(fetch).toHaveBeenCalledTimes(1);
            });

            it('keeps the latest count when an earlier answer comes back last', async () => {
                const answers = [];
                global.fetch = vi.fn(() => new Promise((resolve) => { answers.push(resolve); }));
                await openNew();

                choose('mm-function-picker', 1);
                choose('mm-function-picker', 2);
                const reply = (count) => ({
                    ok: true,
                    status: 200,
                    json: () => Promise.resolve({
                        success: true,
                        count,
                        scout_year_label: '2026-2027',
                        sentence: [{ text: String(count), strong: false }],
                    }),
                });
                answers[1](reply(40));
                await settle();
                answers[0](reply(31));
                await settle();

                expect(document.querySelector('[data-member-group-count]').textContent).toBe('40 membres en 2026-2027');
                expect(document.querySelector('[data-member-group-sentence]').textContent).toBe('40');
            });

            it('says why a badge crossed with something else counts nobody', async () => {
                global.fetch = vi.fn(() => jsonResponse({
                    success: true, count: 0, scout_year_label: '2026-2027', sentence: [],
                }));
                await openNew();

                choose('mm-badge-picker', 6);
                await settle();

                const count = document.querySelector('[data-member-group-count]');
                expect(count.textContent).toContain('0 membre en 2026-2027');
                expect(count.textContent).toContain('Un badge n\'est porté que par le Staff d\'U et les animateurs.');
                expect(count.className).toContain('text-danger');
            });

            it('says when the count could not be computed', async () => {
                global.fetch = vi.fn(() => htmlErrorResponse());
                await openNew();

                choose('mm-section-picker', 4);
                await settle();

                expect(document.querySelector('[data-member-group-count]').textContent)
                    .toBe('Le nombre de destinataires n\'a pas pu être calculé.');
            });

            it('creates the group, then redraws the page from the server', async () => {
                await openNew();
                choose('mm-section-picker', 4);
                await settle();
                global.fetch = vi.fn((url) => jsonResponse(url.endsWith('/blocks')
                    ? payload([MEMBER_GROUP, CONTACT_GROUP])
                    : { success: true, block: { id: 13, kind: 'member_group' } }));

                document.querySelector('[data-member-group-save]').click();
                await settle();

                const [createUrl, createOpts] = fetch.mock.calls[0];
                expect(createUrl).toBe('/admin/listes-de-diffusion/lists/5/member-groups');
                expect(createOpts.method).toBe('POST');
                expect(JSON.parse(createOpts.body)).toEqual({
                    function_ids: [], section_ids: [4], badge_ids: [], _csrf_token: 'tok-123',
                });
                expect(fetch.mock.calls[1][0]).toBe('/admin/listes-de-diffusion/lists/5/blocks');
                expect(isShown('mm-member-group-modal')).toBe(false);
            });

            it('reopens a saved group with its own choices and PATCHes it', async () => {
                global.fetch = vi.fn((url) => jsonResponse(url.endsWith('/blocks')
                    ? payload([MEMBER_GROUP])
                    : { success: true, count: 31, scout_year_label: '2026-2027', sentence: [] }));
                await boot();

                document.querySelector('[data-member-group-open][data-block-id="11"]').click();
                await settle();
                expect(document.querySelector('#mm-function-picker [data-id="1"]').dataset.selected).toBe('true');
                expect(document.querySelector('#mm-function-picker [data-id="2"]').dataset.selected).toBe('false');

                document.querySelector('[data-member-group-save]').click();
                await settle();

                const call = fetch.mock.calls.find(([url]) => url === '/admin/listes-de-diffusion/member-groups/11');
                expect(call[1].method).toBe('PATCH');
            });

            it('creates the group once on a double click', async () => {
                await openNew();
                choose('mm-section-picker', 4);
                await settle();
                global.fetch = vi.fn((url) => jsonResponse(url.endsWith('/blocks')
                    ? payload([MEMBER_GROUP])
                    : { success: true, block: { id: 13, kind: 'member_group' } }));

                const save = document.querySelector('[data-member-group-save]');
                save.click();
                save.click();
                await settle();

                expect(fetch.mock.calls.filter(([url]) => url.endsWith('/member-groups'))).toHaveLength(1);
            });

            it('keeps « Enregistrer » off while saving, even when a choice changes meanwhile', async () => {
                await openNew();
                choose('mm-section-picker', 4);
                await settle();
                let answer;
                global.fetch = vi.fn((url) => {
                    if (url.endsWith('/member-groups')) {
                        return new Promise((resolve) => { answer = resolve; });
                    }
                    return jsonResponse(url.endsWith('/blocks')
                        ? payload([MEMBER_GROUP])
                        : { success: true, count: 3, scout_year_label: '2026-2027', sentence: [] });
                });

                const save = document.querySelector('[data-member-group-save]');
                save.click();
                choose('mm-function-picker', 1);
                await settle();
                expect(save.disabled).toBe(true);
                save.click();
                answer(await jsonResponse({ success: true, block: { id: 13, kind: 'member_group' } }));
                await settle();

                expect(fetch.mock.calls.filter(([url]) => url.endsWith('/member-groups'))).toHaveLength(1);
            });

            it('says a refusal inside the dialog and keeps it open', async () => {
                await openNew();
                choose('mm-function-picker', 1);
                await settle();
                global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Liste introuvable.' }, 422));

                document.querySelector('[data-member-group-save]').click();
                await settle();

                expect(document.querySelector('[data-member-group-error]').textContent).toBe('Liste introuvable.');
                expect(isShown('mm-member-group-modal')).toBe(true);
                // Refused: the button comes back for a corrected attempt.
                expect(document.querySelector('[data-member-group-save]').disabled).toBe(false);
            });
        });

        describe('removing a block', () => {
            it('asks first, naming the block and saying no e-mail leaves', async () => {
                window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
                await boot();

                document.querySelector('[data-block-remove="12"]').click();
                await settle();

                const asked = window.ScoutMagicConfirm.ask.mock.calls[0][0];
                expect(asked.title).toBe('Retirer ce groupe de contacts ?');
                expect(asked.message).toBe('Partenaires de la fête — 37 contacts');
                expect(asked.content[0].text)
                    .toContain('Les e-mails déjà envoyés ne changent pas. Aucun e-mail n\'est envoyé.');
                expect(asked.content[0].note)
                    .toBe('Les désinscriptions sont conservées : elles valent pour toute l\'unité.');
                expect(asked.confirmLabel).toBe('Retirer');
                expect(fetch).not.toHaveBeenCalled();
            });

            it('names a member group by its sentence', async () => {
                window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
                await boot();

                document.querySelector('[data-block-remove="11"]').click();
                await settle();

                const asked = window.ScoutMagicConfirm.ask.mock.calls[0][0];
                expect(asked.title).toBe('Retirer ce groupe de membres ?');
                expect(asked.message).toBe('Les membres qui ont la fonction Animateur. — 31 membres en 2026-2027');
                expect(asked.content[0].text).toContain('Aucun e-mail n\'est envoyé.');
                // Never opens the group's dialog on the way.
                expect(isShown('mm-member-group-modal')).toBe(false);
            });

            it('DELETEs the block once confirmed and redraws the page', async () => {
                await boot();
                global.fetch = vi.fn((url) => jsonResponse(url.endsWith('/blocks')
                    ? payload([MEMBER_GROUP])
                    : { success: true }));

                document.querySelector('[data-block-remove="12"]').click();
                await settle();

                expect(fetch.mock.calls[0][0]).toBe('/admin/listes-de-diffusion/blocks/12');
                expect(fetch.mock.calls[0][1].method).toBe('DELETE');
                expect(document.querySelector('[data-block-remove="12"]')).toBeNull();
            });

            it('toasts a refused removal and keeps the block', async () => {
                await boot();
                global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Groupe introuvable.' }, 422));

                document.querySelector('[data-block-remove="12"]').click();
                await settle();

                expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Groupe introuvable.', { variant: 'error' });
                expect(fetch).toHaveBeenCalledTimes(1);
            });
        });

        describe('« Voir les destinataires »', () => {
            const RECIPIENTS = {
                success: true,
                scout_year_label: '2026-2027',
                recipients: [
                    { name: 'Hélène Collard', display_name: 'Ourson', email: 'helene@example.be', block_ids: [11] },
                    { name: 'Commune', display_name: null, email: 'commune@example.be', block_ids: [12] },
                ],
                blocks: {
                    11: { kind: 'member_group', label: 'Animateur' },
                    12: { kind: 'contact_group', label: 'Partenaires de la fête' },
                },
            };

            async function open() {
                await boot();
                global.fetch = vi.fn(() => jsonResponse(RECIPIENTS));
                document.querySelector('[data-recipients-open]').click();
                await settle();
            }

            it('lists who receives for the effective year, each with the block that brings them', async () => {
                await open();

                expect(lastRequest().url).toBe('/admin/listes-de-diffusion/lists/5/recipients?year=3');
                expect(document.getElementById('mm-recipients-modal-title').textContent)
                    .toBe('2 destinataires en 2026-2027');
                const rows = document.querySelectorAll('[data-recipients-rows] li');
                expect(rows).toHaveLength(2);
                expect(rows[0].textContent).toContain('Hélène Collard — Ourson');
                expect(rows[0].querySelector('.badge').textContent).toBe('Animateur');
                expect(rows[1].querySelector('.badge').textContent).toBe('Partenaires de la fête');
            });

            it('searches without minding accents', async () => {
                await open();

                const search = document.querySelector('[data-recipients-search]');
                search.value = 'helene';
                search.dispatchEvent(new Event('input'));

                const visible = [...document.querySelectorAll('[data-recipients-rows] li')]
                    .filter((li) => !li.classList.contains('d-none'));
                expect(visible).toHaveLength(1);
                expect(visible[0].textContent).toContain('helene@example.be');

                search.value = 'personne';
                search.dispatchEvent(new Event('input'));
                expect(document.querySelector('[data-recipients-empty]').classList.contains('d-none')).toBe(false);
            });

            it('asks again for another year', async () => {
                await open();

                const year = document.querySelector('[data-recipients-year]');
                year.value = '2';
                year.dispatchEvent(new Event('change'));
                await settle();

                expect(lastRequest().url).toBe('/admin/listes-de-diffusion/lists/5/recipients?year=2');
            });

            it('says a refused year inside the dialog', async () => {
                await boot();
                global.fetch = vi.fn(() => jsonResponse(
                    { success: false, error: 'Cette année ne peut pas être choisie.' },
                    422,
                ));

                document.querySelector('[data-recipients-open]').click();
                await settle();

                expect(document.querySelector('[data-recipients-error]').textContent)
                    .toBe('Cette année ne peut pas être choisie.');
            });

            it('renders server text as text, never as markup', async () => {
                await boot();
                global.fetch = vi.fn(() => jsonResponse({
                    ...RECIPIENTS,
                    recipients: [{
                        name: '<img src=x onerror=alert(1)>', display_name: null, email: 'x@example.be', block_ids: [],
                    }],
                }));

                document.querySelector('[data-recipients-open]').click();
                await settle();

                expect(document.querySelector('[data-recipients-rows] img')).toBeNull();
            });
        });
    });
});
