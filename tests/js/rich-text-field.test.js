// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked, Bootstrap's Modal is a two-method stub, and
// window.ScoutMagicToast (loaded by base.html.twig in production) is
// stubbed so failure feedback is a spy call rather than a DOM search.
//
// Exercises the REAL public/assets/js/rich-text-field.js (imported below,
// never reimplemented). The file is an IIFE that wires itself against the
// DOM present at import time, so every test builds its fixture first and
// imports afterwards.
//
// What this suite owns beyond the save/feedback paths is the pairing rule
// that makes this component generic: the preview and its edit button are
// matched by data-key, never by DOM nesting, so a caller can put the button
// anywhere in its own layout. Getting that wrong silently saves one field's
// text over another's, which is exactly the kind of regression a test is
// cheaper than.
//
// rich-text-link.js is imported alongside, as base.html.twig loads it on
// every page: since issue #306 the toolbar and the paste cleaning are its
// job, and stubbing window.ScoutMagicRichText here would assert against a
// stub rather than against the wiring the visitor gets.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const modalStub = { show: vi.fn(), hide: vi.fn() };

function jsonResponse(data) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
}

async function settle() {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

// Two independent fields, their previews rendered far from their buttons on
// purpose — that separation is the behaviour under test.
function buildDom() {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <div class="rich-text-field-preview" data-key="rental.contract"><p>Contrat actuel.</p></div>
        <div class="rich-text-field-preview" data-key="rental.rules"><p>Règlement actuel.</p></div>
        <table>
            <tr>
                <td><button class="rich-text-field-edit-btn" data-key="rental.contract"
                            data-save-url="/location/contrat">Modifier</button></td>
                <td><button class="rich-text-field-edit-btn" data-key="rental.rules"
                            data-save-url="/location/reglement">Modifier</button></td>
            </tr>
        </table>
        <div id="richTextEditorModal">
            <button data-command="bold">B</button>
            <button data-command="formatBlock" data-value="h3">H3</button>
            <div id="richTextEditorContent"></div>
            <button id="richTextEditorSave">Enregistrer</button>
        </div>
    `;
}

async function boot() {
    buildDom();
    vi.resetModules();
    await import('../../public/assets/js/rich-text-link.js');
    await import('../../public/assets/js/rich-text-field.js');
}

/** @param {string} key */
function preview(key) {
    return document.querySelector('.rich-text-field-preview[data-key="' + key + '"]');
}

/** @param {number} index */
function editButton(index) {
    return document.querySelectorAll('.rich-text-field-edit-btn')[index];
}

function save() {
    document.getElementById('richTextEditorSave').dispatchEvent(new Event('click'));
}

/** The parsed body of the first fetch call. */
function postedBody() {
    return JSON.parse(fetch.mock.calls[0][1].body);
}

beforeEach(() => {
    vi.resetModules();
    vi.restoreAllMocks();
    modalStub.show = vi.fn();
    modalStub.hide = vi.fn();
    global.fetch = vi.fn(() => jsonResponse({ success: true }));
    window.ScoutMagicToast = { show: vi.fn() };
    // jsdom has no execCommand at all, so the toolbar's effect on the
    // document is only observable as the call itself.
    document.execCommand = vi.fn(() => true);
    // rich-text-field.js reads the bare `bootstrap` binding.
    // `function`, not an arrow: the code under test calls `new
    // bootstrap.Modal(...)`, and since Vitest 4 a mock built from an
    // arrow implementation is not constructible.
    window.bootstrap = { Modal: vi.fn(function () { return modalStub; }) };
});

