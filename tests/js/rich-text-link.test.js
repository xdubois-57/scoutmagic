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
// Since issue #306 it also owns the toolbar, for the same reason: three
// copies of it disagreed about `data-value`, and two of them wired the same
// modal at once. What it deliberately does NOT own is a sanitiser — the
// server answers each save with the string it stored, and the editors
// repaint with that, so there is no second allowlist here to test.
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
    window.ScoutMagicConfirm = { form: vi.fn(() => Promise.resolve(null)) };
    window.ScoutMagicApi = { postJson: vi.fn(() => Promise.resolve({ ok: true, status: 200, data: { success: true, label: null } })) };
});

/** The dialog answering with these two fields. */
function answering(text, url) {
    return vi.fn(() => Promise.resolve({ text: text, url: url }));
}

/**
 * The form() control an action receives, over two plain values — what the
 * « baguette magique » reads and writes while the dialog is open.
 */
function fakeControl(url, text = '') {
    const values = { url: url, text: text };
    const notes = {};
    return {
        values: values,
        notes: notes,
        control: {
            inputs: [],
            value: (name) => values[name],
            set: (name, value) => { values[name] = value; },
            note: (name, note, tone) => { notes[name] = { text: note, tone: tone }; },
            values: () => ({ ...values }),
        },
    };
}

