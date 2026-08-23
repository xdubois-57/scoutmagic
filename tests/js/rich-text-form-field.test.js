// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network. Exercises the REAL public/assets/js/rich-text-form-field.js
// (imported below, never reimplemented) — the only file in the rich-text
// family that exports its logic as an ES module rather than hiding it in an
// IIFE, so the placeholder round-trip can be tested directly.
//
// That round-trip is the whole reason the file exists: a contenteditable
// surface will happily split `{{ prix_total }}` across elements as it is
// edited, turning a keyword into something that reads fine to a human and
// substitutes to nothing on the server — noticed only once a contract goes
// out with visible braces in it. Chips make a placeholder one indivisible
// character; toStoredHtml() turns them back into source. Both halves are
// pinned here.
//
// The toolbar's createLink branch is deliberately not pinned: it still
// opens a native prompt() for the URL — the last native dialog in this file
// — and asserting it would lock in behaviour waiting on a replacement
// component (design.md §7.5).
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    chipHtml,
    insertKeyword,
    keywordOf,
    toEditorHtml,
    toStoredHtml,
    wireField,
} from '../../public/assets/js/rich-text-form-field.js';

const KEYWORDS = ['prix_total', 'nom_locataire'];

/**
 * One `.rich-text-form-field` block as partials/rich_text_field.html.twig
 * renders it: a server-filled hidden input, an aria-hidden surface, and the
 * form around them.
 *
 * @param {string} value the stored HTML the server rendered into the input
 * @returns {HTMLElement} the block root
 */
function buildField(value) {
    document.body.innerHTML = `
        <form>
            <div class="rich-text-form-field" data-keywords='${JSON.stringify(KEYWORDS)}'>
                <button type="button" data-command="bold">B</button>
                <button type="button" data-insert-keyword="prix_total">Prix total</button>
                <div class="rich-text-form-surface" aria-hidden="true"></div>
                <input type="hidden" class="rich-text-form-value" value="${value}">
            </div>
        </form>
    `;
    const root = /** @type {HTMLElement} */ (document.querySelector('.rich-text-form-field'));
    wireField(root);
    return root;
}

function surface() {
    return /** @type {HTMLElement} */ (document.querySelector('.rich-text-form-surface'));
}

function input() {
    return /** @type {HTMLInputElement} */ (document.querySelector('.rich-text-form-value'));
}

/**
 * Pretend the caret is nowhere in the document. jsdom's focus() on a
 * contenteditable leaves a selection inside it, which a real browser only
 * does once the author has actually clicked there — so which branch of
 * insertKeyword() runs is stated by the test rather than inherited from
 * jsdom's focus behaviour.
 */
function caretNowhere() {
    vi.spyOn(window, 'getSelection').mockReturnValue(/** @type {any} */ ({ rangeCount: 0 }));
}

/**
 * Pretend the caret sits inside `node`.
 *
 * @param {Node} node
 */
function caretIn(node) {
    const range = document.createRange();
    range.selectNodeContents(node);
    vi.spyOn(window, 'getSelection').mockReturnValue(/** @type {any} */ ({
        rangeCount: 1,
        getRangeAt: () => range,
    }));
}

beforeEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    // jsdom implements no editing commands at all — every execCommand call
    // in this file is a browser-side effect this suite does not own.
    document.execCommand = vi.fn(() => true);
});

describe('rich-text-form-field.js: keywordOf()', () => {
    it('reads the keyword off a chip', () => {
        const div = document.createElement('div');
        div.innerHTML = chipHtml('prix_total');
        expect(keywordOf(div.firstElementChild)).toBe('prix_total');
    });

    it('answers null for an ordinary element, a text node and nothing at all', () => {
        expect(keywordOf(document.createElement('span'))).toBeNull();
        expect(keywordOf(/** @type {any} */ (document.createTextNode('prix_total')))).toBeNull();
        expect(keywordOf(/** @type {any} */ (null))).toBeNull();
    });
});

describe('rich-text-form-field.js: chipHtml()', () => {
    it('marks the chip uneditable — the attribute that makes it one character', () => {
        expect(chipHtml('prix_total')).toContain('contenteditable="false"');
    });

    it('shows the placeholder itself, so what the author sees is what is substituted', () => {
        const div = document.createElement('div');
        div.innerHTML = chipHtml('prix_total');
        expect(div.textContent).toBe('{{ prix_total }}');
    });

    it('strips quotes and angle brackets out of the keyword it writes into the attribute', () => {
        expect(chipHtml('a"b<c>&d')).toContain('data-keyword="abcd"');
    });
});

