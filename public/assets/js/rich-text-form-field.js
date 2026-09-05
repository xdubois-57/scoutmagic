/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Generic reusable "rich text INSIDE a form" wiring — the third member of
// the family described in core/View/templates/partials/rich_text_field
// .html.twig, alongside the preview/edit-button pair.
//
// Why a third one. The existing pair edits a value that belongs to the
// generic editable-content store and saves it over fetch() to a fixed
// endpoint. That is the wrong shape whenever the text is a FIELD of
// something a module owns — a rental contract template, say — which is
// saved together with the rest of its form, to the module's own route,
// under the module's own permission check. Those pages were writing raw
// HTML into a <textarea> instead, which is not something anybody should be
// asked to do.
//
// What it does: keeps a contenteditable surface and a hidden input in step,
// and wires the same [data-command] toolbar the shared modal uses.
//
// PLACEHOLDER CHIPS
// ---------------------------------------------------------------------
// A contenteditable surface happily splits a run of text across elements as
// it is edited, so a `{{ prix_total }}` typed by hand can silently become
// `{{ pri<b>x</b>_total }}` — still readable to a human, no longer a
// keyword to the substituter, and only noticed once a contract goes out
// with visible braces in it. That is a real hazard, and it is why these
// editors used to be textareas.
//
// The answer is not to forbid rich text but to stop placeholders being
// ordinary text: each one renders as a single `contenteditable="false"`
// chip that the browser treats as one indivisible character. It cannot be
// split, half-deleted, or formatted from the inside. On submit every chip
// is turned back into its `{{ keyword }}` source, and any placeholder a
// human typed by hand is normalised the same way.
//
// WITHOUT JAVASCRIPT
// ---------------------------------------------------------------------
// The hidden input is rendered by the server already holding the current
// value, so a form submitted before this script runs saves what was already
// there rather than blanking it. The surface is marked aria-hidden until
// wired, and the page carries a <noscript> saying so.

/**
 * The keyword a chip stands for, or null for anything that is not one.
 *
 * @param {Element} node
 * @returns {string|null}
 */
export function keywordOf(node) {
    if (node?.nodeType !== 1) {
        return null;
    }

    const keyword = /** @type {HTMLElement} */ (node).dataset
        ? /** @type {HTMLElement} */ (node).dataset.keyword
        : null;

    return keyword || null;
}

/**
 * The element a chip is rendered as.
 *
 * `contenteditable="false"` is the load-bearing attribute: it is what makes
 * the browser treat the whole chip as one character. The visible text is
 * the placeholder itself, so what the author sees is what the document will
 * substitute.
 *
 * @param {string} keyword
 * @returns {HTMLElement}
 */
export function chipElement(keyword) {
    const chip = document.createElement('span');
    chip.className = 'doc-keyword';
    chip.setAttribute('contenteditable', 'false');
    chip.dataset.keyword = keyword.replace(/["&<>]/g, '');
    chip.textContent = '{{ ' + keyword + ' }}';

    return chip;
}

/**
 * The same chip, as the HTML string `execCommand('insertHTML')` needs.
 *
 * Built from the element rather than concatenated, so the visible text is
 * escaped by the DOM and never by hand.
 *
 * @param {string} keyword
 * @returns {string}
 */
export function chipHtml(keyword) {
    return chipElement(keyword).outerHTML;
}

/**
 * Turn every `{{ keyword }}` a text node carries into a chip, in place.
 *
 * @param {Text} text
 * @param {string[]} knownKeywords
 * @returns {void}
 */
function chipifyTextNode(text, knownKeywords) {
    const source = text.nodeValue || '';
    const pattern = /\{\{\s*([a-z0-9_]+)\s*\}\}/g;
    const chipped = document.createDocumentFragment();
    let cut = 0;
    let match = pattern.exec(source);

    while (match !== null) {
        if (knownKeywords.includes(match[1])) {
            if (match.index > cut) {
                chipped.appendChild(document.createTextNode(source.slice(cut, match.index)));
            }
            chipped.appendChild(chipElement(match[1]));
            cut = match.index + match[0].length;
        }
        match = pattern.exec(source);
    }

    if (cut === 0) {
        return;
    }

    if (cut < source.length) {
        chipped.appendChild(document.createTextNode(source.slice(cut)));
    }

    text.replaceWith(chipped);
}

/**
 * Stored source (with `{{ keyword }}` in it) → editor content (with chips),
 * rewriting the element's TEXT and never re-parsing its markup.
 *
 * Going through the DOM rather than through an HTML string is what keeps
 * the author's own text out of the parser: the server has already rendered
 * this value into the surface, and reading it back out as markup to write
 * it in again would reinterpret whatever it contains a second time, for no
 * gain.
 *
 * Only the closed list is turned into a chip. Something that LOOKS like a
 * placeholder but is not on the list stays plain text on purpose: it is a
 * mistake the author has to see and fix, and dressing it up as a chip would
 * hide it.
 *
 * @param {HTMLElement} root
 * @param {string[]} knownKeywords
 * @returns {void}
 */
export function chipify(root, knownKeywords) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    /** @type {Text[]} */
    const texts = [];

    for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
        const parent = (/** @type {Text} */ (node)).parentElement;
        // A chip's own text reads as a placeholder; walking into one would
        // nest a second chip inside the first on every re-wire.
        if (parent && !parent.closest('[data-keyword]')) {
            texts.push(/** @type {Text} */ (node));
        }
    }

    texts.forEach(function (text) {
        chipifyTextNode(text, knownKeywords);
    });
}

