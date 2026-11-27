/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * « Bailleur de ce bien » offers two explicit choices (issue #1355, D14):
 * the default landlord, or one of this asset's own. The name, address and
 * enterprise number belong to the second only, so they show while it is
 * chosen.
 *
 * Hiding never decides anything: without this script the fields stay
 * visible, and the server empties them whenever « par défaut » is posted,
 * whatever they hold.
 */

/**
 * Whether $form has « Bailleur spécifique à ce bien » chosen.
 *
 * @param {HTMLFormElement} form
 * @returns {boolean}
 */
export function specificLandlordChosen(form) {
    const checked = /** @type {HTMLInputElement|null} */ (form.querySelector('input[name="landlord_mode"]:checked'));

    return checked !== null && checked.value === 'specific';
}

/**
 * Shows the asset's own fields for « spécifique », hides them otherwise.
 *
 * @param {HTMLFormElement} form
 * @returns {void}
 */
export function syncLandlordFields(form) {
    const fields = /** @type {HTMLElement|null} */ (form.querySelector('[data-landlord-specific]'));
    if (fields) {
        fields.hidden = !specificLandlordChosen(form);
    }
}

/**
 * @param {ParentNode} root
 * @returns {void}
 */
export function wireLandlordChoices(root) {
    root.querySelectorAll('form[data-landlord-choice]').forEach(function (element) {
        const form = /** @type {HTMLFormElement} */ (element);
        syncLandlordFields(form);
        form.addEventListener('change', function () {
            syncLandlordFields(form);
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    wireLandlordChoices(document);
});
