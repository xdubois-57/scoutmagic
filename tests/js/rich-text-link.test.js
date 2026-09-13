// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// public/assets/js/rich-text-link.js (imported below, never
// reimplemented): the file is an IIFE that installs
// window.ScoutMagicRichText at import time.
//
// This helper replaced five identical `prompt('URL du lien :')` branches
// (editable.js, rich-text-field.js, rich-text-form-field.js,
// mass-mail-list.js, news-form-builder.js). Two of the three things it
// fixed are invisible until asserted: a bare host used to become a
// relative href, and the caret used to be lost the moment a modal took
// focus.
//
// Since issue #306 it also owns the toolbar and the paste, for the same
// reason: three copies of the toolbar disagreed about `data-value`, and two
// of them wired the same modal at once. The allowlist this file's cleanHtml()
// applies is pinned against the server's by
// tests/Core/Security/RichTextAllowlistsAgreeTest.php, not here — what is
// asserted here is what cleanHtml() does with it.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadHelper() {
    vi.resetModules();
    await import('../../public/assets/js/rich-text-link.js');
    return window.ScoutMagicRichText;
}

/** Builds a contenteditable with `text` in it and selects the whole thing. */
function editorWithSelection(text) {
    const surface = document.createElement('div');
    surface.contentEditable = 'true';
    surface.textContent = text;
    document.body.appendChild(surface);

    const range = document.createRange();
    range.selectNodeContents(surface);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);

    return surface;
}

let execCommand;

beforeEach(() => {
    document.body.innerHTML = '';
    execCommand = vi.fn(() => true);
    document.execCommand = execCommand;
    window.ScoutMagicToast = { show: vi.fn() };
    window.ScoutMagicConfirm = { prompt: vi.fn(() => Promise.resolve(null)) };
});

describe('ScoutMagicRichText.normalizeUrl()', () => {
    it('completes a bare host rather than producing a relative link', async () => {
        const rt = await loadHelper();

        // The bug this replaced: <a href="lesscouts.be"> resolves against
        // the current page, so the link 404s from anywhere but the root.
        expect(rt.normalizeUrl('lesscouts.be')).toBe('https://lesscouts.be');
        expect(rt.normalizeUrl('www.lesscouts.be/unite')).toBe('https://www.lesscouts.be/unite');
    });

    it('leaves a real URL alone, whatever its allowed scheme', async () => {
        const rt = await loadHelper();

        expect(rt.normalizeUrl('https://lesscouts.be')).toBe('https://lesscouts.be');
        expect(rt.normalizeUrl('http://lesscouts.be')).toBe('http://lesscouts.be');
        expect(rt.normalizeUrl('mailto:unite@lesscouts.be')).toBe('mailto:unite@lesscouts.be');
        expect(rt.normalizeUrl('tel:+3221234567')).toBe('tel:+3221234567');
    });

    it('keeps site-relative and same-page links as written', async () => {
        const rt = await loadHelper();

        expect(rt.normalizeUrl('/finance/movements')).toBe('/finance/movements');
        expect(rt.normalizeUrl('#section-2')).toBe('#section-2');
    });

    it('reads a bare email address as an email link', async () => {
        const rt = await loadHelper();

        expect(rt.normalizeUrl('unite@lesscouts.be')).toBe('mailto:unite@lesscouts.be');
        // …but not a URL that merely contains an @.
        expect(rt.normalizeUrl('lesscouts.be/u?a=b@c')).toBe('https://lesscouts.be/u?a=b@c');
    });

    it('refuses a scheme that is not a link', async () => {
        const rt = await loadHelper();

        for (const hostile of [
            'javascript:alert(1)',
            'JavaScript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
            'file:///etc/passwd',
        ]) {
            expect(rt.normalizeUrl(hostile)).toBeNull();
        }
    });

    it('treats blank input as no answer', async () => {
        const rt = await loadHelper();

        expect(rt.normalizeUrl('')).toBeNull();
        expect(rt.normalizeUrl('   ')).toBeNull();
        expect(rt.normalizeUrl(null)).toBeNull();
    });

    it('trims what the visitor pasted', async () => {
        const rt = await loadHelper();

        expect(rt.normalizeUrl('  https://lesscouts.be  ')).toBe('https://lesscouts.be');
    });
});

