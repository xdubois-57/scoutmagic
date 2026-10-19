// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/admin-import.js
// (imported below, never reimplemented here): the year confirmation of the
// Desk import page and the « Dernier import » block that follows the
// selected year.
//
// The file is an IIFE that binds a DOMContentLoaded listener at import
// time, so each test builds its DOM first and then imports the module via
// vi.resetModules() + await import().
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const ISLAND = {
    years: [
        { id: 12, label: '2027-2028', confirm: null, lastImport: null },
        {
            id: 11,
            label: '2026-2027',
            confirm: {
                title: "Importer dans l'année publique 2026-2027 ?",
                message: "L'année suivante 2027-2028 est déjà importable.",
                confirmLabel: 'Importer dans 2026-2027',
            },
            lastImport: { id: 40, summary: '12/06/2027 à 19:02 — 151 membres, 160 lignes' },
        },
    ],
};

function buildPage(island = ISLAND, selected = '12') {
    document.body.innerHTML = `
        <form id="desk-import-form" method="post" action="/admin/import">
            <select id="scout-year-id" name="scout_year_id">
                <option value="12"${selected === '12' ? ' selected' : ''}>2027-2028 — année suivante</option>
                <option value="11"${selected === '11' ? ' selected' : ''}>2026-2027 — année publique</option>
            </select>
            <input type="hidden" name="confirmed_year" value="" id="desk-import-confirmed-year">
            <input type="file" name="csv_file" id="desk-import-file">
            <button type="submit">Importer</button>
        </form>
        <div id="desk-import-last"><h2>Dernier import de 2027-2028</h2></div>
        <script type="application/json" id="desk-import-years">${JSON.stringify(island)}</script>
    `;
}

const listeners = [];

async function load() {
    vi.resetModules();
    const register = document.addEventListener.bind(document);
    vi.spyOn(document, 'addEventListener').mockImplementation((type, handler, options) => {
        listeners.push([type, handler, options]);
        register(type, handler, options);
    });
    await import('../../public/assets/js/admin-import.js');
    document.dispatchEvent(new Event('DOMContentLoaded'));
}

function form() {
    return /** @type {HTMLFormElement} */ (document.getElementById('desk-import-form'));
}

function select(value) {
    const el = /** @type {HTMLSelectElement} */ (document.getElementById('scout-year-id'));
    el.value = value;
    el.dispatchEvent(new Event('change'));
}

function confirmedYear() {
    return /** @type {HTMLInputElement} */ (document.getElementById('desk-import-confirmed-year')).value;
}

