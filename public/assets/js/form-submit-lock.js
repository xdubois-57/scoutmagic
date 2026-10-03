/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Envoi en cours… » — the one way this site says a form is working, and
// the one thing that stops a second tap sending the same file twice
// (issue #756).
//
// The case it was written for: a camp photo on a phone. The visitor picks
// a 4 MB image, presses Envoyer, and for several seconds the page looks
// exactly as it did before — same button, same label, nothing moving. So
// they press it again, and the unit gets the photo twice.
//
// Opt-in, per form: `<form data-submit-lock>`. A form without it behaves
// as it always did, which is why adding this file to a page changes
// nothing until a form asks.
//
// On the first submit, and only the first:
//
//  - every submit button in the form is disabled, so the second tap
//    reaches nothing. Disabled, not merely greyed: a `pointer-events`
//    trick still submits from the keyboard.
//  - the button's label becomes « Envoi en cours… » (or whatever
//    `data-submit-lock-label` says), beside a spinner. The words are what
//    carry it: a spinner alone is invisible to a screen reader and
//    indistinguishable from a decoration to anybody who has not noticed
//    it start.
//  - the form gets `aria-busy="true"`, and the file inputs inside it are
//    locked too — changing the file while its own upload is in flight is
//    the ambiguous state the issue asks to close.
//
// There is deliberately no timeout that unlocks it. These forms post and
// navigate: success and server-side refusal BOTH replace the page, which
// is what returns the button to normal. The one way a locked form can
// come back into view is the back button restoring it from the history
// cache, and `pageshow` is where that is undone — without it, going back
// to this page shows a button that can never be pressed again.

(function () {
    var LOCKED = 'data-submit-lock-engaged';
    var DEFAULT_LABEL = 'Envoi en cours…';

    /**
     * @param {HTMLFormElement} form
     * @returns {HTMLButtonElement[]}
     */
    function submitButtons(form) {
        return Array.from(
            form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')
        );
    }

    /**
     * @param {HTMLFormElement} form
     * @returns {HTMLInputElement[]}
     */
    function fileInputs(form) {
        return /** @type {HTMLInputElement[]} */ (
            Array.from(form.querySelectorAll('input[type="file"]'))
        );
    }

    /**
     * @param {HTMLFormElement} form
     * @returns {void}
     */
    function lock(form) {
        form.setAttribute(LOCKED, '1');
        form.setAttribute('aria-busy', 'true');

        var label = form.dataset.submitLockLabel || DEFAULT_LABEL;

        submitButtons(form).forEach(function (button) {
            // The original markup, kept so `pageshow` can put it back
            // rather than guess at it.
            if (!button.hasAttribute('data-submit-lock-idle')) {
                button.setAttribute('data-submit-lock-idle', button.innerHTML);
            }
            button.disabled = true;
            button.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>'
                + '<span></span>';
            // textContent on the span rather than in the string above:
            // the label comes from a data attribute, so it is author
            // input, and building markup out of it is how an innerHTML
            // sink is born.
            var text = button.querySelector('span:last-child');
            if (text !== null) {
                text.textContent = label;
            }
        });

        fileInputs(form).forEach(function (input) {
            input.disabled = true;
        });
    }

    /**
     * @param {HTMLFormElement} form
     * @returns {void}
     */
    function unlock(form) {
        form.removeAttribute(LOCKED);
        form.removeAttribute('aria-busy');

        submitButtons(form).forEach(function (button) {
            var idle = button.getAttribute('data-submit-lock-idle');
            if (idle !== null) {
                button.innerHTML = idle;
                button.removeAttribute('data-submit-lock-idle');
            }
            button.disabled = false;
        });

        fileInputs(form).forEach(function (input) {
            input.disabled = false;
        });
    }

    /**
     * @param {ParentNode} [root]
     * @returns {void}
     */
    function bind(root) {
        var scope = root || document;
        var forms = /** @type {HTMLFormElement[]} */ (
            Array.from(scope.querySelectorAll('form[data-submit-lock]'))
        );

        forms.forEach(function (form) {
            if (form.dataset.submitLockBound === '1') {
                return;
            }
            form.dataset.submitLockBound = '1';

            form.addEventListener('submit', function (event) {
                // Already sending: this is the second tap, and it stops
                // here. Disabling the button covers the ordinary case;
                // this covers Enter in a text field, and a submit() a
                // script somewhere else might call.
                if (form.hasAttribute(LOCKED)) {
                    event.preventDefault();
                    return;
                }

                // Another listener refused the submit — `data-confirm`,
                // or a validation hook. Nothing is being sent, so
                // nothing should look like it is.
                if (event.defaultPrevented) {
                    return;
                }

                lock(form);
            });
        });
    }

    // The back button restoring a page that was locked when it left.
    // `persisted` is the history cache; Safari also fires this on an
    // ordinary load, where there is nothing locked to undo.
    window.addEventListener('pageshow', function () {
        Array.from(document.querySelectorAll('form[' + LOCKED + ']')).forEach(function (form) {
            unlock(/** @type {HTMLFormElement} */ (form));
        });
    });

    window.ScoutMagicFormSubmitLock = { bind: bind, lock: lock, unlock: unlock };

    bind(document);
})();
