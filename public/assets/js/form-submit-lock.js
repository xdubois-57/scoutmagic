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
    // The attribute name in full, NOT a `dataset` key, and used through
    // get/set/has/removeAttribute on purpose. Sonar's `javascript:S7761`
    // asks for `.dataset` here and is wrong for this one: the same marker
    // is also a CSS attribute selector below
    // (`form[data-submit-lock-engaged]`), which `pageshow` needs to find
    // every locked form in the document. Through `dataset` the marker
    // would be spelled two ways — camelCase for the property, kebab-case
    // for the selector — and this constant could no longer serve both.
    // `submitLockBound` further down IS a dataset key, because nothing
    // ever selects on it.
    var LOCKED = 'data-submit-lock-engaged';
    var DEFAULT_LABEL = 'Envoi en cours…';

    /**
     * What each locked button held before it said « Envoi en cours… »,
     * as NODES. See lock() for why not as markup.
     *
     * @type {WeakMap<Element, ChildNode[]>}
     */
    var idleNodes = new WeakMap();

    /**
     * Every control that can send this form. `(HTMLButtonElement|
     * HTMLInputElement)[]` rather than buttons alone, because the
     * selector's last clause matches `input[type="submit"]` — same shape
     * as offline-nav.js's own submit-control query.
     *
     * @param {HTMLFormElement} form
     * @returns {(HTMLButtonElement|HTMLInputElement)[]}
     */
    function submitButtons(form) {
        return /** @type {(HTMLButtonElement|HTMLInputElement)[]} */ (
            Array.from(
                form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')
            )
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
            // The original CHILD NODES, kept so `pageshow` can put them
            // back — not their markup. Saving `innerHTML` and assigning it
            // again is a « DOM text reinterpreted as HTML » sink, which
            // CodeQL flagged at HIGH on this very file: the round trip
            // re-parses as markup whatever the button happened to contain,
            // and a button renders content a template produced from
            // user-controlled data (AGENTS.md § CodeQL: a value is not safe
            // because it came from your own template). Moving the nodes
            // aside parses nothing at all.
            if (!idleNodes.has(button)) {
                idleNodes.set(button, Array.from(button.childNodes));
            }
            button.disabled = true;

            var spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm me-1';
            spinner.setAttribute('aria-hidden', 'true');

            var text = document.createElement('span');
            // textContent, never markup: the label comes from a data
            // attribute, which is author input.
            text.textContent = label;

            button.replaceChildren(spinner, text);
        });

        // DEFERRED, and this is load-bearing: do not inline it.
        //
        // The browser builds the form's entry list — the actual multipart
        // body — AFTER the `submit` event finishes dispatching, and skips
        // every control that is disabled at that moment. Disabling the
        // file input here, synchronously, therefore drops the chosen file
        // from the very request this lock exists to protect.
        //
        // Measured rather than reasoned: driven through Chromium against
        // a real multipart POST, the body contained no `name="photo"`
        // part at all with the disable inline, and contained the file
        // again with this setTimeout. A jsdom test cannot see it — jsdom
        // does not implement entry-list construction — which is why the
        // test beside this one asserts the input is still enabled while
        // the handler runs.
        setTimeout(function () {
            fileInputs(form).forEach(function (input) {
                input.disabled = true;
            });
        }, 0);
    }

    /**
     * @param {HTMLFormElement} form
     * @returns {void}
     */
    function unlock(form) {
        form.removeAttribute(LOCKED);
        form.removeAttribute('aria-busy');

        submitButtons(form).forEach(function (button) {
            var idle = idleNodes.get(button);
            if (idle !== undefined) {
                button.replaceChildren.apply(button, idle);
                idleNodes.delete(button);
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
        Array.from(document.querySelectorAll('form[' + LOCKED + ']')).forEach(function (element) {
            var form = /** @type {HTMLFormElement} */ (element);
            var buttons = submitButtons(form);

            // Only the instance that LOCKED a form can put its buttons
            // back: the nodes they held live in this copy's own WeakMap.
            // A page carrying a second copy of this script would
            // otherwise have its first listener clear the marker and
            // leave every button reading « Envoi en cours… » for good,
            // because the next listener then finds no form to unlock.
            // So an instance that does not recognise a form leaves it
            // alone, marker included, for the one that does.
            var mine = buttons.length === 0 || buttons.some(function (button) {
                return idleNodes.has(button);
            });
            if (mine) {
                unlock(form);
            }
        });
    });

    window.ScoutMagicFormSubmitLock = { bind: bind, lock: lock, unlock: unlock };

    bind(document);
})();