/** Dispatches a cancellable submit and reports whether it was let through. */
function submit() {
    const event = new Event('submit', { cancelable: true });
    form().dispatchEvent(event);
    return !event.defaultPrevented;
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('admin-import', () => {
    let answer;

    beforeEach(() => {
        document.body.innerHTML = '';
        answer = true;
        window.ScoutMagicConfirm = { ask: vi.fn(async () => answer) };
        window.ScoutMagicApi = {
            pageData: (id) => {
                const el = document.getElementById(id);
                if (!el) {
                    return null;
                }
                try {
                    return JSON.parse(el.textContent || 'null');
                } catch (e) {
                    return null;
                }
            },
        };
    });

    afterEach(() => {
        listeners.splice(0).forEach(([type, handler, options]) =>
            document.removeEventListener(type, handler, options));
        vi.restoreAllMocks();
    });

    describe('confirmationFor / maySubmit', () => {
        it('asks for the year that carries a question, and only for it', async () => {
            await load();
            const { confirmationFor, maySubmit } = window.ScoutMagicDeskImport;

            expect(confirmationFor(ISLAND, '11')?.confirmLabel).toBe('Importer dans 2026-2027');
            expect(confirmationFor(ISLAND, 12)).toBeNull();
            expect(confirmationFor(ISLAND, '99')).toBeNull();
            expect(confirmationFor(null, '11')).toBeNull();

            expect(maySubmit(ISLAND, '12', '')).toBe(true);
            expect(maySubmit(ISLAND, '11', '')).toBe(false);
            expect(maySubmit(ISLAND, '11', '12')).toBe(false);
            expect(maySubmit(ISLAND, '11', '11')).toBe(true);
        });
    });

    describe('submitting', () => {
        it('lets a year without a question through untouched', async () => {
            buildPage();
            await load();

            expect(submit()).toBe(true);
            expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
            expect(confirmedYear()).toBe('');
        });

        it('asks before importing into the public year, naming the action on the button', async () => {
            buildPage(ISLAND, '11');
            await load();
            const requestSubmit = vi.spyOn(form(), 'requestSubmit').mockImplementation(() => {});

            expect(submit()).toBe(false);
            await flush();

            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
                title: "Importer dans l'année publique 2026-2027 ?",
                message: "L'année suivante 2027-2028 est déjà importable.",
                confirmLabel: 'Importer dans 2026-2027',
                variant: 'primary',
            });
            expect(confirmedYear()).toBe('11');
            expect(requestSubmit).toHaveBeenCalledTimes(1);

            // The resubmission goes through: the question is answered for this year.
            expect(submit()).toBe(true);
            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledTimes(1);
        });

        it('sends nothing and confirms nothing when the dialog is cancelled', async () => {
            answer = false;
            buildPage(ISLAND, '11');
            await load();
            const requestSubmit = vi.spyOn(form(), 'requestSubmit').mockImplementation(() => {});

            expect(submit()).toBe(false);
            await flush();

            expect(confirmedYear()).toBe('');
            expect(requestSubmit).not.toHaveBeenCalled();
        });

        it('asks once however many times the button is pressed while the dialog is open', async () => {
            let release;
            window.ScoutMagicConfirm.ask = vi.fn(() => new Promise((resolve) => { release = resolve; }));
            buildPage(ISLAND, '11');
            await load();
            vi.spyOn(form(), 'requestSubmit').mockImplementation(() => {});

            expect(submit()).toBe(false);
            expect(submit()).toBe(false);
            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledTimes(1);

            release(false);
            await flush();
        });

        it('withdraws a confirmation when another year is picked', async () => {
            buildPage(ISLAND, '11');
            await load();
            vi.spyOn(form(), 'requestSubmit').mockImplementation(() => {});
            submit();
            await flush();
            expect(confirmedYear()).toBe('11');

            select('12');
            expect(confirmedYear()).toBe('');

            select('11');
            expect(submit()).toBe(false);
        });
    });

    describe('the last import block', () => {
        it('follows the selected year, with its report and its history', async () => {
            buildPage();
            await load();

            select('11');
            const block = document.getElementById('desk-import-last');
            expect(block.querySelector('h2').textContent).toBe('Dernier import de 2026-2027');
            expect(block.querySelector('p').textContent).toBe('12/06/2027 à 19:02 — 151 membres, 160 lignes');
            const links = [...block.querySelectorAll('a')].map((a) => [a.textContent, a.getAttribute('href')]);
            expect(links).toEqual([
                ['Voir son rapport', '/admin/import/40/rapport'],
                ['Historique de 2026-2027', '/admin/import/historique?annee=11'],
            ]);
        });

        it('says so when the year has no import yet', async () => {
            buildPage(ISLAND, '11');
            await load();

            select('12');
            const block = document.getElementById('desk-import-last');
            expect(block.querySelector('h2').textContent).toBe('Dernier import de 2027-2028');
            expect(block.querySelector('p').textContent).toBe('Aucun import pour 2027-2028.');
            expect(block.querySelector('a')).toBeNull();
        });

        it('renders island text as text, never as markup', async () => {
            const island = {
                years: [
                    { id: 12, label: '<img src=x onerror=alert(1)>', confirm: null,
                      lastImport: { id: 3, summary: '<b>gras</b>' } },
                ],
            };
            buildPage(island);
            await load();

            select('12');
            const block = document.getElementById('desk-import-last');
            expect(block.querySelector('img')).toBeNull();
            expect(block.querySelector('b')).toBeNull();
            expect(block.querySelector('p').textContent).toBe('<b>gras</b>');
        });

        it('builds no link from an id that is not a whole number', async () => {
            const island = {
                years: [{ id: 12, label: '2027-2028', confirm: null, lastImport: { id: 'javascript:alert(1)', summary: 'x' } }],
            };
            buildPage(island);
            await load();

            select('12');
            expect(document.getElementById('desk-import-last').querySelector('a')).toBeNull();
        });
    });

    it('does nothing on a page without the form', async () => {
        document.body.innerHTML = '<p>Autre page</p>';
        await load();

        expect(window.ScoutMagicDeskImport).toBeDefined();
    });
});
