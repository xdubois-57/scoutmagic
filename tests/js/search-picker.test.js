// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/search-picker.js
// (imported below, never reimplemented here): the generic search picker of
// core/View/templates/partials/search_picker.html.twig.
//
// The file is an IIFE that reads the DOM at import time, so each test builds
// its DOM first and then imports the module via vi.resetModules() + await
// import() (the tests/js/camps-stay-picker.test.js pattern). The markup below
// mirrors what the partial renders; Tests\Core\View\SearchPickerRenderingTest
// pins the partial's side of it.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const EVENTS = [
    { id: 11, label: "Fête d'unité — Baladins", subtitle: 'calendrier Baladins', badge: 'Baladins' },
    { id: 12, label: "Fête d'unité — Louveteaux", subtitle: 'calendrier Louveteaux', badge: 'Louveteaux' },
    { id: 13, label: "Fête d'unité — Éclaireurs", badge: 'Éclaireurs', warning: 'Un covoiturage existe déjà pour cet évènement.' },
];

function buildPicker({ mode = 'single', selected = [], url = '/recherche' } = {}) {
    const name = mode === 'multiple' ? 'event_ids[]' : 'event_id';
    document.body.innerHTML = `
        <form id="form">
            <div class="search-picker" id="p" data-search-picker data-mode="${mode}"
                 data-search-url="${url}" data-field-name="${name}"
                 data-empty-label="Aucun évènement ne correspond.">
                <div data-search-picker-fallback>
                    <select name="${name}" ${mode === 'multiple' ? 'multiple' : ''}>
                        <option value="11">Fête d'unité — Baladins</option>
                    </select>
                </div>
                <div class="d-none" data-search-picker-search>
                    ${mode === 'multiple' ? '<div data-search-picker-chosen></div>' : ''}
                    <input type="text" id="p-search">
                    <div class="list-group d-none" data-search-picker-results></div>
                    <div data-search-picker-values></div>
                </div>
            </div>
        </form>
    `;
    // Set through the DOM rather than in the template string: a label
    // holding an apostrophe would otherwise end the attribute early.
    document.getElementById('p').dataset.selected = JSON.stringify(selected);
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/search-picker.js');
}

const search = () => /** @type {HTMLInputElement} */ (document.getElementById('p-search'));
const results = () => /** @type {HTMLElement} */ (document.querySelector('[data-search-picker-results]'));
const posted = () => new FormData(/** @type {HTMLFormElement} */ (document.getElementById('form')));

function answerWith(rows) {
    window.ScoutMagicApi.getJson = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        data: { success: true, results: rows },
    });
}

/** Type, then let the debounce and the promise settle. */
async function type(value) {
    search().value = value;
    search().dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(300);
}

function resultButtons() {
    return Array.from(results().querySelectorAll('button'));
}

