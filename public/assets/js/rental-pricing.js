/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * Keeps the billing-unit note in step with the selector on
 * "Espace chefs d'U > Locations" (module spec §6.8: the explanatory text
 * under the selector describes the CONSEQUENCE of the choice and updates as
 * the choice changes).
 *
 * The texts themselves are NOT here. Each <option> carries its own, rendered
 * server-side from Modules\Rental\Pricing\BillingUnit::availabilityExplanation()
 * — the same method the page's initial render and every other surface use.
 * Duplicating the sentences in JavaScript would create a second source of
 * truth that drifts the first time one of them is reworded, and the drift
 * would be invisible: the page would simply explain the calendar wrongly.
 *
 * The page is fully usable with this script absent or not yet loaded — the
 * note is already correct on arrival, since the server rendered it.
 */

/**
 * The explanation text an <select>'s currently selected option carries.
 *
 * Exported for its own sake as much as for the DOM wiring: "read the
 * selected option's data attribute" has three ways to be subtly wrong (no
 * selection, an option with no attribute, an empty attribute) and each of
 * them ends with the note showing something misleading rather than nothing.
 *
 * @param {HTMLSelectElement} select
 * @returns {string|null} The text, or null when there is nothing to show.
 */
export function explanationForSelection(select) {
    if (!select || typeof select.selectedIndex !== 'number' || select.selectedIndex < 0) {
        return null;
    }

    const option = select.options[select.selectedIndex];
    if (!option) {
        return null;
    }

    const explanation = option.getAttribute('data-explanation');

    return explanation !== null && explanation !== '' ? explanation : null;
}

/**
 * Wires one selector to the element named by its `data-explanation-target`.
 *
 * @param {HTMLSelectElement} select
 * @returns {void}
 */
export function wireExplanation(select) {
    const targetId = select.getAttribute('data-explanation-target');
    if (!targetId) {
        return;
    }

    const target = document.getElementById(targetId);
    if (!target) {
        return;
    }

    select.addEventListener('change', function () {
        const explanation = explanationForSelection(select);
        // Leave the server-rendered text alone rather than blanking the note
        // when there is nothing to replace it with: an empty note reads as
        // "this choice has no consequence", which is the one thing §6.8
        // exists to prevent.
        if (explanation !== null) {
            target.textContent = explanation;
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const selects = document.querySelectorAll('select[data-explanation-target]');
    selects.forEach(function (select) {
        wireExplanation(/** @type {HTMLSelectElement} */ (select));
    });
});
