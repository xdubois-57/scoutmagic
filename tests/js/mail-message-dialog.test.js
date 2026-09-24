// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/mail-message-dialog.js.
//
// « Lire le message » on a mail triage screen: one dialog for the page,
// whose body is MOVED from the row and put back on close — never
// re-parsed from a string.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function buildPage() {
    document.body.innerHTML = `
        <div id="row-1">
            <button type="button" id="open-1"
                    data-mail-message-open="body-1"
                    data-mail-message-title="Le pré"
                    data-mail-message-meta="Lambert · 1 juin 2026">Lire</button>
            <div id="body-1" class="d-none"><p>Bonjour</p></div>
        </div>
        <div id="row-2">
            <button type="button" id="open-2" data-mail-message-open="body-2">Lire</button>
            <div id="body-2" class="d-none"><p>Re</p></div>
        </div>
        <button type="button" id="open-missing" data-mail-message-open="nope">Lire</button>
        <div id="mail-message-modal">
            <h2 id="mail-message-modal-title">Message</h2>
            <p id="mail-message-modal-meta"></p>
            <div id="mail-message-modal-body"></div>
        </div>
    `;
}

const el = (id) => document.getElementById(id);

// Imported ONCE: the script listens for DOMContentLoaded on the document,
// which outlives every test here. Importing it again per test would stack
// a second listener — and a second, independent set of click handlers with
// their own idea of where a body came from.
await import('../../public/assets/js/mail-message-dialog.js');

async function load() {
    document.dispatchEvent(new Event('DOMContentLoaded'));
}

describe('mail-message-dialog', () => {
    beforeEach(() => {
        buildPage();
        window.bootstrap = { Modal: { getOrCreateInstance: vi.fn(() => ({ show: vi.fn() })) } };
    });

    afterEach(() => {
        delete window.bootstrap;
    });

    it('moves the row body into the dialog and names the message', async () => {
        await load();

        el('open-1').click();

        expect(el('mail-message-modal-body').contains(el('body-1'))).toBe(true);
        expect(el('body-1').classList.contains('d-none')).toBe(false);
        expect(el('mail-message-modal-title').textContent).toBe('Le pré');
        expect(el('mail-message-modal-meta').textContent).toBe('Lambert · 1 juin 2026');
        expect(window.bootstrap.Modal.getOrCreateInstance).toHaveBeenCalledWith(el('mail-message-modal'));
    });

    it('gives the body back on close, hidden, so a second opening finds it', async () => {
        await load();
        el('open-1').click();

        // Bubbling, as Bootstrap's own EventHandler dispatches it: the
        // script listens on the document.
        el('mail-message-modal').dispatchEvent(new Event('hidden.bs.modal', { bubbles: true }));

        expect(el('row-1').contains(el('body-1'))).toBe(true);
        expect(el('body-1').classList.contains('d-none')).toBe(true);
        expect(el('mail-message-modal-body').children).toHaveLength(0);

        el('open-1').click();
        expect(el('mail-message-modal-body').contains(el('body-1'))).toBe(true);
    });

    it('opening another message returns the first one to its row', async () => {
        await load();
        el('open-1').click();

        el('open-2').click();

        expect(el('row-1').contains(el('body-1'))).toBe(true);
        expect(el('body-1').classList.contains('d-none')).toBe(true);
        expect(el('mail-message-modal-body').contains(el('body-2'))).toBe(true);
        expect(el('mail-message-modal-title').textContent).toBe('Message');
        expect(el('mail-message-modal-meta').textContent).toBe('');
    });

    it('does nothing for a button whose body does not exist', async () => {
        await load();

        el('open-missing').click();

        expect(el('mail-message-modal-body').children).toHaveLength(0);
    });

    it('still moves the body without Bootstrap', async () => {
        delete window.bootstrap;
        await load();

        el('open-1').click();

        expect(el('mail-message-modal-body').contains(el('body-1'))).toBe(true);
    });

    it('is inert on a page without the dialog', async () => {
        document.body.innerHTML = '<button id="open-1" data-mail-message-open="body-1">Lire</button><div id="body-1" class="d-none"></div>';
        await load();

        el('open-1').click();

        expect(el('body-1').classList.contains('d-none')).toBe(true);
    });

    it('opens on a button that arrived after the page loaded', async () => {
        await load();
        // What a panel refresh does: the row is replaced by a fresh one.
        el('row-1').innerHTML = `
            <button type="button" id="open-late" data-mail-message-open="body-late">Lire</button>
            <div id="body-late" class="d-none"><p>Arrivé après</p></div>`;

        el('open-late').click();

        expect(el('mail-message-modal-body').contains(el('body-late'))).toBe(true);
    });
});
