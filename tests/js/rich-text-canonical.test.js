// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// public/assets/js/rich-text-link.js (imported below, never reimplemented):
// the canonical form of issue #844, and the paste that uses it.
//
// The fixtures are shared with tests/Core/Security/
// RichTextCanonicalContractTest.php, which holds the server's half of the
// same contract: whatever the editors write, HtmlSanitizer keeps byte for
// byte. Here, the editors' half — that a paste from Word, Google Docs or a
// web page comes out as the toolbar's own HTML, and that the canonical form
// maps onto itself.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { beforeEach, describe, expect, it, vi } from 'vitest';

const fixtures = JSON.parse(
    readFileSync(resolve(__dirname, '../fixtures/rich-text/canonical-paste.json'), 'utf8')
).cases;

async function loadHelper() {
    vi.resetModules();
    await import('../../public/assets/js/rich-text-link.js');
    return window.ScoutMagicRichText;
}

/**
 * A paste event as a browser fires it. jsdom has no ClipboardEvent
 * constructor that takes a clipboardData, so the property is put on a
 * plain cancelable event — which is all the handler reads.
 *
 * @param {Record<string, string>} data type → content
 */
function pasteEvent(data) {
    const event = new Event('paste', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'clipboardData', {
        value: {
            types: Object.keys(data),
            getData: (type) => {
                // The clipboard's HTML is never read as a string — the
                // browser pastes it into the bin instead. A read here would
                // be the CodeQL sink the bin exists to avoid.
                if (type === 'text/html') throw new Error('the HTML was read as a string');
                return data[type] ?? '';
            },
        },
    });
    return event;
}

/**
 * Pastes HTML the way a browser does once the handler has moved the caret
 * into the bin: the default action writes the clipboard there, then the
 * handler's timer reads it.
 *
 * @param {HTMLElement} surface
 * @param {string} html what the browser's own paste leaves in the bin
 * @param {string} [text]
 * @returns {Promise<Event>}
 */
async function pasteHtml(surface, html, text = '') {
    const event = pasteEvent({ 'text/html': html, 'text/plain': text });
    surface.dispatchEvent(event);
    const bin = surface.nextElementSibling;
    expect(bin?.classList.contains('rich-text-paste-bin')).toBe(true);
    expect(document.activeElement).toBe(bin);
    bin.innerHTML = html;
    await new Promise((r) => setTimeout(r, 0));
    return event;
}

/** A contenteditable with the caret at its end. */
function surfaceWith(html) {
    const surface = document.createElement('div');
    surface.contentEditable = 'true';
    surface.innerHTML = html;
    document.body.appendChild(surface);

    const range = document.createRange();
    range.selectNodeContents(surface);
    range.collapse(false);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);

    return surface;
}

let execCommand;

beforeEach(() => {
    document.body.innerHTML = '';
    // jsdom implements no editing command: by default insertHTML answers
    // false, as an engine without it would, and the fallback inserts.
    execCommand = vi.fn(() => false);
    document.execCommand = execCommand;
});

describe('ScoutMagicRichText.canonicalHtml() — the shared fixtures', () => {
    it.each(fixtures.map((c) => [c.name, c]))('%s', async (_name, fixture) => {
        const rt = await loadHelper();

        expect(rt.canonicalHtml(fixture.pasted)).toBe(fixture.canonical);
    });

    it.each(fixtures.map((c) => [c.name, c]))('is idempotent: %s', async (_name, fixture) => {
        const rt = await loadHelper();

        // « Une seconde ou dixième normalisation doit être strictement
        // idempotente » — the canonical form is a fixed point.
        const once = rt.canonicalHtml(fixture.pasted);
        expect(rt.canonicalHtml(once)).toBe(once);
        expect(rt.canonicalHtml(rt.canonicalHtml(once))).toBe(once);
    });
});