describe('ScoutMagicRichText.insertLink()', () => {
    it('asks through the site dialog, never the native prompt', async () => {
        const nativePrompt = vi.fn(() => 'https://lesscouts.be');
        window.prompt = nativePrompt;
        const rt = await loadHelper();

        await rt.insertLink(editorWithSelection('Notre site'));

        expect(nativePrompt).not.toHaveBeenCalled();
        expect(window.ScoutMagicConfirm.prompt).toHaveBeenCalledTimes(1);
        expect(window.ScoutMagicConfirm.prompt.mock.calls[0][0]).toMatchObject({
            message: 'URL du lien :',
        });
    });

    it('wraps the selection once the URL is given', async () => {
        window.ScoutMagicConfirm.prompt = vi.fn(() => Promise.resolve('lesscouts.be'));
        const rt = await loadHelper();

        await expect(rt.insertLink(editorWithSelection('Notre site'))).resolves.toBe(true);
        expect(execCommand).toHaveBeenCalledWith('createLink', false, 'https://lesscouts.be');
    });

    it('restores the selection the dialog took, before creating the link', async () => {
        window.ScoutMagicConfirm.prompt = vi.fn(() => {
            // What a real modal does: focus moves, the contenteditable's
            // range is gone. Without a restore, execCommand has nothing to
            // wrap and the link is silently lost.
            window.getSelection().removeAllRanges();
            return Promise.resolve('https://lesscouts.be');
        });
        const rt = await loadHelper();
        const surface = editorWithSelection('Notre site');
        // jsdom does not make a contenteditable <div> focusable, so the
        // call is what can be observed here; the browser does the rest.
        const focus = vi.spyOn(surface, 'focus');

        await rt.insertLink(surface);

        const selection = window.getSelection();
        expect(selection.rangeCount).toBe(1);
        expect(selection.getRangeAt(0).toString()).toBe('Notre site');
        expect(focus).toHaveBeenCalled();
    });

    it('gives the caret back when the visitor changes their mind', async () => {
        window.ScoutMagicConfirm.prompt = vi.fn(() => {
            window.getSelection().removeAllRanges();
            return Promise.resolve(null);
        });
        const rt = await loadHelper();
        const surface = editorWithSelection('Notre site');
        const focus = vi.spyOn(surface, 'focus');

        await expect(rt.insertLink(surface)).resolves.toBe(false);

        expect(execCommand).not.toHaveBeenCalled();
        expect(window.getSelection().rangeCount).toBe(1);
        expect(focus).toHaveBeenCalled();
    });

    it('says why a refused link was refused, instead of doing nothing', async () => {
        window.ScoutMagicConfirm.prompt = vi.fn(() => Promise.resolve('javascript:alert(1)'));
        const rt = await loadHelper();

        await expect(rt.insertLink(editorWithSelection('Cliquez ici'))).resolves.toBe(false);

        expect(execCommand).not.toHaveBeenCalled();
        expect(window.ScoutMagicToast.show).toHaveBeenCalledTimes(1);
        expect(window.ScoutMagicToast.show.mock.calls[0][1]).toEqual({ variant: 'error' });
    });

    it('stays quiet when the field was simply left empty', async () => {
        window.ScoutMagicConfirm.prompt = vi.fn(() => Promise.resolve('   '));
        const rt = await loadHelper();

        await expect(rt.insertLink(editorWithSelection('Notre site'))).resolves.toBe(false);

        // Confirming an empty field is a change of mind, not an error.
        expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
        expect(execCommand).not.toHaveBeenCalled();
    });

    it('works without an editor element to focus', async () => {
        window.ScoutMagicConfirm.prompt = vi.fn(() => Promise.resolve('https://lesscouts.be'));
        const rt = await loadHelper();
        editorWithSelection('Notre site');

        await expect(rt.insertLink(null)).resolves.toBe(true);
        expect(execCommand).toHaveBeenCalledWith('createLink', false, 'https://lesscouts.be');
    });
});

