// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch is mocked, and so are the two site-wide
// dialog toolboxes (window.ScoutMagicToast, window.ScoutMagicConfirm) that
// base.html.twig loads on every page. Exercises the REAL implementation in
// public/assets/js/member-search.js (imported below, never reimplemented
// here). That file is an IIFE that reads the DOM at import time, so each
// test builds its fixture first and then imports the module via
// vi.resetModules() + await import().
//
// The fixture mirrors what core/View/templates/admin/members/search.html.twig
// and its two partials render: the export form with its selected[]
// checkboxes, and the detail card's scout-year offset buttons and departure
// controls.
//
// This page renders member personal data (AGENTS.md § Security checklist),
// so two of the cases below are specifically about a member name or a
// server-sent label carrying a quote or a <script>: nothing on this page may
// turn either into markup.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** One result row, exactly as admin/members/_result_row.html.twig renders it. */
function resultRow(id, lastName, firstName) {
    return `
    <div class="d-flex align-items-center gap-2">
        <label>
            <input class="form-check-input member-export-checkbox m-0" type="checkbox" name="selected[]"
                   value="${id}" aria-label="Sélectionner ${lastName} ${firstName} pour l'export">
        </label>
        <a href="/admin/members?q=dupont&amp;member=${id}#member-detail"><div>${lastName}, ${firstName}</div></a>
    </div>`;
}

const EXPORT_FORM = `
    <form method="get" action="/admin/members/export" id="member-export-form">
        <input type="checkbox" id="member-export-select-all">
        <button type="submit" id="member-export-selection-btn">
            Exporter la sélection<span id="member-export-selection-count"></span>
        </button>
        ${resultRow(101, 'Dupont', 'Camille')}
        ${resultRow(102, 'Martin', 'Alex')}
    </form>`;

const DETAIL_CARD = `
    <div class="card mt-4" id="member-detail">
        <div id="scout-year-offset-card" data-member-year-id="101">
            <button type="button" class="btn offset-btn" data-offset="-1">−1</button>
            <button type="button" class="btn offset-btn active" data-offset="0">0</button>
            <button type="button" class="btn offset-btn" data-offset="1">+1</button>
            <span class="rounded-circle d-inline-block" id="scout-year-offset-dot"></span>
            <span id="scout-year-offset-label">Louveteaux 2</span>
        </div>
        <div class="mt-4" id="departure-card" data-member-year-id="101">
            <input type="checkbox" class="form-check-input" id="departure-checkbox">
            <div class="mt-2" id="departure-comment-row" style="display:none;">
                <textarea id="departure-comment"></textarea>
            </div>
        </div>
    </div>`;

const PAGE = EXPORT_FORM + DETAIL_CARD;

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

