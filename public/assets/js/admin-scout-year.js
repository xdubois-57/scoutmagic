/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Scout-year admin page (core/View/templates/admin/scout_year.html.twig):
// ticking a workflow step submits its own form. Extracted from the
// template's inline <script> so the Vitest suite can exercise the
// production code directly (tests/js/admin-scout-year.test.js).
//
// The checkbox itself carries no name: the value posted is the hidden
// « done » field, already set server-side to the opposite of the current
// state, so a double-click or a stale page can never post a state that
// disagrees with what was rendered. Without JavaScript the <noscript>
// button does exactly the same thing.
//
// `.submit()`, not `.requestSubmit()`, on purpose: these forms carry no
// constraint to validate and no submitter to name, and `.submit()` is
// the one that cannot be intercepted by the delegated `data-confirm`
// handler — which is right here, because the step forms ask nothing.
// The one form on this page that DOES ask (« Activer cette année pour
// tout le monde ? ») now carries `data-confirm` and is handled entirely
// by confirm.js, which is why no confirmation lives in this file.
(function () {
    /** @type {NodeListOf<HTMLInputElement>} */
    var checkboxes = document.querySelectorAll('.js-step-checkbox');

    // A no-op on every other page of the site.
    if (!checkboxes.length) {
        return;
    }

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            var form = /** @type {HTMLFormElement|null} */ (checkbox.closest('.js-step-form'));
            if (form) {
                form.submit();
            }
        });
    });
})();
