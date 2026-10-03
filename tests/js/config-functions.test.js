// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch is mocked, and so are the two site-wide
// dialog toolboxes (window.ScoutMagicToast, window.ScoutMagicConfirm) that
// base.html.twig loads on every page. Exercises the REAL implementation in
// public/assets/js/config-functions.js (imported below, never reimplemented
// here). That file is an IIFE that reads the DOM at import time, so each
// test builds its fixture first and then imports the module via
// vi.resetModules() + await import().
//
// The fixture mirrors what core/View/templates/config/functions.html.twig
// renders: .function-row/.role-select, .flags-group/.flag-lead,
// .section-row with its four controls, and .branch-row.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const FLAGS_GROUP = `
    <div class="flags-group d-none" data-id="7">
        <input type="checkbox" class="flag-lead">
    </div>`;

/**
 * The board of role zones (issue #741), as the template renders it: an
 * « À configurer » zone that lends rows and takes none, then one zone per
 * role, empty ones included.
 */
const BOARD = `
    <div id="function-board">
        <section class="function-zone">
            <span data-zone-count>1</span>
            <div class="function-zone-items" data-role="" data-receives="0" id="zone-unconfirmed">
                <div class="function-row" draggable="true" data-id="7" data-role="">
                    <span class="function-pending-badge"><span class="badge text-bg-warning">Non confirmée</span></span>
                    ${FLAGS_GROUP}
                    <button type="button" class="function-move-up"></button>
                    <button type="button" class="function-move-down"></button>
                </div>
                <p class="function-zone-empty d-none">Toutes les fonctions sont configurées.</p>
            </div>
        </section>
        <section class="function-zone">
            <span data-zone-count>0</span>
            <div class="function-zone-items" data-role="identified" id="zone-identified">
                <p class="function-zone-empty">Aucune fonction — glissez-en une ici.</p>
            </div>
        </section>
        <section class="function-zone">
            <span data-zone-count>1</span>
            <div class="function-zone-items" data-role="chief" id="zone-chief">
                <div class="function-row" draggable="true" data-id="8" data-role="chief">
                    <button type="button" class="function-move-up"></button>
                    <button type="button" class="function-move-down"></button>
                </div>
                <p class="function-zone-empty d-none">Aucune fonction — glissez-en une ici.</p>
            </div>
        </section>
    </div>`;

const SECTION_ROW = `
    <div class="section-row" data-id="10">
        <input type="text" class="section-name-input" value="Louveteaux">
        <input type="email" class="section-email-input" value="lou@example.be">
        <input type="color" class="section-color-input" value="#112233" data-has-override="0">
        <button type="button" class="section-color-reset" disabled></button>
        <input type="checkbox" class="section-visible-input" role="switch"
               id="section-visible-10" checked aria-checked="true">
        <output class="section-email-warning d-none"><span class="section-email-warning-text"></span></output>
    </div>`;

const BRANCH_ROW = `
    <div class="branch-row" data-id="3">
        <input type="url" class="branch-url-input" value="https://lesscouts.be/lou">
    </div>`;

const PAGE = BOARD + SECTION_ROW + BRANCH_ROW;

/** The site-wide envelope for a JSON answer the server really sent. */
function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('config-functions.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    });

    async function boot() {
        // The real fetch toolbox — config-functions.js posts through
        // window.ScoutMagicApi (base.html.twig guarantees this load order
        // in production).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/sortable.js');
        await import('../../public/assets/js/config-functions.js');
    }

    function lastRequest() {
        const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
        return { url, opts, body: JSON.parse(opts.body) };
    }

    describe('entry guard', () => {
        it('does nothing at all on a page carrying none of its controls', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('wires the sections even when the functions list is empty (no import yet)', async () => {
            document.body.innerHTML = SECTION_ROW;
            await boot();
            document.querySelector('.section-name-input').dispatchEvent(new Event('blur'));
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().url).toBe('/config/functions/section-name');
        });
    });

    describe('function roles — the board (issue #741)', () => {
        const zone = (id) => document.getElementById(id);
        const row = (id) => document.querySelector(`.function-row[data-id="${id}"]`);
        const zoneIds = (id) => [...zone(id).querySelectorAll('.function-row')].map((r) => r.dataset.id);
        const emptyShown = (id) => !zone(id).querySelector('.function-zone-empty').classList.contains('d-none');
        const countOf = (id) => zone(id).closest('.function-zone').querySelector('[data-zone-count]').textContent;

        /** A native drag of `id` into `target`'s empty space. */
        function drag(id, target) {
            row(id).dispatchEvent(new Event('dragstart', { bubbles: true }));
            const over = new Event('dragover', { bubbles: true, cancelable: true });
            Object.defineProperties(over, { clientX: { value: 0 }, clientY: { value: 0 } });
            target.dispatchEvent(over);
            row(id).dispatchEvent(new Event('dragend', { bubbles: true }));
        }

        it('offers no role select any more', async () => {
            await boot();
            expect(document.querySelector('.role-select')).toBeNull();
        });

        it('a drag into an EMPTY role saves that role, confirms the function and says so', async () => {
            await boot();

            drag('7', zone('zone-identified').querySelector('.function-zone-empty'));

            expect(zoneIds('zone-identified')).toEqual(['7']);
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, opts, body } = lastRequest();
            expect(url).toBe('/config/functions/update');
            expect(body).toEqual({ function_id: 7, role: 'identified', _csrf_token: 'tok-123' });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Enregistré.', { variant: 'success' }));
            expect(row('7').querySelector('.function-pending-badge')).toBeNull();
            expect(row('7').dataset.role).toBe('identified');
            expect(emptyShown('zone-identified')).toBe(false);
            expect(emptyShown('zone-unconfirmed')).toBe(true);
            expect(countOf('zone-identified')).toBe('1');
            expect(countOf('zone-unconfirmed')).toBe('0');
        });

        it('moving a confirmed function changes its role, and shows the Chef-only flag only where it applies', async () => {
            await boot();

            drag('7', zone('zone-chief'));

            await vi.waitFor(() => expect(row('7').dataset.role).toBe('chief'));
            expect(row('7').querySelector('.flags-group').classList.contains('d-none')).toBe(false);

            drag('7', zone('zone-identified'));
            await vi.waitFor(() => expect(row('7').dataset.role).toBe('identified'));
            expect(row('7').querySelector('.flags-group').classList.contains('d-none')).toBe(true);
            expect(lastRequest().body).toEqual({ function_id: 7, role: 'identified', _csrf_token: 'tok-123' });
        });

        it('PUTS THE FUNCTION BACK in its previous zone when the server refuses, with the error toast', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Rôle invalide.' }));
            await boot();

            drag('8', zone('zone-identified'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Rôle invalide.', { variant: 'error' }));
            expect(zoneIds('zone-chief')).toEqual(['8']);
            expect(zoneIds('zone-identified')).toEqual([]);
            expect(emptyShown('zone-identified')).toBe(true);
            expect(row('8').dataset.role).toBe('chief');
        });

        it('reads an HTTP 500 error page as a failure, never as a save', async () => {
            global.fetch = vi.fn(() => Promise.resolve({
                ok: false,
                status: 500,
                json: () => Promise.reject(new SyntaxError('Unexpected token <')),
            }));
            await boot();

            drag('7', zone('zone-chief'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur : réponse serveur invalide.', { variant: 'error' }));
            expect(zoneIds('zone-unconfirmed')).toEqual(['7']);
            expect(row('7').querySelector('.function-pending-badge')).not.toBeNull();
        });

        it('never takes a function back into « À configurer »', async () => {
            await boot();

            drag('8', zone('zone-unconfirmed'));

            expect(zoneIds('zone-chief')).toEqual(['8']);
            expect(fetch).not.toHaveBeenCalled();
        });

        it('a reorder inside a role sends nothing — the order means nothing', async () => {
            await boot();

            drag('8', zone('zone-chief'));

            expect(fetch).not.toHaveBeenCalled();
        });

        it('the arrows move a function to the zone below or above, never into « À configurer »', async () => {
            await boot();

            row('7').querySelector('.function-move-down').dispatchEvent(new MouseEvent('click', { bubbles: true }));
            expect(zoneIds('zone-identified')).toEqual(['7']);
            await vi.waitFor(() => expect(lastRequest().body.role).toBe('identified'));

            row('7').querySelector('.function-move-up').dispatchEvent(new MouseEvent('click', { bubbles: true }));
            expect(zoneIds('zone-identified')).toEqual(['7']);
            expect(fetch).toHaveBeenCalledTimes(1);

            row('8').querySelector('.function-move-up').dispatchEvent(new MouseEvent('click', { bubbles: true }));
            expect(zoneIds('zone-identified')).toEqual(['7', '8']);
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
            expect(lastRequest().body).toEqual({ function_id: 8, role: 'identified', _csrf_token: 'tok-123' });
        });
    });

    describe('per-function flags', () => {
        it('POSTs the lead flag as a boolean to /config/functions/flags', async () => {
            await boot();
            const lead = document.querySelector('.flag-lead');
            lead.checked = true;
            lead.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/functions/flags');
            expect(body).toEqual({ function_id: 7, lead: true, _csrf_token: 'tok-123' });
        });

        it('toasts the server error when the flag is refused', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Fonction introuvable.' }));
            await boot();
            document.querySelector('.flag-lead').dispatchEvent(new Event('change'));
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Fonction introuvable.', { variant: 'error' }));
        });

        it('confirms a saved flag with a toast', async () => {
            await boot();
            document.querySelector('.flag-lead').dispatchEvent(new Event('change'));
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Enregistré.', { variant: 'success' }));
        });

        it('flips a refused flag back', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Non.' }));
            await boot();
            const lead = document.querySelector('.flag-lead');
            lead.checked = true;
            lead.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Non.', { variant: 'error' }));
            expect(lead.checked).toBe(false);
        });
    });

    describe('sections', () => {
        it('saves the name on blur', async () => {
            await boot();
            const input = document.querySelector('.section-name-input');
            input.value = 'Louveteaux Saint-Michel';
            input.dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/functions/section-name');
            expect(body).toEqual({ section_id: 10, name: 'Louveteaux Saint-Michel', _csrf_token: 'tok-123' });
        });

        it('saves the organisational email on blur', async () => {
            await boot();
            const input = document.querySelector('.section-email-input');
            input.value = 'louveteaux@unite-exemple.be';
            input.dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/functions/section-email');
            expect(body).toEqual({ section_id: 10, email: 'louveteaux@unite-exemple.be', _csrf_token: 'tok-123' });
        });

        // ── The DMARC warning the server answers with (issue #418) ────

        it('shows the warning the server sent for an address it cannot sign for', async () => {
            global.fetch = vi.fn(() => jsonResponse({
                success: true,
                alignment_warning: 'L\'adresse e-mail configurée pour cette section n\'est pas sur le domaine d\'envoi du site (unite.be).'
            }));
            await boot();
            const input = document.querySelector('.section-email-input');
            input.value = 'louveteaux@telenet.be';
            input.dispatchEvent(new Event('blur'));

            const warning = document.querySelector('.section-email-warning');
            await vi.waitFor(() => expect(warning.classList.contains('d-none')).toBe(false));
            expect(document.querySelector('.section-email-warning-text').textContent)
                .toContain('domaine d\'envoi du site');
        });

        // The half that clears it: an answer with nothing to warn about has
        // to take the previous sentence off the screen, or the operator
        // fixes the address and is still told it is wrong.
        it('hides the warning again when the server has nothing to warn about', async () => {
            document.querySelector('.section-email-warning').classList.remove('d-none');
            document.querySelector('.section-email-warning-text').textContent = 'un avertissement précédent';
            global.fetch = vi.fn(() => jsonResponse({ success: true, alignment_warning: null }));
            await boot();
            const input = document.querySelector('.section-email-input');
            input.value = 'louveteaux@unite.be';
            input.dispatchEvent(new Event('blur'));

            const warning = document.querySelector('.section-email-warning');
            await vi.waitFor(() => expect(warning.classList.contains('d-none')).toBe(true));
            expect(document.querySelector('.section-email-warning-text').textContent).toBe('');
        });

        it('saves the visibility switch as a boolean', async () => {
            await boot();
            const input = document.querySelector('.section-visible-input');
            input.checked = false;
            input.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/functions/section-visibility');
            expect(body).toEqual({ section_id: 10, visible: false, _csrf_token: 'tok-123' });
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Enregistré.', { variant: 'success' }));
        });

        it('flips a refused visibility switch back, aria-checked included', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Non.' }));
            window.ScoutMagicNav = {
                syncSwitchAriaChecked: (input) => input.setAttribute('aria-checked', String(input.checked)),
            };
            await boot();
            const input = document.querySelector('.section-visible-input');
            input.checked = false;
            input.setAttribute('aria-checked', 'false');
            input.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Non.', { variant: 'error' }));
            expect(input.checked).toBe(true);
            expect(input.getAttribute('aria-checked')).toBe('true');
            delete window.ScoutMagicNav;
        });

        it('confirms a saved name with a toast, and keeps a refused one on screen', async () => {
            await boot();
            const name = document.querySelector('.section-name-input');
            name.dispatchEvent(new Event('blur'));
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Enregistré.', { variant: 'success' }));

            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Nom invalide.' }));
            name.value = 'Nouveau nom';
            name.dispatchEvent(new Event('blur'));
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Nom invalide.', { variant: 'error' }));
            expect(name.value).toBe('Nouveau nom');
        });

        it('adopts the effective colour the server answers with and enables the reset button', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, color: '#445566' }));
            await boot();
            const colorInput = document.querySelector('.section-color-input');
            colorInput.value = '#445566';
            colorInput.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/functions/section-color');
            expect(body).toEqual({ section_id: 10, color: '#445566', _csrf_token: 'tok-123' });
            await vi.waitFor(() => expect(document.querySelector('.section-color-reset').disabled).toBe(false));
            expect(colorInput.dataset.hasOverride).toBe('1');
        });

        it('clears the override with a null colour and disables the reset button again', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: true, color: '#00aa00' }));
            await boot();
            const reset = document.querySelector('.section-color-reset');
            reset.disabled = false;
            reset.click();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().body).toEqual({ section_id: 10, color: null, _csrf_token: 'tok-123' });
            const colorInput = document.querySelector('.section-color-input');
            await vi.waitFor(() => expect(colorInput.value).toBe('#00aa00'));
            expect(colorInput.dataset.hasOverride).toBe('0');
            expect(reset.disabled).toBe(true);
        });

        it('puts the colour picker back on the saved colour when the save fails', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Couleur invalide.' }));
            await boot();
            const colorInput = document.querySelector('.section-color-input');
            colorInput.value = '#ffffff';
            colorInput.dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Couleur invalide.', { variant: 'error' }));
            expect(colorInput.dataset.hasOverride).toBe('0');
            expect(colorInput.value).toBe('#112233');
        });
    });

    describe('branches', () => {
        it('saves the explanation link on blur', async () => {
            await boot();
            const input = document.querySelector('.branch-url-input');
            input.value = 'https://lesscouts.be/baladins';
            input.dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body } = lastRequest();
            expect(url).toBe('/config/functions/branch-url');
            expect(body).toEqual({ branch_id: 3, url: 'https://lesscouts.be/baladins', _csrf_token: 'tok-123' });
        });
    });

    describe('server text never becomes markup', () => {
        it('renders a script-carrying error message as text in the real toast', async () => {
            // The one place server-controlled text reaches the DOM: the
            // error toast. Run it through the REAL toast.js rather than the
            // stub, so the escaping guarantee is the production one.
            delete window.ScoutMagicToast;
            await import('../../public/assets/js/toast.js');
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: '<img src=x onerror=alert(1)>' }));
            await boot();
            document.querySelector('.function-row[data-id="8"] .function-move-up')
                .dispatchEvent(new MouseEvent('click', { bubbles: true }));

            await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
            expect(document.querySelector('.toast-body img')).toBeNull();
            expect(document.querySelector('.toast-body').textContent).toBe('<img src=x onerror=alert(1)>');
        });
    });
});
