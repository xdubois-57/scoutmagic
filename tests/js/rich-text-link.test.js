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