describe('member-search.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    });

    async function boot() {
        // The real fetch toolbox — member-search.js posts through
        // window.ScoutMagicApi (base.html.twig guarantees this load order
        // in production).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/member-search.js');
    }

    function lastRequest() {
        const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
        return { url, opts, body: JSON.parse(opts.body) };
    }

    const checkboxes = () => Array.from(document.querySelectorAll('.member-export-checkbox'));
    const el = (id) => document.getElementById(id);

    describe('entry guard', () => {
        it('does nothing at all on a page carrying none of its anchors', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('wires the export list on a search with results but no selected member', async () => {
            document.body.innerHTML = EXPORT_FORM;
            await boot();
            expect(/** @type {HTMLButtonElement} */ (el('member-export-selection-btn')).disabled).toBe(true);
        });

        it('wires the detail card on a page whose search returned a single member', async () => {
            document.body.innerHTML = DETAIL_CARD;
            await boot();
            document.querySelector('.offset-btn').dispatchEvent(new Event('click'));
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().url).toBe('/members/101/scout-year-offset');
        });

        it('scrolls the detail card into view when the browser can', async () => {
            const scrollIntoView = vi.fn();
            document.getElementById('member-detail').scrollIntoView = scrollIntoView;
            await boot();
            expect(scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'start' });
        });
    });

    describe('export selection', () => {
        it('starts with the submit button disabled and no count', async () => {
            await boot();
            expect(/** @type {HTMLButtonElement} */ (el('member-export-selection-btn')).disabled).toBe(true);
            expect(el('member-export-selection-count').textContent).toBe('');
        });

        it('enables the button and shows the live count when a member is ticked', async () => {
            await boot();
            const cb = checkboxes()[0];
            cb.checked = true;
            cb.dispatchEvent(new Event('change'));

            expect(/** @type {HTMLButtonElement} */ (el('member-export-selection-btn')).disabled).toBe(false);
            expect(el('member-export-selection-count').textContent).toBe(' (1)');
            expect(/** @type {HTMLInputElement} */ (el('member-export-select-all')).indeterminate).toBe(true);
        });

        it('ticks every row from the master checkbox and reports them all', async () => {
            await boot();
            const all = /** @type {HTMLInputElement} */ (el('member-export-select-all'));
            all.checked = true;
            all.dispatchEvent(new Event('change'));

            expect(checkboxes().every(cb => cb.checked)).toBe(true);
            expect(el('member-export-selection-count').textContent).toBe(' (2)');
            expect(all.indeterminate).toBe(false);
        });

        it('unticks every row again, disabling the button', async () => {
            await boot();
            const all = /** @type {HTMLInputElement} */ (el('member-export-select-all'));
            all.checked = true;
            all.dispatchEvent(new Event('change'));
            all.checked = false;
            all.dispatchEvent(new Event('change'));

            expect(checkboxes().some(cb => cb.checked)).toBe(false);
            expect(/** @type {HTMLButtonElement} */ (el('member-export-selection-btn')).disabled).toBe(true);
        });

        it('never sends anything by itself — the export form is a plain GET submit', async () => {
            await boot();
            const all = /** @type {HTMLInputElement} */ (el('member-export-select-all'));
            all.checked = true;
            all.dispatchEvent(new Event('change'));
            expect(fetch).not.toHaveBeenCalled();
        });

        it('cannot be broken out of by a member name carrying a quote or a <script>', async () => {
            // A real family name is not an attack, but a member value must
            // never be able to become markup. The script reads these rows,
            // it never rebuilds them: ticking them all must add no element
            // to the page and must still count correctly.
            const PAYLOAD = '"><script>window.pwned=1</script>';
            document.body.innerHTML = EXPORT_FORM + resultRow(103, 'Nom', 'Prénom');
            // Set programmatically, the way Twig's autoescaping delivers it:
            // the raw payload really is in the attribute and in the text,
            // inert, rather than having broken the fixture's own markup.
            const injected = checkboxes()[2];
            injected.setAttribute('aria-label', 'Sélectionner ' + PAYLOAD + ' pour l\'export');
            injected.closest('.d-flex').querySelector('a div').textContent = PAYLOAD;
            await boot();
            const all = /** @type {HTMLInputElement} */ (el('member-export-select-all'));
            all.checked = true;
            all.dispatchEvent(new Event('change'));

            expect(el('member-export-selection-count').textContent).toBe(' (3)');
            expect(window.pwned).toBeUndefined();
            expect(document.querySelector('#member-export-form script')).toBeNull();
            // The payload is still the row's own text, inert, where the
            // server put it.
            expect(checkboxes()[2].getAttribute('aria-label')).toBe('Sélectionner ' + PAYLOAD + ' pour l\'export');
            const nameCell = checkboxes()[2].closest('.d-flex').querySelector('a div');
            expect(nameCell.textContent).toBe(PAYLOAD);
            expect(nameCell.children.length).toBe(0);
        });
    });

    describe('scout-year offset', () => {
        function clickOffset(value) {
            document.querySelector(`.offset-btn[data-offset="${value}"]`).dispatchEvent(new Event('click'));
        }

        it('POSTs the offset with the CSRF token in the body AND the header', async () => {
            await boot();
            clickOffset(-1);

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, opts, body } = lastRequest();
            expect(url).toBe('/members/101/scout-year-offset');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({ offset: -1, _csrf_token: 'tok-123' });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
        });

        it('moves the active state and adopts the label and colour the server answers with', async () => {
            global.fetch = vi.fn(() => jsonResponse({
                success: true,
                branch_year_label: 'Baladins 3',
                branch_color: '#aa2211',
            }));
            await boot();
            clickOffset(1);

            await vi.waitFor(() => expect(el('scout-year-offset-label').textContent).toBe('Baladins 3'));
            expect(document.querySelector('.offset-btn[data-offset="1"]').classList.contains('active')).toBe(true);
            expect(document.querySelector('.offset-btn[data-offset="0"]').classList.contains('active')).toBe(false);
            expect(el('scout-year-offset-dot').style.backgroundColor).toBe('rgb(170, 34, 17)');
        });

        it('disables every button for the round trip and re-enables them afterwards', async () => {
            await boot();
            clickOffset(-1);
            expect(Array.from(document.querySelectorAll('.offset-btn')).every(b => b.disabled)).toBe(true);
            await vi.waitFor(() => expect(
                Array.from(document.querySelectorAll('.offset-btn')).some(b => b.disabled)
            ).toBe(false));
        });

        it('surfaces a business failure as an error toast and moves nothing', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Décalage invalide.' }));
            await boot();
            clickOffset(1);

            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Décalage invalide.', { variant: 'error' }));
            expect(document.querySelector('.offset-btn[data-offset="0"]').classList.contains('active')).toBe(true);
            expect(el('scout-year-offset-label').textContent).toBe('Louveteaux 2');
            expect(Array.from(document.querySelectorAll('.offset-btn')).some(b => b.disabled)).toBe(false);
        });

        it('reads an HTTP 500 error page as a failure, never as a save (ok is not success)', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();
            clickOffset(1);

            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur.', { variant: 'error' }));
            expect(document.querySelector('.offset-btn[data-offset="0"]').classList.contains('active')).toBe(true);
            expect(el('scout-year-offset-label').textContent).toBe('Louveteaux 2');
        });

        it('toasts a network failure instead of failing silently', async () => {
            global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
            await boot();
            clickOffset(1);

            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur.', { variant: 'error' }));
        });

        it('writes the server label as text, so a <script> in it stays a <script> nobody runs', async () => {
            global.fetch = vi.fn(() => jsonResponse({
                success: true,
                branch_year_label: '<img src=x onerror=alert(1)>',
            }));
            await boot();
            clickOffset(1);

            await vi.waitFor(() => expect(el('scout-year-offset-label').textContent)
                .toBe('<img src=x onerror=alert(1)>'));
            expect(el('scout-year-offset-label').querySelector('img')).toBeNull();
        });

        it('sends nothing when the card carries no usable member id', async () => {
            // No member value ever builds a URL here: an id that is not a
            // positive integer is not an id, and the request is not made.
            document.getElementById('scout-year-offset-card').dataset.memberYearId = 'undefined';
            await boot();
            clickOffset(1);

            await Promise.resolve();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('departure', () => {
        it('POSTs the leaving flag and reveals the comment row', async () => {
            await boot();
            const checkbox = /** @type {HTMLInputElement} */ (el('departure-checkbox'));
            checkbox.checked = true;
            checkbox.dispatchEvent(new Event('change'));

            expect(el('departure-comment-row').style.display).toBe('');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const { url, body, opts } = lastRequest();
            expect(url).toBe('/members/101/departure');
            expect(body).toEqual({ leaving: true, _csrf_token: 'tok-123' });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
        });

        it('hides the comment row again when the member is no longer leaving', async () => {
            await boot();
            const checkbox = /** @type {HTMLInputElement} */ (el('departure-checkbox'));
            checkbox.checked = false;
            checkbox.dispatchEvent(new Event('change'));

            expect(el('departure-comment-row').style.display).toBe('none');
            await vi.waitFor(() => expect(lastRequest().body)
                .toEqual({ leaving: false, _csrf_token: 'tok-123' }));
        });

        it('saves the comment on blur, one field at a time', async () => {
            await boot();
            const comment = /** @type {HTMLTextAreaElement} */ (el('departure-comment'));
            comment.value = 'Déménagement';
            comment.dispatchEvent(new Event('blur'));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().body).toEqual({ comment: 'Déménagement', _csrf_token: 'tok-123' });
        });

        it('surfaces a business failure as an error toast', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Membre introuvable.' }));
            await boot();
            el('departure-checkbox').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Membre introuvable.', { variant: 'error' }));
        });

        it('falls back to the page\'s own wording when the failure carries no message', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false }));
            await boot();
            el('departure-checkbox').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur lors de l\'enregistrement.', { variant: 'error' }));
        });

        it('reads an HTTP 500 error page as a failure, never as a save (ok is not success)', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();
            el('departure-checkbox').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur lors de l\'enregistrement.', { variant: 'error' }));
        });

        it('keeps « Erreur réseau. » for the request that never reached a server', async () => {
            global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
            await boot();
            el('departure-checkbox').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Erreur réseau.', { variant: 'error' }));
        });
    });

    describe('server text never becomes markup', () => {
        it('renders a script-carrying error message as text in the real toast', async () => {
            delete window.ScoutMagicToast;
            await import('../../public/assets/js/toast.js');
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: '<img src=x onerror=alert(1)>' }));
            await boot();
            el('departure-checkbox').dispatchEvent(new Event('change'));

            await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
            expect(document.querySelector('.toast-body img')).toBeNull();
            expect(document.querySelector('.toast-body').textContent).toBe('<img src=x onerror=alert(1)>');
        });
    });
});