/**
 * Editor HTML (with chips) → the source to store.
 *
 * Works on a detached clone so the visible surface is never disturbed by
 * being saved — an editor that reflowed under the caret on every keystroke
 * would be unusable.
 *
 * @param {HTMLElement} surface
 * @returns {string}
 */
export function toStoredHtml(surface) {
    const clone = /** @type {HTMLElement} */ (surface.cloneNode(true));

    clone.querySelectorAll('[data-keyword]').forEach(function (chip) {
        const keyword = keywordOf(chip);
        if (keyword) {
            chip.replaceWith(document.createTextNode('{{ ' + keyword + ' }}'));
        }
    });

    // A placeholder typed by hand rather than inserted: normalise its
    // spacing so it matches the server's pattern exactly, instead of
    // failing to substitute over a stray double space.
    return clone.innerHTML.replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/g, '{{ $1 }}');
}

/**
 * Insert a chip at the caret, or at the end when the caret is elsewhere.
 *
 * @param {HTMLElement} surface
 * @param {string} keyword
 * @returns {void}
 */
export function insertKeyword(surface, keyword) {
    surface.focus();

    const selection = window.getSelection();
    const inSurface = selection
        && selection.rangeCount > 0
        && surface.contains(selection.getRangeAt(0).commonAncestorContainer);

    if (!inSurface) {
        surface.insertAdjacentHTML('beforeend', chipHtml(keyword) + '&nbsp;');
        surface.dispatchEvent(new Event('input', { bubbles: true }));

        return;
    }

    document.execCommand('insertHTML', false, chipHtml(keyword) + '&nbsp;');
    surface.dispatchEvent(new Event('input', { bubbles: true }));
}

/**
 * Wire one `.rich-text-form-field` block.
 *
 * @param {HTMLElement} root
 * @returns {void}
 */
export function wireField(root) {
    const surface = /** @type {HTMLElement|null} */ (root.querySelector('.rich-text-form-surface'));
    const input = /** @type {HTMLInputElement|null} */ (root.querySelector('.rich-text-form-value'));
    if (!surface || !input) {
        return;
    }

    let known = [];
    try {
        known = JSON.parse(root.dataset.keywords || '[]');
    } catch (e) {
        known = [];
    }

    // The surface already holds the value: the server rendered it there.
    // All that is missing is the chips.
    chipify(surface, known);
    surface.setAttribute('contenteditable', 'true');
    // Announced as an editable multi-line box only now that it is one.
    surface.setAttribute('role', 'textbox');
    surface.setAttribute('aria-multiline', 'true');
    surface.removeAttribute('aria-hidden');

    function sync() {
        input.value = toStoredHtml(surface);
    }

    surface.addEventListener('input', sync);
    surface.addEventListener('blur', sync);

    // The submit listener is what actually guarantees the value is current:
    // an author who inserts a chip and hits Enter never fires blur.
    const form = root.closest('form');
    if (form) {
        form.addEventListener('submit', sync);
    }

    root.querySelectorAll('[data-command]').forEach(function (button) {
        button.addEventListener('click', function () {
            const command = /** @type {HTMLElement} */ (button).dataset.command;
            if (command === 'createLink') {
                // Shared: captures the selection, asks, normalizes the URL
                // and gives focus back. See rich-text-link.js. sync() runs
                // after the dialog, not before it, or the inserted link
                // would never reach the hidden field.
                window.ScoutMagicRichText.insertLink(surface).then(sync);
                return;
            }
            if (command === 'formatBlock') {
                document.execCommand(command, false, '<' + /** @type {HTMLElement} */ (button).dataset.value + '>');
            } else {
                document.execCommand(command, false, null);
            }
            surface.focus();
            sync();
        });
    });

    root.querySelectorAll('[data-insert-keyword]').forEach(function (button) {
        button.addEventListener('click', function () {
            insertKeyword(surface, /** @type {HTMLElement} */ (button).dataset.insertKeyword || '');
            sync();
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.rich-text-form-field').forEach(function (root) {
        wireField(/** @type {HTMLElement} */ (root));
    });
});
