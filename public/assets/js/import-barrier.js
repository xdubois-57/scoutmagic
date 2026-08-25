/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The danger zone of the Desk import barrier
// (core/View/templates/admin/import_barrier.html.twig).
//
// This file owns the state of one button and nothing else. The
// confirmation word is checked server-side by
// Core\Http\Controller\ImportController, exactly as Maintenance's own
// destructive actions are (maintenance.js has the same split): re-enabling
// this button from the console, or posting the form without ever loading
// this script, changes nothing about whether the import is accepted.
//
// Keeping the comparison in a named, exported function rather than inline
// is what makes it testable — the button wiring around it is DOM glue, the
// rule about what counts as "typed correctly" is not.
(function () {
    'use strict';

    /**
     * Whether what was typed matches the confirmation word.
     *
     * Surrounding whitespace is forgiven (a phone keyboard adds a trailing
     * space on its own) and so is case, because the field renders in
     * uppercase and someone typing lowercase into it is not making a
     * different choice. Nothing else is: a word that merely contains
     * the confirmation, or a different word altogether, is not a
     * confirmation.
     *
     * @param {string} typed
     * @param {string} word
     * @returns {boolean}
     */
    function matchesConfirmation(typed, word) {
        if (typeof typed !== 'string' || typeof word !== 'string' || word === '') {
            return false;
        }

        return typed.trim().toUpperCase() === word.toUpperCase();
    }

    window.ScoutMagicImportBarrier = { matchesConfirmation: matchesConfirmation };

    document.addEventListener('DOMContentLoaded', function () {
        var input = /** @type {HTMLInputElement|null} */ (document.getElementById('barrier-confirm-keyword'));
        var submit = /** @type {HTMLButtonElement|null} */ (document.getElementById('barrier-submit'));
        if (!input || !submit) {
            return;
        }

        var word = input.dataset.confirmWord || '';

        function refresh() {
            submit.disabled = !matchesConfirmation(input.value, word);
        }

        input.addEventListener('input', refresh);
        refresh();
    });
})();