describe('ScoutMagicRichText.wireToolbar()', () => {
    /** The shared modal's markup, reduced to what the toolbar needs. */
    function toolbar() {
        document.body.innerHTML = `
            <div id="modal">
                <button data-command="bold">B</button>
                <button data-command="formatBlock" data-value="h2">H2</button>
                <button data-command="formatBlock">P</button>
                <button data-command="createLink">Lien</button>
            </div>
            <div id="surface" contenteditable="true"></div>
        `;
        return {
            root: document.getElementById('modal'),
            surface: document.getElementById('surface'),
        };
    }

    /** @param {string} command */
    function click(command) {
        document.querySelector('[data-command="' + command + '"]').dispatchEvent(new Event('click'));
    }

    it('gives formatBlock the button\'s data-value, in the angle brackets browsers want', async () => {
        const rt = await loadHelper();
        const { root, surface } = toolbar();

        rt.wireToolbar(root, surface);
        click('formatBlock');

        // editable.js passed null here, so H2, H3 and « Paragraphe » did
        // nothing at all on every page of the site.
        expect(execCommand).toHaveBeenCalledWith('formatBlock', false, '<h2>');
    });

    it('falls back to a paragraph rather than <undefined> on a button with no value', async () => {
        const rt = await loadHelper();
        const { root, surface } = toolbar();

        rt.wireToolbar(root, surface);
        document.querySelectorAll('[data-command="formatBlock"]')[1].dispatchEvent(new Event('click'));

        expect(execCommand).toHaveBeenCalledWith('formatBlock', false, '<p>');
    });

    it('wires a button once however many callers ask for it', async () => {
        const rt = await loadHelper();
        const { root, surface } = toolbar();

        // editable.js and rich-text-field.js, on a configuration-mode page.
        rt.wireToolbar(root, surface);
        rt.wireToolbar(root, surface);
        click('bold');

        // Twice would apply the toggle and undo it in the same click —
        // « la plupart du temps impossible d'appliquer une mise en page ».
        expect(execCommand).toHaveBeenCalledTimes(1);
    });

    it('hands createLink to the shared dialog rather than running it raw', async () => {
        window.ScoutMagicConfirm.prompt = vi.fn(() => Promise.resolve('lesscouts.be'));
        const rt = await loadHelper();
        const { root, surface } = toolbar();
        const afterCommand = vi.fn();

        rt.wireToolbar(root, surface, afterCommand);
        click('createLink');
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(execCommand).toHaveBeenCalledWith('createLink', false, 'https://lesscouts.be');
        // After the dialog, never before it: a hidden input synced first
        // would never see the link.
        expect(afterCommand).toHaveBeenCalledTimes(1);
    });

    it('runs the after-command hook once per ordinary command too', async () => {
        const rt = await loadHelper();
        const { root, surface } = toolbar();
        const afterCommand = vi.fn();

        rt.wireToolbar(root, surface, afterCommand);
        click('bold');

        expect(afterCommand).toHaveBeenCalledTimes(1);
    });
});

describe('ScoutMagicRichText.cleanHtml()', () => {
    it('keeps what the server keeps', async () => {
        const rt = await loadHelper();

        expect(rt.cleanHtml('<p>Bonjour <strong>tout</strong> le <em>monde</em>.</p>'))
            .toBe('<p>Bonjour <strong>tout</strong> le <em>monde</em>.</p>');
    });

    it('keeps the words of a wrapper the server would unwrap', async () => {
        const rt = await loadHelper();

        // A paste from a word processor, or Safari's own <span>.
        expect(rt.cleanHtml('<span class="Apple-style-span">Bonjour</span>')).toBe('Bonjour');
        expect(rt.cleanHtml('<p>Un <font color="red">mot</font>.</p>')).toBe('<p>Un mot.</p>');
    });

    it('turns an inline style back into the tag that survives the save', async () => {
        const rt = await loadHelper();

        // This is « ça marche parfois »: a browser answers execCommand with
        // whichever of the two spellings it feels like, and only one of them
        // outlives the page.
        expect(rt.cleanHtml('<span style="font-weight: bold">Gras</span>')).toBe('<strong>Gras</strong>');
        expect(rt.cleanHtml('<span style="font-style: italic">Penché</span>')).toBe('<em>Penché</em>');
    });

    it('remaps a heading the server does not know to the nearest one it does', async () => {
        const rt = await loadHelper();

        expect(rt.cleanHtml('<h1>Titre</h1>')).toBe('<h2>Titre</h2>');
        expect(rt.cleanHtml('<h6>Sous-titre</h6>')).toBe('<h4>Sous-titre</h4>');
    });

    it('makes a browser\'s line-<div> a paragraph, but never wraps a block in one', async () => {
        const rt = await loadHelper();

        // How a contenteditable spells a line.
        expect(rt.cleanHtml('<div>Une ligne</div>')).toBe('<p>Une ligne</p>');
        // A document's outer <div>: <p><h2>…</h2></p> is not a tree any
        // parser gives back, so this one is unwrapped instead.
        expect(rt.cleanHtml('<div><h1>Titre</h1><p>Texte.</p></div>')).toBe('<h2>Titre</h2><p>Texte.</p>');
    });

    it('removes a script and its content, never just its tags', async () => {
        const rt = await loadHelper();

        expect(rt.cleanHtml('<p>Avant</p><script>alert(1)</script><p>Après</p>'))
            .toBe('<p>Avant</p><p>Après</p>');
        // The server strips these eight with their content; unwrapping one
        // here would show words the save then loses.
        expect(rt.cleanHtml('<textarea>Caché</textarea>')).toBe('');
    });

    it('drops an attribute the server drops, event handlers first', async () => {
        const rt = await loadHelper();

        expect(rt.cleanHtml('<p class="lead" onclick="alert(1)">Texte</p>')).toBe('<p>Texte</p>');
        expect(rt.cleanHtml('<a href="https://lesscouts.be" title="Site" rel="me">Lien</a>'))
            .toBe('<a href="https://lesscouts.be" title="Site" rel="me">Lien</a>');
    });

    it('refuses a href the server would refuse, however it is spelt', async () => {
        const rt = await loadHelper();

        expect(rt.cleanHtml('<a href="javascript:alert(1)">Piège</a>')).toBe('<a>Piège</a>');
        // A browser ignores a tab inside a scheme; a naive check does not.
        expect(rt.cleanHtml('<a href="java&#9;script:alert(1)">Piège</a>')).toBe('<a>Piège</a>');
    });

    it('drops an image that lost its src rather than leaving a broken one', async () => {
        const rt = await loadHelper();

        expect(rt.cleanHtml('<img src="data:image/png;base64,AAAA" alt="x">')).toBe('');
        expect(rt.cleanHtml('<img src="/files/12" alt="Photo">')).toBe('<img src="/files/12" alt="Photo">');
    });

    it('forces rel on a link opening a new tab, as the server does', async () => {
        const rt = await loadHelper();

        expect(rt.cleanHtml('<a href="https://lesscouts.be" target="_blank">Site</a>'))
            .toBe('<a href="https://lesscouts.be" target="_blank" rel="noopener noreferrer">Site</a>');
    });

    it('answers an empty string for nothing at all', async () => {
        const rt = await loadHelper();

        expect(rt.cleanHtml('')).toBe('');
        expect(rt.cleanHtml(null)).toBe('');
    });

    it('never builds an element of THIS page to read untrusted markup into', async () => {
        const rt = await loadHelper();
        const created = vi.spyOn(document, 'createElement');

        rt.cleanHtml('<img src="x" onerror="alert(1)"><span style="font-weight:bold">a</span>');

        // The first version of this read the paste into a detached
        // `document.createElement('div')`, on the grounds that a detached
        // element is not in the page. It is not, and that buys nothing: a
        // detached <div> still STARTS the image load, the load fails, and
        // `onerror` runs — in a page holding the author's session. CodeQL
        // was right and the comment above it was wrong. A DOMParser
        // document is inert instead, which is the actual property needed;
        // the rename() path creates its elements in that document, never
        // in this one.
        expect(created).not.toHaveBeenCalled();
        created.mockRestore();
    });
});

