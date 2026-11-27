// Isolated JavaScript unit test — jsdom only, no PHP server. Exercises the
// REAL implementation in public/assets/js/rental-landlord-choice.js: the
// asset's own landlord fields show only for « Bailleur spécifique » (#1355).
import { beforeEach, describe, expect, it } from 'vitest';
import { specificLandlordChosen, wireLandlordChoices } from '../../public/assets/js/rental-landlord-choice.js';

function render(mode) {
    document.body.innerHTML = `
        <form data-landlord-choice>
            <input type="radio" name="landlord_mode" value="default" ${mode === 'default' ? 'checked' : ''}>
            <input type="radio" name="landlord_mode" value="specific" ${mode === 'specific' ? 'checked' : ''}>
            <div data-landlord-specific><input name="landlord_name" value="ASBL Exemple"></div>
        </form>`;

    return /** @type {HTMLFormElement} */ (document.querySelector('form'));
}

describe('rental landlord choice', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('reads which choice is made', () => {
        expect(specificLandlordChosen(render('specific'))).toBe(true);
        expect(specificLandlordChosen(render('default'))).toBe(false);
    });

    it('shows the fields for a specific landlord only, and follows the choice', () => {
        const form = render('default');
        wireLandlordChoices(document);
        const fields = /** @type {HTMLElement} */ (form.querySelector('[data-landlord-specific]'));

        expect(fields.hidden).toBe(true);

        const specific = /** @type {HTMLInputElement} */ (form.querySelector('input[value="specific"]'));
        specific.checked = true;
        form.dispatchEvent(new Event('change'));

        expect(fields.hidden).toBe(false);
    });

    it('hides without erasing what was typed', () => {
        const form = render('specific');
        wireLandlordChoices(document);
        const fallback = /** @type {HTMLInputElement} */ (form.querySelector('input[value="default"]'));
        fallback.checked = true;
        form.dispatchEvent(new Event('change'));

        expect(/** @type {HTMLInputElement} */ (form.querySelector('input[name="landlord_name"]')).value).toBe('ASBL Exemple');
    });
});