describe('search-picker', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = '';
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('the upgrade', () => {
        it('removes the fallback list so the field is never posted twice', async () => {
            buildPicker({ mode: 'multiple', selected: [{ id: 11, label: 'A' }] });
            await load();

            expect(document.querySelector('[data-search-picker-fallback]')).toBeNull();
            expect(document.querySelector('select')).toBeNull();
            expect(document.querySelector('[data-search-picker-search]').classList.contains('d-none')).toBe(false);
            expect(posted().getAll('event_ids[]')).toEqual(['11']);
        });

        it('keeps the pre-selected row of a single picker in the input and in the form', async () => {
            buildPicker({ selected: [{ id: 7, label: 'Week-end' }] });
            await load();

            expect(search().value).toBe('Week-end');
            expect(posted().getAll('event_id')).toEqual(['7']);
        });

        it('posts an empty value when a single picker has nothing chosen', async () => {
            buildPicker();
            await load();

            expect(posted().getAll('event_id')).toEqual(['']);
        });

        it('survives a malformed data-selected attribute', async () => {
            buildPicker();
            document.getElementById('p').dataset.selected = '{not json';
            await load();

            expect(posted().getAll('event_id')).toEqual(['']);
        });
    });

    describe('typing and the delay', () => {
        beforeEach(async () => {
            buildPicker();
            await load();
            answerWith(EVENTS);
        });

        it('waits for a pause before asking the server, and asks once', async () => {
            search().value = 'f';
            search().dispatchEvent(new Event('input'));
            search().value = 'fê';
            search().dispatchEvent(new Event('input'));
            await vi.advanceTimersByTimeAsync(100);
            expect(window.ScoutMagicApi.getJson).not.toHaveBeenCalled();

            search().value = 'fête';
            search().dispatchEvent(new Event('input'));
            await vi.advanceTimersByTimeAsync(300);

            expect(window.ScoutMagicApi.getJson).toHaveBeenCalledTimes(1);
            expect(window.ScoutMagicApi.getJson).toHaveBeenCalledWith('/recherche?q=f%C3%AAte');
        });

        it('appends the query to a URL that already carries one', async () => {
            buildPicker({ url: '/recherche?scope=2' });
            await load();
            answerWith(EVENTS);

            await type('camp');

            expect(window.ScoutMagicApi.getJson).toHaveBeenCalledWith('/recherche?scope=2&q=camp');
        });

        it('does not ask anything for a blank query', async () => {
            await type('   ');

            expect(window.ScoutMagicApi.getJson).not.toHaveBeenCalled();
            expect(results().classList.contains('d-none')).toBe(true);
        });

        it('draws label, subtitle, badge and warning as text', async () => {
            await type('fête');

            const buttons = resultButtons();
            expect(buttons).toHaveLength(3);
            expect(buttons[0].textContent).toContain("Fête d'unité — Baladins");
            expect(buttons[0].textContent).toContain('calendrier Baladins');
            expect(buttons[0].querySelector('.badge').textContent).toBe('Baladins');
            expect(buttons[2].textContent).toContain('Un covoiturage existe déjà');
        });

        it('never renders a label as markup', async () => {
            answerWith([{ id: 1, label: '<img src=x onerror=alert(1)>' }]);
            await type('x');

            expect(results().querySelector('img')).toBeNull();
            expect(resultButtons()[0].textContent).toContain('<img');
        });

        it('says so when nothing matches instead of showing an empty list', async () => {
            answerWith([]);
            await type('zzz');

            expect(resultButtons()).toHaveLength(0);
            expect(results().textContent).toContain('Aucun évènement ne correspond.');
            expect(results().classList.contains('d-none')).toBe(false);
        });

        it('treats a failed request as no result rather than as an exception', async () => {
            window.ScoutMagicApi.getJson = vi.fn().mockResolvedValue({ ok: false, status: 500, data: null });
            await type('fête');

            expect(results().textContent).toContain('Aucun évènement ne correspond.');
        });

        it('ignores a slow answer to an older query', async () => {
            let releaseFirst;
            window.ScoutMagicApi.getJson = vi.fn()
                .mockImplementationOnce(() => new Promise((resolve) => { releaseFirst = resolve; }))
                .mockResolvedValueOnce({ ok: true, status: 200, data: { success: true, results: [EVENTS[1]] } });

            await type('f');
            await type('fête l');
            releaseFirst({ ok: true, status: 200, data: { success: true, results: EVENTS } });
            await vi.advanceTimersByTimeAsync(0);

            expect(resultButtons()).toHaveLength(1);
            expect(resultButtons()[0].textContent).toContain('Louveteaux');
        });

        it('closes the list on Escape', async () => {
            await type('fête');
            search().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

            expect(results().classList.contains('d-none')).toBe(true);
        });
    });

    describe('single choice', () => {
        beforeEach(async () => {
            buildPicker();
            await load();
            answerWith(EVENTS);
        });

        it('posts the chosen id and shows its label in the input', async () => {
            await type('fête');
            resultButtons()[1].click();

            expect(posted().getAll('event_id')).toEqual(['12']);
            expect(search().value).toBe("Fête d'unité — Louveteaux");
            expect(results().classList.contains('d-none')).toBe(true);
        });

        it('un-chooses as soon as the reader types again', async () => {
            await type('fête');
            resultButtons()[1].click();
            await type('autre');

            expect(posted().getAll('event_id')).toEqual(['']);
        });

        it('picks the first suggestion on Enter instead of submitting the form', async () => {
            await type('fête');
            const event = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true });
            search().dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
            expect(posted().getAll('event_id')).toEqual(['11']);
        });
    });

    describe('a choice ends every search still under way', () => {
        beforeEach(async () => {
            buildPicker();
            await load();
            answerWith(EVENTS);
        });

        it('does not reopen the list when a pause was pending at the moment of the click', async () => {
            await type('fête');
            search().value = 'fête l';
            search().dispatchEvent(new Event('input'));
            resultButtons()[1].click();
            await vi.advanceTimersByTimeAsync(300);

            expect(results().classList.contains('d-none')).toBe(true);
            expect(posted().getAll('event_id')).toEqual(['12']);
            expect(window.ScoutMagicApi.getJson).toHaveBeenCalledTimes(1);
        });

        it('ignores an answer still in flight when the reader chooses', async () => {
            let release;
            window.ScoutMagicApi.getJson = vi.fn()
                .mockResolvedValueOnce({ ok: true, status: 200, data: { success: true, results: EVENTS } })
                .mockImplementationOnce(() => new Promise((resolve) => { release = resolve; }));
            await type('fête');
            await type('fête b');
            resultButtons()[0].click();
            release({ ok: true, status: 200, data: { success: true, results: EVENTS } });
            await vi.advanceTimersByTimeAsync(0);

            expect(results().classList.contains('d-none')).toBe(true);
        });

        it('will not let Enter pick from a list that answers an older query', async () => {
            await type('fête');
            search().value = 'fête é';
            search().dispatchEvent(new Event('input'));
            const event = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true });
            search().dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
            expect(posted().getAll('event_id')).toEqual(['']);
        });
        it('will not let Enter pick while the answer to the current query is still in flight', async () => {
            let release;
            window.ScoutMagicApi.getJson = vi.fn()
                .mockResolvedValueOnce({ ok: true, status: 200, data: { success: true, results: EVENTS } })
                .mockImplementationOnce(() => new Promise((resolve) => { release = resolve; }));
            await type('fête');
            // The pause has run out: the request for « fête é » is in flight.
            await type('fête é');
            const event = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true });
            search().dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
            expect(posted().getAll('event_id')).toEqual(['']);

            release({ ok: true, status: 200, data: { success: true, results: [EVENTS[2]] } });
            await vi.advanceTimersByTimeAsync(0);
            search().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', cancelable: true }));
            expect(posted().getAll('event_id')).toEqual(['13']);
        });
    });

    describe('a required single picker', () => {
        it('keeps the requirement on the search box once the list is gone', async () => {
            buildPicker();
            document.getElementById('p').dataset.required = '1';
            await load();
            answerWith(EVENTS);

            expect(search().validationMessage).toBe('Choisissez un élément dans la liste.');
            await type('fête');
            resultButtons()[0].click();
            expect(search().validationMessage).toBe('');
        });
    });

    describe('multiple choice', () => {
        beforeEach(async () => {
            buildPicker({ mode: 'multiple', selected: [{ id: 11, label: "Fête d'unité — Baladins" }] });
            await load();
            answerWith(EVENTS);
        });

        it('draws one removable chip per retained row', () => {
            const chips = document.querySelectorAll('[data-search-picker-chosen] button');
            expect(chips).toHaveLength(1);
            expect(chips[0].getAttribute('aria-label')).toBe("Retirer Fête d'unité — Baladins");
        });

        it('never offers again a row already retained', async () => {
            await type('fête');

            const labels = resultButtons().map((b) => b.textContent);
            expect(labels).toHaveLength(2);
            expect(labels.some((l) => l.includes('Baladins'))).toBe(false);
        });

        it('says nothing matches when every result is already retained', async () => {
            answerWith([EVENTS[0]]);
            await type('baladins');

            expect(resultButtons()).toHaveLength(0);
            expect(results().textContent).toContain('Aucun évènement ne correspond.');
        });

        it('adds a chosen row, clears the search and posts every id', async () => {
            await type('fête');
            resultButtons()[0].click();

            expect(search().value).toBe('');
            expect(posted().getAll('event_ids[]')).toEqual(['11', '12']);
            expect(document.querySelectorAll('[data-search-picker-chosen] button')).toHaveLength(2);
        });

        it('removes a row when its chip is clicked', async () => {
            /** @type {HTMLButtonElement} */ (document.querySelector('[data-search-picker-chosen] button')).click();

            expect(posted().getAll('event_ids[]')).toEqual([]);
            expect(document.querySelectorAll('[data-search-picker-chosen] button')).toHaveLength(0);
        });

        it('announces every change with the retained rows', async () => {
            const listener = vi.fn();
            document.getElementById('p').addEventListener('search-picker:change', listener);

            await type('fête');
            resultButtons()[1].click();

            expect(listener).toHaveBeenCalledTimes(1);
            const ids = listener.mock.calls[0][0].detail.selected.map((row) => row.id);
            expect(ids).toEqual([11, 13]);
            expect(listener.mock.calls[0][0].detail.selected[1].warning).toContain('existe déjà');
        });
    });
});
