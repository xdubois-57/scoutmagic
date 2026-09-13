// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked, Bootstrap's Modal is a two-method stub, and
// window.ScoutMagicToast (loaded by base.html.twig in production) is
// stubbed so failure feedback is a spy call rather than a DOM search.
//
// Exercises the REAL public/assets/js/editable.js (imported below, never
// reimplemented). The file is an IIFE that wires itself against the DOM
// present at import time, so every test builds its fixture first and
// imports afterwards — the tests/js/finance-receipts.test.js pattern.
//
// rich-text-link.js is imported alongside, as base.html.twig loads it on
// every page: since issue #306 the toolbar is its job, and stubbing
// window.ScoutMagicRichText here would assert against a stub rather than
// against the wiring the visitor gets.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const modalStub = { show: vi.fn(), hide: vi.fn() };

function jsonResponse(data) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
}

async function settle() {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

/**
 * The configuration-mode page: the shared rich-text modal plus one editable
 * text block and two editable images (one already holding a picture, one
 * still empty).
 */
function buildDom() {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <div class="editable-image" data-key="home.hero">
            <img src="/files/1" alt="">
            <div class="editable-overlay"><button class="editable-edit-btn">Modifier</button></div>
        </div>
        <div class="editable-image" data-key="home.side" data-context="member_photo">
            <div class="editable-overlay"><button class="editable-edit-btn">Ajouter</button></div>
        </div>
        <div class="editable-content" data-key="home.intro">
            <div class="editable-overlay"><button class="editable-edit-btn">Modifier</button></div>
            <p>Texte actuel.</p>
        </div>
        <div id="richTextEditorModal">
            <button data-command="bold">B</button>
            <button data-command="formatBlock" data-value="h2">H2</button>
            <div id="richTextEditorContent"></div>
            <button id="richTextEditorSave">Enregistrer</button>
        </div>
    `;
}

async function boot() {
    buildDom();
    vi.resetModules();
    await import('../../public/assets/js/rich-text-link.js');
    await import('../../public/assets/js/editable.js');
}

/** The .editable-content block, whose innerHTML the save path rewrites. */
function block() {
    return document.querySelector('.editable-content');
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
    // editable.js reads the bare `bootstrap` binding, not window.bootstrap.
    // `function`, not an arrow: the code under test calls `new
    // bootstrap.Modal(...)`, and since Vitest 4 a mock built from an
    // arrow implementation is not constructible.
    window.bootstrap = { Modal: vi.fn(function () { return modalStub; }) };
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { href: '/accueil', pathname: '/accueil' },
    });
});

describe('editable.js: editable images', () => {
    it('sends the edit button to the upload page with the block\'s key and the default context', async () => {
        await boot();

        document.querySelector('.editable-image .editable-edit-btn').dispatchEvent(new Event('click'));

        expect(window.location.href)
            .toBe('/upload?context=editable_image&key=home.hero&return=%2Faccueil');
    });

    it('honours a data-context override (member_photo() reuses this wiring)', async () => {
        await boot();

        document.querySelectorAll('.editable-image .editable-edit-btn')[1].dispatchEvent(new Event('click'));

        expect(window.location.href)
            .toBe('/upload?context=member_photo&key=home.side&return=%2Faccueil');
    });

    it('makes the whole box clickable only while it holds no image yet', async () => {
        await boot();

        // The one that already has an <img>: clicking the box itself (not
        // the hover button) must do nothing.
        document.querySelectorAll('.editable-image')[0].dispatchEvent(new Event('click'));
        expect(window.location.href).toBe('/accueil');

        // The empty one: its placeholder text is all there is to click.
        document.querySelectorAll('.editable-image')[1].dispatchEvent(new Event('click'));
        expect(window.location.href).toBe('/upload?context=member_photo&key=home.side&return=%2Faccueil');
    });

    it('wires image upload even on a page with no rich-text modal at all', async () => {
        buildDom();
        document.getElementById('richTextEditorModal').remove();
        vi.resetModules();
        await import('../../public/assets/js/rich-text-link.js');
        await import('../../public/assets/js/editable.js');

        document.querySelector('.editable-image .editable-edit-btn').dispatchEvent(new Event('click'));

        expect(window.location.href).toContain('/upload?context=editable_image&key=home.hero');
    });
});

