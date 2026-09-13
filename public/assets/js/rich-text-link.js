/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The shared rich-text toolbox — window.ScoutMagicRichText: inserting a
// link, and wiring a [data-command] toolbar.
//
// **The file is still named for the link.** Renaming it would touch
// `public/sw.js`'s precache list, the base template, the type declarations
// and four comments, for no change in behaviour; the global has always
// been `ScoutMagicRichText` rather than `…Link`, so the module's identity
// was already the broader one.
//
// WHAT IS DELIBERATELY NOT HERE: a sanitiser. A first cut of the issue
// #306 fix carried one — an allowlist mirroring
// `Core\Security\HtmlSanitizer`, so the editor could show what the server
// was going to keep instead of markup it was about to drop. It was the
// wrong answer twice over. It was a second copy of a security-relevant
// list, guessing at the real one. And it meant parsing untrusted markup
// in the visitor's page to do it, which CodeQL flagged as an XSS sink and
// was right to: the safety of the whole thing rested on a hand-rolled
// sanitiser nothing could verify. The server already knows exactly what it
// kept, so it now SAYS so — every save route returns the stored string and
// the editors repaint with that. One list, no drift, nothing to parse.
//
// THE TOOLBAR IS HERE FOR THE REASON THE LINK IS. Three toolbars wired
// `[data-command]` separately — editable.js, rich-text-field.js and
// rich-text-form-field.js — and two of them read `data-value` for
// `formatBlock`. The third, editable.js, called
// `execCommand('formatBlock', false, null)`: in configuration mode, the
// H2, H3 and « Paragraphe » buttons of the shared modal did nothing at
// all, on every page of the site (issue #306).
//
// Worse than the copy that was wrong was the copy that was double.
// editable.js selected `[data-command]` document-wide while
// rich-text-field.js selected `#richTextEditorModal [data-command]` — the
// same buttons — so a page loading both in configuration mode (Configuration
// > RGPD, > E-mails, the banner, registration and Encadrement pages) ran
// every command TWICE on one click. Bold, italic, underline and the two
// list commands are toggles: applied and immediately un-applied, which is
// exactly the « la plupart du temps impossible d'appliquer une mise en
// page » of that issue. A comment in rich-text-field.js asserted this
// could not happen — "only when configuration mode is active, so this is
// never a double-wiring in practice" — and configuration mode is precisely
// when both are active.
//
// One wiring, called by each of them, cannot be double and cannot disagree
// with itself about `data-value`.
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
    var ALLOWED_SCHEMES = new Set(['http', 'https', 'mailto', 'tel']);

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

        var scheme = /^([a-z][a-z0-9+.-]*):/i.exec(url);
        if (scheme) {
            return ALLOWED_SCHEMES.has(scheme[1].toLowerCase()) ? url : null;
        }
        // Site-relative and same-page links are already hrefs.
        if (url.startsWith('/') || url.startsWith('#')) {
            return url;
        }
        // The label class excludes '.', so the domain groups cannot
        // backtrack against each other on a long non-matching input.
        if (/^[^@\s/]+@[^@\s/.]+(?:\.[^@\s/.]+)+$/.test(url)) {
            return 'mailto:' + url;
        }
        return 'https://' + url;
    }

    /**
     * @returns {Range|null} the caret or selection as it stands right now
     */
    function captureSelection() {
        var selection = window.getSelection();
        return selection?.rangeCount > 0 ? selection.getRangeAt(0).cloneRange() : null;
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

    // ————— The toolbar —————

    /**
     * Wires every `[data-command]` button under $root to act on $surface.
     *
     * Idempotent per button: a second call over the same markup — two
     * scripts both driving the shared modal, which is what issue #306 was
     * — leaves the first wiring alone instead of adding a second handler
     * that undoes it.
     *
     * @param {ParentNode} root where the buttons live
     * @param {HTMLElement} surface the contenteditable they act on
     * @param {(() => void)|null} [afterCommand] run after each command —
     *        rich-text-form-field.js syncs its hidden input here
     * @returns {void}
     */
    function wireToolbar(root, surface, afterCommand) {
        root.querySelectorAll('[data-command]').forEach(function (node) {
            var button = /** @type {HTMLElement} */ (node);
            if (button.dataset.richTextWired === 'yes') {
                return;
            }
            button.dataset.richTextWired = 'yes';

            button.addEventListener('click', function () {
                var command = button.dataset.command;
                if (command === 'createLink') {
                    insertLink(surface).then(function () {
                        if (afterCommand) afterCommand();
                    });
                    return;
                }

                if (command === 'formatBlock') {
                    // The argument editable.js never passed at all — it
                    // sent null, and a formatBlock with no block to format
                    // is a button that does nothing. The angle brackets are
                    // the form the other two toolbars already used and the
                    // one every engine accepts; a bare `h2` is not.
                    document.execCommand(command, false, '<' + (button.dataset.value || 'p') + '>');
                } else {
                    document.execCommand(command || '', false, null);
                }

                surface.focus();
                if (afterCommand) afterCommand();
            });
        });
    }

    window.ScoutMagicRichText = {
        insertLink: insertLink,
        normalizeUrl: normalizeUrl,
        wireToolbar: wireToolbar
    };
})();
