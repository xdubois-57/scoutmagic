// Isolated JavaScript unit test — jsdom only, no PHP server. Exercises the
// REAL implementation in public/assets/js/rental-pricing-minimum.js: the
// minimum in people is offered only for a per-person billing unit (#1355).
import { beforeEach, describe, expect, it } from 'vitest';
import {
    applyMinimumChoice,
    selectionIsPersonBased,
    wireMinimumChoices,
} from '../../public/assets/js/rental-pricing-minimum.js';

function render(selected, minimum) {
    document.body.innerHTML = `
        <form id="pricing-form">
            <select id="billing-unit" data-minimum-persons-target="choice" data-minimum-persons-note="note">
                <option value="per_night" data-person-based="0" ${selected === 'per_night' ? 'selected' : ''}>Par nuit</option>
                <option value="per_person_night" data-person-based="1" ${selected === 'per_person_night' ? 'selected' : ''}>Par personne et par nuit</option>
            </select>
            <input type="radio" name="minimum_mode" value="none" ${minimum === 'none' ? 'checked' : ''}>
            <input type="radio" name="minimum_mode" value="amount" ${minimum === 'amount' ? 'checked' : ''}>
            <div id="choice"><input type="radio" name="minimum_mode" value="persons" ${minimum === 'persons' ? 'checked' : ''}></div>
            <p id="note"></p>
        </form>`;

    return /** @type {HTMLSelectElement} */ (document.getElementById('billing-unit'));
}

const checked = () => /** @type {HTMLInputElement} */ (document.querySelector('input[name="minimum_mode"]:checked'))?.value;

describe('rental pricing minimum', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('reads the per-person flag of the option selected', () => {
        expect(selectionIsPersonBased(render('per_person_night', 'none'))).toBe(true);
        expect(selectionIsPersonBased(render('per_night', 'none'))).toBe(false);
    });

    it('offers the minimum in people for a per-person unit only', () => {
        const select = render('per_person_night', 'persons');
        wireMinimumChoices(document);

        expect(document.getElementById('choice').hidden).toBe(false);
        expect(document.getElementById('note').hidden).toBe(true);

        select.value = 'per_night';
        select.dispatchEvent(new Event('change'));

        expect(document.getElementById('choice').hidden).toBe(true);
        expect(document.getElementById('note').hidden).toBe(false);
    });

    it('falls back to no minimum when the hidden choice was the one selected', () => {
        const select = render('per_night', 'persons');
        applyMinimumChoice(select, document.getElementById('choice'), document.getElementById('note'));

        expect(checked()).toBe('none');
    });

    it('leaves a minimum amount alone when the unit changes', () => {
        const select = render('per_night', 'amount');
        applyMinimumChoice(select, document.getElementById('choice'), document.getElementById('note'));

        expect(checked()).toBe('amount');
    });
});