describe('editable.js: editing a rich-text block', () => {
    it('loads the block\'s content into the editor without its hover overlay', async () => {
        await boot();

        document.querySelector('.editable-content .editable-edit-btn').dispatchEvent(new Event('click'));

        const editor = document.getElementById('richTextEditorContent');
        expect(editor.innerHTML).toContain('<p>Texte actuel.</p>');
        expect(editor.querySelector('.editable-overlay')).toBeNull();
        expect(modalStub.show).toHaveBeenCalled();
        // The block on the page is untouched — the editor works on a clone.
        expect(block().querySelector('.editable-overlay')).not.toBeNull();
    });

    it('stands down entirely when the save button is clicked without a block open', async () => {
        await boot();

        document.getElementById('richTextEditorSave').dispatchEvent(new Event('click'));
        await settle();

        // The shared modal is driven by rich-text-field.js and by pages with
        // their own add flow too: this handler must not post their content.
        expect(fetch).not.toHaveBeenCalled();
    });

    it('posts the edited HTML with the key, the type and the CSRF token, then closes', async () => {
        await boot();

        document.querySelector('.editable-content .editable-edit-btn').dispatchEvent(new Event('click'));
        document.getElementById('richTextEditorContent').innerHTML = '<p>Texte modifié.</p>';
        document.getElementById('richTextEditorSave').dispatchEvent(new Event('click'));
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/api/editable-content');
        expect(postedBody()).toEqual({
            key: 'home.intro',
            value: '<p>Texte modifié.</p>',
            type: 'rich_text',
            _csrf_token: 'tok',
        });
        expect(modalStub.hide).toHaveBeenCalled();
    });

    it('repaints the block with the new HTML and puts the overlay back in front', async () => {
        await boot();

        document.querySelector('.editable-content .editable-edit-btn').dispatchEvent(new Event('click'));
        document.getElementById('richTextEditorContent').innerHTML = '<p>Texte modifié.</p>';
        document.getElementById('richTextEditorSave').dispatchEvent(new Event('click'));
        await settle();

        expect(block().innerHTML).toContain('<p>Texte modifié.</p>');
        // The hover overlay carries the edit button: losing it would leave
        // the block uneditable until the next full page load.
        expect(block().firstElementChild.classList.contains('editable-overlay')).toBe(true);
    });

    it('toasts the server error and leaves the block as it was on a refusal', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Contenu trop long.' }));
        await boot();

        document.querySelector('.editable-content .editable-edit-btn').dispatchEvent(new Event('click'));
        document.getElementById('richTextEditorContent').innerHTML = '<p>Texte modifié.</p>';
        document.getElementById('richTextEditorSave').dispatchEvent(new Event('click'));
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Contenu trop long.', { variant: 'error' });
        expect(block().innerHTML).toContain('<p>Texte actuel.</p>');
        expect(modalStub.hide).not.toHaveBeenCalled();
    });

    it('falls back to a generic French line when the server names no error', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false }));
        await boot();

        document.querySelector('.editable-content .editable-edit-btn').dispatchEvent(new Event('click'));
        document.getElementById('richTextEditorSave').dispatchEvent(new Event('click'));
        await settle();

        expect(window.ScoutMagicToast.show)
            .toHaveBeenCalledWith('Erreur lors de l\'enregistrement.', { variant: 'error' });
    });

    it('toasts « Erreur réseau. » when the request never lands', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot();

        document.querySelector('.editable-content .editable-edit-btn').dispatchEvent(new Event('click'));
        document.getElementById('richTextEditorSave').dispatchEvent(new Event('click'));
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Erreur réseau.', { variant: 'error' });
        expect(modalStub.hide).not.toHaveBeenCalled();
    });
});

// Issue #306: « la plupart du temps impossible d'appliquer une mise en
// page » and « visible une fois sauvé, disparaît quand la page est
// rechargée ». Both were in this file.
describe('editable.js: the toolbar (issue #306)', () => {
    it('passes the button\'s data-value to formatBlock instead of null', async () => {
        await boot();

        document.querySelector('[data-command="formatBlock"]').dispatchEvent(new Event('click'));

        // Was `execCommand('formatBlock', false, null)`, which is the H2,
        // H3 and « Paragraphe » buttons of every page doing nothing at all.
        expect(document.execCommand).toHaveBeenCalledWith('formatBlock', false, '<h2>');
    });

    it('runs a command once when rich-text-field.js drives the same modal', async () => {
        buildDom();
        vi.resetModules();
        await import('../../public/assets/js/rich-text-link.js');
        await import('../../public/assets/js/editable.js');
        // The configuration-mode page: both scripts, one modal, the same
        // buttons. Two handlers on a toggle like bold applied it and undid
        // it in the same click.
        await import('../../public/assets/js/rich-text-field.js');

        document.querySelector('[data-command="bold"]').dispatchEvent(new Event('click'));

        expect(document.execCommand).toHaveBeenCalledTimes(1);
    });

    it('repaints the block with what the SERVER stored, not with what it sent', async () => {
        // The sanitiser prunes the wrapper markup a browser leaves behind
        // when a heading is applied, so the two differ — and repainting
        // with the sent copy is what made a heading survive the save and
        // then vanish on the next page load (issue #306).
        global.fetch = vi.fn(() => jsonResponse({ success: true, value: '<h2>Titre</h2>Texte.' }));
        await boot();

        document.querySelector('.editable-content .editable-edit-btn').dispatchEvent(new Event('click'));
        document.getElementById('richTextEditorContent').innerHTML =
            '<div><h1>Titre</h1><span class="x">Texte.</span></div>';
        document.getElementById('richTextEditorSave').dispatchEvent(new Event('click'));
        await settle();

        expect(postedBody().value).toBe('<div><h1>Titre</h1><span class="x">Texte.</span></div>');
        expect(block().innerHTML).toContain('<h2>Titre</h2>');
        expect(block().innerHTML).not.toContain('<h1>');
    });

    it('falls back to what it sent when a save route answers without a value', async () => {
        // rich-text-field.js posts to a caller-supplied URL; a module that
        // grows one later must not blank the block for want of the field.
        await boot();

        document.querySelector('.editable-content .editable-edit-btn').dispatchEvent(new Event('click'));
        document.getElementById('richTextEditorContent').innerHTML = '<p>Texte modifié.</p>';
        document.getElementById('richTextEditorSave').dispatchEvent(new Event('click'));
        await settle();

        expect(block().innerHTML).toContain('<p>Texte modifié.</p>');
    });
});
