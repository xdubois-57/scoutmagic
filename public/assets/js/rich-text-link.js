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

    // The « baguette magique »: proposes a link's text from the page it
    // points at (core/Http/Controller/LinkLabelController.php).
    var LABEL_ENDPOINT = '/api/lien/libelle';

    /**
     * Fills « Texte du lien » from the page at the address typed below it —
     * its title, or the name of the file it ends with — or says, under the
     * field, why it could not.
     *
     * @param {ScoutMagicFormControl} control
     * @returns {Promise<void>}
     */
    function suggestLabel(control) {
        var href = normalizeUrl(control.value('url'));
        if (href === null) {
            control.note('text', 'Indiquez d\'abord l\'adresse du lien.', 'warning');
            return Promise.resolve();
        }
        if (!/^https?:\/\//i.test(href)) {
            control.note('text', 'Aucun titre à proposer pour cette adresse : tapez le texte du lien.', 'warning');
            return Promise.resolve();
        }

        control.note('text', 'Recherche du titre de la page…', 'muted');
        return window.ScoutMagicApi.postJson(LABEL_ENDPOINT, { url: href }).then(function (res) {
            var label = res.ok && res.data?.success ? res.data.label : null;
            if (typeof label === 'string' && label !== '') {
                control.set('text', label);
                control.note('text', '');
                return;
            }
            if (!res.ok && typeof res.data?.error === 'string') {
                control.note('text', res.data.error, 'warning');
                return;
            }
            control.note('text', 'Aucun titre trouvé pour cette page : tapez le texte du lien.', 'warning');
        });
    }

    /**
     * Asks for a link — the text to show and the address — and puts it
     * where the selection was.
     *
     * « Texte du lien » starts with the selected text, so selecting a word
     * and clicking « Lien » still links that word; it can be changed, or
     * proposed from the page by the button beside it (issue #840). Left
     * empty, the address itself is shown, as before.
     *
     * @param {HTMLElement|null} surface the contenteditable to give focus
     *        back to. Passing null still works — the selection is restored
     *        either way — but the caller usually knows its editor.
     * @returns {Promise<boolean>} true when a link was inserted
     */
    function insertLink(surface) {
        var range = captureSelection();
        var selected = range === null ? '' : range.toString();

        return window.ScoutMagicConfirm.form({
            title: 'Insérer un lien',
            fields: [
                {
                    name: 'text',
                    label: 'Texte du lien',
                    value: selected.trim(),
                    placeholder: 'L\'adresse, si vous le laissez vide',
                    action: {
                        label: 'Proposer le titre de la page',
                        icon: 'bi-magic',
                        run: suggestLabel
                    }
                },
                { name: 'url', label: 'Adresse du lien', placeholder: 'https://…' }
            ],
            confirmLabel: 'Insérer le lien'
        }).then(function (answer) {
            restoreSelection(surface, range);
            if (answer === null) {
                // Dismissed: the caret is back where the visitor left it.
                return false;
            }

            var href = normalizeUrl(answer.url);
            if (href === null) {
                if (String(answer.url || '').trim() !== '') {
                    window.ScoutMagicToast.show(
                        'Ce lien n\'a pas pu être inséré : seules les adresses web, email et téléphone sont acceptées.',
                        { variant: 'error' }
                    );
                }
                return false;
            }

            var text = String(answer.text || '').trim();
            if (selected.trim() !== '' && text === selected.trim()) {
                // The selection, unchanged: wrapping it keeps whatever bold
                // or italics it already carried.
                document.execCommand('createLink', false, href);
                return true;
            }

            // A new text: the link replaces the selection, built from
            // nodes — the text is the visitor's, never read as markup.
            var link = document.createElement('a');
            link.setAttribute('href', href);
            link.textContent = text === '' ? href : text;
            var fragment = document.createDocumentFragment();
            fragment.appendChild(link);
            // Without the editor element, the one the selection was in.
            var node = range?.commonAncestorContainer ?? null;
            var element = node?.nodeType === 1 ? /** @type {Element} */ (node) : node?.parentElement ?? null;
            var target = surface || /** @type {HTMLElement|null} */ (element?.closest('[contenteditable]:not([contenteditable="false"])') ?? null);
            if (target === null) {
                return false;
            }
            insertFragment(target, fragment);
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
     * @property {boolean} [siteImages] keep an <img> only when it is one of
     *           this site's own files (a path from the root, or a full
     *           address on this origin, kept as its path): a surface
     *           without an image button must still let an author cut an
     *           image the text already holds and paste it elsewhere in it,
     *           without letting a paste bring one in from another site
     * @property {boolean} [stored] the text is one the server already
     *           accepted, being opened or saved again: what the sanitiser
     *           keeps and no button makes — <img>, <h4>, <blockquote>, a
     *           link's target, rel and title — survives, because dropping it on save would change the text
     *           without anybody asking. A paste never gets this.
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
     * @property {Array<[string, string]>|null} [linkAttributes] the link's
     *           target, rel and title, in their order — kept for a stored
     *           text only (see `stored`)
     */

    /**
     * @typedef {object} Segment
     * @property {string} [text]
     * @property {boolean} [br]
     * @property {{src: string, alt: string|null, width: string|null, height: string|null}} [img]
     * @property {Format} fmt
     */

    /** @type {Format} */
    var PLAIN = { bold: false, italic: false, underline: false, href: null, linkAttributes: null };

    // What a stored link keeps besides its href: what the sanitiser accepts
    // on <a>, and no button writes.
    var LINK_ATTRIBUTES = new Set(['target', 'rel', 'title']);

    /**
     * Everything that tells one link from another: two neighbours with the
     * same href but a different target are two links.
     *
     * @param {Format} format
     * @returns {string|null}
     */
    function linkKey(format) {
        return format.href === null ? null : JSON.stringify([format.href, format.linkAttributes ?? null]);
    }

    /**
     * @param {Format} format
     * @returns {Format} the same link, and no formatting
     */
    function linkOnly(format) {
        return { bold: false, italic: false, underline: false, href: format.href, linkAttributes: format.linkAttributes ?? null };
    }

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
        // Any other control character refuses the link: a browser strips a
        // leading one before reading the scheme, so « \x01javascript: » would
        // otherwise pass as a relative URL. HtmlSanitizer refuses it too.
        if (url === '' || /[\x00-\x1f\x7f]/.test(url)) {
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
     * An image source as a path from this site's root, or null when it is
     * another site's. A path from the root is kept as it is; `//host` is
     * another site, and so is `/\\host`, a browser reading a backslash as a
     * slash. A full address on this site's own origin becomes its path:
     * cutting an image and pasting it back puts it on the clipboard that way
     * — Chromium resolves every `src` when it copies.
     *
     * @param {string} src a source safeUrl() already accepted
     * @returns {string|null}
     */
    function sitePath(src) {
        if (/^\/(?![/\\])/.test(src)) {
            return src;
        }
        if (!/^https?:/i.test(src)) {
            return null;
        }
        var url;
        try {
            url = new URL(src);
        } catch {
            // An address that does not parse is not this site's.
            return null;
        }
        // The path is checked again: `https://this-site//host/x` is this
        // origin, but its path would read as another site once written alone.
        if (url.origin !== window.location.origin || !/^\/(?![/\\])/.test(url.pathname)) {
            return null;
        }
        return url.pathname + url.search + url.hash;
    }

    /**
     * The formatting an element puts on its text, on top of what it
     * inherits. A style beats a tag, in both directions: Google Docs wraps a
     * whole paste in `<b style="font-weight:normal">`, which is not bold,
     * and every word processor marks bold with a styled span.
     *
     * @param {Element} element
     * @param {Format} inherited
     * @param {boolean} [stored] keep a link's target, rel and title
     * @returns {Format}
     */
    function formatOf(element, inherited, stored) {
        var tag = element.localName;
        /** @type {Format} */
        var format = {
            bold: inherited.bold || tag === 'strong' || tag === 'b',
            italic: inherited.italic || tag === 'em' || tag === 'i' || tag === 'cite' || tag === 'var' || tag === 'dfn',
            underline: inherited.underline || tag === 'u' || tag === 'ins',
            href: inherited.href,
            linkAttributes: inherited.linkAttributes ?? null
        };

        if (tag === 'a') {
            var href = safeUrl(element.getAttribute('href'));
            if (href !== null) {
                format.href = href;
                format.linkAttributes = null;
                if (stored) {
                    /** @type {Array<[string, string]>} */
                    var kept = [];
                    Array.from(element.attributes).forEach(function (attribute) {
                        if (LINK_ATTRIBUTES.has(attribute.name)) kept.push([attribute.name, attribute.value]);
                    });
                    format.linkAttributes = kept.length > 0 ? kept : null;
                }
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
        for (var bullet of Array.from(bullets)) {
            if (/mso-list\s*:\s*ignore/i.test(bullet.getAttribute('style') || '')) {
                return /^\s*(?:\d+|[a-z]|[ivxlcdm]+)[.)]/i.test(bullet.textContent || '');
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
        return a.bold === b.bold && a.italic === b.italic && a.underline === b.underline && linkKey(a) === linkKey(b);
    }

    /**
     * `text` without the plain spaces it ends with — a loop rather than
     * `/ +$/`, which backtracks quadratically on a long run of spaces, and
     * not trimEnd(), which would take a non-breaking space with them.
     *
     * @param {string} text
     * @returns {string}
     */
    function withoutTrailingSpaces(text) {
        var end = text.length;
        while (end > 0 && text.codePointAt(end - 1) === 32) {
            end--;
        }
        return text.slice(0, end);
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
            var last = out.at(-1);
            if (last?.text !== undefined) {
                last.text = withoutTrailingSpaces(last.text);
                if (last.text === '') {
                    out.pop();
                }
            }
        }

        segments.forEach(function (segment) {
            var fmt = heading
                ? Object.assign(linkOnly(segment.fmt), { italic: segment.fmt.italic, underline: segment.fmt.underline })
                : segment.fmt;

            if (segment.br) {
                trimLineEnd();
                out.push({ br: true, fmt: PLAIN });
                lineStart = true;
                lastSpace = false;
                return;
            }
            if (segment.img) {
                out.push({ img: segment.img, fmt: linkOnly(fmt) });
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
            var previous = out.at(-1);
            if (previous?.text !== undefined && sameFormat(previous.fmt, fmt)) {
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
            var previous = merged.at(-1);
            if (previous?.text !== undefined && segment.text !== undefined && sameFormat(previous.fmt, segment.fmt)) {
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
                    // alt="" marks a decorative image; only a missing alt is no alt.
                    if (segment.img.alt !== null) image.setAttribute('alt', segment.img.alt);
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
        /** @param {Format} format @returns {string|boolean|null} */
        var valueOf = function (format) {
            return key === 'href' ? linkKey(format) : format[key];
        };
        var i = 0;
        while (i < segments.length) {
            var value = valueOf(segments[i].fmt);
            var j = i + 1;
            while (j < segments.length && valueOf(segments[j].fmt) === value) {
                j++;
            }
            var run = segments.slice(i, j);
            if (value) {
                var wrapper = doc.createElement(NESTING[level].tag);
                if (key === 'href') {
                    var link = segments[i].fmt;
                    wrapper.setAttribute('href', String(link.href));
                    (link.linkAttributes ?? []).forEach(function (attribute) {
                        wrapper.setAttribute(attribute[0], attribute[1]);
                    });
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
        var stored = Boolean(options?.stored);
        var images = stored || Boolean(options?.images);
        var siteImages = Boolean(options?.siteImages);
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
                return segment.br === true || (segment.text?.includes('\u00a0') === true);
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
                var last = segments.at(-1);
                if (last !== undefined && !last.br) {
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
                li.remove();
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
                element.remove();
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
            if (wordList?.localName !== tag || blockParent.lastChild !== wordList) {
                wordList = doc.createElement(tag);
                blockParent.appendChild(wordList);
            }
            listItem(source, format, wordList);
            // A bullet paragraph with nothing but its hidden marker makes no
            // item; a list that got none is no list either, as in list().
            if (!wordList.hasChildNodes()) {
                wordList.remove();
                wordList = null;
            }
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
         * @param {Text} node
         * @param {Format} format
         * @returns {void}
         */
        function visitText(node, format) {
            var text = node.data;
            if (preformatted) {
                text.split('\n').forEach(function (line, index) {
                    if (index > 0) segments.push({ br: true, fmt: PLAIN });
                    if (line !== '') segments.push({ text: line, fmt: format });
                });
            } else if (text !== '') {
                segments.push({ text: text, fmt: format });
            }
        }

        /**
         * An <img> as a segment, when this surface keeps images and its
         * source passes; null otherwise.
         *
         * @param {Element} element
         * @param {Format} format
         * @returns {Segment|null}
         */
        function imageSegment(element, format) {
            var src = images || siteImages ? safeUrl(element.getAttribute('src'), true) : null;
            if (src !== null && !images) {
                src = sitePath(src);
            }
            if (src === null) {
                return null;
            }
            /** @param {string} name @returns {string|null} */
            var dimension = function (name) {
                var value = element.getAttribute(name) || '';
                return /^\d{1,4}$/.test(value) ? value : null;
            };
            return {
                img: { src: src, alt: element.getAttribute('alt'), width: dimension('width'), height: dimension('height') },
                fmt: format
            };
        }

        /**
         * The elements with no content of their own to walk: a line break,
         * an image, a rule.
         *
         * @param {Element} element
         * @param {Format} format
         * @returns {boolean} whether `element` was one
         */
        function visitLeaf(element, format) {
            var tag = element.localName;
            if (tag === 'br') {
                segments.push({ br: true, fmt: PLAIN });
            } else if (tag === 'img') {
                var image = imageSegment(element, format);
                if (image !== null) segments.push(image);
            } else if (tag === 'hr') {
                boundary();
            } else {
                return false;
            }
            return true;
        }

        /**
         * @param {Element} element
         * @param {Format} inner the formatting its content carries
         * @returns {void}
         */
        function visitContainer(element, inner) {
            var tag = element.localName;
            if (tag === 'ul' || tag === 'ol') {
                list(element, tag, inner);
            } else if (item === null && isWordListItem(element)) {
                wordListItem(element, inner);
            } else if (tag === 'li' || tag === 'p') {
                block(element, 'p', inner);
            } else if (Object.hasOwn(HEADINGS, tag)) {
                block(element, stored && tag === 'h4' ? 'h4' : HEADINGS[tag], inner);
            } else if (tag === 'pre') {
                preformatted = true;
                block(element, 'p', inner);
                preformatted = false;
            } else if (stored && tag === 'blockquote' && item === null) {
                quotation(element, inner);
            } else if (TRANSPARENT_BLOCKS.has(tag)) {
                boundary();
                walk(element, inner);
                boundary();
            } else {
                walk(element, inner);
            }
        }

        /**
         * A stored <blockquote>, kept as one: its content becomes blocks
         * inside it rather than beside it.
         *
         * @param {Element} source
         * @param {Format} format
         * @returns {void}
         */
        function quotation(source, format) {
            flush();
            var quote = doc.createElement('blockquote');
            blockParent.appendChild(quote);
            var outer = blockParent;
            blockParent = quote;
            walk(source, format);
            flush();
            blockParent = outer;
            wordList = null;
            if (!quote.hasChildNodes()) {
                quote.remove();
            }
        }

        /**
         * @param {Node} node
         * @param {Format} format
         * @returns {void}
         */
        function visit(node, format) {
            if (node.nodeType === 3) {
                visitText(/** @type {Text} */ (node), format);
                return;
            }
            if (node.nodeType !== 1) {
                return;
            }
            var element = /** @type {Element} */ (node);
            if (DROPPED.has(element.localName) || isHidden(element) || visitLeaf(element, format)) {
                return;
            }
            visitContainer(element, formatOf(element, format, stored));
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
        /** @type {string[]} the lines of the paragraph being read */
        var lines = [];
        var close = function () {
            if (lines.length === 0) return;
            var block = document.createElement('p');
            lines.forEach(function (line, index) {
                if (index > 0) block.appendChild(document.createElement('br'));
                block.appendChild(document.createTextNode(line));
            });
            fragment.appendChild(block);
            lines = [];
        };
        // Line by line, not one split on a pattern: a blank line closes the
        // paragraph, so any run of them separates two, however long.
        String(text).replace(/\r\n?/g, '\n').split('\n').forEach(function (line) {
            if (line.trim() === '') {
                close();
            } else {
                lines.push(line);
            }
        });
        close();
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
        if (content.childNodes.length === 1 && /** @type {Element|null} */ (content.firstChild)?.localName === 'p') {
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
        var range = selection?.rangeCount ? selection.getRangeAt(0) : null;
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
        surface.after(bin);
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
        if (options?.decorate) {
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
            var types = Array.from(clipboard?.types ?? []);

            if (types.includes('text/html')) {
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

            var text = clipboard?.getData('text/plain') ?? '';
            if (text.trim() === '') {
                // A file, a clipboard the page may not read, or only
                // whitespace: nothing here to rebuild, and the browser's own
                // paste still replaces a selection with it. What it inserts
                // goes through the canonical form when it leaves the editor.
                return;
            }
            event.preventDefault();
            land(surface, plainTextFragment(text), options, afterChange);
        });
    }

    // ————— A list item made a heading or a paragraph (#885) —————
    //
    // `formatBlock` inside a `<li>` does not make that item a heading. The
    // engine climbs to the nearest block it knows how to format — the
    // LIST — and wraps all of it: `<h2><ul><li>un</li></ul></h2>`. That is
    // invalid HTML (a heading holds no list), « Paragraphe » then nests a
    // `<p>` inside the `<h2>` instead of replacing it, so the item stays a
    // heading on screen, and the canonical form drops the impossible `<h2>`
    // on save, so the heading the author saw is gone after reopening.
    //
    // So for an item, the toolbar does the move itself, the same way for
    // H2, H3 and « Paragraphe »: the item leaves its list — cut in two
    // around it when it sat in the middle — and becomes the block asked
    // for, keeping its inline content (bold, links…). What is on screen is
    // then exactly what is saved. « Paragraphe » on an item gives what a
    // second click on « Liste à puces » gives: plain text, out of the list.
    //
    // The browser's undo history does not record a change made by hand. The
    // move is kept to one DOM operation per node and touches nothing outside
    // the list, so Ctrl+Z after it undoes the commands before it rather than
    // corrupting the text.

    /** The tags a list item is made of when it is not plain inline content. */
    var ITEM_WRAPPERS = new Set(['P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6']);

    /**
     * Whether $item stands in a list of $surface. A bare `<li>` straight in
     * the surface — stored content that never went through this editor
     * can hold one, as neither sanitizer checks where an `<li>` sits — has
     * no list to leave: the engine's formatBlock handles it like any text.
     *
     * @param {HTMLLIElement} item
     * @param {HTMLElement} surface
     * @returns {boolean}
     */
    function inAList(item, surface) {
        var list = item.parentElement;

        return !!list && list !== surface && (list.nodeName === 'UL' || list.nodeName === 'OL');
    }

    /**
     * The lines of $item: its children cut at each nested list. An item
     * holds one line, or several when a nested list sits between two runs
     * of its own text — `<li>avant<ul>…</ul>texte</li>`, which the
     * canonical form keeps as it is.
     *
     * @param {HTMLLIElement} item
     * @returns {ChildNode[][]} every line, empty ones included, in order
     */
    function linesOf(item) {
        /** @type {ChildNode[][]} */
        var lines = [[]];
        Array.from(item.childNodes).forEach(function (child) {
            if (child.nodeName === 'UL' || child.nodeName === 'OL') {
                lines.push([]);
            } else {
                lines.at(-1)?.push(child);
            }
        });

        return lines;
    }

    /**
     * The line of a list item the selection reaches: its nodes, and the
     * item and the node it stands before, for a line with no node yet.
     *
     * @typedef {{item: HTMLLIElement, nodes: ChildNode[], before: ChildNode|null}} ItemLine
     */

    /**
     * The lines of list items the selection reaches in $surface, in
     * document order: the one holding the caret, or every line the
     * selection touches. A line, not a whole item: in
     * `<li>avant<ul>…</ul>texte</li>` the caret in « texte » makes
     * « texte » the heading, not « avant ». A selection that starts in a
     * paragraph above a list and ends inside it reaches its first items
     * too: leaving those to the engine is the very bug of #885.
     *
     * @param {HTMLElement} surface
     * @returns {{lines: ItemLine[], range: Range|null}}
     */
    function listLinesInSelection(surface) {
        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return { lines: [], range: null };
        }
        var range = selection.getRangeAt(0);
        if (range.collapsed) {
            var node = range.startContainer;
            var element = node.nodeType === Node.ELEMENT_NODE ? /** @type {Element} */ (node) : node.parentElement;
            var item = /** @type {HTMLLIElement|null} */ (element?.closest('li') ?? null);
            if (!item || !surface.contains(item) || item === surface || !inAList(item, surface)) {
                return { lines: [], range: range };
            }

            return { lines: [lineAtCaret(item, node, range.startOffset)], range: range };
        }

        /** @type {ItemLine[]} */
        var lines = [];
        Array.from(surface.querySelectorAll('li')).forEach(function (li) {
            var item = /** @type {HTMLLIElement} */ (li);
            if (!inAList(item, surface)) {
                return;
            }
            linesOf(item).forEach(function (nodes) {
                var touched = nodes.some(function (child) {
                    return range.intersectsNode(child);
                });
                // A line of bare whitespace — the indentation between two
                // nested lists — is not something the author selected.
                if (touched && !nodes.every(isBlank)) {
                    lines.push({ item: item, nodes: nodes, before: null });
                }
            });
        });

        // querySelectorAll() gives an outer item before the items nested in
        // it, so its second line — after the nested list — would come
        // before them. The lines are moved in the order they are read.
        lines.sort(function (a, b) {
            return a.nodes[0].compareDocumentPosition(b.nodes[0]) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
        });

        return { lines: lines, range: range };
    }

    /**
     * Whether $node is markup indentation: a text node of whitespace only.
     * Any element counts as content — an image, and a `<br>` too: an item
     * the author left empty on purpose is a line of its own, and skipping
     * it would leave its bullet behind among the new blocks, for the
     * canonical form to drop on save.
     *
     * @param {ChildNode} node
     * @returns {boolean}
     */
    function isBlank(node) {
        return node.nodeType === Node.TEXT_NODE && (node.textContent || '').trim() === '';
    }

    /**
     * The line of $item that holds the caret, standing at $offset in $node.
     *
     * @param {HTMLLIElement} item
     * @param {Node} node
     * @param {number} offset
     * @returns {ItemLine}
     */
    function lineAtCaret(item, node, offset) {
        // The child of $item the caret is in, or — the caret standing
        // between two children of the item itself — the one after it.
        /** @type {Node|null} */
        var child = node;
        if (node === item) {
            child = item.childNodes[offset] ?? null;
        } else {
            while (child && child.parentNode !== item) {
                child = child.parentNode;
            }
        }

        var lines = linesOf(item);
        var children = Array.from(item.childNodes);
        var index = 0;
        var position = child ? children.indexOf(/** @type {ChildNode} */ (child)) : children.length;
        // Which line: one more for every nested list before the caret.
        children.slice(0, Math.max(position, 0)).forEach(function (previous) {
            if (previous.nodeName === 'UL' || previous.nodeName === 'OL') {
                index++;
            }
        });
        var nodes = lines[index] ?? [];
        var before = nodes.length === 0 && child ? /** @type {ChildNode} */ (child) : null;

        return { item: item, nodes: nodes, before: before };
    }

    /**
     * Whether $range reaches anything visible outside every list item of
     * $surface — a paragraph above the list, or one between two lists.
     * Every node, not the two ends only: a selection from one list to
     * another holds the paragraph between them, which is the engine's to
     * format once the items have moved.
     *
     * @param {Range} range
     * @param {HTMLElement} surface
     * @returns {boolean}
     */
    function touchesOutsideLists(range, surface) {
        var walker = document.createTreeWalker(surface, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
        for (var node = walker.nextNode(); node; node = walker.nextNode()) {
            var visible = node.nodeType === Node.TEXT_NODE
                ? (node.textContent || '').trim() !== ''
                : node.nodeName === 'IMG';
            if (visible && range.intersectsNode(node) && !node.parentElement?.closest('li')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Takes $block out of the list item that holds it, and that list out
     * of the item above it if the list was nested, until $block stands
     * outside every list. Each list is cut in two around the item: what
     * came before stays where it was, what came after follows $block.
     *
     * @param {HTMLElement} block already in place of the item's content
     * @param {HTMLElement} surface
     * @returns {void}
     */
    function liftOutOfLists(block, surface) {
        var item = block.parentElement;
        while (item?.tagName === 'LI' && surface.contains(item) && item !== surface) {
            var list = item.parentElement;
            // Never the surface itself: lifting out of it would put the
            // block outside the editor, and an emptied « list » removed
            // would be the editor (see inAList()).
            if (!list || list === surface || (list.nodeName !== 'UL' && list.nodeName !== 'OL')) {
                return;
            }

            // What the item held after $block — a nested list, mostly —
            // stays an item, at the head of the second half.
            var rest = document.createElement('li');
            while (block.nextSibling) {
                rest.appendChild(block.nextSibling);
            }
            var after = /** @type {HTMLElement} */ (list.cloneNode(false));
            if (rest.hasChildNodes()) {
                after.appendChild(rest);
            }
            while (item.nextSibling) {
                after.appendChild(item.nextSibling);
            }
            // A numbered list's second half starts again at 1. Carrying the
            // count on would take a `start` attribute, which the canonical
            // form does not keep: the screen would number 3 what the saved
            // text numbers 1.

            list.after(block);
            if (after.hasChildNodes()) {
                block.after(after);
            }
            removeIfEmpty(item, list);
            item = block.parentElement;
        }
    }

    /**
     * Removes $item once its block has left it, if nothing is left in it,
     * and $list with it if that was its last item.
     *
     * Whitespace counts as empty — the canonical form drops such an item on
     * save, so the screen must not keep an empty bullet — but an item still
     * holding an image or a nested list is kept.
     *
     * @param {HTMLElement} item
     * @param {HTMLElement} list
     * @returns {void}
     */
    function removeIfEmpty(item, list) {
        if (!item.hasChildNodes() || (item.textContent || '').trim() === '' && !item.querySelector('img, ul, ol')) {
            item.remove();
        }
        if (!list.querySelector(':scope > li')) {
            list.remove();
        }
    }

    /**
     * The block a « formatBlock » button asks for, as one of the three
     * literals the toolbar offers — never the attribute's own text, which
     * would otherwise reach createElement() and execCommand() as written.
     * Anything else, a missing value included, is a paragraph.
     *
     * @param {string|undefined} value the button's data-value
     * @returns {'h2'|'h3'|'p'}
     */
    function blockTagFor(value) {
        switch ((value || '').toLowerCase()) {
            case 'h2':
                return 'h2';
            case 'h3':
                return 'h3';
            default:
                return 'p';
        }
    }

    /**
     * Makes $line a $tag block of its own, out of every list. What the item
     * held before the line stays in the item; what came after it follows
     * the block, as liftOutOfLists() does for any block.
     *
     * @param {ItemLine} line
     * @param {HTMLElement} surface
     * @param {'h2'|'h3'|'p'} tag
     * @returns {HTMLElement} the new block
     */
    function lineToBlock(line, surface, tag) {
        var block = document.createElement(tag);
        // Read now, not when the selection was: lifting an earlier line of
        // the same item has moved this one into the item that follows.
        var anchor = line.nodes[0] ?? line.before;
        var item = /** @type {HTMLElement} */ (anchor?.parentElement ?? line.item);
        item.insertBefore(block, anchor ?? null);
        line.nodes.forEach(function (next) {
            if (ITEM_WRAPPERS.has(next.nodeName)) {
                // The item's text in a block of its own (a pasted `<p>`):
                // its content comes across, never the block itself, which
                // would put a block inside the new one.
                while (next.firstChild) {
                    block.appendChild(next.firstChild);
                }
                next.remove();
            } else {
                block.appendChild(next);
            }
        });
        if (!block.hasChildNodes()) {
            // An empty item: a <br> keeps the new block one line high and
            // gives the caret somewhere to stand.
            block.appendChild(document.createElement('br'));
        }

        liftOutOfLists(block, surface);

        return block;
    }

    /**
     * Makes every line of a list item the selection reaches a $tag block
     * of its own. Does nothing when it reaches none.
     *
     * The nodes are moved, never copied, so the selection is put back on
     * the same text once the items have moved. When it also reached
     * content outside the list — a paragraph above it — that part is still
     * the engine's: `engine` says so, and the caller runs formatBlock on
     * the restored selection, which no longer holds any list item.
     *
     * @param {HTMLElement} surface
     * @param {'h2'|'h3'|'p'} tag
     * @returns {{range: Range, engine: boolean}|null} the selection to put
     *          back once the surface has focus again; null when no item
     */
    function listItemsToBlocks(surface, tag) {
        var found = listLinesInSelection(surface);
        if (found.lines.length === 0 || !found.range) {
            return null;
        }
        var range = found.range;
        var bounds = { start: range.startContainer, startOffset: range.startOffset, end: range.endContainer, endOffset: range.endOffset };
        var engine = !range.collapsed && touchesOutsideLists(range, surface);

        var blocks = found.lines.map(function (line) {
            return lineToBlock(line, surface, tag);
        });

        // Each end as it was, while it still exists and its offset still
        // fits: a list cut in two can keep a node and lose the children an
        // offset counted, and setEnd() would then throw before the caller
        // had told the form anything had changed. An end that no longer
        // fits — an item removed with its list — falls back to the edge of
        // the new blocks on its own side only, so the other end keeps what
        // the selection held outside the list for the engine.
        var restored = document.createRange();
        if (stillFits(bounds.start, bounds.startOffset, surface)) {
            restored.setStart(bounds.start, bounds.startOffset);
        } else {
            restored.setStartBefore(blocks[0]);
        }
        if (stillFits(bounds.end, bounds.endOffset, surface)) {
            restored.setEnd(bounds.end, bounds.endOffset);
        } else {
            restored.setEndAfter(/** @type {HTMLElement} */ (blocks.at(-1)));
        }

        return { range: restored, engine: engine };
    }

    /**
     * Whether a boundary at $offset in $node can still be set: $node is in
     * $surface, and the offset is within what it holds now.
     *
     * @param {Node} node
     * @param {number} offset
     * @param {HTMLElement} surface
     * @returns {boolean}
     */
    function stillFits(node, offset, surface) {
        if (!surface.contains(node)) {
            return false;
        }
        var size = node.nodeType === Node.TEXT_NODE ? (node.textContent || '').length : node.childNodes.length;

        return offset <= size;
    }

    /**
     * Whether the selection lies in $surface.
     *
     * @param {HTMLElement} surface
     * @returns {boolean}
     */
    function selectionIsIn(surface) {
        var selection = window.getSelection();

        return !!selection && selection.rangeCount > 0 && surface.contains(selection.getRangeAt(0).commonAncestorContainer);
    }

    /**
     * The engine's own formatBlock, for what holds no list item. The angle
     * brackets are the form the other two toolbars already used and the
     * one every engine accepts; a bare `h2` is not.
     *
     * @param {'h2'|'h3'|'p'} tag
     * @returns {void}
     */
    function formatBlockByEngine(tag) {
        document.execCommand('formatBlock', false, '<' + tag + '>');
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

                // The command acts on the selection, wherever it is: with the
                // caret left in another editor of the same form — or in its
                // title field — this button would format, or link, that one.
                // Focusing first brings the selection here; a selection
                // already here is kept as is.
                if (!selectionIsIn(surface)) {
                    surface.focus();
                }

                if (command === 'createLink') {
                    insertLink(surface).then(function () {
                        if (afterCommand) afterCommand();
                    });
                    return;
                }

                var moved = null;
                var tag = blockTagFor(button.dataset.value);
                if (command === 'formatBlock') {
                    // In a list item, never the engine's formatBlock: it
                    // wraps the whole list (#885, above).
                    moved = listItemsToBlocks(surface, tag);
                    if (!moved) {
                        // The argument editable.js never passed at all — it
                        // sent null, and a formatBlock with no block to
                        // format is a button that does nothing.
                        formatBlockByEngine(tag);
                    }
                } else {
                    document.execCommand(command || '', false, null);
                }

                surface.focus();
                if (moved) {
                    // After the focus, which may itself move the caret.
                    var selection = window.getSelection();
                    selection?.removeAllRanges();
                    selection?.addRange(moved.range);
                    if (moved.engine) {
                        // What the selection held outside the list — no
                        // list item left in it now for the engine to wrap.
                        formatBlockByEngine(tag);
                    }
                }
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
