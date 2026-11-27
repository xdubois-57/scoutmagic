/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * « Nombre de personnes minimum » is offered only while the billing unit
 * selected depends on the number of people (issue #1355, D7), and follows
 * the selector as it changes, before anything is saved.
 *
 * Which units are per person is NOT decided here: each <option> carries
 * `data-person-based`, rendered from Pricing\BillingUnit::isPersonBased(),
 * the method the server checks the saved minimum against. The server still
 * refuses a minimum in people on any other unit; this only stops offering
 * a choice that would be refused.
 *
 * Without this script the server-rendered state is already right for the
 * unit saved, and the server's refusal covers a unit changed by hand.
 */

/**
 * Whether the option selected in $select is a per-person billing unit.
 *
 * @param {HTMLSelectElement} select
 * @returns {boolean}
 */
export function selectionIsPersonBased(select) {
    const option = select.options[select.selectedIndex];

    return option?.dataset.personBased === '1';
}

/**
 * Shows or hides the minimum-in-people choice for the unit selected. When
 * it is hidden while chosen, « Aucun minimum » is chosen instead, so the
 * form never posts a choice nobody can see.
 *
 * @param {HTMLSelectElement} select
 * @param {HTMLElement} choice the wrapper of the minimum-in-people option
 * @param {HTMLElement|null} note the explanation shown while it is hidden
 * @returns {void}
 */
export function applyMinimumChoice(select, choice, note) {
    const personBased = selectionIsPersonBased(select);
    choice.hidden = !personBased;
    // Hidden is not enough: a hidden field still takes part in the form's
    // own validation, and a number below its minimum would block the save
    // with no message on screen. Disabled, it is neither checked nor sent.
    choice.querySelectorAll('input').forEach(function (input) {
        /** @type {HTMLInputElement} */ (input).disabled = !personBased;
    });
    if (note) {
        note.hidden = personBased;
    }

    if (personBased) {
        return;
    }

    const radio = /** @type {HTMLInputElement|null} */ (choice.querySelector('input[type="radio"]'));
    if (radio?.checked) {
        radio.checked = false;
        const form = select.form;
        const none = form
            ? /** @type {HTMLInputElement|null} */ (form.querySelector('input[name="minimum_mode"][value="none"]'))
            : null;
        if (none) {
            none.checked = true;
        }
    }
}

/**
 * Wires every billing-unit selector that names a minimum-in-people choice.
 *
 * @param {ParentNode} root
 * @returns {void}
 */
export function wireMinimumChoices(root) {
    root.querySelectorAll('select[data-minimum-persons-target]').forEach(function (element) {
        const select = /** @type {HTMLSelectElement} */ (element);
        const choice = document.getElementById(select.dataset.minimumPersonsTarget ?? '');
        if (!choice) {
            return;
        }
        const note = document.getElementById(select.dataset.minimumPersonsNote ?? '');

        applyMinimumChoice(select, choice, note);
        select.addEventListener('change', function () {
            applyMinimumChoice(select, choice, note);
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    wireMinimumChoices(document);
});
