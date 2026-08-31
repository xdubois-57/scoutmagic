/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Insertion buttons for an automatic e-mail's variables — Configuration >
// E-mails (core/View/templates/partials/email_variable_buttons.html.twig).
//
// Deliberately small and separate from rich-text-field.js, which is the
// shared editor and is not forked for this page: the body is edited inside
// that modal, so a button on the page behind it has nothing to insert
// into. It copies instead, and says so.
//
// A group with `data-target` names an ordinary <input> (the subject) and
// inserts at the caret; a group without one copies the placeholder to the
// clipboard.
(function () {
    var groups = document.querySelectorAll('.email-variable-buttons');
    if (groups.length === 0) return;

    /**
     * Insert `text` at the caret of `field`, replacing any selection, and
     * leave the caret after what was inserted — so clicking two buttons in
     * a row produces two placeholders rather than one overwriting the
     * other.
     *
     * @param {HTMLInputElement} field
     * @param {string} text
     */
    function insertAtCaret(field, text) {
        var start = field.selectionStart === null ? field.value.length : field.selectionStart;
        var end = field.selectionEnd === null ? start : field.selectionEnd;

        field.value = field.value.slice(0, start) + text + field.value.slice(end);
        field.focus();
        field.setSelectionRange(start + text.length, start + text.length);
    }

    /**
     * @param {string} text
     */
    function copy(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                window.ScoutMagicToast.show('« ' + text +' » copié — collez-le dans le message.');
            }).catch(function () {
                window.ScoutMagicToast.show('Copiez ce texte à la main : ' + text, { variant: 'error' });
            });
            return;
        }

        // No clipboard API (an old browser, or a page not served over
        // HTTPS): say what to type rather than failing silently.
        window.ScoutMagicToast.show('Copiez ce texte à la main : ' + text, { variant: 'error' });
    }

    groups.forEach(function (group) {
        var targetId = /** @type {HTMLElement} */ (group).dataset.target;
        var field = targetId
            ? /** @type {HTMLInputElement | null} */ (document.getElementById(targetId))
            : null;

        group.querySelectorAll('[data-variable]').forEach(function (button) {
            button.addEventListener('click', function () {
                var name = /** @type {HTMLElement} */ (button).dataset.variable;
                var placeholder = '{{ ' + name + ' }}';

                if (field) {
                    insertAtCaret(field, placeholder);
                    return;
                }

                copy(placeholder);
            });
        });
    });
})();
