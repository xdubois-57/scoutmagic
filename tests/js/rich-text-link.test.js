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

    it('finds the editor from the selection when none is given, for a new text too', async () => {
        window.ScoutMagicConfirm.form = answering('Le site', 'lesscouts.be');
        const rt = await loadHelper();
        editorWithSelection('ici').setAttribute('contenteditable', 'true');

        await expect(rt.insertLink(null)).resolves.toBe(true);
        expect(execCommand).toHaveBeenCalledWith('insertHTML', false, '<a href="https://lesscouts.be">Le site</a>');
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

describe('ScoutMagicRichText.wireToolbar() — a list item made a heading or a paragraph (#885)', () => {
    /**
     * The shared toolbar's three block buttons over a surface holding
     * $html, with the caret inside the text node $caretIn.
     *
     * @param {string} html
     * @param {string} caretIn the exact text of the node the caret sits in
     */
    async function editing(html, caretIn) {
        const rt = await loadHelper();
        document.body.innerHTML = `
            <div id="modal">
                <button data-command="formatBlock" data-value="h2">H2</button>
                <button data-command="formatBlock" data-value="h3">H3</button>
                <button data-command="formatBlock" data-value="p">P</button>
            </div>
            <div id="surface" contenteditable="true"></div>
        `;
        const surface = document.getElementById('surface');
        surface.innerHTML = html;

        const walker = document.createTreeWalker(surface, NodeFilter.SHOW_TEXT);
        let text = walker.nextNode();
        while (text && text.textContent !== caretIn) {
            text = walker.nextNode();
        }
        const range = document.createRange();
        range.setStart(text, 1);
        range.collapse(true);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);

        const afterCommand = vi.fn();
        rt.wireToolbar(document.getElementById('modal'), surface, afterCommand);

        return {
            rt,
            surface,
            afterCommand,
            caretText: text,
            press: (value) => document.querySelector('[data-value="' + value + '"]').dispatchEvent(new Event('click')),
        };
    }

    it.each(['h2', 'h3', 'p'])('takes the first item out of its list as a <%s>', async (tag) => {
        const { surface, press } = await editing('<ul><li>un</li><li>deux</li></ul>', 'un');

        press(tag);

        expect(surface.innerHTML).toBe(`<${tag}>un</${tag}><ul><li>deux</li></ul>`);
        expect(execCommand).not.toHaveBeenCalled();
    });

    it('cuts the list in two around an item in the middle', async () => {
        const { surface, press } = await editing('<ul><li>un</li><li>deux</li><li>trois</li></ul>', 'deux');

        press('h2');

        expect(surface.innerHTML).toBe('<ul><li>un</li></ul><h2>deux</h2><ul><li>trois</li></ul>');
    });

    it('takes the last item out after the list, and removes a list left empty', async () => {
        const { surface, press } = await editing('<ul><li>un</li><li>deux</li></ul><p>suite</p>', 'deux');
        press('h3');
        expect(surface.innerHTML).toBe('<ul><li>un</li></ul><h3>deux</h3><p>suite</p>');

        const alone = await editing('<ul><li>seul</li></ul>', 'seul');
        alone.press('p');
        expect(alone.surface.innerHTML).toBe('<p>seul</p>');
    });

    it('keeps the inline content of the item, bold and links included', async () => {
        const { surface, press } = await editing(
            '<ul><li><strong>Gras</strong> et <a href="https://lesscouts.be">lien</a></li></ul>',
            ' et '
        );

        press('h2');

        expect(surface.innerHTML).toBe('<h2><strong>Gras</strong> et <a href="https://lesscouts.be">lien</a></h2>');
    });

    it('applies the same rule to a numbered list', async () => {
        const { rt, surface, press } = await editing('<ol><li>un</li><li>deux</li><li>trois</li></ol>', 'deux');

        press('p');

        // No `start` on the second half: the canonical form would not keep
        // it, and the screen would number differently from the saved text.
        expect(surface.innerHTML).toBe('<ol><li>un</li></ol><p>deux</p><ol><li>trois</li></ol>');
        expect(rt.canonicalHtml(surface.innerHTML)).toBe(surface.innerHTML);
    });

    it('saves exactly what is on screen: the canonical form keeps the heading', async () => {
        const { rt, surface, press } = await editing('<ul><li>un</li><li>deux</li><li>trois</li></ul>', 'deux');

        press('h2');

        // What the engine's formatBlock produced, <h2><ul>…</ul></h2>, was
        // invalid — and the canonical form dropped the heading on save.
        expect(rt.canonicalHtml(surface.innerHTML)).toBe(surface.innerHTML);
    });

    it('leaves the caret in the text it was in, and reports the change', async () => {
        const { press, caretText, afterCommand } = await editing('<ul><li>un</li><li>deux</li></ul>', 'deux');

        press('h2');

        const range = window.getSelection().getRangeAt(0);
        expect(range.startContainer).toBe(caretText);
        expect(range.startOffset).toBe(1);
        expect(caretText.parentElement.tagName).toBe('H2');
        // A move made by hand fires no `input`: the hook is how an editor
        // with a hidden field or an onChange learns of it.
        expect(afterCommand).toHaveBeenCalledTimes(1);
    });

    it('takes an item of a nested list out of both lists', async () => {
        const { surface, press } = await editing('<ul><li>haut<ul><li>bas</li></ul></li><li>après</li></ul>', 'bas');

        press('h2');

        expect(surface.innerHTML).toBe('<ul><li>haut</li></ul><h2>bas</h2><ul><li>après</li></ul>');
    });

    /** The text node of $surface whose text is exactly $text. */
    function textNode(surface, text) {
        const walker = document.createTreeWalker(surface, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode();
        while (node && node.textContent !== text) {
            node = walker.nextNode();
        }
        return node;
    }

    function select(surface, fromText, toText) {
        const range = document.createRange();
        range.setStart(textNode(surface, fromText), 1);
        range.setEnd(textNode(surface, toText), 1);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
    }

    // A selection that starts in a paragraph above the list and ends in it
    // used to reach the engine whole — which wrapped the list in a heading.
    it('takes the items a selection reaches out of the list, and leaves the paragraph above to the engine', async () => {
        const { surface, press } = await editing('<p>avant</p><ul><li>un</li><li>deux</li></ul>', 'avant');
        select(surface, 'avant', 'un');

        press('h2');

        expect(surface.innerHTML).toBe('<p>avant</p><h2>un</h2><ul><li>deux</li></ul>');
        // The engine gets what is left, with no list item left in the selection.
        expect(execCommand).toHaveBeenCalledTimes(1);
        expect(execCommand).toHaveBeenCalledWith('formatBlock', false, '<h2>');
        const range = window.getSelection().getRangeAt(0);
        expect(range.startContainer.textContent).toBe('avant');
        expect(range.endContainer.textContent).toBe('un');
    });

    // Both ends in a list item, and a paragraph between the two lists: the
    // ends alone would leave that paragraph out of the command.
    it('leaves to the engine a paragraph between two lists that the selection holds', async () => {
        const { surface, press } = await editing('<ul><li>un</li></ul><p>milieu</p><ul><li>deux</li></ul>', 'un');
        select(surface, 'un', 'deux');

        press('h2');

        expect(surface.innerHTML).toBe('<h2>un</h2><p>milieu</p><h2>deux</h2>');
        expect(execCommand).toHaveBeenCalledTimes(1);
        expect(execCommand).toHaveBeenCalledWith('formatBlock', false, '<h2>');
    });

    // A selection counted in children of the list itself: once the list is
    // cut in two, it no longer has the third child the end pointed after.
    it('survives a selection whose offsets no longer fit the list it was cut from, and still tells the form', async () => {
        const { surface, press, afterCommand } = await editing('<ul><li>un</li><li>deux</li><li>trois</li><li>quatre</li></ul>', 'un');
        const list = surface.querySelector('ul');
        const range = document.createRange();
        range.setStart(list, 1);
        range.setEnd(list, 3);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);

        press('h2');

        expect(surface.innerHTML).toBe('<ul><li>un</li></ul><h2>deux</h2><h2>trois</h2><ul><li>quatre</li></ul>');
        expect(afterCommand).toHaveBeenCalledTimes(1);
    });

    // Two editors on one form: the caret left in the other one must not
    // receive the command of this one's toolbar.
    it('brings the selection to its own editor before running a command, when it was in another', async () => {
        const { surface, press } = await editing('<p>ici</p>', 'ici');
        const other = document.createElement('div');
        other.contentEditable = 'true';
        other.textContent = 'ailleurs';
        document.body.appendChild(other);
        const range = document.createRange();
        range.setStart(/** @type {Text} */ (other.firstChild), 2);
        range.collapse(true);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        const focus = vi.spyOn(surface, 'focus');

        press('h2');

        expect(focus).toHaveBeenCalled();
        expect(focus.mock.invocationCallOrder[0]).toBeLessThan(execCommand.mock.invocationCallOrder[0]);
    });

    it('brings the selection to its own editor before « Lien » too', async () => {
        const { surface } = await editing('<p>ici</p>', 'ici');
        const link = document.createElement('button');
        link.dataset.command = 'createLink';
        document.getElementById('modal').appendChild(link);
        window.ScoutMagicRichText.wireToolbar(document.getElementById('modal'), surface, null);
        const title = document.createElement('input');
        title.value = 'Titre de l\'article';
        document.body.appendChild(title);
        const range = document.createRange();
        range.selectNodeContents(title);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        const focus = vi.spyOn(surface, 'focus');

        link.dispatchEvent(new Event('click'));

        expect(focus).toHaveBeenCalled();
    });

    it('keeps a selection already in its own editor, without focusing it first', async () => {
        const { surface, press } = await editing('<p>ici</p>', 'ici');
        const focus = vi.spyOn(surface, 'focus');

        press('h2');

        // Once, after the command — as before.
        expect(focus).toHaveBeenCalledTimes(1);
        expect(focus.mock.invocationCallOrder[0]).toBeGreaterThan(execCommand.mock.invocationCallOrder[0]);
    });

    // An item left empty on purpose (Enter twice) is a line like any other:
    // skipped, it stayed a bullet among the new blocks.
    it('takes an empty item a selection crosses out of the list with the others', async () => {
        const { surface, press } = await editing('<ul><li>un</li><li><br></li><li>deux</li></ul>', 'un');
        select(surface, 'un', 'deux');

        press('h2');

        expect(surface.querySelector('li')).toBeNull();
        expect(surface.innerHTML).toBe('<h2>un</h2><h2><br></h2><h2>deux</h2>');
    });

    // Stored content can hold an <li> straight in the surface: there is no
    // list to leave, and the surface itself must never be taken for one.
    it('leaves a bare item with no list around it to the engine, and keeps the editor in place', async () => {
        const { surface, press } = await editing('<li>seul</li>', 'seul');

        press('h2');

        expect(surface.isConnected).toBe(true);
        expect(surface.innerHTML).toBe('<li>seul</li>');
        expect(execCommand).toHaveBeenCalledWith('formatBlock', false, '<h2>');
    });

    // A selection ending on the item element itself (a triple click): the
    // item goes with its list, but the paragraph above stays in the
    // selection the engine is given.
    it('keeps the paragraph above in the selection when the end stood on an item that went with its list', async () => {
        const { surface, press } = await editing('<p>avant</p><ul><li>un</li></ul>', 'avant');
        const range = document.createRange();
        range.setStart(textNode(surface, 'avant'), 0);
        range.setEnd(surface.querySelector('li'), 1);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);

        press('h2');

        expect(surface.innerHTML).toBe('<p>avant</p><h2>un</h2>');
        expect(execCommand).toHaveBeenCalledWith('formatBlock', false, '<h2>');
        const restored = window.getSelection().getRangeAt(0);
        expect(restored.startContainer.textContent).toBe('avant');
        expect(restored.intersectsNode(surface.querySelector('h2'))).toBe(true);
    });

    it('takes every item of a selection over several out of the list, and keeps the selection', async () => {
        const { surface, press } = await editing('<ul><li>un</li><li>deux</li><li>trois</li></ul>', 'un');
        select(surface, 'un', 'deux');

        press('h3');

        expect(surface.innerHTML).toBe('<h3>un</h3><h3>deux</h3><ul><li>trois</li></ul>');
        expect(execCommand).not.toHaveBeenCalled();
        const range = window.getSelection().getRangeAt(0);
        expect(range.collapsed).toBe(false);
        expect(range.startContainer.textContent).toBe('un');
        expect(range.endContainer.textContent).toBe('deux');
    });

    // The canonical form drops a whitespace-only item on save: the screen
    // must not keep it as an empty bullet.
    it('leaves no empty bullet behind when the item above held only whitespace', async () => {
        const { rt, surface, press } = await editing('<ul><li> <ul><li>bas</li></ul></li></ul>', 'bas');

        press('h2');

        expect(surface.innerHTML).toBe('<h2>bas</h2>');
        expect(rt.canonicalHtml(surface.innerHTML)).toBe(surface.innerHTML);
    });

    // An item cut in two lines by a nested list: the line holding the
    // caret is the one made a heading, not the item's first.
    it('makes a heading of the line after a nested list when the caret is there', async () => {
        const { rt, surface, press } = await editing('<ul><li>avant<ul><li>sous</li></ul>texte</li><li>fin</li></ul>', 'texte');

        press('h2');

        expect(surface.innerHTML).toBe('<ul><li>avant<ul><li>sous</li></ul></li></ul><h2>texte</h2><ul><li>fin</li></ul>');
        expect(rt.canonicalHtml(surface.innerHTML)).toBe(surface.innerHTML);
    });

    it('makes a heading of the text after a nested list that opens the item, and no empty heading', async () => {
        const { surface, press } = await editing('<ul><li><ul><li>sous</li></ul>texte</li></ul>', 'texte');

        press('h2');

        expect(surface.innerHTML).toBe('<ul><li><ul><li>sous</li></ul></li></ul><h2>texte</h2>');
    });

    it('takes each line a selection crosses out of the list, in the order they are read', async () => {
        const { surface, press } = await editing('<ul><li>avant<ul><li>sous</li></ul>texte</li></ul>', 'avant');
        select(surface, 'avant', 'texte');

        press('h3');

        expect(surface.innerHTML).toBe('<h3>avant</h3><h3>sous</h3><h3>texte</h3>');
    });

    it('makes a paragraph of any block it was not built to offer, never an element named by the attribute', async () => {
        const { surface } = await editing('<ul><li>un</li></ul>', 'un');
        const odd = document.querySelector('[data-value="h2"]');
        odd.dataset.value = 'script';

        odd.dispatchEvent(new Event('click'));

        // The button's own text never reaches createElement(): only the
        // three blocks of the toolbar can come out of it (CodeQL, #893).
        expect(surface.innerHTML).toBe('<p>un</p>');
        expect(surface.querySelector('script')).toBeNull();
    });

    it('still hands a heading outside any list to the engine, so « Paragraphe » turns it back', async () => {
        const { press } = await editing('<h2>Titre</h2>', 'Titre');

        press('p');

        expect(execCommand).toHaveBeenCalledWith('formatBlock', false, '<p>');
    });
});
