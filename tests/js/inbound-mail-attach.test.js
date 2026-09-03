// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/inbound-mail-attach.js.
//
// « Rattacher à… » on a message of the unit's mail: pick a module, type,
// choose from what the module's directory answers. What the form posts
// must never differ from what the chief saw — that is the property most
// of these tests are about.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function buildForm() {
    document.body.innerHTML = `
        <form data-inbound-attach>
            <select data-attach-module>
                <option value="">—</option>
                <option value="camps">Camps</option>
                <option value="rental" selected>Locations</option>
            </select>
            <input data-attach-reference value="">
            <div data-attach-results class="list-group d-none"></div>
            <span data-attach-chosen class="d-none"></span>
        </form>
        <button id="outside" type="button">ailleurs</button>
    `;
}

const q = (selector) => document.querySelector(selector);

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/inbound-mail-attach.js');
}

function answer(targets, success = true) {
    window.ScoutMagicApi = {
        getJson: vi.fn(async () => ({ data: { success, targets } })),
    };
    return window.ScoutMagicApi.getJson;
}

async function type(text) {
    const input = q('[data-attach-reference]');
    input.value = text;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(300);
}

describe('inbound-mail-attach', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        buildForm();
    });

    afterEach(() => {
        vi.useRealTimers();
        delete window.ScoutMagicApi;
    });

    it('asks the module directory for what was typed, after a pause', async () => {
        const getJson = answer([
            { reference: 'LOC-2027-0042', label: 'LOC-2027-0042 — Local', detail: 'du 01/07 au 04/07' },
            { reference: 'LOC-2027-0043', label: 'LOC-2027-0043 — Prairie', detail: null },
        ]);
        await load();

        const input = q('[data-attach-reference]');
        input.value = 'loc';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        expect(getJson).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(300);

        expect(getJson).toHaveBeenCalledWith('/courrier/cibles?module=rental&q=loc');
        const buttons = q('[data-attach-results]').querySelectorAll('button');
        expect(buttons).toHaveLength(2);
        expect(buttons[0].textContent).toContain('LOC-2027-0042 — Local');
        expect(buttons[0].textContent).toContain('du 01/07 au 04/07');
        expect(buttons[1].querySelectorAll('div')).toHaveLength(1);
        expect(q('[data-attach-results]').classList.contains('d-none')).toBe(false);
    });

    it('collapses a burst of keystrokes into one request', async () => {
        const getJson = answer([]);
        await load();

        const input = q('[data-attach-reference]');
        for (const value of ['l', 'lo', 'loc']) {
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            await vi.advanceTimersByTimeAsync(100);
        }
        await vi.advanceTimersByTimeAsync(300);

        expect(getJson).toHaveBeenCalledTimes(1);
        expect(getJson).toHaveBeenCalledWith('/courrier/cibles?module=rental&q=loc');
    });

    it('says so when nothing matches', async () => {
        answer([]);
        await load();

        await type('zzz');

        expect(q('[data-attach-results]').textContent).toContain('Rien ne correspond.');
    });

    it('writes the chosen reference into the field the form posts, and shows its label', async () => {
        answer([{ reference: 'LOC-2027-0042', label: 'LOC-2027-0042 — Local', detail: null }]);
        await load();
        await type('loc');

        q('[data-attach-results] button').click();

        expect(q('[data-attach-reference]').value).toBe('LOC-2027-0042');
        expect(q('[data-attach-chosen]').textContent).toBe('LOC-2027-0042 — Local');
        expect(q('[data-attach-chosen]').classList.contains('d-none')).toBe(false);
        expect(q('[data-attach-results]').classList.contains('d-none')).toBe(true);
        expect(q('[data-attach-results]').children).toHaveLength(0);
    });

    it('un-chooses as soon as the chief types again', async () => {
        answer([{ reference: 'LOC-2027-0042', label: 'Local', detail: null }]);
        await load();
        await type('loc');
        q('[data-attach-results] button').click();

        q('[data-attach-reference]').dispatchEvent(new Event('input', { bubbles: true }));

        expect(q('[data-attach-chosen]').classList.contains('d-none')).toBe(true);
        expect(q('[data-attach-chosen]').textContent).toBe('');
    });

    it('changing the module clears the field and the list', async () => {
        answer([{ reference: 'LOC-2027-0042', label: 'Local', detail: null }]);
        await load();
        await type('loc');

        const module = q('[data-attach-module]');
        module.value = 'camps';
        module.dispatchEvent(new Event('change', { bubbles: true }));

        expect(q('[data-attach-reference]').value).toBe('');
        expect(q('[data-attach-results]').classList.contains('d-none')).toBe(true);
        expect(q('[data-attach-chosen]').classList.contains('d-none')).toBe(true);
    });

    it('asks nothing for an empty query or no module', async () => {
        const getJson = answer([]);
        await load();

        await type('   ');
        expect(getJson).not.toHaveBeenCalled();

        q('[data-attach-module]').value = '';
        await type('loc');
        expect(getJson).not.toHaveBeenCalled();
        expect(q('[data-attach-results]').classList.contains('d-none')).toBe(true);
    });

    it('treats a refused answer as no target at all', async () => {
        answer([{ reference: 'x', label: 'x', detail: null }], false);
        await load();

        await type('loc');

        expect(q('[data-attach-results]').textContent).toContain('Rien ne correspond.');
    });

    it('hides the list on Escape and on a click elsewhere', async () => {
        answer([{ reference: 'LOC-2027-0042', label: 'Local', detail: null }]);
        await load();

        await type('loc');
        q('[data-attach-reference]').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(q('[data-attach-results]').classList.contains('d-none')).toBe(true);

        await type('loc');
        expect(q('[data-attach-results]').classList.contains('d-none')).toBe(false);
        q('[data-attach-results]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(q('[data-attach-results]').classList.contains('d-none')).toBe(false);
        document.getElementById('outside').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(q('[data-attach-results]').classList.contains('d-none')).toBe(true);
    });

    it('leaves a form missing one of its parts alone', async () => {
        document.body.innerHTML = '<form data-inbound-attach><input data-attach-reference></form>';
        const getJson = answer([]);
        await load();

        await type('loc');

        expect(getJson).not.toHaveBeenCalled();
    });
});
