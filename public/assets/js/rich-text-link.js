/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The shared rich-text toolbox — window.ScoutMagicRichText: inserting a
// link, wiring a [data-command] toolbar, and cleaning what a paste brings
// in.
//
// **The file is still named for the link**, which is now the smallest of
// the three. Renaming it would touch `public/sw.js`'s precache list, the
// base template, the type declarations and four comments, for no change in
// behaviour; the global has always been `ScoutMagicRichText` rather than
// `…Link`, so the module's identity was already the broader one.
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

    // ————— What a paste, or a browser, is allowed to leave behind —————

    /**
     * The tags `Core\Security\HtmlSanitizer` keeps, and nothing else.
     *
     * **This list exists twice on purpose, and the duplicate is checked.**
     * The server is the authority — everything stored goes through the PHP
     * sanitiser whatever happens here (SECURITY.md), and this copy adds no
     * security of its own. What it adds is HONESTY: without it the editor
     * shows, and then saves back into the page, markup the server is about
     * to drop, so a heading looks applied until the next page load — the
     * second half of issue #306. `Tests\Core\Security\
     * RichTextAllowlistsAgreeTest` fails when the two lists drift.
     *
     * @type {Record<string, string[]>}
     */
    var ALLOWED = {
        p: [], br: [], strong: [], b: [], em: [], i: [], u: [],
        a: ['href', 'title', 'target', 'rel'],
        ul: [], ol: [], li: [],
        h2: [], h3: [], h4: [],
        blockquote: [],
        img: ['src', 'alt', 'width', 'height']
    };

    /**
     * What a disallowed tag becomes instead of being dropped.
     *
     * Unwrapping is the server's answer, and it is the right one for a
     * `<span class="Apple-style-span">`: keep the words, lose the wrapper.
     * Applied to a paste it is too blunt — `<div>` is how every browser
     * spells a line inside a contenteditable, and `<h1>` is how every word
     * processor spells a title — so the whole of a pasted document arrives
     * as one unbroken paragraph. These four keep the author's intent in a
     * tag the server accepts.
     *
     * @type {Record<string, string>}
     */
    var REMAPPED = { div: 'p', h1: 'h2', h5: 'h4', h6: 'h4' };

    /**
     * Everything that already stands on its own line.
     *
     * A `<div>` only earns a `<p>` when it holds a line of its own: the
     * `<div>` that wraps a whole pasted document holds paragraphs and
     * headings, and `<p><h2>…</h2></p>` is not a tree any parser will give
     * back — the browser breaks the `<p>` open and the shape the author
     * pasted is lost. That one is unwrapped, as the server would.
     */
    var BLOCK_LEVEL = 'address,article,aside,blockquote,div,dl,figure,footer,h1,h2,h3,h4,h5,h6,'
        + 'header,hr,li,main,nav,ol,p,pre,section,table,ul';

    /**
     * The tags `HtmlSanitizer` removes WITH their content, rather than
     * unwrapping. Kept in step with the PHP by the same ratchet test: a
     * `<textarea>`'s text is content to the DOM and nothing at all to the
     * server, so unwrapping one here would show words the save then loses.
     *
     * @type {string[]}
     */
    var STRIPPED_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'textarea', 'select'];

    /**
     * The inline styles worth a tag of their own.
     *
     * A browser asked to embolden a selection may answer `<b>`, or
     * `<span style="font-weight: bold">`, depending on the browser and on
     * `styleWithCSS`. The first survives the sanitiser and the second does
     * not, which is why « ça marche parfois » was a fair description.
     *
     * @param {HTMLElement} element
     * @returns {string|null} the tag to wrap this element's children in
     */
    function tagForInlineStyle(element) {
        var style = element.style;
        var weight = style.fontWeight;
        if (weight === 'bold' || weight === 'bolder' || (/^\d+$/.test(weight) && Number(weight) >= 600)) {
            return 'strong';
        }
        if (style.fontStyle === 'italic' || style.fontStyle === 'oblique') {
            return 'em';
        }
        if (style.textDecorationLine === 'underline' || style.textDecoration.indexOf('underline') === 0) {
            return 'u';
        }
        return null;
    }

    /**
     * The server's `isSafeUrlValue()`, to the letter — including stripping
     * tab/CR/LF first, because a browser ignores them inside a scheme and
     * `java&#9;script:` would otherwise read as "no scheme at all".
     *
     * @param {string} value
     * @returns {boolean}
     */
    function isSafeUrlValue(value) {
        var normalized = String(value).replace(/[\t\r\n]+/g, '').trim().toLowerCase();
        var scheme = /^([a-z][a-z0-9+.-]*):/.exec(normalized);

        return scheme === null || ALLOWED_SCHEMES.has(scheme[1]);
    }

    /**
     * @param {Element} element
     * @param {string} tagName
     * @returns {void}
     */
    function keepAllowedAttributes(element, tagName) {
        var allowed = ALLOWED[tagName] || [];
        Array.prototype.slice.call(element.attributes).forEach(function (attribute) {
            var name = attribute.name.toLowerCase();
            // `on…` first: the server removes every event handler whatever
            // the tag, and none of them is in any allowlist anyway — saying
            // so here keeps the two readable side by side.
            if (name.indexOf('on') === 0 || allowed.indexOf(name) === -1) {
                element.removeAttribute(attribute.name);
                return;
            }
            if ((name === 'href' || name === 'src') && !isSafeUrlValue(attribute.value)) {
                element.removeAttribute(attribute.name);
            }
        });

        // An <img> that lost its src is an empty broken element; the server
        // drops it rather than leave it behind, so neither do we.
        if (tagName === 'img' && !element.hasAttribute('src')) {
            element.parentNode?.removeChild(element);
            return;
        }

        if (tagName === 'a' && element.getAttribute('target') === '_blank') {
            element.setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * @param {Element} element the element to replace by $tagName
     * @param {string} tagName
     * @returns {Element} the replacement, already holding the children
     */
    function rename(element, tagName) {
        var replacement = element.ownerDocument.createElement(tagName);
        while (element.firstChild) {
            replacement.appendChild(element.firstChild);
        }
        element.parentNode?.replaceChild(replacement, element);
        return replacement;
    }

    /**
     * Replaces an element by its own children, in place.
     *
     * @param {Element} element
     * @returns {void}
     */
    function unwrap(element) {
        var parent = element.parentNode;
        while (element.firstChild) {
            parent?.insertBefore(element.firstChild, element);
        }
        parent?.removeChild(element);
    }

    /**
     * Cleans every descendant of $node, deepest first.
     *
     * Bottom-up on purpose: an element is judged once its own contents are
     * already settled, so unwrapping it hands the parent children that need
     * no second pass — and a `<div>` is asked whether it still holds a block
     * after its children have been remapped, not before.
     *
     * @param {Node} node
     * @returns {void}
     */
    function cleanNode(node) {
        Array.prototype.slice.call(node.childNodes).forEach(function (child) {
            if (child.nodeType === Node.TEXT_NODE) {
                return;
            }
            if (child.nodeType !== Node.ELEMENT_NODE) {
                // Comments and processing instructions, as the server does.
                child.parentNode?.removeChild(child);
                return;
            }

            var element = /** @type {HTMLElement} */ (child);
            var tagName = element.tagName.toLowerCase();

            // The same tags the server removes with their content, for the
            // same reason. A paste is untrusted text like any other.
            if (STRIPPED_WITH_CONTENT.indexOf(tagName) !== -1) {
                element.parentNode?.removeChild(element);
                return;
            }

            cleanNode(element);

            var target = REMAPPED[tagName];
            if (target !== undefined) {
                if (target === 'p' && element.querySelector(BLOCK_LEVEL) !== null) {
                    unwrap(element);
                } else {
                    keepAllowedAttributes(rename(element, target), target);
                }
                return;
            }

            if (!Object.prototype.hasOwnProperty.call(ALLOWED, tagName)) {
                var carried = tagForInlineStyle(element);
                if (carried !== null) {
                    rename(element, carried);
                } else {
                    // Keep the words, lose the wrapper.
                    unwrap(element);
                }
                return;
            }

            keepAllowedAttributes(element, tagName);
        });
    }

    /**
     * The HTML as it will come back from the server, computed here.
     *
     * @param {string} html
     * @returns {string}
     */
    function cleanHtml(html) {
        var holder = document.createElement('div');
        // Parsed detached: nothing here is inserted into the page, so an
        // `onerror` on a pasted <img> has nothing to fire against.
        holder.innerHTML = String(html == null ? '' : html);
        cleanNode(holder);
        return holder.innerHTML;
    }

    /**
     * Makes a paste bring in only what the site can store.
     *
     * Without this the editor accepts a whole word processor's markup,
     * shows it, saves it, and the server keeps the words while dropping
     * the formatting — so the text changes shape on the next page load
     * rather than at the moment anybody could react to it.
     *
     * @param {HTMLElement} surface
     * @param {(() => void)|null} [afterPaste]
     * @returns {void}
     */
    function wirePaste(surface, afterPaste) {
        if (surface.dataset.richTextPasteWired === 'yes') {
            return;
        }
        surface.dataset.richTextPasteWired = 'yes';

        surface.addEventListener('paste', function (event) {
            var clipboard = /** @type {ClipboardEvent} */ (event).clipboardData;
            if (!clipboard) {
                return;
            }

            var html = clipboard.getData('text/html');
            event.preventDefault();

            if (html !== '') {
                document.execCommand('insertHTML', false, cleanHtml(html));
            } else {
                document.execCommand('insertText', false, clipboard.getData('text/plain'));
            }

            if (afterPaste) afterPaste();
        });
    }

    window.ScoutMagicRichText = {
        insertLink: insertLink,
        normalizeUrl: normalizeUrl,
        wireToolbar: wireToolbar,
        wirePaste: wirePaste,
        cleanHtml: cleanHtml
    };
})();
