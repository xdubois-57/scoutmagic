/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Mail sandbox (modules/test_tools/views/mail_sandbox.html.twig): the
// « type this word to confirm » gate on « Tout supprimer ». Extracted
// from the template's inline <script> so the Vitest suite can exercise
// the production code directly (tests/js/mail-sandbox.test.js).
//
// Convenience only — the server compares the word again and refuses the
// request outright when it does not match. The word rides in
// `data-confirmation-word` on the field rather than being interpolated
// into a script body.
(function () {
    var keyword = /** @type {HTMLInputElement|null} */ (
        document.getElementById('sandbox-empty-keyword')
    );
    var submit = /** @type {HTMLButtonElement|null} */ (
        document.getElementById('sandbox-empty-submit')
    );

    // A no-op on every other page of the site.
    if (!keyword || !submit) {
        return;
    }

    var expected = keyword.dataset.confirmationWord || '';

    keyword.addEventListener('input', function () {
        submit.disabled = keyword.value !== expected;
    });
})();
