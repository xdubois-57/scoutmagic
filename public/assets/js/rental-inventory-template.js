/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * The inventory checklist on the Gabarits page (#708, IT-10): each item's
 * sort — « Quantité » or « Oui / Non » — and its expected count, edited in
 * place in its row and saved without reloading. Adding, retiring and
 * reordering are the shared list editor's (list-editor.js, `in_place`).
 *
 * The expected count only exists for a quantity: the field is hidden for a
 * yes/no item, in the rows and in the add form alike.
 */

/**
 * Whether the expected count applies to a sort.
 *
 * @param {string} kind
 * @returns {boolean}
 */
export function countApplies(kind) {
    return kind === 'quantity';
}

/**
 * What one row's controls say, ready to post.
 *
 * @param {HTMLElement} row The element carrying `data-inventory-item`.
 * @returns {{id: number, kind: string, expected_count: string|null}}
 */
export function rowPayload(row) {
    const kind = /** @type {HTMLSelectElement} */ (row.querySelector('[data-inventory-field="kind"]')).value;
    const count = /** @type {HTMLInputElement|null} */ (row.querySelector('[data-inventory-field="expected_count"]'));

    return {
        id: Number.parseInt(row.dataset.inventoryItem || '0', 10),
        kind: kind,
        expected_count: countApplies(kind) && count ? count.value : null,
    };
}

/**
 * Wires one checklist: the rows (present and inserted later — the change
 * listener is delegated) and the add form.
 *
 * @param {HTMLElement} root The `.inventory-template` wrapper.
 * @returns {void}
 */
export function wireInventoryTemplate(root) {
    const updateUrl = root.dataset.updateUrl || '';

    root.addEventListener('change', function (event) {
        const field = /** @type {HTMLElement} */ (event.target);
        const row = /** @type {HTMLElement|null} */ (field.closest('[data-inventory-item]'));
        if (!row || !field.dataset.inventoryField) {
            return;
        }

        const payload = rowPayload(row);
        const count = /** @type {HTMLInputElement|null} */ (row.querySelector('[data-inventory-field="expected_count"]'));
        if (count) {
            count.hidden = !countApplies(payload.kind);
        }

        window.ScoutMagicApi.postJson(updateUrl, payload).then(function (res) {
            const data = res.data || {};
            if (!data.success) {
                window.ScoutMagicToast.show(data.error || "L'élément n'a pas pu être enregistré.", { variant: 'error' });
            }
        }).catch(function () {
            window.ScoutMagicToast.show("Erreur réseau : l'élément n'a pas été enregistré.", { variant: 'error' });
        });
    });

    const addForm = /** @type {HTMLFormElement|null} */ (root.querySelector('.inventory-add-form'));
    if (!addForm) {
        return;
    }

    const kind = /** @type {HTMLSelectElement} */ (addForm.querySelector('[name="kind"]'));
    const countField = /** @type {HTMLElement|null} */ (addForm.querySelector('[data-inventory-count-field]'));
    const syncAddForm = function () {
        if (countField) {
            countField.hidden = !countApplies(kind.value);
        }
    };
    kind.addEventListener('change', syncAddForm);
    // The list editor resets the form after an item is added; the count
    // must come back with the default sort.
    addForm.addEventListener('list-editor:added', syncAddForm);
    syncAddForm();
}

document.querySelectorAll('.inventory-template').forEach(function (root) {
    wireInventoryTemplate(/** @type {HTMLElement} */ (root));
});
