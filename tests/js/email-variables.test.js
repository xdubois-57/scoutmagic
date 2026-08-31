// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in
// public/assets/js/email-variables.js (imported below, never
// reimplemented here): the variable insertion buttons of Configuration >
// E-mails.
//
// What is pinned here is the fix for the part of the page that made
// writing an e-mail tedious: the buttons work INSIDE the rich-text
// editor, at the caret. Before, the body could only be edited in a modal
// the buttons were not in, so adding three variables meant saving,
// copying a placeholder, reopening the editor and pasting — three times.
//
// The file is an IIFE that binds its listeners at import time, so each
// test builds its DOM first and then imports the module via
// vi.resetModules() + await import().
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/email-variables.js');
}

/** A caret (or a selection) inside `node`, the way a writer leaves one. */
function placeCaret(node, start, end = start) {
    const range = document.createRange();
    range.setStart(node, start);
    range.setEnd(node, end);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
}

describe('email-variables', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        window.ScoutMagicToast = { show: vi.fn() };
    });

    describe('inside the rich-text editor', () => {
        beforeEach(() => {
            document.body.innerHTML = `
                <div id="richTextEditorContent" contenteditable="true"></div>
                <div class="email-variable-buttons" data-target="richTextEditorContent">
                    <button type="button" data-variable="renter_name">Nom</button>
                    <button type="button" data-variable="reference">Référence</button>
                </div>
            `;
        });

        it('inserts the placeholder where the caret is, not at the end', async () => {
            const editor = document.getElementById('richTextEditorContent');
            editor.textContent = 'Bonjour , voici votre demande.';
            await load();

            placeCaret(editor.firstChild, 8);
            document.querySelector('[data-variable="renter_name"]').click();

            expect(editor.textContent).toBe('Bonjour {{ renter_name }}, voici votre demande.');
        });

        it('leaves the caret after what it inserted, so two clicks give two variables', async () => {
            const editor = document.getElementById('richTextEditorContent');
            editor.textContent = 'Bonjour .';
            await load();

            placeCaret(editor.firstChild, 8);
            document.querySelector('[data-variable="renter_name"]').click();
            document.querySelector('[data-variable="reference"]').click();

            expect(editor.textContent).toBe('Bonjour {{ renter_name }}{{ reference }}.');
        });

        it('replaces the selected text rather than adding to it', async () => {
            const editor = document.getElementById('richTextEditorContent');
            editor.textContent = 'Bonjour NOM,';
            await load();

            placeCaret(editor.firstChild, 8, 11);
            document.querySelector('[data-variable="renter_name"]').click();

            expect(editor.textContent).toBe('Bonjour {{ renter_name }},');
        });

        it('appends at the end when the editor was never clicked into', async () => {
            const editor = document.getElementById('richTextEditorContent');
            editor.textContent = 'Bonjour,';
            window.getSelection().removeAllRanges();
            await load();

            document.querySelector('[data-variable="reference"]').click();

            expect(editor.textContent).toBe('Bonjour,{{ reference }}');
        });

        it('inserts text, never markup — the braces are the point', async () => {
            const editor = document.getElementById('richTextEditorContent');
            editor.innerHTML = '<p>Bonjour</p>';
            await load();

            document.querySelector('[data-variable="reference"]').click();

            expect(editor.innerHTML).toContain('{{ reference }}');
            expect(editor.querySelectorAll('p').length).toBe(1);
        });

        it('keeps the caret by refusing the focus a mousedown would take', async () => {
            await load();

            const event = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
            document.querySelector('[data-variable="reference"]').dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
        });
    });

    describe('in the subject field', () => {
        beforeEach(() => {
            document.body.innerHTML = `
                <input type="text" id="email-subject" value="Votre demande">
                <div class="email-variable-buttons" data-target="email-subject">
                    <button type="button" data-variable="reference">Référence</button>
                </div>
            `;
        });

        it('inserts at the caret of the input', async () => {
            const field = document.getElementById('email-subject');
            await load();

            field.setSelectionRange(6, 6);
            document.querySelector('[data-variable="reference"]').click();

            expect(field.value).toBe('Votre {{ reference }}demande');
        });
    });

    describe('on the page, with no target', () => {
        it('copies the placeholder and says so', async () => {
            const written = [];
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: { writeText: vi.fn(async (text) => { written.push(text); }) },
            });
            document.body.innerHTML = `
                <div class="email-variable-buttons">
                    <button type="button" data-variable="reference">Référence</button>
                </div>
            `;
            await load();

            document.querySelector('[data-variable="reference"]').click();
            await Promise.resolve();

            expect(written).toEqual(['{{ reference }}']);
            expect(window.ScoutMagicToast.show).toHaveBeenCalled();
        });
    });
});
