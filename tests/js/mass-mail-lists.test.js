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

/**
 * One select bar in mode:multi, as partials/select_bar.html.twig renders
 * it — the same class names, data attributes and check-mark span the real
 * select-bar.js toggles, so this suite exercises the real component rather
 * than a stand-in for it.
 *
 * @param {string} id
 * @param {string} noneText
 * @param {string} countLabel
 * @param {{id: number, label: string}[]} items
 */
function selectBar(id, noneText, countLabel, items) {
    const rows = items.map((item) => `
        <li>
            <button type="button" class="select-bar-item" data-id="${item.id}"
                    data-selected="false" aria-pressed="false">
                <span><i class="bi bi-check-lg invisible"></i></span>
                <span><span class="select-bar-row-label">${item.label}</span></span>
            </button>
        </li>`).join('');

    return `
    <div class="select-bar" id="${id}" data-mode="multi">
        <details class="select-bar-details">
            <summary class="select-bar-trigger">
                <span class="select-bar-value"
                      data-none-text="${noneText}" data-count-label="${countLabel}">${noneText}</span>
            </summary>
            <div class="select-bar-panel"><ul>${rows}</ul></div>
        </details>
    </div>`;
}

// Mirrors what modules/mass_mail/views/mailing_lists.html.twig renders: two
// custom lists (one active, one not), the shared modal the create/edit flow
// drives, and the three criteria pickers with the sentence and the count
// underneath them.
const PAGE = `
    <button type="button" id="cfg-new-list-btn">Nouvelle liste</button>
    <ul id="cfg-custom-lists">
        <li data-id="7">
            <button type="button" class="cfg-edit-list-btn" data-id="7" data-name="Parents louveteaux"
                    data-description="Les parents de la meute" data-function-ids="2,3" data-section-ids="5"
                    data-badge-ids="11"></button>
            <button type="button" class="cfg-toggle-list-btn" data-id="7" data-active="1"></button>
            <button type="button" class="cfg-delete-list-btn" data-id="7"></button>
        </li>
        <li data-id="9">
            <button type="button" class="cfg-toggle-list-btn" data-id="9" data-active="0"></button>
            <button type="button" class="cfg-delete-list-btn" data-id="9"></button>
        </li>
    </ul>

    <div id="cfg-list-modal">
        <h2 id="cfg-list-modal-title">Nouvelle liste</h2>
        <div id="cfg-list-error" class="d-none"></div>
        <input type="text" id="cfg-list-name" value="">
        <textarea id="cfg-list-description"></textarea>
        ${selectBar('cfg-function-picker', 'Toutes les fonctions', 'fonctions', [
            { id: 2, label: 'Animateur' },
            { id: 3, label: 'Intendant' },
        ])}
        ${selectBar('cfg-section-picker', 'Toutes les sections', 'sections', [
            { id: 5, label: 'Meute' },
            { id: 6, label: 'Troupe' },
        ])}
        ${selectBar('cfg-badge-picker', 'Tous les badges', 'badges', [
            { id: 11, label: 'Infirmier' },
            { id: 12, label: 'Trésorier' },
        ])}
        <p id="cfg-criteria-sentence"></p>
        <p id="cfg-criteria-count" data-year-label="2025-2026"></p>
        <button type="button" id="cfg-list-save-btn">Enregistrer</button>
    </div>
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
        // The real fetch toolbox and the real select bar — base.html.twig
        // guarantees this load order in production (api.js and
        // select-bar.js both ship before {% block scripts %}).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/select-bar.js');
        await import('../../public/assets/js/mass-mail-lists.js');
    }

    /**
     * Clicks one picker row, the way a person does.
     *
     * @param {string} pickerId
     * @param {number} id
     */
    function toggle(pickerId, id) {
        document.querySelector(`#${pickerId} .select-bar-item[data-id="${id}"]`).click();
    }

    const sentence = () => document.getElementById('cfg-criteria-sentence').textContent;
    const countLine = () => document.getElementById('cfg-criteria-count');

    function lastRequest() {
        const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
        return { url, opts, body: JSON.parse(opts.body) };
    }

    const deleteBtn = () => document.querySelector('.cfg-delete-list-btn[data-id="7"]');

    /**
     * The ids one picker currently has selected, in DOM order.
     *
     * @param {string} pickerId
     */
    function selected(pickerId) {
        return Array.from(document.querySelectorAll(`#${pickerId} .select-bar-item[data-selected="true"]`))
            .map((el) => el.dataset.id);
    }

    describe('entry guard', () => {
        it('does nothing at all on a page without the modal', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('the sentence the three axes add up to', () => {
        it('says the list holds nobody while no axis carries a criterion', async () => {
            await boot();
            document.getElementById('cfg-new-list-btn').click();

            expect(sentence()).toBe('Aucun membre du site dans cette liste.');
        });

        it('names one axis in the singular, without a count', async () => {
            await boot();
            toggle('cfg-function-picker', 2);

            expect(sentence()).toBe('Membres qui exercent la fonction choisie.');
        });

        it('counts an axis that carries several, and joins the axes with ET', async () => {
            await boot();
            toggle('cfg-function-picker', 2);
            toggle('cfg-function-picker', 3);
            toggle('cfg-section-picker', 5);
            toggle('cfg-badge-picker', 11);
            toggle('cfg-badge-picker', 12);

            expect(sentence()).toBe(
                'Membres qui exercent une des 2 fonctions choisies ET sont dans la section choisie '
                + 'ET portent un des 2 badges choisis.',
            );
        });

        it('drops an empty axis from the sentence entirely — a stated ET over nothing is what confuses', async () => {
            await boot();
            toggle('cfg-badge-picker', 11);

            expect(sentence()).toBe('Membres qui portent le badge choisi.');
            expect(sentence()).not.toContain('ET');
            expect(sentence()).not.toContain('section');
        });

        it('goes back to nobody when the last criterion is unticked', async () => {
            await boot();
            toggle('cfg-section-picker', 5);
            toggle('cfg-section-picker', 5);

            expect(sentence()).toBe('Aucun membre du site dans cette liste.');
        });
    });

    describe('the live count beside it', () => {
        it('asks the server for the current criteria and states the year it counted', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, count: 12, scout_year_label: '2025-2026' }));
            await boot();

            toggle('cfg-function-picker', 2);
            toggle('cfg-section-picker', 5);
            await settle();

            const { url, body } = lastRequest();
            expect(url).toBe('/admin/listes-de-diffusion/preview-count');
            expect(body).toEqual({
                function_ids: [2],
                section_ids: [5],
                badge_ids: [],
                _csrf_token: 'tok-123',
            });
            expect(countLine().textContent).toBe('12 destinataires pour l\'année 2025-2026.');
            expect(countLine().className).not.toContain('text-danger');
        });

        it('writes the singular for exactly one recipient', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, count: 1, scout_year_label: '2025-2026' }));
            await boot();

            toggle('cfg-badge-picker', 11);
            await settle();

            expect(countLine().textContent).toBe('1 destinataire pour l\'année 2025-2026.');
        });

        it('turns red at zero and says what to look at — the crossing, not the spelling', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, count: 0, scout_year_label: '2025-2026' }));
            await boot();

            toggle('cfg-badge-picker', 11);
            toggle('cfg-section-picker', 5);
            await settle();

            expect(countLine().className).toContain('text-danger');
            expect(countLine().textContent).toContain('0 destinataire');
            expect(countLine().textContent).toContain('vérifiez le croisement');
        });

        it('asks nothing at all while no axis carries a criterion', async () => {
            await boot();
            document.getElementById('cfg-new-list-btn').click();
            await settle();

            expect(fetch).not.toHaveBeenCalled();
            expect(countLine().textContent).toBe('');
        });

        it('keeps the answer of the LAST request, whatever order the answers come back in', async () => {
            const resolvers = [];
            global.fetch = vi.fn(() => new Promise((resolve) => {
                resolvers.push(resolve);
            }));
            await boot();

            toggle('cfg-function-picker', 2);
            toggle('cfg-function-picker', 3);
            await settle();
            expect(resolvers).toHaveLength(2);

            // The FIRST request answers last — a stale answer that must not
            // land on screen.
            resolvers[1]({ ok: true, status: 200, json: () => Promise.resolve({ success: true, count: 9, scout_year_label: '2025-2026' }) });
            await settle();
            resolvers[0]({ ok: true, status: 200, json: () => Promise.resolve({ success: true, count: 4, scout_year_label: '2025-2026' }) });
            await settle();

            expect(countLine().textContent).toBe('9 destinataires pour l\'année 2025-2026.');
        });

        it('says the count could not be computed rather than showing a stale number', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();

            toggle('cfg-function-picker', 2);
            await settle();

            expect(countLine().textContent).toBe('Le nombre de destinataires n\'a pas pu être calculé.');
            expect(countLine().className).not.toContain('text-danger');
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

    describe('creating and editing a list', () => {
        it('POSTs a new list with the checked functions and sections', async () => {
            await boot();

            document.getElementById('cfg-new-list-btn').click();
            document.getElementById('cfg-list-name').value = 'Anciens';
            document.getElementById('cfg-list-description').value = 'Les anciens de l\'unité';
            toggle('cfg-function-picker', 3);
            toggle('cfg-section-picker', 6);
            toggle('cfg-badge-picker', 12);
            document.getElementById('cfg-list-save-btn').click();
            await settle();

            const { url, opts, body } = lastRequest();
            expect(url).toBe('/admin/listes-de-diffusion/lists');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({
                name: 'Anciens',
                description: 'Les anciens de l\'unité',
                function_ids: [3],
                section_ids: [6],
                badge_ids: [12],
                _csrf_token: 'tok-123',
            });
            expect(window.location.reload).toHaveBeenCalled();
        });

        it('PATCHes the edited list to its own URL, pre-filled from the row', async () => {
            await boot();

            document.querySelector('.cfg-edit-list-btn').click();
            expect(document.getElementById('cfg-list-modal-title').textContent).toBe('Modifier la liste');
            expect(document.getElementById('cfg-list-name').value).toBe('Parents louveteaux');
            expect(selected('cfg-function-picker')).toEqual(['2', '3']);
            expect(selected('cfg-section-picker')).toEqual(['5']);
            expect(selected('cfg-badge-picker')).toEqual(['11']);

            document.getElementById('cfg-list-save-btn').click();
            await settle();

            const { url, opts, body } = lastRequest();
            expect(url).toBe('/admin/listes-de-diffusion/lists/7');
            expect(opts.method).toBe('PATCH');
            expect(body.name).toBe('Parents louveteaux');
            expect(body.function_ids).toEqual([2, 3]);
            expect(body.section_ids).toEqual([5]);
            expect(body.badge_ids).toEqual([11]);
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
            expect(selected('cfg-function-picker')).toEqual([]);
            expect(selected('cfg-badge-picker')).toEqual([]);
            expect(sentence()).toBe('Aucun membre du site dans cette liste.');

            document.getElementById('cfg-list-save-btn').click();
            await settle();

            // Back to the collection URL: the edited id must not linger.
            expect(lastRequest().url).toBe('/admin/listes-de-diffusion/lists');
            expect(lastRequest().opts.method).toBe('POST');
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
