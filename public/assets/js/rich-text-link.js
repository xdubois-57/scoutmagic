/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Inserting a link in a rich-text field — window.ScoutMagicRichText.
//
// Five toolbars implemented this, identically and separately: editable.js,
// rich-text-field.js, rich-text-form-field.js, mass-mail-list.js and
// news-form-builder.js each carried
//
//     if (cmd === 'createLink') {
//         var url = prompt('URL du lien :');
//         if (url) document.execCommand(cmd, false, url);
//     }
//
// Five copies meant five places to fix three real problems, so none of
// them ever was:
//
//  1. `prompt()` is banned (design.md §7.5) and is the worst of the three
//     native boxes on a phone. Replacing it is not a straight swap: a modal
//     takes focus, and a contenteditable that loses focus loses its
//     selection — `execCommand('createLink')` would then have nothing to
//     wrap. So the range is captured before the dialog opens and restored
//     after it closes, which is the only part of this that is subtle.
//  2. Typing `lesscouts.be` produced `<a href="lesscouts.be">`, which the
//     browser resolves against the current page — a link to a 404. A bare
//     host now gets https://, and an email address gets mailto:.
//  3. `javascript:` was accepted verbatim. Stored rich text is sanitised
//     server-side (AGENTS.md § Security checklist), so this was not a hole,
//     but silently keeping a link that the sanitiser will strip is a
//     confusing way to say no. It is refused here, with a reason.
//
// Loaded by base.html.twig on every page, alongside the other toolboxes,
// because editable.js is too. IIFE exposing a global, same reasoning as
// api.js.
(function () {
    // Everything a link in a scout unit's page legitimately points at.
    var ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Turns what the visitor typed into an href, or null if it is not one
     * we are willing to insert.
     *
     * @param {string} raw
     * @returns {string|null} null means "refused" — the caller says why
     */
    function normalizeUrl(raw) {
        var url = String(raw == null ? '' : raw).trim();
        if (url === '') {
            return null;
        }

        var scheme = url.match(/^([a-z][a-z0-9+.-]*):/i);
        if (scheme) {
            return ALLOWED_SCHEMES.indexOf(scheme[1].toLowerCase()) === -1 ? null : url;
        }
        // Site-relative and same-page links are already hrefs.
        if (url.charAt(0) === '/' || url.charAt(0) === '#') {
            return url;
        }
        if (/^[^@\s/]+@[^@\s/]+\.[^@\s/]+$/.test(url)) {
            return 'mailto:' + url;
        }
        return 'https://' + url;
    }

    /**
     * @returns {Range|null} the caret or selection as it stands right now
     */
    function captureSelection() {
        var selection = window.getSelection();
        return selection && selection.rangeCount > 0 ? selection.getRangeAt(0).cloneRange() : null;
    }

    /**
     * Puts the caret back where it was before the dialog stole focus.
     *
     * @param {HTMLElement|null} surface the contenteditable being edited
     * @param {Range|null} range what captureSelection() returned
     * @returns {void}
     */
    function restoreSelection(surface, range) {
        if (surface) {
            surface.focus();
        }
        var selection = window.getSelection();
        if (!selection || range === null) {
            return;
        }
        selection.removeAllRanges();
        selection.addRange(range);
    }

    /**
     * Asks for a URL and wraps the current selection in a link.
     *
     * @param {HTMLElement|null} surface the contenteditable to give focus
     *        back to. Passing null still works — the selection is restored
     *        either way — but the caller usually knows its editor.
     * @returns {Promise<boolean>} true when a link was inserted
     */
    function insertLink(surface) {
        var range = captureSelection();

        return window.ScoutMagicConfirm.prompt({
            message: 'URL du lien :',
            title: 'Insérer un lien',
            placeholder: 'https://…',
            confirmLabel: 'Insérer le lien'
        }).then(function (answer) {
            if (answer === null) {
                // Dismissed: put the caret back so the visitor can carry on
                // typing where they left off.
                restoreSelection(surface, range);
                return false;
            }

            var href = normalizeUrl(answer);
            restoreSelection(surface, range);

            if (href === null) {
                if (answer.trim() !== '') {
                    window.ScoutMagicToast.show(
                        'Ce lien n\'a pas pu être inséré : seules les adresses web, email et téléphone sont acceptées.',
                        { variant: 'error' }
                    );
                }
                return false;
            }

            document.execCommand('createLink', false, href);
            return true;
        });
    }

    window.ScoutMagicRichText = { insertLink: insertLink, normalizeUrl: normalizeUrl };
})();
