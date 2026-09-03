// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/camps-stay-picker.js
// (imported below, never reimplemented here): the « Rattacher à » control on
// the camps mail screen, which upgrades a short `<select>` into a search box
// over every stay of the unit.
//
// The file is an IIFE that reads the DOM at import time, so each test builds
// its DOM first and then imports the module via vi.resetModules() + await
// import() (the tests/js/camps-booked-by.test.js pattern).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const STAYS = [
    { id: 7, label: 'Camp de La Fresnaye — 18–20 septembre 2026', detail: 'Petit camp · Confirmé', reason: 'Période annoncée par le message' },
    { id: 9, label: 'Domaine de Mozet — 12–19 juillet 2028', detail: 'Grand camp · À confirmer', reason: '' },
];

function buildPicker(searchable = true, preferred = '7') {
    document.body.innerHTML = `
        <form>
            <div class="stay-picker" data-stay-picker data-preferred="${preferred}">
                <div data-stay-picker-fallback>
                    <select name="camp_id" id="camp-42">
                        <option value="">Choisir un séjour…</option>
                        <option value="9">Domaine de Mozet</option>
                    </select>
                </div>
                ${searchable ? `
                <div class="d-none" data-stay-picker-search>
                    <input type="text" id="camp-search-42" autocomplete="off">
                    <input type="hidden" name="camp_id" value="" disabled data-stay-picker-value>
                    <div class="stay-picker__results list-group d-none" data-stay-picker-results></div>
                </div>` : ''}
            </div>
        </form>
    `;
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/camps-stay-picker.js');
}

function search() {
    return /** @type {HTMLInputElement} */ (document.getElementById('camp-search-42'));
}

function hidden() {
    return /** @type {HTMLInputElement} */ (document.querySelector('[data-stay-picker-value]'));
}

function results() {
    return /** @type {HTMLElement} */ (document.querySelector('[data-stay-picker-results]'));
}

function answerWith(stays) {
    window.ScoutMagicApi.getJson = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        data: { success: true, stays },
    });
}

/** Type, then let the debounce and the promise settle. */
async function type(value) {
    search().value = value;
    search().dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(300);
}

describe('camps-stay-picker', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = '';
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('the upgrade', () => {
        beforeEach(async () => {
            buildPicker();
            await load();
        });

        it('removes the select it replaces', () => {
            // Both controls are named camp_id: leaving the list in the DOM
            // would post two values, and the one the browser sent first is
            // not the one the chief chose.
            expect(document.querySelector('[data-stay-picker-fallback]')).toBeNull();
            expect(document.querySelectorAll('[name="camp_id"]')).toHaveLength(1);
        });

        it('enables and shows the search half', () => {
            expect(hidden().disabled).toBe(false);
            expect(document.querySelector('[data-stay-picker-search]').classList.contains('d-none')).toBe(false);
        });
    });

    it('leaves the select alone when no search half was rendered', async () => {
        // The server built no search service. A search box that cannot
        // search is worse than a list.
        buildPicker(false);
        await load();

        expect(document.querySelector('[data-stay-picker-fallback]')).not.toBeNull();
        expect(document.querySelector('select[name="camp_id"]')).not.toBeNull();
    });

    describe('searching', () => {
        beforeEach(async () => {
            buildPicker();
            await load();
            answerWith(STAYS);
        });

        it('asks the server for what was typed, carrying the preferred ids', async () => {
            await type('fresnaye');

            const url = window.ScoutMagicApi.getJson.mock.calls[0][0];
            expect(url).toContain('/chefs/camps/courrier/sejours');
            expect(url).toContain('q=fresnaye');
            expect(url).toContain('preferred=7');
        });

        it('shows each stay with the reason it is proposed', async () => {
            await type('a');

            const text = results().textContent;
            expect(text).toContain('Camp de La Fresnaye');
            expect(text).toContain('Période annoncée par le message');
            // The second has no reason, so it shows what it is instead.
            expect(text).toContain('Grand camp · À confirmer');
        });

        it('posts the id of the stay that was clicked, and shows its name', async () => {
            await type('a');
            results().querySelector('button').click();

            expect(hidden().value).toBe('7');
            expect(search().value).toBe('Camp de La Fresnaye — 18–20 septembre 2026');
            expect(results().classList.contains('d-none')).toBe(true);
        });

        it('un-chooses as soon as the text is edited again', async () => {
            await type('a');
            results().querySelector('button').click();
            expect(hidden().value).toBe('7');

            await type('autre chose');

            // A stale id posted under a name the chief has since edited is
            // the one failure this control could produce silently.
            expect(hidden().value).toBe('');
        });

        it('says so rather than going blank when nothing matches', async () => {
            answerWith([]);
            await type('zzz');

            expect(results().textContent).toContain('Aucun séjour ne correspond.');
            expect(results().classList.contains('d-none')).toBe(false);
        });

        it('proposes before a single keystroke', async () => {
            search().dispatchEvent(new Event('focus'));
            await vi.advanceTimersByTimeAsync(0);

            expect(window.ScoutMagicApi.getJson).toHaveBeenCalled();
            expect(results().textContent).toContain('Camp de La Fresnaye');
        });

        it('closes on Escape', async () => {
            await type('a');
            expect(results().classList.contains('d-none')).toBe(false);

            search().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

            expect(results().classList.contains('d-none')).toBe(true);
        });

        it('closes when the click lands outside it', async () => {
            await type('a');

            document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));

            expect(results().classList.contains('d-none')).toBe(true);
        });
    });
});
