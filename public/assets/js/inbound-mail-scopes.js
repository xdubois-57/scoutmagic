/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The mailbox scope form (modules/inbound_mail/views/config/mailbox_scopes.html.twig).
//
// The screen asks two questions in order — what is this box for, and then,
// for a shared box only, what each module may do with it. The second
// question is meaningless for a dedicated box and the « qui peut lire »
// control is meaningless for a module that does not analyse the box at all,
// so this hides what does not apply.
//
// **Nothing here is a control.** Both halves of the form are rendered and
// both are submitted; the server reads the half the chosen purpose selects
// and ignores the other. With the script blocked the page shows everything
// at once and still saves exactly the same thing — hiding is a courtesy to
// the reader, never the rule.

/**
 * @param {HTMLFormElement} form
 * @returns {void}
 */
export function applyVisibility(form) {
    var purpose = /** @type {HTMLInputElement|null} */ (
        form.querySelector('[data-scope-purpose]:checked')
    );
    var chosen = purpose === null ? 'shared' : purpose.value;

    form.querySelectorAll('[data-scope-section]').forEach(function (node) {
        var section = /** @type {HTMLElement} */ (node);
        section.hidden = section.getAttribute('data-scope-section') !== chosen;
    });

    // On a shared box, « qui peut lire » only means something once the
    // module is analysing. Hidden rather than disabled: a disabled radio
    // submits nothing, and the server would then read « Personne » for a
    // module whose answer the operator never touched.
    form.querySelectorAll('[data-scope-analyze]').forEach(function (node) {
        var toggle = /** @type {HTMLInputElement} */ (node);
        var id = toggle.getAttribute('data-scope-analyze');
        var block = /** @type {HTMLElement|null} */ (
            form.querySelector('[data-scope-read="' + id + '"]')
        );
        if (block !== null) {
            block.hidden = !toggle.checked;
        }
    });
}

/**
 * @param {Document|HTMLElement} root
 * @returns {void}
 */
export function init(root) {
    var form = root.querySelector('form[data-mailbox-scopes]');
    if (form === null) {
        return;
    }

    form.addEventListener('change', function (event) {
        var target = event.target;
        if (target instanceof HTMLElement
            && (target.hasAttribute('data-scope-purpose') || target.hasAttribute('data-scope-analyze'))) {
            applyVisibility(/** @type {HTMLFormElement} */ (form));
        }
    });

    applyVisibility(/** @type {HTMLFormElement} */ (form));
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
}
