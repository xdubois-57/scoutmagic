// Isolated JavaScript unit test — jsdom only, no PHP server. Exercises the
// REAL implementation in public/assets/js/rental-asset-defaults.js: the
// billing-unit suggestion on the asset creation form.
//
// The suggestion is a courtesy, never an authority: the server accepts
// whatever the select posts, and a unit the chief picked by hand must never
// be silently replaced — which is exactly the behaviour worth pinning here.
import { beforeEach, describe, expect, it } from 'vitest';
import { suggestedBillingUnit, wireSuggestion } from '../../public/assets/js/rental-asset-defaults.js';

describe('suggestedBillingUnit', () => {
    it('suggests per person per night for buildings and grounds', () => {
        expect(suggestedBillingUnit('Local')).toBe('per_person_night');
        expect(suggestedBillingUnit('Salle')).toBe('per_person_night');
        expect(suggestedBillingUnit('Terrain de camp')).toBe('per_person_night');
    });

    it('suggests full days for equipment that leaves and comes back', () => {
        expect(suggestedBillingUnit('Tente')).toBe('per_day');
        expect(suggestedBillingUnit('Remorque')).toBe('per_day');
        expect(suggestedBillingUnit('Matériel')).toBe('per_day');
    });

    it('matches on keywords because the type list is configurable', () => {
        // A unit that adds its own labels still gets the right suggestion.
        expect(suggestedBillingUnit('Grande salle polyvalente')).toBe('per_person_night');
        expect(suggestedBillingUnit('Tente patrouille')).toBe('per_day');
    });

    it('matches regardless of accents and case', () => {
        expect(suggestedBillingUnit('MATERIEL')).toBe('per_day');
        expect(suggestedBillingUnit('matériel cuisine')).toBe('per_day');
    });

    it('says nothing for a type it does not recognise', () => {
        expect(suggestedBillingUnit('Autre')).toBe('');
        expect(suggestedBillingUnit('')).toBe('');
    });
});

describe('wireSuggestion', () => {
    /** @type {HTMLSelectElement} */
    let typeSelect;
    /** @type {HTMLSelectElement} */
    let billingSelect;

    beforeEach(() => {
        document.body.innerHTML = '';

        typeSelect = document.createElement('select');
        ['', 'Local', 'Remorque', 'Autre'].forEach((value) => {
            const option = document.createElement('option');
            option.value = value;
            typeSelect.appendChild(option);
        });

        billingSelect = document.createElement('select');
        ['flat_stay', 'per_night', 'per_day', 'per_person_night'].forEach((value) => {
            const option = document.createElement('option');
            option.value = value;
            billingSelect.appendChild(option);
        });
        billingSelect.value = 'per_person_night';

        document.body.append(typeSelect, billingSelect);
        wireSuggestion(typeSelect, billingSelect);
    });

    /**
     * @param {HTMLSelectElement} select
     * @param {string} value
     */
    function choose(select, value) {
        select.value = value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    it('follows the type with a matching suggestion', () => {
        choose(typeSelect, 'Remorque');

        expect(billingSelect.value).toBe('per_day');
    });

    it('leaves the selection alone when the type says nothing', () => {
        choose(typeSelect, 'Autre');

        expect(billingSelect.value).toBe('per_person_night');
    });

    it('never overrides a unit the chief picked by hand', () => {
        choose(billingSelect, 'per_night');
        choose(typeSelect, 'Remorque');

        expect(billingSelect.value).toBe('per_night');
    });

    it('its own programmatic change does not count as a hand-made choice', () => {
        // First suggestion fires a change event (so the explanation note
        // refreshes); a later type change must still be able to suggest.
        choose(typeSelect, 'Remorque');
        choose(typeSelect, 'Local');

        expect(billingSelect.value).toBe('per_person_night');
    });
});