describe('rich-text-field.js: opening a field', () => {
    it('loads the preview matched by data-key, not the one nearest the button', async () => {
        await boot();

        editButton(1).dispatchEvent(new Event('click'));

        expect(document.getElementById('richTextEditorContent').innerHTML).toBe('<p>Règlement actuel.</p>');
        expect(modalStub.show).toHaveBeenCalled();
    });

    it('opens empty rather than throwing when no preview carries that key', async () => {
        buildDom();
        preview('rental.rules').remove();
        vi.resetModules();
        await import('../../public/assets/js/rich-text-link.js');
        await import('../../public/assets/js/rich-text-field.js');

        editButton(1).dispatchEvent(new Event('click'));

        expect(document.getElementById('richTextEditorContent').innerHTML).toBe('');
        expect(modalStub.show).toHaveBeenCalled();
    });

    it('does nothing at all on a page with no shared modal', async () => {
        buildDom();
        document.getElementById('richTextEditorModal').remove();
        vi.resetModules();
        await import('../../public/assets/js/rich-text-link.js');
        await import('../../public/assets/js/rich-text-field.js');

        editButton(0).dispatchEvent(new Event('click'));

        expect(modalStub.show).not.toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('rich-text-field.js: saving a field', () => {
    it('stands down when the save button is clicked without a field open', async () => {
        await boot();

        save();
        await settle();

        // editable.js and the modules' own add flows share this same modal.
        expect(fetch).not.toHaveBeenCalled();
    });

    it('posts to the clicked button\'s own data-save-url, with its key and the CSRF token', async () => {
        await boot();

        editButton(1).dispatchEvent(new Event('click'));
        document.getElementById('richTextEditorContent').innerHTML = '<p>Nouveau règlement.</p>';
        save();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/location/reglement');
        expect(postedBody()).toEqual({
            key: 'rental.rules',
            value: '<p>Nouveau règlement.</p>',
            type: 'rich_text',
            _csrf_token: 'tok',
        });
    });

    it('repaints only the matching preview and closes the modal on success', async () => {
        await boot();

        editButton(1).dispatchEvent(new Event('click'));
        document.getElementById('richTextEditorContent').innerHTML = '<p>Nouveau règlement.</p>';
        save();
        await settle();

        expect(preview('rental.rules').innerHTML).toBe('<p>Nouveau règlement.</p>');
        expect(preview('rental.contract').innerHTML).toBe('<p>Contrat actuel.</p>');
        expect(modalStub.hide).toHaveBeenCalled();
    });

    it('toasts the server error and leaves the preview untouched on a refusal', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Contenu refusé.' }));
        await boot();

        editButton(0).dispatchEvent(new Event('click'));
        document.getElementById('richTextEditorContent').innerHTML = '<p>Nouveau contrat.</p>';
        save();
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Contenu refusé.', { variant: 'error' });
        expect(preview('rental.contract').innerHTML).toBe('<p>Contrat actuel.</p>');
        expect(modalStub.hide).not.toHaveBeenCalled();
    });

    it('falls back to a generic French line when the server names no error', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false }));
        await boot();

        editButton(0).dispatchEvent(new Event('click'));
        save();
        await settle();

        expect(window.ScoutMagicToast.show)
            .toHaveBeenCalledWith('Erreur lors de l\'enregistrement.', { variant: 'error' });
    });

    it('toasts « Erreur réseau. » when the request never lands', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot();

        editButton(0).dispatchEvent(new Event('click'));
        save();
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Erreur réseau.', { variant: 'error' });
        expect(modalStub.hide).not.toHaveBeenCalled();
    });

    it('keeps a key holding a quote from selecting the wrong preview', async () => {
        // escapeAttr() exists for exactly this: the key goes into a
        // querySelector attribute string.
        buildDom();
        preview('rental.rules').setAttribute('data-key', 'rental."odd"');
        editButton(1).setAttribute('data-key', 'rental."odd"');
        vi.resetModules();
        await import('../../public/assets/js/rich-text-link.js');
        await import('../../public/assets/js/rich-text-field.js');

        editButton(1).dispatchEvent(new Event('click'));

        expect(document.getElementById('richTextEditorContent').innerHTML).toBe('<p>Règlement actuel.</p>');
    });
});

// Issue #306. This file's own toolbar already passed data-value; what it
// did wrong was wire the shared modal a second time.
describe('rich-text-field.js: the toolbar (issue #306)', () => {
    it('runs a command once when editable.js drives the same modal', async () => {
        buildDom();
        vi.resetModules();
        await import('../../public/assets/js/rich-text-link.js');
        await import('../../public/assets/js/rich-text-field.js');
        // Configuration mode: both scripts live on one page, against one
        // modal. A comment here used to claim that could not happen.
        await import('../../public/assets/js/editable.js');

        document.querySelector('[data-command="bold"]').dispatchEvent(new Event('click'));

        expect(document.execCommand).toHaveBeenCalledTimes(1);
    });

    it('still gives formatBlock its heading', async () => {
        await boot();

        document.querySelector('[data-command="formatBlock"]').dispatchEvent(new Event('click'));

        expect(document.execCommand).toHaveBeenCalledWith('formatBlock', false, '<h3>');
    });

    it('saves and echoes the same HTML the server will keep', async () => {
        await boot();

        editButton(0).dispatchEvent(new Event('click'));
        document.getElementById('richTextEditorContent').innerHTML =
            '<p><font color="red">Contrat</font> <b>signé</b>.</p>';
        save();
        await settle();

        // <font> is not on the server's list; <b> is.
        expect(postedBody().value).toBe('<p>Contrat <b>signé</b>.</p>');
        expect(preview('rental.contract').innerHTML).toBe('<p>Contrat <b>signé</b>.</p>');
    });
});
