/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Insertion buttons for an automatic e-mail's variables — Configuration >
// E-mails (core/View/templates/partials/email_variable_buttons.html.twig).
//
// Three targets, one behaviour each:
//
// - an <input> (the subject): insert at the caret;
// - the rich-text editor's contenteditable, where the same buttons are
//   rendered INSIDE the modal (partials/rich_text_editor.html.twig's
//   extra-toolbar slot): insert at the caret there too. Without it, adding
//   three variables to a message meant saving, copying, reopening and
//   pasting — three times;
// - no target at all: copy to the clipboard, and say so.
//
// Deliberately separate from rich-text-field.js, which is the shared
// editor and is not forked for this page: this script only ever writes
// text at a caret, and knows nothing about saving.
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
     * The same thing in a contenteditable. The caret is preserved by the
     * mousedown handler below (which stops the button from taking focus),
     * so the current selection is still the one the writer left in the
     * text; when there is none — the editor was never clicked — the
     * placeholder goes to the end rather than nowhere.
     *
     * A text node, never innerHTML: what is inserted is `{{ nom }}` and
     * must stay exactly that, braces and all.
     *
     * @param {HTMLElement} editor
     * @param {string} text
     */
    function insertIntoEditor(editor, text) {
        var selection = window.getSelection();
        var node = document.createTextNode(text);
        var caret = selection !== null
            && selection.rangeCount > 0
            && editor.contains(selection.getRangeAt(0).commonAncestorContainer)
                ? selection.getRangeAt(0)
                : null;

        if (caret === null) {
            editor.appendChild(node);
            caret = document.createRange();
        } else {
            caret.deleteContents();
            caret.insertNode(node);
        }

        caret.setStartAfter(node);
        caret.collapse(true);

        // Focus first, then put the caret back: giving focus to an element
        // can move the selection to its start, which would send the next
        // placeholder to the beginning of the message.
        editor.focus();
        if (selection !== null) {
            selection.removeAllRanges();
            selection.addRange(caret);
        }
    }

    /**
     * Whether a target is the rich-text editor rather than the subject
     * input. Both the property and the attribute are consulted: a browser
     * answers `isContentEditable` (including for an inherited one), while
     * jsdom — where this file's tests run — implements the attribute
     * only, and a target it got wrong would take the `<input>` path and
     * throw on a `value` that does not exist.
     *
     * @param {HTMLElement} el
     */
    function isEditor(el) {
        if (el.isContentEditable) return true;

        var attribute = el.getAttribute('contenteditable');

        return attribute !== null && attribute !== 'false';
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
        var field = targetId ? document.getElementById(targetId) : null;

        group.querySelectorAll('[data-variable]').forEach(function (button) {
            // Keeps the caret where the writer left it: a button that takes
            // focus first collapses the selection, and the placeholder then
            // lands at the start of the text instead of where they were
            // typing.
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });

            button.addEventListener('click', function () {
                var name = /** @type {HTMLElement} */ (button).dataset.variable;
                var placeholder = '{{ ' + name + ' }}';

                if (field && isEditor(/** @type {HTMLElement} */ (field))) {
                    insertIntoEditor(/** @type {HTMLElement} */ (field), placeholder);
                    return;
                }

                if (field) {
                    insertAtCaret(/** @type {HTMLInputElement} */ (field), placeholder);
                    return;
                }

                copy(placeholder);
            });
        });
    });
})();
