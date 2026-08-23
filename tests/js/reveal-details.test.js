// Isolated JavaScript unit test — jsdom-simulated DOM only. Exercises
// the REAL implementation in public/assets/js/reveal-details.js
// (imported below, never reimplemented here).
//
// This is the shared « click a row to see its detail » behaviour that
// three screens had each written by hand — the journal's table, the
// journal's cards, and the scheduled-actions table. It installs ONE
// delegated listener on the document, so each test re-imports it into a
// fresh module registry and removes the listener afterwards.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const JOURNAL_TABLE = `
    <table><tbody>
        <tr class="journal-row" id="row-1" data-reveal="next:.context-row"><td>a</td></tr>
        <tr class="d-none context-row" id="ctx-1"><td>détail a</td></tr>
        <tr class="journal-row" id="row-2" data-reveal="next:.context-row"><td>b</td></tr>
        <tr class="d-none context-row" id="ctx-2"><td>détail b</td></tr>
    </tbody></table>`;

const JOURNAL_CARD = `
    <div class="card journal-card" id="card-1" data-reveal=".context-detail">
        <p>b</p>
        <pre class="d-none context-detail">détail b</pre>
    </div>`;

describe('reveal-details.js', () => {
    const listeners = [];

    beforeEach(async () => {
        vi.resetModules();
        document.body.innerHTML = '';
        const register = document.addEventListener.bind(document);
        vi.spyOn(document, 'addEventListener').mockImplementation((type, handler, options) => {
            listeners.push([type, handler, options]);
            register(type, handler, options);
        });
        await import('../../public/assets/js/reveal-details.js');
    });

    afterEach(() => {
        listeners.splice(0).forEach(([type, handler, options]) =>
            document.removeEventListener(type, handler, options));
        vi.restoreAllMocks();
    });

    const hidden = (id) => document.getElementById(id).classList.contains('d-none');
    const click = (id) =>
        document.getElementById(id).dispatchEvent(new Event('click', { bubbles: true }));

    describe('the sibling form — next:.selector', () => {
        it('reveals the row that follows, and only that one', () => {
            document.body.innerHTML = JOURNAL_TABLE;
            click('row-1');

            expect(hidden('ctx-1')).toBe(false);
            expect(hidden('ctx-2')).toBe(true);
        });

        it('hides it again on a second click', () => {
            document.body.innerHTML = JOURNAL_TABLE;
            click('row-1');
            click('row-1');

            expect(hidden('ctx-1')).toBe(true);
        });

        it('does nothing when the next sibling is not the expected one', () => {
            document.body.innerHTML = `
                <table><tbody>
                    <tr id="lonely" data-reveal="next:.context-row"><td>a</td></tr>
                    <tr class="d-none other-row" id="other"><td>x</td></tr>
                </tbody></table>`;

            expect(() => click('lonely')).not.toThrow();
            expect(hidden('other')).toBe(true);
        });

        it('does nothing when there is no next sibling at all', () => {
            document.body.innerHTML = '<div id="last" data-reveal="next:.context-row"></div>';

            expect(() => click('last')).not.toThrow();
        });
    });

    describe('the descendant form — .selector', () => {
        it('reveals the detail block inside the card', () => {
            document.body.innerHTML = JOURNAL_CARD;
            click('card-1');

            expect(document.querySelector('.context-detail').classList.contains('d-none'))
                .toBe(false);
        });

        it('does nothing when the card carries no such block', () => {
            document.body.innerHTML = '<div id="bare" data-reveal=".context-detail"><p>b</p></div>';

            expect(() => click('bare')).not.toThrow();
        });
    });

    describe('what it must not swallow', () => {
        it('leaves a link inside the row to its own job', () => {
            document.body.innerHTML = `
                <table><tbody>
                    <tr id="row" data-reveal="next:.context-row"><td><a href="/x" id="link">voir</a></td></tr>
                    <tr class="d-none context-row" id="ctx"><td>détail</td></tr>
                </tbody></table>`;
            click('link');

            expect(hidden('ctx')).toBe(true);
        });

        it('leaves a button, a checkbox and a label alone too', () => {
            document.body.innerHTML = `
                <div id="row" data-reveal=".detail">
                    <button type="button" id="btn"></button>
                    <input type="checkbox" id="cb">
                    <label for="cb" id="lbl">x</label>
                    <p class="d-none detail">détail</p>
                </div>`;

            ['btn', 'cb', 'lbl'].forEach((id) => {
                click(id);
                expect(document.querySelector('.detail').classList.contains('d-none')).toBe(true);
            });
        });

        it('ignores a click on a row that asks for nothing', () => {
            document.body.innerHTML = `
                <table><tbody>
                    <tr id="plain"><td>a</td></tr>
                    <tr class="d-none context-row" id="ctx"><td>détail</td></tr>
                </tbody></table>`;

            expect(() => click('plain')).not.toThrow();
            expect(hidden('ctx')).toBe(true);
        });
    });

    describe('delegation', () => {
        it('works on a row added after the file ran — nothing to re-wire', () => {
            document.body.innerHTML = '';
            document.body.insertAdjacentHTML('beforeend', JOURNAL_TABLE);
            click('row-2');

            expect(hidden('ctx-2')).toBe(false);
        });

        it('reveals from a click on a cell inside the row, not just the row itself', () => {
            document.body.innerHTML = JOURNAL_TABLE;
            document.querySelector('#row-1 td')
                .dispatchEvent(new Event('click', { bubbles: true }));

            expect(hidden('ctx-1')).toBe(false);
        });
    });
});
