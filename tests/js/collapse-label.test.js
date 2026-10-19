// Isolated JavaScript unit test — jsdom-simulated DOM only. Exercises
// the REAL implementation in public/assets/js/collapse-label.js
// (imported below, never reimplemented here).
//
// Bootstrap's own JS is not loaded here: what this module listens to are
// the `show.bs.collapse` / `hide.bs.collapse` events Bootstrap dispatches
// on the collapse TARGET, so the tests dispatch those events directly.
// That is the contract, and it is the whole reason the swap moved out of
// CSS — see the module's own docblock.
import { beforeEach, describe, expect, it, vi } from 'vitest';

// The <table> wrapper is not decoration: jsdom's parser discards a
// <tbody> that is not inside one, exactly as a browser would.
const MARKUP = `
    <button type="button" class="collapsed" data-bs-toggle="collapse" data-bs-target="#more"
            aria-expanded="false" data-collapse-label-expanded="Afficher moins">
        <span data-collapse-label>Afficher les 15 précédentes</span>
        <i class="bi bi-chevron-down"></i>
    </button>
    <table><tbody id="more" class="collapse"><tr><td>une ligne</td></tr></tbody></table>`;

async function load(markup = MARKUP) {
    document.body.innerHTML = markup;
    vi.resetModules();
    await import('../../public/assets/js/collapse-label.js');

    return {
        target: /** @type {HTMLElement} */ (document.getElementById('more')),
        label: /** @type {HTMLElement|null} */ (document.querySelector('[data-collapse-label]')),
    };
}

/** @param {HTMLElement} el @param {string} type */
function fire(el, type) {
    el.dispatchEvent(new Event(type, { bubbles: false }));
}

describe('collapse-label.js', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('ships one correct label before anything is clicked', async () => {
        // The failure this module replaces rendered BOTH labels at once
        // whenever the stylesheet was a version behind.
        const { label } = await load();

        expect(label.textContent).toBe('Afficher les 15 précédentes');
        expect(document.body.textContent).not.toContain('Afficher moins');
    });

    it('swaps to the expanded label when the collapse opens', async () => {
        const { target, label } = await load();

        fire(target, 'show.bs.collapse');

        expect(label.textContent).toBe('Afficher moins');
    });

    it('swaps back when the collapse closes', async () => {
        const { target, label } = await load();

        fire(target, 'show.bs.collapse');
        fire(target, 'hide.bs.collapse');

        expect(label.textContent).toBe('Afficher les 15 précédentes');
    });

    it('survives repeated toggling without accumulating text', async () => {
        const { target, label } = await load();

        for (let i = 0; i < 3; i++) {
            fire(target, 'show.bs.collapse');
            fire(target, 'hide.bs.collapse');
        }

        expect(label.textContent).toBe('Afficher les 15 précédentes');
    });

    it('ignores a trigger whose target does not exist', async () => {
        const orphan = `
            <button data-bs-toggle="collapse" data-bs-target="#nope" data-collapse-label-expanded="Afficher moins">
                <span data-collapse-label>Afficher les 15 précédentes</span>
            </button>`;

        await expect(load(orphan)).resolves.toBeDefined();
        expect(document.querySelector('[data-collapse-label]').textContent).toBe('Afficher les 15 précédentes');
    });

    it('ignores a trigger carrying no label element', async () => {
        const noLabel = `
            <button data-bs-toggle="collapse" data-bs-target="#more" data-collapse-label-expanded="Afficher moins"></button>
            <div id="more"></div>`;
        // (a plain <div> here: the point is the missing label, not the table)

        await expect(load(noLabel)).resolves.toBeDefined();
    });

    it('swaps on a trigger rendered after the script loaded', async () => {
        // A rental booking's page re-renders its panels in place after each
        // action (rental-booking.js): the trigger it puts back is a new
        // element, and a binding made at load would never see it.
        await load('<p>vide</p>');
        document.body.innerHTML = MARKUP;
        const target = /** @type {HTMLElement} */ (document.getElementById('more'));
        const label = /** @type {HTMLElement} */ (document.querySelector('[data-collapse-label]'));

        fire(target, 'show.bs.collapse');
        expect(label.textContent).toBe('Afficher moins');

        fire(target, 'hide.bs.collapse');
        expect(label.textContent).toBe('Afficher les 15 précédentes');
    });

    it('folds the billing details back to « Modifier » when they were rendered open', async () => {
        // « Facture » opens the billing details while their name or address
        // is missing, so the markup then carries the EXPANDED label and the
        // collapsed one comes from its own attribute.
        const { target, label } = await load(`
            <button type="button" data-bs-toggle="collapse" data-bs-target="#coordonnees-facturation-body"
                    aria-expanded="true"
                    data-collapse-label-collapsed="Modifier" data-collapse-label-expanded="Replier">
                <span data-collapse-label>Replier</span>
            </button>
            <div class="collapse show" id="coordonnees-facturation-body"></div>`)
            .then(() => ({
                target: /** @type {HTMLElement} */ (document.getElementById('coordonnees-facturation-body')),
                label: /** @type {HTMLElement} */ (document.querySelector('[data-collapse-label]')),
            }));

        expect(label.textContent).toBe('Replier');

        fire(target, 'hide.bs.collapse');
        expect(label.textContent).toBe('Modifier');

        fire(target, 'show.bs.collapse');
        expect(label.textContent).toBe('Replier');
    });

    it('leaves a trigger aimed at another collapse alone', async () => {
        const { label } = await load(MARKUP + '<div id="other" class="collapse"></div>');

        fire(/** @type {HTMLElement} */ (document.getElementById('other')), 'show.bs.collapse');

        expect(label.textContent).toBe('Afficher les 15 précédentes');
    });
});
