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
    if (!node || node.nodeType !== 1) {
        return null;
    }

    const keyword = /** @type {HTMLElement} */ (node).dataset
        ? /** @type {HTMLElement} */ (node).dataset.keyword
        : null;

    return keyword ? keyword : null;
}

/**
 * The HTML a chip is rendered as.
 *
 * `contenteditable="false"` is the load-bearing attribute: it is what makes
 * the browser treat the whole chip as one character. The visible text is
 * the placeholder itself, so what the author sees is what the document will
 * substitute.
 *
 * @param {string} keyword
 * @returns {string}
 */
export function chipHtml(keyword) {
    return '<span class="doc-keyword" contenteditable="false" data-keyword="'
        + keyword.replace(/["&<>]/g, '') + '">{{ ' + keyword + ' }}</span>';
}

/**
 * Stored source (with `{{ keyword }}` in it) → editor HTML (with chips).
 *
 * Only the closed list is turned into a chip. Something that LOOKS like a
 * placeholder but is not on the list stays plain text on purpose: it is a
 * mistake the author has to see and fix, and dressing it up as a chip would
 * hide it.
 *
 * @param {string} html
 * @param {string[]} knownKeywords
 * @returns {string}
 */
export function toEditorHtml(html, knownKeywords) {
    return String(html).replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/g, function (match, keyword) {
        return knownKeywords.indexOf(keyword) === -1 ? match : chipHtml(keyword);
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

    surface.innerHTML = toEditorHtml(input.value, known);
    surface.setAttribute('contenteditable', 'true');
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
