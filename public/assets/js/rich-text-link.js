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
// WHAT IS DELIBERATELY NOT HERE: a sanitiser. (What IS here since issue
// #844, the canonical form further down, is not one — see « The canonical
// form » for the difference, which is the whole point.) A first cut of the issue
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

    // ————— The canonical form (issue #844) —————
    //
    // One representation for one look. The toolbar of every generic editor
    // offers the same nine gestures — paragraph, H2, H3, bold, italic,
    // underline, the two lists and a link — so the HTML those gestures can
    // mean is a small, closed grammar:
    //
    //     blocks   p, h2, h3, ul > li, ol > li (a list may nest in an li)
    //     inline   a[href] > strong > em > u > text, and <br>
    //
    // always nested in that order, never empty, never styled. A pasted
    // `<span style="font-weight:700">` and the Bold button must both end up
    // as `<strong>`: before this, the first one reached the server as a
    // span, lost its style there, and came back as plain text — the « mise
    // en forme qui change à l'enregistrement » of the issue — while the
    // fragments the server did keep (Word's `<p class=MsoNormal>`, Google
    // Docs' `<b style="font-weight:normal">` wrapper) were exactly the ones
    // the buttons could no longer toggle off.
    //
    // WHY THIS IS NOT THE SANITISER THE HEADER REFUSES. That one copied the
    // server's allowlist and pruned untrusted markup in place, so the page's
    // safety rested on a hand-written filter. This builds NEW nodes: it
    // reads the foreign markup in an inert DOMParser document (no browsing
    // context — nothing loads, nothing runs), and writes only elements it
    // creates itself from the grammar above, plus text nodes. No attribute
    // is ever copied except an href or an image src that passed the scheme
    // allowlist, and no foreign string is ever handed to an HTML parser on
    // the live page. Security stays where it was: `Core\Security\
    // HtmlSanitizer` cleans every string the server receives, whatever sent
    // it, and the editors still repaint with what the server answers.
    //
    // Where it runs: on paste, on opening a stored text, and on the HTML
    // an editor sends or posts. NOT after every toolbar command — rewriting
    // the live DOM under the caret would break the selection and the
    // browser's own undo history, which only knows its own commands. A
    // command's `<b>` therefore becomes `<strong>` when it leaves the
    // editor, which is when the two representations could ever be compared.
    //
    // Idempotent by construction: its own output is in the grammar, and the
    // grammar maps onto itself.

    /**
     * @typedef {object} CanonicalOptions
     * @property {boolean} [images] keep <img> — the news editor has an
     *           image button; the generic surfaces do not
     * @property {(container: HTMLElement) => void} [decorate] runs over a
     *           pasted fragment before it is inserted — rich-text form
     *           fields turn `{{ keyword }}` text into chips here
     */

    /**
     * @typedef {object} Format
     * @property {boolean} bold
     * @property {boolean} italic
     * @property {boolean} underline
     * @property {string|null} href
     */

    /**
     * @typedef {object} Segment
     * @property {string} [text]
     * @property {boolean} [br]
     * @property {{src: string, alt: string|null, width: string|null, height: string|null}} [img]
     * @property {Format} fmt
     */

    /** @type {Format} */
    var PLAIN = { bold: false, italic: false, underline: false, href: null };

    // Whatever a page or a word processor puts on the clipboard that is not
    // text to keep: dropped with its content, as the server drops script,
    // style, iframe, object, embed, form, textarea and select.
    var DROPPED = new Set([
        'script', 'style', 'template', 'noscript', 'head', 'title', 'meta', 'link',
        'iframe', 'frame', 'object', 'embed', 'svg', 'math', 'canvas', 'video', 'audio',
        'form', 'button', 'input', 'select', 'option', 'textarea'
    ]);

    // Containers that only separate blocks: their content is kept, they
    // are not.
    var TRANSPARENT_BLOCKS = new Set([
        'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav',
        'blockquote', 'figure', 'figcaption', 'address', 'center', 'fieldset',
        'details', 'summary', 'dl', 'dt', 'dd',
        'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th'
    ]);

    // Six heading levels outside, two buttons inside.
    var HEADINGS = { h1: 'h2', h2: 'h2', h3: 'h3', h4: 'h3', h5: 'h3', h6: 'h3' };

    /**
     * An href a pasted link may keep, or null. The scheme allowlist is
     * insertLink()'s; tabs and newlines go first, as a browser drops them
     * before reading a scheme (« java\tscript: »).
     *
     * @param {string|null} raw
     * @param {boolean} [imageSource] an <img src>: web or relative only
     * @returns {string|null}
     */
    function safeUrl(raw, imageSource) {
        var url = String(raw == null ? '' : raw).replace(/[\t\r\n]+/g, '').trim();
        if (url === '') {
            return null;
        }
        var scheme = /^([a-z][a-z0-9+.-]*):/i.exec(url);
        if (!scheme) {
            return url;
        }
        var name = scheme[1].toLowerCase();
        if (imageSource) {
            return name === 'http' || name === 'https' ? url : null;
        }
        return ALLOWED_SCHEMES.has(name) ? url : null;
    }

    /**
     * The formatting an element puts on its text, on top of what it
     * inherits. A style beats a tag, in both directions: Google Docs wraps a
     * whole paste in `<b style="font-weight:normal">`, which is not bold,
     * and every word processor marks bold with a styled span.
     *
     * @param {Element} element
     * @param {Format} inherited
     * @returns {Format}
     */
    function formatOf(element, inherited) {
        var tag = element.localName;
        /** @type {Format} */
        var format = {
            bold: inherited.bold || tag === 'strong' || tag === 'b',
            italic: inherited.italic || tag === 'em' || tag === 'i' || tag === 'cite' || tag === 'var' || tag === 'dfn',
            underline: inherited.underline || tag === 'u' || tag === 'ins',
            href: inherited.href
        };

        if (tag === 'a') {
            var href = safeUrl(element.getAttribute('href'));
            if (href !== null) {
                format.href = href;
            }
        }

        var style = (element.getAttribute('style') || '').toLowerCase();
        var weight = /(?:^|;)\s*font-weight\s*:\s*([a-z0-9]+)/.exec(style);
        if (weight) {
            format.bold = weight[1] === 'bold' || weight[1] === 'bolder'
                || (/^\d+$/.test(weight[1]) && Number(weight[1]) >= 600);
        }
        var slant = /(?:^|;)\s*font-style\s*:\s*([a-z]+)/.exec(style);
        if (slant) {
            format.italic = slant[1] === 'italic' || slant[1] === 'oblique';
        }
        var decoration = /(?:^|;)\s*text-decoration(?:-line)?\s*:\s*([^;]+)/.exec(style);
        if (decoration) {
            format.underline = /\bunderline\b/.test(decoration[1]);
        }

        return format;
    }

    /**
     * Content a reader of the source never saw: `display:none`, and Word's
     * list bullets (`mso-list:Ignore`), which are typed characters standing
     * in for the list the grammar rebuilds.
     *
     * @param {Element} element
     * @returns {boolean}
     */
    function isHidden(element) {
        var style = (element.getAttribute('style') || '').toLowerCase();
        return /display\s*:\s*none/.test(style) || /mso-list\s*:\s*ignore/.test(style);
    }

    /**
     * A Word list paragraph — Word puts no <ul> on the clipboard, only
     * paragraphs styled `mso-list:l0 level1 lfo1`.
     *
     * @param {Element} element
     * @returns {boolean}
     */
    function isWordListItem(element) {
        return /mso-list\s*:\s*l\d/.test((element.getAttribute('style') || '').toLowerCase());
    }

    /**
     * Whether a Word list paragraph is numbered: its hidden bullet reads
     * « 1. », « a) », « iv. ».
     *
     * @param {Element} element
     * @returns {boolean}
     */
    function isNumberedWordItem(element) {
        var bullets = element.querySelectorAll('[style]');
        for (var i = 0; i < bullets.length; i++) {
            if (/mso-list\s*:\s*ignore/i.test(bullets[i].getAttribute('style') || '')) {
                return /^\s*(?:\d+|[a-z]|[ivxlcdm]+)[.)]/i.test(bullets[i].textContent || '');
            }
        }
        return false;
    }

    /**
     * @param {Format} a
     * @param {Format} b
     * @returns {boolean}
     */
    function sameFormat(a, b) {
        return a.bold === b.bold && a.italic === b.italic && a.underline === b.underline && a.href === b.href;
    }

    /**
     * A block's segments, cleaned: whitespace collapsed the way a browser
     * renders it, nothing at either end of a line, equal neighbours merged,
     * and the invisible trailing <br> of a non-empty line dropped.
     *
     * @param {Segment[]} segments
     * @param {boolean} heading bold means nothing inside a heading
     * @returns {Segment[]|null} null when nothing visible is left
     */
    function cleanSegments(segments, heading) {
        /** @type {Segment[]} */
        var out = [];
        var lineStart = true;
        var lastSpace = false;

        /** @returns {void} */
        function trimLineEnd() {
            var last = out.length > 0 ? out[out.length - 1] : null;
            if (last && last.text !== undefined) {
                last.text = last.text.replace(/ +$/, '');
                if (last.text === '') {
                    out.pop();
                }
            }
        }

        segments.forEach(function (segment) {
            var fmt = heading
                ? { bold: false, italic: segment.fmt.italic, underline: segment.fmt.underline, href: segment.fmt.href }
                : segment.fmt;

            if (segment.br) {
                trimLineEnd();
                out.push({ br: true, fmt: PLAIN });
                lineStart = true;
                lastSpace = false;
                return;
            }
            if (segment.img) {
                out.push({ img: segment.img, fmt: { bold: false, italic: false, underline: false, href: fmt.href } });
                lineStart = false;
                lastSpace = false;
                return;
            }

            var text = String(segment.text).replace(/[ \t\n\r\f]+/g, ' ');
            if (lineStart || lastSpace) {
                text = text.replace(/^ +/, '');
            }
            if (text === '') {
                return;
            }
            var previous = out.length > 0 ? out[out.length - 1] : null;
            if (previous && previous.text !== undefined && sameFormat(previous.fmt, fmt)) {
                previous.text += text;
            } else {
                out.push({ text: text, fmt: fmt });
            }
            lineStart = false;
            lastSpace = text.endsWith(' ');
        });
        trimLineEnd();

        var visible = out.some(function (segment) {
            return segment.img !== undefined || (segment.text !== undefined && /\S/.test(segment.text));
        });
        if (!visible) {
            return null;
        }

        // « a<br> » shows « a »; « a<br><br> » shows « a » and an empty
        // line. Dropping only a LONE trailing <br> keeps the second as it is
        // and keeps the rule idempotent.
        var n = out.length;
        if (n >= 2 && out[n - 1].br && !out[n - 2].br) {
            out.pop();
        }

        // Trimming can leave equal neighbours side by side.
        return out.reduce(function (merged, segment) {
            var previous = merged.length > 0 ? merged[merged.length - 1] : null;
            if (previous && previous.text !== undefined && segment.text !== undefined && sameFormat(previous.fmt, segment.fmt)) {
                previous.text += segment.text;
            } else {
                merged.push(segment);
            }
            return merged;
        }, /** @type {Segment[]} */ ([]));
    }

    // a > strong > em > u: one nesting order, so one HTML per look.
    var NESTING = [
        { key: 'href', tag: 'a' },
        { key: 'bold', tag: 'strong' },
        { key: 'italic', tag: 'em' },
        { key: 'underline', tag: 'u' }
    ];

    /**
     * Writes cleaned segments under `parent`, wrapping maximal runs that
     * share a link, then bold, then italic, then underline.
     *
     * @param {Document} doc
     * @param {Node} parent
     * @param {Segment[]} segments
     * @param {number} level
     * @returns {void}
     */
    function emit(doc, parent, segments, level) {
        if (level === NESTING.length) {
            segments.forEach(function (segment) {
                if (segment.br) {
                    parent.appendChild(doc.createElement('br'));
                } else if (segment.img) {
                    var image = doc.createElement('img');
                    image.setAttribute('src', segment.img.src);
                    if (segment.img.alt) image.setAttribute('alt', segment.img.alt);
                    if (segment.img.width) image.setAttribute('width', segment.img.width);
                    if (segment.img.height) image.setAttribute('height', segment.img.height);
                    parent.appendChild(image);
                } else {
                    parent.appendChild(doc.createTextNode(String(segment.text)));
                }
            });
            return;
        }

        var key = NESTING[level].key;
        var i = 0;
        while (i < segments.length) {
            var value = segments[i].fmt[key];
            var j = i + 1;
            while (j < segments.length && segments[j].fmt[key] === value) {
                j++;
            }
            var run = segments.slice(i, j);
            if (value) {
                var wrapper = doc.createElement(NESTING[level].tag);
                if (key === 'href') {
                    wrapper.setAttribute('href', String(value));
                }
                parent.appendChild(wrapper);
                emit(doc, wrapper, run, level + 1);
            } else {
                emit(doc, parent, run, level + 1);
            }
            i = j;
        }
    }

    /**
     * An editor's own HTML → a fragment of the live document, in the
     * canonical grammar. What is passed here is a stored text the server
     * already sanitised, or the editor's own innerHTML on its way out —
     * never the clipboard, which only ever reaches canonicalNodes() as the
     * nodes the browser itself pasted (see wireSurface()). The string is
     * read inside an inert DOMParser document; every node of the result is
     * created here.
     *
     * @param {string} html
     * @param {CanonicalOptions} [options]
     * @returns {DocumentFragment}
     */
    function canonicalFragment(html, options) {
        var source = new DOMParser().parseFromString(String(html == null ? '' : html), 'text/html');
        return canonicalNodes(source.body, options);
    }

    /**
     * The children of `sourceRoot` → a fragment of the live document, in the
     * canonical grammar. `sourceRoot` is only ever read.
     *
     * @param {Node} sourceRoot
     * @param {CanonicalOptions} [options]
     * @returns {DocumentFragment}
     */
    function canonicalNodes(sourceRoot, options) {
        var images = Boolean(options && options.images);
        var doc = document;

        var root = doc.createDocumentFragment();
        /** @type {Node} where finished blocks go */
        var blockParent = root;
        /** @type {HTMLElement|null} the list item being filled, if any */
        var item = null;
        var kind = 'p';
        var preformatted = false;
        /** @type {Segment[]} */
        var segments = [];
        /** @type {HTMLElement|null} the list Word paragraphs are joining */
        var wordList = null;

        /** @returns {void} */
        function flush() {
            if (segments.length === 0) {
                return;
            }
            var heading = kind !== 'p';
            // An empty line is one somebody made: a <br>, or the
            // non-breaking space Word writes into an empty paragraph. Plain
            // whitespace between two tags is not one.
            var deliberate = segments.some(function (segment) {
                return segment.br === true || (segment.text !== undefined && segment.text.indexOf('\u00a0') !== -1);
            });
            var cleaned = cleanSegments(segments, heading && item === null);
            segments = [];

            if (item !== null) {
                if (cleaned !== null) {
                    emit(doc, item, cleaned, 0);
                }
                return;
            }

            if (cleaned === null && !deliberate) {
                return;
            }
            var block = doc.createElement(cleaned === null ? 'p' : kind);
            if (cleaned === null) {
                // A line someone left empty on purpose — the only empty
                // block there is.
                block.appendChild(doc.createElement('br'));
            } else {
                emit(doc, block, cleaned, 0);
            }
            blockParent.appendChild(block);
            wordList = null;
        }

        /** A block boundary: a new block, or a new line inside a list item. */
        function boundary() {
            if (item !== null) {
                var last = segments.length > 0 ? segments[segments.length - 1] : null;
                if (last && !last.br) {
                    segments.push({ br: true, fmt: PLAIN });
                }
                return;
            }
            flush();
        }

        /**
         * @param {Element} source
         * @param {Format} format
         * @param {HTMLElement} list
         * @returns {void}
         */
        function listItem(source, format, list) {
            var li = doc.createElement('li');
            list.appendChild(li);

            var saved = { item: item, segments: segments, kind: kind };
            item = li;
            segments = [];
            kind = 'p';
            walk(source, format);
            flush();
            item = saved.item;
            segments = saved.segments;
            kind = saved.kind;

            if (!li.hasChildNodes()) {
                list.removeChild(li);
            }
        }

        /**
         * @param {Element} source
         * @param {string} tag ul or ol
         * @param {Format} format
         * @returns {void}
         */
        function list(source, tag, format) {
            flush();
            var target = item !== null ? item : blockParent;
            var element = doc.createElement(tag);
            target.appendChild(element);

            for (var child = source.firstChild; child !== null; child = child.nextSibling) {
                if (child.nodeType === 1 && /** @type {Element} */ (child).localName === 'li') {
                    listItem(/** @type {Element} */ (child), format, element);
                } else if (child.nodeType === 1 || /\S/.test(child.textContent || '')) {
                    // Content straight under the list: old HTML nests a list
                    // in a list without the <li>; give it one.
                    var wrapper = source.ownerDocument.createElement('li');
                    wrapper.appendChild(child.cloneNode(true));
                    listItem(wrapper, format, element);
                }
            }

            if (!element.hasChildNodes()) {
                target.removeChild(element);
            }
            if (item === null) {
                wordList = null;
            }
        }

        /**
         * @param {Element} source
         * @param {Format} format
         * @returns {void}
         */
        function wordListItem(source, format) {
            flush();
            var tag = isNumberedWordItem(source) ? 'ol' : 'ul';
            if (wordList === null || wordList.localName !== tag || blockParent.lastChild !== wordList) {
                wordList = doc.createElement(tag);
                blockParent.appendChild(wordList);
            }
            listItem(source, format, wordList);
        }

        /**
         * @param {Element} source
         * @param {string} blockKind
         * @param {Format} format
         * @returns {void}
         */
        function block(source, blockKind, format) {
            if (item !== null) {
                boundary();
                walk(source, format);
                boundary();
                return;
            }
            flush();
            var outer = kind;
            kind = blockKind;
            walk(source, format);
            flush();
            kind = outer;
        }

        /**
         * @param {Node} node
         * @param {Format} format
         * @returns {void}
         */
        function visit(node, format) {
            if (node.nodeType === 3) {
                var text = /** @type {Text} */ (node).data;
                if (preformatted) {
                    text.split('\n').forEach(function (line, index) {
                        if (index > 0) segments.push({ br: true, fmt: PLAIN });
                        if (line !== '') segments.push({ text: line, fmt: format });
                    });
                } else if (text !== '') {
                    segments.push({ text: text, fmt: format });
                }
                return;
            }
            if (node.nodeType !== 1) {
                return;
            }

            var element = /** @type {Element} */ (node);
            var tag = element.localName;
            if (DROPPED.has(tag) || isHidden(element)) {
                return;
            }
            if (tag === 'br') {
                segments.push({ br: true, fmt: PLAIN });
                return;
            }
            if (tag === 'img') {
                var src = images ? safeUrl(element.getAttribute('src'), true) : null;
                if (src !== null) {
                    /** @param {string} name @returns {string|null} */
                    var dimension = function (name) {
                        var value = element.getAttribute(name) || '';
                        return /^\d{1,4}$/.test(value) ? value : null;
                    };
                    segments.push({
                        img: { src: src, alt: element.getAttribute('alt'), width: dimension('width'), height: dimension('height') },
                        fmt: format
                    });
                }
                return;
            }
            if (tag === 'hr') {
                boundary();
                return;
            }

            var inner = formatOf(element, format);
            if (tag === 'ul' || tag === 'ol') {
                list(element, tag, inner);
            } else if (item === null && isWordListItem(element)) {
                wordListItem(element, inner);
            } else if (tag === 'li' || tag === 'p') {
                block(element, 'p', inner);
            } else if (Object.hasOwn(HEADINGS, tag)) {
                block(element, HEADINGS[tag], inner);
            } else if (tag === 'pre') {
                preformatted = true;
                block(element, 'p', inner);
                preformatted = false;
            } else if (TRANSPARENT_BLOCKS.has(tag)) {
                boundary();
                walk(element, inner);
                boundary();
            } else {
                walk(element, inner);
            }
        }

        /**
         * @param {Node} parent
         * @param {Format} format
         * @returns {void}
         */
        function walk(parent, format) {
            for (var child = parent.firstChild; child !== null; child = child.nextSibling) {
                visit(child, format);
            }
        }

        walk(sourceRoot, PLAIN);
        flush();

        return root;
    }

    /**
     * Foreign HTML → canonical HTML, as a string. What an editor sends or
     * posts goes through this.
     *
     * @param {string} html
     * @param {CanonicalOptions} [options]
     * @returns {string}
     */
    function canonicalHtml(html, options) {
        var holder = document.createElement('div');
        holder.appendChild(canonicalFragment(html, options));
        return holder.innerHTML;
    }

    /**
     * Plain text → a fragment: a blank line starts a paragraph, a single
     * newline is a line break. What a paste without HTML becomes.
     *
     * @param {string} text
     * @returns {DocumentFragment}
     */
    function plainTextFragment(text) {
        var fragment = document.createDocumentFragment();
        String(text).replace(/\r\n?/g, '\n').split(/\n[ \t]*\n/).forEach(function (paragraph) {
            if (paragraph.trim() === '') {
                return;
            }
            var block = document.createElement('p');
            paragraph.split('\n').forEach(function (line, index) {
                if (index > 0) block.appendChild(document.createElement('br'));
                block.appendChild(document.createTextNode(line));
            });
            fragment.appendChild(block);
        });
        return fragment;
    }

    /**
     * Puts a fragment at the caret. A lone paragraph goes in as its inline
     * content — a word copied from a web page must not split the paragraph
     * it lands in. `insertHTML` keeps the paste on the browser's undo stack;
     * its HTML is the serialisation of nodes built above, never the
     * clipboard's.
     *
     * @param {HTMLElement} surface
     * @param {DocumentFragment} fragment
     * @returns {void}
     */
    function insertFragment(surface, fragment) {
        var content = fragment;
        if (content.childNodes.length === 1 && content.firstChild && /** @type {Element} */ (content.firstChild).localName === 'p') {
            var paragraph = content.firstChild;
            content = document.createDocumentFragment();
            while (paragraph.firstChild) {
                content.appendChild(paragraph.firstChild);
            }
        }
        if (!content.hasChildNodes()) {
            return;
        }

        var holder = document.createElement('div');
        holder.appendChild(content.cloneNode(true));
        if (document.execCommand('insertHTML', false, holder.innerHTML)) {
            return;
        }

        // An engine without insertHTML: the same nodes, by hand.
        var selection = window.getSelection();
        var range = selection && selection.rangeCount > 0 ? selection.getRangeAt(0) : null;
        if (!selection || range === null || !surface.contains(range.commonAncestorContainer)) {
            surface.appendChild(content);
            return;
        }
        range.deleteContents();
        var last = content.lastChild;
        range.insertNode(content);
        if (last) {
            range.setStartAfter(last);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);
        }
    }

    /**
     * The paste bin of one surface: a hidden contenteditable right next to
     * it, created on first use. Next to it rather than on <body>, because a
     * Bootstrap modal hands focus straight back to itself when it moves
     * anywhere outside it.
     *
     * @param {HTMLElement} surface
     * @returns {HTMLElement}
     */
    function pasteBin(surface) {
        var next = surface.nextElementSibling;
        if (next instanceof HTMLElement && next.classList.contains('rich-text-paste-bin')) {
            return next;
        }
        var bin = document.createElement('div');
        bin.className = 'rich-text-paste-bin visually-hidden';
        bin.setAttribute('contenteditable', 'true');
        bin.setAttribute('aria-hidden', 'true');
        bin.tabIndex = -1;
        surface.insertAdjacentElement('afterend', bin);
        return bin;
    }

    /**
     * Puts a canonical fragment where the caret was, and says so.
     *
     * @param {HTMLElement} surface
     * @param {DocumentFragment} fragment
     * @param {CanonicalOptions|undefined} options
     * @param {(() => void)|null|undefined} afterChange
     * @returns {void}
     */
    function land(surface, fragment, options, afterChange) {
        var content = fragment;
        if (options && options.decorate) {
            var holder = document.createElement('div');
            holder.appendChild(content);
            options.decorate(holder);
            content = document.createDocumentFragment();
            while (holder.firstChild) {
                content.appendChild(holder.firstChild);
            }
        }

        insertFragment(surface, content);
        surface.dispatchEvent(new Event('input', { bubbles: true }));
        if (afterChange) afterChange();
    }

    /**
     * Gives a contenteditable the canonical paste: every path that pastes
     * — the keyboard, the context menu, a phone's « Coller » — fires
     * `paste`, and what it carries is rebuilt before anything of it reaches
     * the text. Idempotent per surface, like wireToolbar() per button: the
     * shared modal is wired by two scripts.
     *
     * THE CLIPBOARD'S HTML IS NEVER READ AS A STRING. Parsing it here, even
     * into an inert DOMParser document, is a cross-site scripting sink by
     * CodeQL's reading, and the release refuses an open alert. So the
     * browser does the parsing it does for every paste anyway — scripts
     * and event handlers stripped by its own paste sanitiser — into a
     * hidden contenteditable next to the surface (the « paste bin » of the
     * established editors), and only the resulting NODES are read, by
     * canonicalNodes(). The caret is put back and the canonical fragment
     * inserted where it was, on the browser's undo stack.
     *
     * Plain text is read as text: it becomes text nodes and line breaks.
     *
     * @param {HTMLElement} surface
     * @param {CanonicalOptions} [options]
     * @param {(() => void)|null} [afterChange] run after a paste — form
     *        fields sync their hidden input here
     * @returns {void}
     */
    function wireSurface(surface, options, afterChange) {
        if (surface.dataset.richTextSurface === 'yes') {
            return;
        }
        surface.dataset.richTextSurface = 'yes';

        surface.addEventListener('paste', function (event) {
            var clipboard = event.clipboardData;
            var types = clipboard ? Array.prototype.slice.call(clipboard.types || []) : [];

            if (types.indexOf('text/html') !== -1) {
                var range = captureSelection();
                var bin = pasteBin(surface);
                bin.replaceChildren();
                bin.focus();
                var inBin = document.createRange();
                inBin.selectNodeContents(bin);
                var selection = window.getSelection();
                if (selection) {
                    selection.removeAllRanges();
                    selection.addRange(inBin);
                }

                // No preventDefault(): the browser's own paste lands in the
                // bin, and is read once it has.
                window.setTimeout(function () {
                    var fragment = canonicalNodes(bin, options);
                    bin.replaceChildren();
                    restoreSelection(surface, range);
                    land(surface, fragment, options, afterChange);
                }, 0);
                return;
            }

            var text = clipboard ? clipboard.getData('text/plain') : '';
            if (text === '') {
                // A file, or a clipboard the page may not read: nothing
                // here to rebuild. What the browser inserts still goes
                // through the canonical form when it leaves the editor.
                return;
            }
            event.preventDefault();
            land(surface, plainTextFragment(text), options, afterChange);
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
     * @param {CanonicalOptions} [options] what this surface can hold
     *        beyond the common grammar; see wireSurface()
     * @returns {void}
     */
    function wireToolbar(root, surface, afterCommand, options) {
        // The surface gets its paste handling here because every generic
        // editor already comes through this call — the shared modal (twice,
        // see above), and each rich-text form field.
        wireSurface(surface, options, afterCommand);

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
        canonicalFragment: canonicalFragment,
        canonicalHtml: canonicalHtml,
        insertLink: insertLink,
        normalizeUrl: normalizeUrl,
        wireSurface: wireSurface,
        wireToolbar: wireToolbar
    };
})();
