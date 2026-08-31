// Isolated JavaScript unit test — jsdom-simulated DOM only. Exercises
// the REAL implementation in public/assets/js/notes-clamp.js (imported
// below, never reimplemented here).
//
// jsdom does no layout, so scrollHeight/clientHeight are both 0 on every
// element: each test defines them explicitly, which is also the only
// honest way to state the case under test ("content taller than its box"
// / "content that fits"). That measurement is the whole point of the
// module — the clamp itself is CSS.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const MARKUP = `
    <div class="notes-clamp" id="notes" data-notes-clamp="notes-toggle">
        <p>Des notes de version potentiellement très longues.</p>
    </div>
    <button type="button" id="notes-toggle" hidden aria-expanded="false"
            data-label-collapsed="Voir la description complète"
            data-label-expanded="Réduire la description">
        <span data-notes-clamp-label>Voir la description complète</span>
    </button>`;

/**
 * @param {HTMLElement} el
 * @param {{scrollHeight: number, clientHeight: number}} box
 */
function measure(el, box) {
    Object.defineProperty(el, 'scrollHeight', { value: box.scrollHeight, configurable: true });
    Object.defineProperty(el, 'clientHeight', { value: box.clientHeight, configurable: true });
}

/** @param {{scrollHeight: number, clientHeight: number}} box */
async function load(box) {
    document.body.innerHTML = MARKUP;
    measure(/** @type {HTMLElement} */ (document.getElementById('notes')), box);
    vi.resetModules();
    await import('../../public/assets/js/notes-clamp.js');

    return {
        block: /** @type {HTMLElement} */ (document.getElementById('notes')),
        button: /** @type {HTMLButtonElement} */ (document.getElementById('notes-toggle')),
        label: /** @type {HTMLElement} */ (document.querySelector('[data-notes-clamp-label]')),
    };
}

describe('notes-clamp.js', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('reveals the button when the notes are taller than the clamp', async () => {
        const { button, block } = await load({ scrollHeight: 900, clientHeight: 480 });

        expect(button.hidden).toBe(false);
        expect(block.classList.contains('notes-clamp')).toBe(true);
    });

    it('drops the clamp and keeps the button hidden when everything already fits', async () => {
        // A two-line patch note: a button revealing nothing is worse than
        // no button, and a fade over content that fits is a lie.
        const { button, block } = await load({ scrollHeight: 120, clientHeight: 120 });

        expect(button.hidden).toBe(true);
        expect(block.classList.contains('notes-clamp')).toBe(false);
    });

    it('treats a sub-pixel difference as fitting, not as overflow', async () => {
        const { button } = await load({ scrollHeight: 481, clientHeight: 480 });

        expect(button.hidden).toBe(true);
    });

    it('expands on click, and says so to assistive technology', async () => {
        const { block, button, label } = await load({ scrollHeight: 900, clientHeight: 480 });

        button.click();

        expect(block.classList.contains('is-expanded')).toBe(true);
        expect(button.getAttribute('aria-expanded')).toBe('true');
        expect(label.textContent).toBe('Réduire la description');
    });

    it('collapses again on a second click', async () => {
        const { block, button, label } = await load({ scrollHeight: 900, clientHeight: 480 });

        button.click();
        button.click();

        expect(block.classList.contains('is-expanded')).toBe(false);
        expect(button.getAttribute('aria-expanded')).toBe('false');
        expect(label.textContent).toBe('Voir la description complète');
    });

    it('ignores a block whose named button does not exist', async () => {
        document.body.innerHTML = `<div class="notes-clamp" id="orphan" data-notes-clamp="nope"></div>`;
        measure(/** @type {HTMLElement} */ (document.getElementById('orphan')), { scrollHeight: 900, clientHeight: 480 });
        vi.resetModules();

        await expect(import('../../public/assets/js/notes-clamp.js')).resolves.toBeDefined();
    });
});
