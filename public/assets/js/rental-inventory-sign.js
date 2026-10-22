/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * « Valider, signer et envoyer » (#989, lot E;
 * modules/rental/views/management/booking_inventory_sign.html.twig): the
 * renter's choice decides whether their signature is asked for.
 *
 * « Le locataire signe ici » keeps the renter's fields; « Le locataire est
 * absent » and « Le locataire refuse de signer » set them aside — by
 * DISABLING their fieldset, the one way signature-dialog.js recognises
 * (issue #989, lot D): the typed name, required, then leaves the browser's
 * validation, and nothing of the renter is sent — and show the warning
 * that the inventory proves less without the renter's signature.
 *
 * « Signé sur papier » (#989, lot F, decision Q3) sets BOTH signatures
 * aside — the renter's and the manager's fieldsets disabled, and hidden —
 * and enables the paper fieldset, whose photo of the signed sheet is
 * required: both parties signed the printed sheet, nobody signs here.
 *
 * The pads, the wipe after sending and on a back-forward restore, and the
 * « Tracez… » message are signature-pad.js's and signature-dialog.js's;
 * this file only moves the fieldset and the warning.
 */

/** The renter's choice that keeps their signature (`Stay\RenterSigning::ON_SITE`). */
export const RENTER_SIGNS = 'on_site';

/** Both parties signed the printed sheet (`Stay\RenterSigning::PAPER`). */
export const SIGNED_ON_PAPER = 'paper';

/**
 * Disables a fieldset, or enables it — and hides it too when asked.
 *
 * @param {HTMLFormElement} form
 * @param {string} selector
 * @param {boolean} disabled
 * @param {boolean} hidden
 * @returns {void}
 */
function setAside(form, selector, disabled, hidden) {
    form.querySelectorAll(selector).forEach((element) => {
        const fieldset = /** @type {HTMLFieldSetElement} */ (element);
        fieldset.disabled = disabled;
        fieldset.hidden = hidden;
    });
}

/**
 * Sets the renter's fields aside, or back, from the choice checked in this
 * form, and shows the warning when they do not sign; on paper, sets the
 * manager's aside too and asks for the photo of the sheet instead.
 *
 * @param {HTMLFormElement} form
 * @returns {void}
 */
export function applyRenterChoice(form) {
    const checked = /** @type {HTMLInputElement|null} */ (form.querySelector('input[name="renter_signing"]:checked'));
    const signs = checked !== null && checked.value === RENTER_SIGNS;
    const onPaper = checked !== null && checked.value === SIGNED_ON_PAPER;

    setAside(form, 'fieldset[data-renter-signature]', !signs, onPaper);
    setAside(form, 'fieldset[data-manager-signature]', onPaper, onPaper);
    setAside(form, 'fieldset[data-paper-sheet]', !onPaper, !onPaper);
    form.querySelectorAll('[data-manager-paper-note]').forEach((element) => {
        /** @type {HTMLElement} */ (element).hidden = !onPaper;
    });
    form.querySelectorAll('[data-renter-unsigned-warning]').forEach((element) => {
        /** @type {HTMLElement} */ (element).hidden = signs || onPaper || checked === null;
    });
}

/**
 * @param {Document} doc
 * @returns {void}
 */
export function wire(doc) {
    doc.querySelectorAll('form[data-inventory-signing-form]').forEach((form) => {
        applyRenterChoice(/** @type {HTMLFormElement} */ (form));
    });

    // Once per document: a second wiring would listen twice.
    if (/** @type {any} */ (doc).scoutMagicInventorySigningWired === true) {
        return;
    }
    /** @type {any} */ (doc).scoutMagicInventorySigningWired = true;

    doc.addEventListener('change', (event) => {
        const target = /** @type {HTMLElement} */ (event.target);
        if (!target.matches?.('input[name="renter_signing"]')) {
            return;
        }
        const form = target.closest('form[data-inventory-signing-form]');
        if (form !== null) {
            applyRenterChoice(/** @type {HTMLFormElement} */ (form));
        }
    });

    // A page restored from the back-forward cache keeps the radio the
    // browser restored: the fieldset follows it again.
    globalThis.addEventListener('pageshow', () => {
        doc.querySelectorAll('form[data-inventory-signing-form]').forEach((form) => {
            applyRenterChoice(/** @type {HTMLFormElement} */ (form));
        });
    });
}

wire(document);
