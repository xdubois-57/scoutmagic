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
// The one thing deliberately NOT pinned here is the toolbar's createLink
// branch: it still opens a native prompt() for the URL, the last native
// dialog in this file, and a test asserting that would lock in behaviour
// that is waiting on a replacement component (design.md §7.5).
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
            <div id="richTextEditorContent"></div>
            <button id="richTextEditorSave">Enregistrer</button>
        </div>
    `;
}

async function boot() {
    buildDom();
    vi.resetModules();
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
    // editable.js reads the bare `bootstrap` binding, not window.bootstrap.
    window.bootstrap = { Modal: vi.fn(() => modalStub) };
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