describe('rich-text-form-field.js: toEditorHtml() — stored source to chips', () => {
    it('turns every known placeholder into a chip', () => {
        const html = toEditorHtml('<p>Bonjour {{ nom_locataire }}, total {{ prix_total }}.</p>', KEYWORDS);
        const div = document.createElement('div');
        div.innerHTML = html;
        expect(div.querySelectorAll('[data-keyword]')).toHaveLength(2);
    });

    it('tolerates missing and extra spacing inside the braces', () => {
        const div = document.createElement('div');
        div.innerHTML = toEditorHtml('{{prix_total}} et {{   prix_total   }}', KEYWORDS);
        expect(div.querySelectorAll('[data-keyword]')).toHaveLength(2);
    });

    it('leaves something that only LOOKS like a placeholder as plain text on purpose', () => {
        // Dressing an unknown keyword up as a chip would hide a mistake the
        // author has to see and fix.
        const html = toEditorHtml('<p>{{ prix_totale }}</p>', KEYWORDS);
        expect(html).toBe('<p>{{ prix_totale }}</p>');
    });
});

describe('rich-text-form-field.js: toStoredHtml() — chips back to source', () => {
    it('writes each chip back as its normalised {{ keyword }}', () => {
        const div = document.createElement('div');
        div.innerHTML = 'Total : ' + chipHtml('prix_total');
        expect(toStoredHtml(div)).toBe('Total : {{ prix_total }}');
    });

    it('normalises a placeholder typed by hand with stray spacing', () => {
        const div = document.createElement('div');
        div.innerHTML = '<p>{{prix_total}} · {{   nom_locataire   }}</p>';
        expect(toStoredHtml(div)).toBe('<p>{{ prix_total }} · {{ nom_locataire }}</p>');
    });

    it('never disturbs the visible surface it reads — it works on a clone', () => {
        const div = document.createElement('div');
        div.innerHTML = chipHtml('prix_total');
        toStoredHtml(div);
        expect(div.querySelectorAll('[data-keyword]')).toHaveLength(1);
    });

    it('round-trips: source → editor → source is the identity', () => {
        const source = '<p>Bonjour <b>{{ nom_locataire }}</b>, total {{ prix_total }}.</p>';
        const div = document.createElement('div');
        div.innerHTML = toEditorHtml(source, KEYWORDS);
        expect(toStoredHtml(div)).toBe(source);
    });
});

describe('rich-text-form-field.js: wireField()', () => {
    it('paints the stored value as chips and hands the surface over to the author', () => {
        buildField('Bonjour {{ nom_locataire }}.');

        expect(surface().querySelectorAll('[data-keyword]')).toHaveLength(1);
        expect(surface().getAttribute('contenteditable')).toBe('true');
        // aria-hidden marks "not wired yet"; leaving it on would hide the
        // whole editor from a screen reader.
        expect(surface().hasAttribute('aria-hidden')).toBe(false);
    });

    it('leaves the block alone when its surface or hidden input is missing', () => {
        document.body.innerHTML = '<div class="rich-text-form-field"><div class="rich-text-form-surface"></div></div>';
        const root = /** @type {HTMLElement} */ (document.querySelector('.rich-text-form-field'));
        expect(() => wireField(root)).not.toThrow();
        expect(surface().getAttribute('contenteditable')).toBeNull();
    });

    it('survives a malformed data-keywords rather than refusing to wire', () => {
        document.body.innerHTML = `
            <div class="rich-text-form-field" data-keywords="{pas du json">
                <div class="rich-text-form-surface"></div>
                <input type="hidden" class="rich-text-form-value" value="{{ prix_total }}">
            </div>
        `;
        wireField(/** @type {HTMLElement} */ (document.querySelector('.rich-text-form-field')));

        expect(surface().getAttribute('contenteditable')).toBe('true');
        // No known keywords: the placeholder stays plain text.
        expect(surface().querySelectorAll('[data-keyword]')).toHaveLength(0);
    });

    it('keeps the hidden input in step on input and on blur', () => {
        buildField('');

        surface().innerHTML = 'Total ' + chipHtml('prix_total');
        surface().dispatchEvent(new Event('input'));
        expect(input().value).toBe('Total {{ prix_total }}');

        surface().innerHTML = 'Rien';
        surface().dispatchEvent(new Event('blur'));
        expect(input().value).toBe('Rien');
    });

    it('syncs on submit — an author who inserts a chip and hits Enter never fires blur', () => {
        buildField('');

        surface().innerHTML = chipHtml('nom_locataire');
        // No input/blur event at all: submit is the last guarantee.
        expect(input().value).toBe('');

        const form = document.querySelector('form');
        form.addEventListener('submit', (e) => e.preventDefault());
        form.dispatchEvent(new Event('submit', { cancelable: true }));

        expect(input().value).toBe('{{ nom_locataire }}');
    });

    it('inserts a chip from the toolbar and syncs the hidden input in one go', () => {
        buildField('');
        caretNowhere();

        document.querySelector('[data-insert-keyword]').dispatchEvent(new Event('click'));

        expect(surface().querySelectorAll('[data-keyword]')).toHaveLength(1);
        expect(input().value).toContain('{{ prix_total }}');
    });

    it('runs a plain toolbar command against the surface and syncs afterwards', () => {
        buildField('Texte');

        document.querySelector('[data-command="bold"]').dispatchEvent(new Event('click'));

        expect(document.execCommand).toHaveBeenCalledWith('bold', false, null);
        expect(input().value).toBe('Texte');
    });
});