describe('ScoutMagicRichText.canonicalHtml() — what the toolbar itself writes', () => {
    it('gives the Bold button and a pasted styled span the same HTML', async () => {
        const rt = await loadHelper();

        // Chromium's execCommand('bold') writes <b>; a word processor writes
        // a styled span. One look, one HTML, once it leaves the editor.
        const fromButton = rt.canonicalHtml('<p><b>Bonjour</b></p>');
        const fromPaste = rt.canonicalHtml('<p><span style="font-weight:bold">Bonjour</span></p>');

        expect(fromButton).toBe('<p><strong>Bonjour</strong></p>');
        expect(fromPaste).toBe(fromButton);
    });

    it('treats every editing command of the shared toolbar as already canonical once mapped', async () => {
        const rt = await loadHelper();

        expect(rt.canonicalHtml('<h2>Titre</h2><p><i>a</i> <u>b</u></p><ul><li>x</li></ul><ol><li>y</li></ol>'))
            .toBe('<h2>Titre</h2><p><em>a</em> <u>b</u></p><ul><li>x</li></ul><ol><li>y</li></ol>');
    });
});

describe('ScoutMagicRichText.canonicalHtml() — images, only where the surface has the button', () => {
    it('drops an image on a surface without an image button', async () => {
        const rt = await loadHelper();

        expect(rt.canonicalHtml('<p>a<img src="https://example.be/x.png" alt="x">b</p>')).toBe('<p>ab</p>');
    });

    it('keeps a web image, with its alt and plain-number size, where images are part of the grammar', async () => {
        const rt = await loadHelper();

        expect(rt.canonicalHtml(
            '<p><img src="/files/7" alt="Camp" width="640" height="480px" style="float:left" class="x"></p>',
            { images: true }
        )).toBe('<p><img src="/files/7" alt="Camp" width="640"></p>');
    });

    it('refuses an image source the server would refuse too', async () => {
        const rt = await loadHelper();

        expect(rt.canonicalHtml('<p><img src="data:image/png;base64,AAAA">x</p>', { images: true })).toBe('<p>x</p>');
        expect(rt.canonicalHtml('<p><img src="javascript:alert(1)">x</p>', { images: true })).toBe('<p>x</p>');
    });
});

describe('ScoutMagicRichText.canonicalHtml() — siteImages', () => {
    it('keeps one of the site\'s own images, so a cut-and-paste inside the text does not lose it', async () => {
        const rt = await loadHelper();

        expect(rt.canonicalHtml('<p><img src="/files/3" alt="Camp"></p>', { siteImages: true }))
            .toBe('<p><img src="/files/3" alt="Camp"></p>');
    });

    it('still refuses an image from another site, protocol-relative ones included', async () => {
        const rt = await loadHelper();

        expect(rt.canonicalHtml('<p><img src="https://ailleurs.be/x.png">a</p>', { siteImages: true })).toBe('<p>a</p>');
        expect(rt.canonicalHtml('<p><img src="//ailleurs.be/x.png">b</p>', { siteImages: true })).toBe('<p>b</p>');
        // A browser reads a backslash after the first slash as a slash.
        expect(rt.canonicalHtml('<p><img src="/\\ailleurs.be/x.png">c</p>', { siteImages: true })).toBe('<p>c</p>');
    });
});

describe('ScoutMagicRichText.canonicalHtml() — a stored text keeps what the server accepted', () => {
    it('keeps an <h4> and a quotation that no button makes, but a paste flattens them', async () => {
        const rt = await loadHelper();
        const stored = '<h4>Petit titre</h4><blockquote><p>Cité</p><p><b>Encore</b></p></blockquote><p>Fin</p>';

        // Opening and saving again must not change what the author did not touch.
        expect(rt.canonicalHtml(stored, { stored: true }))
            .toBe('<h4>Petit titre</h4><blockquote><p>Cité</p><p><strong>Encore</strong></p></blockquote><p>Fin</p>');
        // A paste is held to the toolbar's own gestures.
        expect(rt.canonicalHtml(stored)).toBe('<h3>Petit titre</h3><p>Cité</p><p><strong>Encore</strong></p><p>Fin</p>');
    });

    it('is idempotent in that mode too, and gives loose quoted text a paragraph', async () => {
        const rt = await loadHelper();
        const once = rt.canonicalHtml('<blockquote>Une citation</blockquote><img src="/files/2" alt="x">', { stored: true });

        expect(once).toBe('<blockquote><p>Une citation</p></blockquote><p><img src="/files/2" alt="x"></p>');
        expect(rt.canonicalHtml(once, { stored: true })).toBe(once);
    });
});

