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
 * The pads, the wipe after sending and on a back-forward restore, and the
 * « Tracez… » message are signature-pad.js's and signature-dialog.js's;
 * this file only moves the fieldset and the warning.
 */

/** The renter's choice that keeps their signature (`Stay\RenterSigning::ON_SITE`). */
export const RENTER_SIGNS = 'on_site';

/**
 * Sets the renter's fields aside, or back, from the choice checked in this
 * form, and shows the warning when they do not sign.
 *
 * @param {HTMLFormElement} form
 * @returns {void}
 */
export function applyRenterChoice(form) {
    const checked = /** @type {HTMLInputElement|null} */ (form.querySelector('input[name="renter_signing"]:checked'));
    const signs = checked !== null && checked.value === RENTER_SIGNS;

    form.querySelectorAll('fieldset[data-renter-signature]').forEach((element) => {
        /** @type {HTMLFieldSetElement} */ (element).disabled = !signs;
    });
    form.querySelectorAll('[data-renter-unsigned-warning]').forEach((element) => {
        /** @type {HTMLElement} */ (element).hidden = signs || checked === null;
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
