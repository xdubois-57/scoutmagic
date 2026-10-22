/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * The signature dialog a module opens to sign a document (issue #926, lot
 * C1; core/View/templates/partials/signature_dialog.html.twig). The pad
 * itself is signature-pad.js, loaded before this file; this one handles
 * what is the dialog's own:
 *
 * - **The preview of the stored signature is fetched when the dialog
 *   opens, and dropped when it closes** (decision Q5). The page never
 *   carries the image: it may sit in a cache, the image route answers
 *   `no-store` to its owner alone. The address is a constant of this file,
 *   never read from the page.
 * - **Nothing stays behind.** Closing the dialog (« Annuler », the cross,
 *   Échap), sending it, and coming back to the page through the browser's
 *   back-forward cache each empty the pad, the hidden field, the chosen
 *   file, the typed name and the « Mémoriser » box — which is never left
 *   ticked for a next document, or a next person holding the phone.
 * - **Only the picture leaves**: the pad's `toDataURL()` PNG, never a
 *   point or a timing (ticket §6c).
 * - A dialog sent with nothing traced and no image chosen says so in
 *   place rather than reaching the server for the same answer.
 *
 * Wired at load and, again, whenever a dialog opens: a page whose panels
 * are re-rendered under a fetch (rental-booking.js) brings a fresh copy of
 * the dialog that nothing has wired yet. Wiring twice does nothing.
 */

(function () {
    'use strict';

    /** The owner's own signature, answered `no-store` (AccountSignatureController::image()). */
    const PREVIEW_URL = '/account/signature/image';

    /**
     * Empties everything the dialog's form could still hold.
     *
     * @param {HTMLFormElement} form
     */
    function wipe(form) {
        form.dispatchEvent(new CustomEvent('signature-pad:reset'));
        form.querySelectorAll('input[name="signature_data"], input[name="signer_name"], input[type="file"]')
            .forEach((input) => {
                /** @type {HTMLInputElement} */ (input).value = '';
            });
        form.querySelectorAll('input[name="remember_signature"]').forEach((box) => {
            /** @type {HTMLInputElement} */ (box).checked = false;
        });
        const missing = /** @type {HTMLElement|null} */ (form.querySelector('[data-signature-missing]'));
        if (missing !== null) {
            missing.hidden = true;
        }
    }

    /**
     * Shows the stored signature in the dialog's preview, from the owner's
     * route — or empties the preview, when `show` is false.
     *
     * @param {HTMLFormElement} form
     * @param {boolean} show
     */
    function preview(form, show) {
        const frame = form.querySelector('[data-signature-preview]');
        if (frame === null) {
            return;
        }
        frame.replaceChildren();
        if (!show) {
            return;
        }
        const image = document.createElement('img');
        image.src = PREVIEW_URL;
        image.alt = 'Votre signature enregistrée';
        image.className = 'img-fluid';
        image.style.maxHeight = '120px';
        frame.appendChild(image);
    }

    /**
     * Whether a dialog asking for a new signature was sent with nothing
     * to sign with. Read after signature-pad.js has filled the hidden
     * field, which it does on the same submit, registered first.
     *
     * @param {HTMLFormElement} form
     * @returns {boolean}
     */
    function isEmpty(form) {
        if (form.dataset.signatureMode === 'stored') {
            return false;
        }
        const field = /** @type {HTMLInputElement|null} */ (form.querySelector('input[name="signature_data"]'));
        const file = /** @type {HTMLInputElement|null} */ (form.querySelector('input[type="file"]'));
        const drawn = field !== null && field.value !== '';
        const chosen = file !== null && file.files !== null && file.files.length > 0;

        return !drawn && !chosen;
    }

    /**
     * Wires one dialog: its pad (signature-pad.js, first, so its submit
     * listener fills the field before this one reads it), the wipe on
     * close, and the wipe after send. Idempotent.
     *
     * @param {HTMLFormElement} form
     * @returns {boolean} whether the form sat in a dialog, wired now or before
     */
    function wire(form) {
        const modal = form.closest('.modal');
        if (modal === null) {
            return false;
        }
        if (form.dataset.signatureDialogWired === '1') {
            return true;
        }
        form.dataset.signatureDialogWired = '1';

        const pad = /** @type {any} */ (globalThis).ScoutMagicSignaturePad;
        pad?.wire(form);

        modal.addEventListener('hidden.bs.modal', () => {
            preview(form, false);
            wipe(form);
        });

        form.addEventListener('submit', (event) => {
            if (isEmpty(form)) {
                event.preventDefault();
                const missing = /** @type {HTMLElement|null} */ (form.querySelector('[data-signature-missing]'));
                if (missing !== null) {
                    missing.hidden = false;
                }
                return;
            }
            // The browser has already gathered what it sends by the time
            // this runs out: emptying the form next is safe.
            setTimeout(() => wipe(form), 0);
        });

        return true;
    }

    /**
     * The dialog's form, for an event a dialog raised.
     *
     * @param {Event} event
     * @returns {HTMLFormElement|null}
     */
    function formOf(event) {
        const target = event.target;
        if (!(target instanceof Element)) {
            return null;
        }

        return /** @type {HTMLFormElement|null} */ (target.querySelector('form[data-signature-dialog-form]'));
    }

    /**
     * Opening: wired if it was not yet, then the preview.
     *
     * @param {Event} event Bootstrap's `show.bs.modal`
     */
    function onShow(event) {
        const form = formOf(event);
        if (form === null || !wire(form)) {
            return;
        }
        preview(form, form.dataset.signatureMode === 'stored');
    }

    /**
     * Closes and empties every dialog on the page, for a page restored
     * from the back-forward cache: it comes back as it was left, dialog
     * open and pad traced.
     */
    function restore() {
        const bootstrap = /** @type {any} */ (window).bootstrap;
        document.querySelectorAll('form[data-signature-dialog-form]').forEach((element) => {
            const form = /** @type {HTMLFormElement} */ (element);
            preview(form, false);
            wipe(form);
            const modal = form.closest('.modal');
            if (modal !== null && bootstrap?.Modal) {
                bootstrap.Modal.getInstance(modal)?.hide();
            }
        });
    }

    document.querySelectorAll('form[data-signature-dialog-form]').forEach((element) => {
        wire(/** @type {HTMLFormElement} */ (element));
    });
    // Bootstrap's modal events bubble: one listener serves every dialog,
    // the ones a fetch brings later included.
    document.addEventListener('show.bs.modal', onShow);
    window.addEventListener('pageshow', (event) => {
        if (/** @type {PageTransitionEvent} */ (event).persisted) {
            restore();
        }
    });

    // --- Test seam (no behavioural effect) ---------------------------------
    // A classic script (AGENTS.md § CSS / frontend): everything above is
    // private to this IIFE. ONE namespaced global lets
    // tests/js/signature-dialog.test.js reach it — same precedent as
    // signature-pad.js's ScoutMagicSignaturePadInternals.
    //
    // Test-only: nothing in production reads this.
    globalThis.ScoutMagicSignatureDialogInternals = {
        PREVIEW_URL: PREVIEW_URL,
        wipe: wipe,
        preview: preview,
        isEmpty: isEmpty,
        wire: wire,
        restore: restore,
    };
})();