/** The wand of the dialog insertLink() opened. */
function wand() {
    const spec = window.ScoutMagicConfirm.form.mock.calls[0][0];
    return spec.fields.find((f) => f.name === 'text').action;
}

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
    it('asks through the site dialog, never the native prompt: the text above the address', async () => {
        const nativePrompt = vi.fn(() => 'https://lesscouts.be');
        window.prompt = nativePrompt;
        const rt = await loadHelper();

        await rt.insertLink(editorWithSelection('Notre site'));

        expect(nativePrompt).not.toHaveBeenCalled();
        expect(window.ScoutMagicConfirm.form).toHaveBeenCalledTimes(1);
        const spec = window.ScoutMagicConfirm.form.mock.calls[0][0];
        expect(spec.title).toBe('Insérer un lien');
        expect(spec.fields.map((f) => [f.name, f.label])).toEqual([
            ['text', 'Texte du lien'],
            ['url', 'Adresse du lien'],
        ]);
    });

    it('starts « Texte du lien » with the selected text, and leaves it empty without one', async () => {
        const rt = await loadHelper();

        await rt.insertLink(editorWithSelection('  Notre site '));
        expect(window.ScoutMagicConfirm.form.mock.calls[0][0].fields[0].value).toBe('Notre site');

        window.getSelection().removeAllRanges();
        await rt.insertLink(null);
        expect(window.ScoutMagicConfirm.form.mock.calls[1][0].fields[0].value).toBe('');
    });

    it('wraps the selection itself when its text is kept, so its formatting stays', async () => {
        window.ScoutMagicConfirm.form = answering('Notre site', 'lesscouts.be');
        const rt = await loadHelper();

        await expect(rt.insertLink(editorWithSelection('Notre site'))).resolves.toBe(true);
        expect(execCommand).toHaveBeenCalledWith('createLink', false, 'https://lesscouts.be');
    });

    it('puts a link with the new text in place of the selection when the text was changed', async () => {
        window.ScoutMagicConfirm.form = answering('Le site <b>des Scouts</b>', 'lesscouts.be');
        const rt = await loadHelper();

        await expect(rt.insertLink(editorWithSelection('ici'))).resolves.toBe(true);

        // Built from nodes: the visitor's text is text, never markup.
        expect(execCommand).toHaveBeenCalledWith(
            'insertHTML', false, '<a href="https://lesscouts.be">Le site &lt;b&gt;des Scouts&lt;/b&gt;</a>',
        );
        expect(execCommand).not.toHaveBeenCalledWith('createLink', expect.anything(), expect.anything());
    });

    it('shows the address itself when « Texte du lien » is left empty', async () => {
        window.ScoutMagicConfirm.form = answering('  ', 'lesscouts.be/unite');
        const rt = await loadHelper();
        const surface = editorWithSelection('');

        await expect(rt.insertLink(surface)).resolves.toBe(true);
        expect(execCommand).toHaveBeenCalledWith(
            'insertHTML', false, '<a href="https://lesscouts.be/unite">https://lesscouts.be/unite</a>',
        );
    });

    it('restores the selection the dialog took, before creating the link', async () => {
        window.ScoutMagicConfirm.form = vi.fn(() => {
            // What a real modal does: focus moves, the contenteditable's
            // range is gone. Without a restore, execCommand has nothing to
            // wrap and the link is silently lost.
            window.getSelection().removeAllRanges();
            return Promise.resolve({ text: 'Notre site', url: 'https://lesscouts.be' });
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
        window.ScoutMagicConfirm.form = vi.fn(() => {
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
        window.ScoutMagicConfirm.form = answering('Cliquez ici', 'javascript:alert(1)');
        const rt = await loadHelper();

        await expect(rt.insertLink(editorWithSelection('Cliquez ici'))).resolves.toBe(false);

        expect(execCommand).not.toHaveBeenCalled();
        expect(window.ScoutMagicToast.show).toHaveBeenCalledTimes(1);
        expect(window.ScoutMagicToast.show.mock.calls[0][1]).toEqual({ variant: 'error' });
    });

    it('stays quiet when the address was simply left empty', async () => {
        window.ScoutMagicConfirm.form = answering('Notre site', '   ');
        const rt = await loadHelper();

        await expect(rt.insertLink(editorWithSelection('Notre site'))).resolves.toBe(false);

        // Confirming an empty field is a change of mind, not an error.
        expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
        expect(execCommand).not.toHaveBeenCalled();
    });

    it('works without an editor element to focus', async () => {
        window.ScoutMagicConfirm.form = answering('Notre site', 'https://lesscouts.be');
        const rt = await loadHelper();
        editorWithSelection('Notre site');

        await expect(rt.insertLink(null)).resolves.toBe(true);
        expect(execCommand).toHaveBeenCalledWith('createLink', false, 'https://lesscouts.be');
    });
});

describe('the « baguette magique » of the link dialog (#840)', () => {
    it('fills « Texte du lien » with what the page proposes', async () => {
        window.ScoutMagicApi.postJson = vi.fn(() => Promise.resolve({ ok: true, status: 200, data: { success: true, label: 'Agenda — Les Scouts' } }));
        const rt = await loadHelper();
        await rt.insertLink(editorWithSelection(''));
        const { control, values, notes } = fakeControl('lesscouts.be/agenda');

        await wand().run(control);

        // The address is normalised first: what is looked up is what will be linked.
        expect(window.ScoutMagicApi.postJson).toHaveBeenCalledWith('/api/lien/libelle', { url: 'https://lesscouts.be/agenda' });
        expect(values.text).toBe('Agenda — Les Scouts');
        expect(notes.text.text).toBe('');
    });

    it('asks for the address first, without a request', async () => {
        const rt = await loadHelper();
        await rt.insertLink(editorWithSelection(''));
        const { control, notes } = fakeControl('  ');

        await wand().run(control);

        expect(window.ScoutMagicApi.postJson).not.toHaveBeenCalled();
        expect(notes.text).toEqual({ text: 'Indiquez d\'abord l\'adresse du lien.', tone: 'warning' });
    });

    it('does not look up an address that has no page to read', async () => {
        const rt = await loadHelper();
        await rt.insertLink(editorWithSelection(''));
        const { control, values, notes } = fakeControl('unite@example.be', 'Écrivez-nous');

        await wand().run(control);

        expect(window.ScoutMagicApi.postJson).not.toHaveBeenCalled();
        expect(values.text).toBe('Écrivez-nous');
        expect(notes.text.tone).toBe('warning');
    });

    it('warns when nothing was found, and leaves what was typed alone', async () => {
        const rt = await loadHelper();
        await rt.insertLink(editorWithSelection(''));
        const { control, values, notes } = fakeControl('https://example.be/', 'Mon texte');

        await wand().run(control);

        expect(values.text).toBe('Mon texte');
        expect(notes.text).toEqual({ text: 'Aucun titre trouvé pour cette page : tapez le texte du lien.', tone: 'warning' });
    });

    it('passes on the server\'s own sentence when it refuses, as for a spent budget', async () => {
        window.ScoutMagicApi.postJson = vi.fn(() => Promise.resolve({
            ok: false, status: 429, data: { success: false, error: 'Trop de demandes de titre en peu de temps.' },
        }));
        const rt = await loadHelper();
        await rt.insertLink(editorWithSelection(''));
        const { control, notes } = fakeControl('https://example.be/');

        await wand().run(control);

        expect(notes.text).toEqual({ text: 'Trop de demandes de titre en peu de temps.', tone: 'warning' });
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
        window.ScoutMagicConfirm.form = answering('Notre site', 'lesscouts.be');
        const rt = await loadHelper();
        const { root, surface } = toolbar();
        const afterCommand = vi.fn();

        rt.wireToolbar(root, surface, afterCommand);
        // Nothing selected in the surface: the link goes in with its text.
        click('createLink');
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(execCommand).toHaveBeenCalledWith('insertHTML', false, '<a href="https://lesscouts.be">Notre site</a>');
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
