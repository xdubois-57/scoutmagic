/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Shared live password complexity checklist — mirrors Core\Security\
// PasswordPolicy exactly (5 rules, ≥12 chars). Reused by the password-reset
// page and the account page's change-password box.
function initPasswordComplexityChecklist(inputId, listId) {
    var input = document.getElementById(inputId);
    var list = document.getElementById(listId);
    if (!input || !list) return null;

    function check(password) {
        return {
            length: password.length >= 12,
            uppercase: /\p{Lu}/u.test(password),
            lowercase: /\p{Ll}/u.test(password),
            digit: /\d/.test(password),
            symbol: /[^\p{L}\p{N}]/u.test(password)
        };
    }

    function isValid(password) {
        var results = check(password);
        return Object.keys(results).every(function (key) { return results[key]; });
    }

    function render() {
        var results = check(input.value);
        Object.keys(results).forEach(function (rule) {
            var li = list.querySelector('[data-rule="' + rule + '"]');
            if (!li) return;
            var icon = li.querySelector('i');
            var ok = results[rule];
            li.classList.toggle('text-success', ok);
            li.classList.toggle('text-body-secondary', !ok);
            icon.className = ok ? 'bi bi-check-circle-fill me-1' : 'bi bi-circle text-body-secondary me-1';
        });
    }

    input.addEventListener('input', render);
    render();

    return { isValid: function () { return isValid(input.value); } };
}

// A classic (non-module) <script src="password-complexity.js"> already
// attaches this top-level `function` declaration to `window`/`globalThis`
// on its own — this line changes nothing for the browser. It exists so
// tests/js/password-complexity.test.js can `import` this exact file (an ES
// module, where top-level declarations are module-scoped, not global) and
// call the real implementation directly, rather than reimplementing its
// logic in a test-only copy.
globalThis.initPasswordComplexityChecklist = initPasswordComplexityChecklist;
