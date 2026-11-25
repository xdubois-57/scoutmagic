/* ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE. */
(function () {
    'use strict';
    const form = document.getElementById('fees-scale-form');
    const rule = document.getElementById('fees-guest-rule');
    if (!(form instanceof HTMLFormElement) || !rule) return;
    const from = /** @type {HTMLInputElement|null} */ (form.querySelector('[name="guest_from"]'));
    const until = /** @type {HTMLInputElement|null} */ (form.querySelector('[name="guest_until"]'));
    const year = /** @type {HTMLInputElement|null} */ (form.querySelector('[name="scale_year"]'));
    const amount = /** @type {HTMLInputElement|null} */ (form.querySelector('[name="amount_guest"]'));
    if (!(from instanceof HTMLInputElement) || !(until instanceof HTMLInputElement)
        || !(year instanceof HTMLInputElement) || !(amount instanceof HTMLInputElement)) return;

    function update() {
        const price = amount.value.trim();
        const validPrice = /^\d+(?:[.,]\d{1,2})?$/.test(price);
        const amountText = validPrice
            ? 'La cotisation Invités de ' + Number(price.replace(',', '.')).toLocaleString('fr-BE', {
                minimumFractionDigits: 2, maximumFractionDigits: 2
            }) + ' €'
            : 'La cotisation Invités (montant à compléter)';
        rule.textContent = amountText + ' s’applique aux nouvelles inscriptions du '
            + (from.value.trim() || '…') + ' au ' + (until.value.trim() || '…')
            + ' inclus, pendant l’année civile ' + year.value.slice(5, 9) + ' (' + year.value + ').';
    }
    form.addEventListener('input', update);
    update();
}());
