// Isolated JavaScript unit test — jsdom-simulated DOM only, no PHP server, no
// network. Exercises the REAL implementation in
// public/assets/js/rental-pricing.js (imported below, never reimplemented
// here): the note under the billing-unit selector on
// "Espace chefs d'U > Locations", which module spec §6.8 requires to describe
// the CONSEQUENCE of the choice and to update as the choice changes.
//
// The explanation texts deliberately live on the <option> elements, rendered
// server-side from Modules\Rental\Pricing\BillingUnit — so these tests supply
// their own placeholder texts rather than asserting the real French
// sentences. Asserting the wording here would pin it in two places at once,
// which is exactly the drift the design avoids; the wording itself is
// covered by RentalPricingEngineTest on the PHP side.
import { beforeEach, describe, expect, it } from 'vitest';
import { explanationForSelection, wireExplanation } from '../../public/assets/js/rental-pricing.js';

/**
 * @param {Array<{value: string, explanation?: string|null}>} options
 */
function buildSelector(options, { targetId = 'note' } = {}) {
    document.body.innerHTML = '';

    const select = document.createElement('select');
    select.setAttribute('data-explanation-target', targetId);

    options.forEach((spec) => {
        const option = document.createElement('option');
        option.value = spec.value;
        option.textContent = spec.value;
        if (spec.explanation !== undefined && spec.explanation !== null) {
            option.setAttribute('data-explanation', spec.explanation);
        }
        select.appendChild(option);
    });

    const note = document.createElement('div');
    note.id = targetId;
    note.textContent = 'texte initial rendu par le serveur';

    document.body.appendChild(select);
    document.body.appendChild(note);

    return { select, note };
}

describe('explanationForSelection', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('returns the selected option’s explanation', () => {
        const { select } = buildSelector([
            { value: 'per_night', explanation: 'nuits' },
            { value: 'per_day', explanation: 'jours' },
        ]);

        expect(explanationForSelection(select)).toBe('nuits');

        select.selectedIndex = 1;
        expect(explanationForSelection(select)).toBe('jours');
    });

    it('returns null when the selected option carries no explanation', () => {
        const { select } = buildSelector([{ value: 'per_night' }]);

        expect(explanationForSelection(select)).toBeNull();
    });

    it('returns null for an empty explanation rather than an empty string', () => {
        // An empty note reads as "this choice has no consequence", which is
        // the one thing §6.8 exists to prevent.
        const { select } = buildSelector([{ value: 'per_night', explanation: '' }]);

        expect(explanationForSelection(select)).toBeNull();
    });

    it('returns null when there is no selection at all', () => {
        const { select } = buildSelector([{ value: 'per_night', explanation: 'nuits' }]);
        select.selectedIndex = -1;

        expect(explanationForSelection(select)).toBeNull();
    });

    it('returns null for a missing selector rather than throwing', () => {
        expect(explanationForSelection(null)).toBeNull();
        expect(explanationForSelection(/** @type {any} */ ({}))).toBeNull();
    });
});

describe('wireExplanation', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('updates the note when the selection changes', () => {
        const { select, note } = buildSelector([
            { value: 'per_night', explanation: 'le jour de départ reste disponible' },
            { value: 'per_day', explanation: 'le jour de retour est occupé' },
        ]);

        wireExplanation(select);
        select.selectedIndex = 1;
        select.dispatchEvent(new Event('change'));

        expect(note.textContent).toBe('le jour de retour est occupé');
    });

    it('leaves the server-rendered note alone when the new option has none', () => {
        const { select, note } = buildSelector([
            { value: 'per_night', explanation: 'nuits' },
            { value: 'per_day' },
        ]);

        wireExplanation(select);
        select.selectedIndex = 1;
        select.dispatchEvent(new Event('change'));

        expect(note.textContent).toBe('texte initial rendu par le serveur');
    });

    it('does nothing when the target element does not exist', () => {
        const { select } = buildSelector([{ value: 'per_night', explanation: 'nuits' }]);
        select.setAttribute('data-explanation-target', 'absent');

        expect(() => {
            wireExplanation(select);
            select.dispatchEvent(new Event('change'));
        }).not.toThrow();
    });

    it('does nothing when the selector declares no target', () => {
        const { select, note } = buildSelector([{ value: 'per_night', explanation: 'nuits' }]);
        select.removeAttribute('data-explanation-target');

        wireExplanation(select);
        select.dispatchEvent(new Event('change'));

        expect(note.textContent).toBe('texte initial rendu par le serveur');
    });

    it('sets text rather than markup, so an explanation can never inject HTML', () => {
        const { select, note } = buildSelector([
            { value: 'a', explanation: 'sûr' },
            { value: 'b', explanation: '<img src=x onerror=alert(1)>' },
        ]);

        wireExplanation(select);
        select.selectedIndex = 1;
        select.dispatchEvent(new Event('change'));

        expect(note.querySelector('img')).toBeNull();
        expect(note.textContent).toBe('<img src=x onerror=alert(1)>');
    });
});
