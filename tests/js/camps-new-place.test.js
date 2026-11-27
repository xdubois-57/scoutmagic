// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/camps-new-place.js
// (imported below, never reimplemented here): the stay form's « nouveau
// lieu » block, hidden while an existing place is chosen in « Lieu du
// séjour », and whose point picker is told to redraw when it appears (#1353).
//
// The file is an IIFE that reads the DOM at import time, so each test builds
// its DOM first and then imports the module via vi.resetModules() + await
// import().
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function buildForm(selected) {
    document.body.innerHTML = `
        <select id="place-id" name="place_id">
            <option value="" ${selected === '' ? 'selected' : ''}>Un nouveau lieu</option>
            <option value="7" ${selected === '7' ? 'selected' : ''}>Ferme du Bois</option>
        </select>
        <div id="camps-new-place">
            <fieldset data-point-picker></fieldset>
        </div>
    `;
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/camps-new-place.js');
}

function block() {
    return /** @type {HTMLElement} */ (document.getElementById('camps-new-place'));
}

function choose(value) {
    const select = /** @type {HTMLSelectElement} */ (document.getElementById('place-id'));
    select.value = value;
    select.dispatchEvent(new Event('change'));
}

describe('camps-new-place', () => {
    let refresh;
    let wire;

    beforeEach(() => {
        refresh = vi.fn();
        wire = vi.fn(() => ({ locate: vi.fn(), refresh }));
        window.ScoutMagicPointPicker = { wire };
    });

    afterEach(() => {
        delete window.ScoutMagicPointPicker;
        document.body.innerHTML = '';
    });

    it('shows the block and redraws its map when a new place is chosen at load', async () => {
        buildForm('');
        await load();

        expect(block().hidden).toBe(false);
        expect(wire).toHaveBeenCalledWith(document.querySelector('[data-point-picker]'));
        expect(refresh).toHaveBeenCalledTimes(1);
    });

    it('hides the block for an existing place, and leaves its map alone', async () => {
        buildForm('7');
        await load();

        expect(block().hidden).toBe(true);
        expect(refresh).not.toHaveBeenCalled();
    });

    it('follows the choice, redrawing the map each time the block appears', async () => {
        buildForm('7');
        await load();

        choose('');
        expect(block().hidden).toBe(false);
        expect(refresh).toHaveBeenCalledTimes(1);

        choose('7');
        expect(block().hidden).toBe(true);
        expect(refresh).toHaveBeenCalledTimes(1);
    });

    it('still shows and hides the block without the point picker', async () => {
        delete window.ScoutMagicPointPicker;
        buildForm('');
        await load();
        choose('7');

        expect(block().hidden).toBe(true);
    });

    it('does nothing on a page without the block', async () => {
        document.body.innerHTML = '<select id="place-id"><option value="">x</option></select>';

        await expect(load()).resolves.toBeUndefined();
        expect(wire).not.toHaveBeenCalled();
    });
});
