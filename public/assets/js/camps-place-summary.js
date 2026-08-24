/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Écrire le résumé maintenant » / « Régénérer » on a camp place
// (modules/camps/views/place.html.twig).
//
// Both post to the same action, and behind that action is a call to the
// LLM connector — several seconds on a good day, and nothing at all on
// screen while it runs. A visitor who sees a button that did not react
// presses it again, which is a second request and a second call to a
// paid API for the same place.
//
// So the button says it is working: disabled, with a spinner, for as
// long as the page it is submitting takes to come back. There is no
// "finished" branch on purpose — the answer is a new page, and re-enabling
// the button would mean re-enabling it a moment before it disappears.
// The one case where the page does NOT go away is the visitor coming
// back through the history, which `pageshow` covers: a browser restoring
// a page from its cache restores the disabled button with it.

(function () {
    var SELECTOR = 'form[data-summary-form]';

    /**
     * @param {HTMLFormElement} form
     * @returns {HTMLButtonElement|null}
     */
    function submitButtonOf(form) {
        return /** @type {HTMLButtonElement|null} */ (form.querySelector('button[type="submit"]'));
    }

    /**
     * @param {HTMLFormElement} form
     * @returns {void}
     */
    function markBusy(form) {
        var button = submitButtonOf(form);
        if (button === null || button.disabled) {
            return;
        }

        // Kept so `release()` can put back exactly what was there — the
        // two buttons of this page do not carry the same words.
        button.dataset.idleLabel = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"'
            + ' aria-hidden="true"></span>Rédaction en cours…';
    }

    /**
     * @param {HTMLFormElement} form
     * @returns {void}
     */
    function release(form) {
        var button = submitButtonOf(form);
        if (button === null || button.dataset.idleLabel === undefined) {
            return;
        }

        button.innerHTML = button.dataset.idleLabel;
        button.disabled = false;
        delete button.dataset.idleLabel;
    }

    /**
     * @param {ParentNode} [root]
     * @returns {void}
     */
    function bind(root) {
        var scope = root || document;
        var forms = scope.querySelectorAll(SELECTOR);

        for (var i = 0; i < forms.length; i++) {
            (function (/** @type {HTMLFormElement} */ form) {
                form.addEventListener('submit', function () {
                    markBusy(form);
                });
            })(/** @type {HTMLFormElement} */ (forms[i]));
        }

        // Back-button: a page restored from the browser's cache comes back
        // exactly as it was left, disabled button included.
        window.addEventListener('pageshow', function (event) {
            if (!(/** @type {PageTransitionEvent} */ (event)).persisted) {
                return;
            }
            for (var j = 0; j < forms.length; j++) {
                release(/** @type {HTMLFormElement} */ (forms[j]));
            }
        });
    }

    window.ScoutMagicCampsPlaceSummary = { bind: bind, markBusy: markBusy, release: release };

    bind(document);
})();