describe('rich-text-form-field.js: insertKeyword()', () => {
    it('appends at the end when the caret is not in the surface', () => {
        buildField('Début.');
        // The chip goes to the end rather than into whatever element the
        // caret happens to sit in elsewhere on the page.
        caretNowhere();

        insertKeyword(surface(), 'prix_total');

        expect(surface().textContent).toContain('Début.');
        expect(surface().lastElementChild.getAttribute('data-keyword')).toBe('prix_total');
        expect(document.execCommand).not.toHaveBeenCalled();
    });

    it('appends at the end when the caret sits in some OTHER element', () => {
        buildField('Début.');
        caretIn(document.querySelector('form'));

        insertKeyword(surface(), 'prix_total');

        expect(surface().lastElementChild.getAttribute('data-keyword')).toBe('prix_total');
    });

    it('announces itself with an input event so the hidden input catches up', () => {
        buildField('');
        caretNowhere();
        const seen = vi.fn();
        surface().addEventListener('input', seen);

        insertKeyword(surface(), 'prix_total');

        expect(seen).toHaveBeenCalled();
    });

    it('goes through execCommand when the caret IS inside the surface', () => {
        buildField('Texte');
        caretIn(surface());

        insertKeyword(surface(), 'nom_locataire');

        expect(document.execCommand)
            .toHaveBeenCalledWith('insertHTML', false, expect.stringContaining('nom_locataire'));
        // The caret's own position decides where it lands — nothing is
        // appended behind the author's back.
        expect(surface().querySelectorAll('[data-keyword]')).toHaveLength(0);
    });
});

describe('rich-text-form-field.js: DOMContentLoaded wiring', () => {
    it('wires every block on the page once the document is ready', async () => {
        document.body.innerHTML = `
            <div class="rich-text-form-field" data-keywords='["prix_total"]'>
                <div class="rich-text-form-surface" aria-hidden="true"></div>
                <input type="hidden" class="rich-text-form-value" value="{{ prix_total }}">
            </div>
            <div class="rich-text-form-field" data-keywords='["prix_total"]'>
                <div class="rich-text-form-surface" aria-hidden="true"></div>
                <input type="hidden" class="rich-text-form-value" value="Rien">
            </div>
        `;
        vi.resetModules();
        await import('../../public/assets/js/rich-text-form-field.js');
        document.dispatchEvent(new Event('DOMContentLoaded'));

        const surfaces = document.querySelectorAll('.rich-text-form-surface');
        expect(surfaces[0].getAttribute('contenteditable')).toBe('true');
        expect(surfaces[0].querySelectorAll('[data-keyword]')).toHaveLength(1);
        expect(surfaces[1].getAttribute('contenteditable')).toBe('true');
    });
});
