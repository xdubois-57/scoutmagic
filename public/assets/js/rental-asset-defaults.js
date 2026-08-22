/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * Pre-selects a sensible billing unit on the asset CREATION form when the
 * type changes: a hall is usually let per person per night, a trailer per
 * full day. A suggestion only — it never overrides a unit the chief already
 * picked by hand, and with this script absent the form simply keeps its
 * server-rendered default.
 *
 * The mapping matches on keywords rather than exact labels because the type
 * list itself is configurable (`asset_type_suggestions`): a unit that adds
 * "Grande salle" or "Tente patrouille" still gets the right suggestion.
 */

/**
 * The billing unit to suggest for an asset type, or '' when the type says
 * nothing usable — in which case the current selection is left alone.
 *
 * @param {string} assetType
 * @returns {string} A BillingUnit value, or ''.
 */
export function suggestedBillingUnit(assetType) {
    const type = (assetType || '')
        .toLowerCase()
        .normalize('NFD')
        // Strip combining accents left by NFD, so "matériel" matches
        // "materiel" whatever the configured label's spelling.
        .replace(/[\u0300-\u036f]/g, '');

    // Buildings and grounds sleep people: per person per night.
    if (/local|salle|gite|batiment|terrain|prairie/.test(type)) {
        return 'per_person_night';
    }

    // Equipment leaves and comes back: full days, the return day occupied.
    if (/tente|remorque|materiel/.test(type)) {
        return 'per_day';
    }

    return '';
}

/**
 * Wire the suggestion between a type select and a billing-unit select.
 *
 * Exported for the unit test; the DOMContentLoaded block below is the only
 * production caller. A change the USER makes to the billing select turns the
 * suggestion off for good — their choice must never be silently replaced —
 * while the change events this function dispatches itself (so
 * select-explanation.js refreshes the note under the select) do not.
 *
 * @param {HTMLSelectElement} typeSelect
 * @param {HTMLSelectElement} billingSelect
 * @returns {void}
 */
export function wireSuggestion(typeSelect, billingSelect) {
    let chosenByHand = false;
    let dispatching = false;

    billingSelect.addEventListener('change', function () {
        if (!dispatching) {
            chosenByHand = true;
        }
    });

    typeSelect.addEventListener('change', function () {
        if (chosenByHand) {
            return;
        }

        const suggested = suggestedBillingUnit(typeSelect.value);
        if (suggested === '' || billingSelect.value === suggested) {
            return;
        }

        billingSelect.value = suggested;
        // Announce the programmatic change so select-explanation.js updates
        // the consequence note under the select.
        dispatching = true;
        billingSelect.dispatchEvent(new Event('change', { bubbles: true }));
        dispatching = false;
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = /** @type {HTMLSelectElement|null} */ (document.getElementById('new-asset-type'));
    const billingSelect = /** @type {HTMLSelectElement|null} */ (document.getElementById('new-billing-unit'));

    if (typeSelect && billingSelect) {
        wireSuggestion(typeSelect, billingSelect);
    }
});
