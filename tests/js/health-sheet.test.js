// Isolated JavaScript unit test — jsdom-simulated DOM only. Exercises the REAL
// public/assets/js/health-sheet.js (imported below, never reimplemented here),
// which shows the boxes that depend on an OUI / NON answer only while that
// answer is chosen (issue #1346). The script is an IIFE that reads the DOM at
// import time, so each test builds its fixture first and then imports it with
// vi.resetModules() + await import().
//
// The fixture mirrors what modules/official_documents/views/health_sheet.html.twig
// renders for the three questions: a select, and the block that depends on it.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * @param {{allergic?: string, diet?: string, treatment?: string}} [saved]
 */
function page(saved = {}) {
    const select = (name, value) => `
        <select name="${name}" id="${name}">
            <option value=""${value === '' ? ' selected' : ''}>Non précisé</option>
            <option value="yes"${value === 'yes' ? ' selected' : ''}>Oui</option>
            <option value="no"${value === 'no' ? ' selected' : ''}>Non</option>
        </select>`;
    document.body.innerHTML = `
        <form id="sheet" method="post">
            ${select('allergic', saved.allergic ?? '')}
            <div id="allergy-block" data-reveal-when="allergic=yes">
                <textarea name="allergies">Arachides</textarea>
            </div>
            ${select('follows_diet', saved.diet ?? '')}
            <div id="diet-block" data-reveal-when="follows_diet=yes">
                <textarea name="diet">Sans porc</textarea>
            </div>
            ${select('takes_treatment', saved.treatment ?? '')}
            <div id="treatment-block" data-reveal-when="takes_treatment=yes">
                <textarea name="treatment">Ventoline</textarea>
                ${select('treatment_autonomy', '')}
            </div>
            <textarea name="participation_details"></textarea>
        </form>`;
}

/** @param {string} name @param {string} value */
function choose(name, value) {
    const select = /** @type {HTMLSelectElement} */ (document.querySelector(`[name="${name}"]`));
    select.value = value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
}

const hidden = (id) => /** @type {HTMLElement} */ (document.getElementById(id)).hidden;

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/health-sheet.js');
}

describe('health-sheet.js', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('hides every dependent block while its question is unanswered', async () => {
        page();
        await load();

        expect(hidden('allergy-block')).toBe(true);
        expect(hidden('diet-block')).toBe(true);
        expect(hidden('treatment-block')).toBe(true);
    });

    it('shows a block only for the answer it names', async () => {
        page();
        await load();

        choose('allergic', 'yes');
        expect(hidden('allergy-block')).toBe(false);
        expect(hidden('diet-block')).toBe(true);

        choose('allergic', 'no');
        expect(hidden('allergy-block')).toBe(true);

        choose('allergic', '');
        expect(hidden('allergy-block')).toBe(true);
    });

    it('opens on what was saved: OUI shows its block at once', async () => {
        page({ allergic: 'yes', treatment: 'no' });
        await load();

        expect(hidden('allergy-block')).toBe(false);
        expect(hidden('treatment-block')).toBe(true);
    });

    it('follows each question independently', async () => {
        page();
        await load();

        choose('follows_diet', 'yes');
        choose('takes_treatment', 'yes');

        expect(hidden('diet-block')).toBe(false);
        expect(hidden('treatment-block')).toBe(false);
        expect(hidden('allergy-block')).toBe(true);
    });

    // Hiding never erases: OUI → NON → OUI must find what was typed, and a
    // hidden field is still in the form, so the server receives it either way.
    it('keeps what was typed while a block is hidden, and still sends it', async () => {
        page({ allergic: 'yes' });
        await load();
        const area = /** @type {HTMLTextAreaElement} */ (document.querySelector('[name="allergies"]'));
        area.value = 'Arachides, pollen';

        choose('allergic', 'no');
        expect(hidden('allergy-block')).toBe(true);
        expect(area.value).toBe('Arachides, pollen');
        const posted = new FormData(/** @type {HTMLFormElement} */ (document.getElementById('sheet')));
        expect(posted.get('allergies')).toBe('Arachides, pollen');

        choose('allergic', 'yes');
        expect(hidden('allergy-block')).toBe(false);
        expect(area.value).toBe('Arachides, pollen');
    });

    it('hides the autonomy with the treatment: it is only asked when a treatment is declared', async () => {
        page({ treatment: 'yes' });
        await load();
        expect(hidden('treatment-block')).toBe(false);

        choose('takes_treatment', 'no');
        expect(hidden('treatment-block')).toBe(true);
        expect(document.querySelector('[name="treatment_autonomy"]')).not.toBeNull();
    });

    it('leaves a field that depends on nothing alone', async () => {
        page();
        await load();

        const area = /** @type {HTMLElement} */ (document.querySelector('[name="participation_details"]'));
        expect(area.hidden).toBe(false);
        choose('allergic', 'no');
        expect(area.hidden).toBe(false);
    });

    // No script, no hiding: nothing in the markup is hidden by itself, and a
    // rule naming a control that does not exist hides nothing either.
    it('hides nothing when the controlling field is not on the page', async () => {
        document.body.innerHTML = `
            <form><div id="orphan" data-reveal-when="ghost=yes"><input name="x"></div></form>`;
        await load();

        expect(hidden('orphan')).toBe(false);
    });

    it('ignores a malformed rule', async () => {
        document.body.innerHTML = `
            <form><select name="a"><option value="yes" selected>Oui</option></select>
            <div id="bad" data-reveal-when="no-equals-sign"></div></form>`;
        await load();

        expect(hidden('bad')).toBe(false);
    });

    it('does nothing on a page without dependent blocks', async () => {
        document.body.innerHTML = '<form><input name="a"></form>';
        await expect(load()).resolves.toBeUndefined();
    });
});