describe('ScoutMagicRichText.canonicalFragment()', () => {
    it('builds nodes of the live document and never runs what it reads', async () => {
        const rt = await loadHelper();
        window.__pasted = false;

        const fragment = rt.canonicalFragment('<img src="x" onerror="window.__pasted = true"><p onclick="x()">texte</p>');
        const holder = document.createElement('div');
        holder.appendChild(fragment);

        expect(holder.innerHTML).toBe('<p>texte</p>');
        expect(holder.firstChild.ownerDocument).toBe(document);
        await new Promise((r) => setTimeout(r, 0));
        expect(window.__pasted).toBe(false);
    });
});

describe('ScoutMagicRichText.wireSurface() — the paste', () => {
    it('rebuilds the clipboard HTML and inserts it with insertHTML, on the undo stack', async () => {
        const rt = await loadHelper();
        execCommand.mockImplementation(() => true);
        const surface = surfaceWith('<p>Avant</p>');
        rt.wireSurface(surface);

        const event = await pasteHtml(
            surface,
            '<h1 style="color:red">Titre</h1><p><span style="font-weight:700">gras</span></p>',
            'Titre\n\ngras'
        );

        // The browser's own paste is let through — into the bin — and
        // only its nodes are read.
        expect(event.defaultPrevented).toBe(false);
        expect(execCommand).toHaveBeenCalledWith('insertHTML', false, '<h2>Titre</h2><p><strong>gras</strong></p>');
        expect(surface.nextElementSibling.innerHTML).toBe('');
    });

    it('inserts a lone paragraph as inline content, so a word does not split the line it lands in', async () => {
        const rt = await loadHelper();
        execCommand.mockImplementation(() => true);
        const surface = surfaceWith('<p>Avant</p>');
        rt.wireSurface(surface);

        await pasteHtml(surface, '<span style="font-style:italic">mot</span>');

        expect(execCommand).toHaveBeenCalledWith('insertHTML', false, '<em>mot</em>');
    });

    it('falls back to inserting the same nodes where insertHTML is not available', async () => {
        const rt = await loadHelper();
        const surface = surfaceWith('<p>Avant</p>');
        rt.wireSurface(surface);

        await pasteHtml(surface, '<ul><li><b>un</b></li></ul>');

        expect(surface.innerHTML).toBe('<p>Avant</p><ul><li><strong>un</strong></li></ul>');
    });

    it('turns plain text into paragraphs and line breaks', async () => {
        const rt = await loadHelper();
        const surface = surfaceWith('');
        rt.wireSurface(surface);

        surface.dispatchEvent(pasteEvent({ 'text/plain': 'Un\nDeux\r\n\r\nTrois <b>pas une balise</b>' }));

        expect(surface.innerHTML).toBe('<p>Un<br>Deux</p><p>Trois &lt;b&gt;pas une balise&lt;/b&gt;</p>');
    });

    it('leaves a clipboard with neither HTML nor text to the browser', async () => {
        const rt = await loadHelper();
        const surface = surfaceWith('');
        rt.wireSurface(surface);

        const event = pasteEvent({});
        surface.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });

    it('announces the change, so a form field syncs its hidden input', async () => {
        const rt = await loadHelper();
        const surface = surfaceWith('');
        const afterChange = vi.fn();
        const onInput = vi.fn();
        surface.addEventListener('input', onInput);
        rt.wireSurface(surface, {}, afterChange);

        surface.dispatchEvent(pasteEvent({ 'text/plain': 'x' }));

        expect(onInput).toHaveBeenCalledTimes(1);
        expect(afterChange).toHaveBeenCalledTimes(1);
    });

    it('lets the surface decorate the pasted fragment before it lands', async () => {
        const rt = await loadHelper();
        const surface = surfaceWith('');
        rt.wireSurface(surface, {
            decorate: (container) => {
                container.querySelectorAll('strong').forEach((s) => s.setAttribute('data-seen', 'yes'));
            },
        });

        await pasteHtml(surface, '<p><b>a</b></p><p>b</p>');

        expect(surface.innerHTML).toBe('<p><strong data-seen="yes">a</strong></p><p>b</p>');
    });

    it('wires a surface once, however many scripts ask', async () => {
        const rt = await loadHelper();
        execCommand.mockImplementation(() => true);
        const surface = surfaceWith('');
        rt.wireSurface(surface);
        rt.wireSurface(surface);
        rt.wireToolbar(document.body, surface);

        surface.dispatchEvent(pasteEvent({ 'text/plain': 'x' }));

        expect(execCommand.mock.calls.filter((c) => c[0] === 'insertHTML')).toHaveLength(1);
    });
});