describe('ScoutMagicRichText.wirePaste()', () => {
    /** A paste event carrying `html` and/or `text`. */
    function pasteEvent(html, text) {
        const event = new Event('paste', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'clipboardData', {
            value: { getData: (type) => (type === 'text/html' ? html : text) },
        });
        return event;
    }

    function surfaceElement() {
        document.body.innerHTML = '<div id="surface" contenteditable="true"></div>';
        return document.getElementById('surface');
    }

    it('inserts the cleaned markup instead of what the clipboard carried', async () => {
        const rt = await loadHelper();
        const surface = surfaceElement();

        rt.wirePaste(surface);
        const event = pasteEvent('<div><h1 style="color:red">Titre</h1></div>', 'Titre');
        surface.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(execCommand).toHaveBeenCalledWith('insertHTML', false, '<h2>Titre</h2>');
    });

    it('falls back to the plain text when the clipboard carries no markup', async () => {
        const rt = await loadHelper();
        const surface = surfaceElement();

        rt.wirePaste(surface);
        surface.dispatchEvent(pasteEvent('', 'Juste du texte'));

        expect(execCommand).toHaveBeenCalledWith('insertText', false, 'Juste du texte');
    });

    it('wires a surface once, and calls back after each paste', async () => {
        const rt = await loadHelper();
        const surface = surfaceElement();
        const afterPaste = vi.fn();

        rt.wirePaste(surface, afterPaste);
        rt.wirePaste(surface, afterPaste);
        surface.dispatchEvent(pasteEvent('<p>Texte</p>', 'Texte'));

        expect(execCommand).toHaveBeenCalledTimes(1);
        expect(afterPaste).toHaveBeenCalledTimes(1);
    });

    it('leaves the browser to it when there is no clipboard at all', async () => {
        const rt = await loadHelper();
        const surface = surfaceElement();

        rt.wirePaste(surface);
        const event = new Event('paste', { bubbles: true, cancelable: true });
        surface.dispatchEvent(event);

        // Better a raw paste than a swallowed one.
        expect(event.defaultPrevented).toBe(false);
        expect(execCommand).not.toHaveBeenCalled();
    });
});
