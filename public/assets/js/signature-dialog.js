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
 *
 * **A form of the page signs too** (issue #989, lot D): a
 * `form[data-signature-inline-form]`, not in a dialog, carrying one
 * `[data-signature-field]` container per signer
 * (partials/signature_fields.html.twig) — the renter as a witness and the
 * manager, on one inventory. It gets the same care, container by
 * container: emptied once it is sent and on a page restored from the
 * back-forward cache, each signer told in place when they left their
 * frame empty, the stored signature's preview fetched into its own
 * container when the page shows it. There is no closing to hook: leaving
 * the page is the « Annuler » of such a form, and the back-forward cache
 * is what could bring it back. A container inside a disabled fieldset
 * is a signer the page has set aside (a renter who is absent) and is not
 * asked for anything — and only a disabled fieldset sets one aside: it
 * also takes the signer's fields out of the browser's own validation and
 * out of what is sent, where `[hidden]` alone would leave the required
 * name blocking the form.
 */

(function () {
    'use strict';

    /** The owner's own signature, answered `no-store` (AccountSignatureController::image()). */
    const PREVIEW_URL = '/account/signature/image';

    /** The forms this file cares for: a dialog's, or one of the page signing in place. */
    const FORMS = 'form[data-signature-dialog-form], form[data-signature-inline-form]';

    /**
     * The signers of a form: its `[data-signature-field]` containers, or
     * the form itself when it has none (markup written before them).
     *
     * @param {HTMLFormElement} form
     * @returns {HTMLElement[]}
     */
    function signersOf(form) {
        const containers = /** @type {HTMLElement[]} */ (Array.from(form.querySelectorAll('[data-signature-field]')));

        return containers.length > 0 ? containers : [form];
    }

    /**
     * The mode of one signer: its container's, else the form's.
     *
     * @param {HTMLElement} signer
     * @returns {string}
     */
    function modeOf(signer) {
        return signer.dataset.signatureMode
            ?? /** @type {HTMLElement|null} */ (signer.closest('form'))?.dataset.signatureMode
            ?? '';
    }

    /**
     * Empties everything the form could still hold, signer by signer —
     * and nothing of the form outside its signers' containers.
     *
     * @param {HTMLFormElement} form
     */
    function wipe(form) {
        form.dispatchEvent(new CustomEvent('signature-pad:reset'));
        signersOf(form).forEach((signer) => {
            signer.querySelectorAll('input[name$="signature_data"], input[name$="signer_name"], input[type="file"]')
                .forEach((input) => {
                    /** @type {HTMLInputElement} */ (input).value = '';
                });
            signer.querySelectorAll('input[name$="remember_signature"]').forEach((box) => {
                /** @type {HTMLInputElement} */ (box).checked = false;
            });
            const missing = /** @type {HTMLElement|null} */ (signer.querySelector('[data-signature-missing]'));
            if (missing !== null) {
                missing.hidden = true;
            }
        });
    }

    /**
     * Shows the stored signature in the preview of one signer (or of the
     * whole form), from the owner's route — or empties the preview, when
     * `show` is false.
     *
     * @param {HTMLElement} signer
     * @param {boolean} show
     */
    function preview(signer, show) {
        const frame = signer.querySelector('[data-signature-preview]');
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
     * The preview of every signer of the form signing with the stored
     * signature — shown, or dropped when `show` is false.
     *
     * @param {HTMLFormElement} form
     * @param {boolean} show
     */
    function previews(form, show) {
        signersOf(form).forEach((signer) => preview(signer, show && modeOf(signer) === 'stored'));
    }

    /**
     * Whether a signer was set aside by the page — inside a disabled
     * fieldset, the one way to do it (see the header) — and so is not
     * asked for a signature.
     *
     * @param {HTMLElement} signer
     * @returns {boolean}
     */
    function setAside(signer) {
        return signer.closest('fieldset[disabled]') !== null;
    }

    /**
     * The signers of the form asked for a new signature who were sent
     * with nothing to sign with. Read after signature-pad.js has filled
     * the hidden fields, which it does on the same submit, registered
     * first.
     *
     * @param {HTMLFormElement} form
     * @returns {HTMLElement[]}
     */
    function emptySigners(form) {
        return signersOf(form).filter((signer) => {
            if (modeOf(signer) === 'stored' || setAside(signer)) {
                return false;
            }
            const field = /** @type {HTMLInputElement|null} */ (signer.querySelector('input[name$="signature_data"]'));
            const file = /** @type {HTMLInputElement|null} */ (signer.querySelector('input[type="file"]'));
            const drawn = field !== null && field.value !== '';
            const chosen = file !== null && file.files !== null && file.files.length > 0;

            return !drawn && !chosen;
        });
    }

    /**
     * Whether a form asking for a new signature was sent with nothing to
     * sign with, for one of its signers at least.
     *
     * @param {HTMLFormElement} form
     * @returns {boolean}
     */
    function isEmpty(form) {
        return emptySigners(form).length > 0;
    }

    /**
     * Wires one dialog, or one form of the page signing in place: its pads
     * (signature-pad.js, first, so its submit listener fills the fields
     * before this one reads them), the wipe on close (a dialog), the
     * preview (a form of the page, now), and the wipe after send.
     * Idempotent.
     *
     * @param {HTMLFormElement} form
     * @returns {boolean} whether the form is one this file cares for, wired now or before
     */
    function wire(form) {
        const modal = form.closest('.modal');
        const inline = modal === null && form.dataset.signatureInlineForm !== undefined;
        if (modal === null && !inline) {
            return false;
        }
        if (form.dataset.signatureDialogWired === '1') {
            return true;
        }
        form.dataset.signatureDialogWired = '1';

        const pad = /** @type {any} */ (globalThis).ScoutMagicSignaturePad;
        pad?.wire(form);

        if (modal !== null) {
            modal.addEventListener('hidden.bs.modal', () => {
                previews(form, false);
                wipe(form);
            });
        } else {
            previews(form, true);
        }

        form.addEventListener('submit', (event) => {
            const empty = emptySigners(form);
            signersOf(form).forEach((signer) => {
                const missing = /** @type {HTMLElement|null} */ (signer.querySelector('[data-signature-missing]'));
                if (missing !== null) {
                    missing.hidden = !empty.includes(signer);
                }
            });
            if (empty.length > 0) {
                event.preventDefault();
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
        previews(form, true);
    }

    /**
     * Closes and empties every dialog on the page, and empties every form
     * of the page signing in place, for a page restored from the
     * back-forward cache: it comes back as it was left, dialog open and
     * pad traced. A form of the page fetches its preview again, the
     * page being shown again.
     */
    function restore() {
        const bootstrap = /** @type {any} */ (window).bootstrap;
        document.querySelectorAll(FORMS).forEach((element) => {
            const form = /** @type {HTMLFormElement} */ (element);
            previews(form, false);
            wipe(form);
            const modal = form.closest('.modal');
            if (modal !== null && bootstrap?.Modal) {
                bootstrap.Modal.getInstance(modal)?.hide();
            }
            if (modal === null && form.dataset.signatureDialogWired === '1') {
                previews(form, true);
            }
        });
    }

    document.querySelectorAll(FORMS).forEach((element) => {
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
        emptySigners: emptySigners,
        wire: wire,
        restore: restore,
    };
})();
