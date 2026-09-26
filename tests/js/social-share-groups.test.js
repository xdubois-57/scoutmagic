// Isolated JavaScript unit test — jsdom-simulated DOM only. Exercises
// the REAL implementation in public/assets/js/social-share-groups.js
// (imported below, never reimplemented here).
import { beforeEach, describe, expect, it, vi } from 'vitest';

const MARKUP = `
    <div data-share-groups>
        <input type="checkbox" name="destinations[]" value="groups" data-share-groups-toggle>
        <span data-share-groups-summary data-empty-label="Aucun groupe choisi">Aucun groupe choisi</span>
        <input type="checkbox" name="groups[]" value="3" data-share-group-name="Staff Lutins">
        <input type="checkbox" name="groups[]" value="4" data-share-group-name="Staff d'unité">
        <input type="checkbox" name="groups[]" value="5" data-share-group-name="Déjà publié" checked disabled>
    </div>`;

async function load() {
    document.body.innerHTML = MARKUP;
    vi.resetModules();
    await import('../../public/assets/js/social-share-groups.js');

    const boxes = /** @type {NodeListOf<HTMLInputElement>} */ (document.querySelectorAll('input[name="groups[]"]'));
    return {
        toggle: /** @type {HTMLInputElement} */ (document.querySelector('[data-share-groups-toggle]')),
        summary: /** @type {HTMLElement} */ (document.querySelector('[data-share-groups-summary]')),
        lutins: boxes[0],
        unite: boxes[1],
    };
}

/** @param {HTMLInputElement} box @param {boolean} checked */
function choose(box, checked) {
    box.checked = checked;
    box.dispatchEvent(new Event('change', { bubbles: true }));
}

describe('social-share-groups.js', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('names no group while none is chosen — an already published one is not a choice', async () => {
        const { summary, toggle } = await load();

        expect(summary.textContent).toBe('Aucun groupe choisi');
        expect(toggle.checked).toBe(false);
    });

    it('lists the groups chosen, in clear, and ticks « Groupe de discussion »', async () => {
        const { summary, toggle, lutins, unite } = await load();

        choose(lutins, true);
        choose(unite, true);

        expect(summary.textContent).toBe("Staff Lutins, Staff d'unité");
        expect(toggle.checked).toBe(true);
    });

    it('unticks « Groupe de discussion » when the last group is dropped', async () => {
        const { summary, toggle, lutins } = await load();

        choose(lutins, true);
        choose(lutins, false);

        expect(summary.textContent).toBe('Aucun groupe choisi');
        expect(toggle.checked).toBe(false);
    });
});
