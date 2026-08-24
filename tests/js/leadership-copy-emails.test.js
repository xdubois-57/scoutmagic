// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in
// public/assets/js/leadership-copy-emails.js (imported below, never
// reimplemented here): « Copier les adresses » on the Encadrement lists.
//
// The file is an IIFE that binds a delegated listener at import time, so
// each test builds its DOM first and then imports the module via
// vi.resetModules() + await import() (the tests/js/chip-picker.test.js
// pattern).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function buildList(rows) {
    document.body.innerHTML = `
        <button type="button" data-copy-emails="training-to-convince">Copier les adresses</button>
        <ul id="training-to-convince">
            ${rows.map((r) => `<li${r === null ? '' : ` data-email="${r}"`}>Quelqu'un</li>`).join('')}
        </ul>
        <ul id="empty-list"><li>Personne sans adresse</li></ul>
    `;
    return document.getElementById('training-to-convince');
}

// The file installs ONE delegated listener on the document, so each test
// re-imports it into a fresh module registry and the listener is removed
// afterwards — otherwise every later click would be handled once per test
// already run (the tests/js/reveal-details.test.js pattern).
const listeners = [];

async function load() {
    vi.resetModules();
    const register = document.addEventListener.bind(document);
    vi.spyOn(document, 'addEventListener').mockImplementation((type, handler, options) => {
        listeners.push([type, handler, options]);
        register(type, handler, options);
    });
    await import('../../public/assets/js/leadership-copy-emails.js');
}

describe('leadership-copy-emails', () => {
    let written;

    afterEach(() => {
        listeners.splice(0).forEach(([type, handler, options]) =>
            document.removeEventListener(type, handler, options));
        vi.restoreAllMocks();
    });

    beforeEach(async () => {
        written = [];
        document.body.innerHTML = '';
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: vi.fn(async (text) => {
                    written.push(text);
                }),
            },
        });
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { prompt: vi.fn(async () => null), ask: vi.fn(async () => true) };
    });

    describe('collect', () => {
        it('reads the addresses of one list, in the order they are shown', async () => {
            const list = buildList(['a@example.org', 'b@example.org']);
            await load();

            expect(window.ScoutMagicLeadershipEmails.collect(list))
                .toEqual(['a@example.org', 'b@example.org']);
        });

        it('skips the rows Desk holds no address for', async () => {
            const list = buildList(['a@example.org', null, 'b@example.org']);
            await load();

            expect(window.ScoutMagicLeadershipEmails.collect(list))
                .toEqual(['a@example.org', 'b@example.org']);
        });

        it('keeps one copy of somebody who holds two functions', async () => {
            // An animateur who is also an intendant appears once per
            // function; pasting their address twice is how a mail client
            // starts warning about it.
            const list = buildList(['a@example.org', ' A@Example.org ', 'b@example.org']);
            await load();

            expect(window.ScoutMagicLeadershipEmails.collect(list))
                .toEqual(['a@example.org', 'b@example.org']);
        });

        it('never rewrites the address it copies', async () => {
            const list = buildList(['Jean.Martin@Example.ORG']);
            await load();

            expect(window.ScoutMagicLeadershipEmails.collect(list))
                .toEqual(['Jean.Martin@Example.ORG']);
        });
    });

    describe('format', () => {
        it('joins the addresses the way a To: field wants them', async () => {
            await load();

            expect(window.ScoutMagicLeadershipEmails.format(['a@example.org', 'b@example.org']))
                .toBe('a@example.org; b@example.org');
        });

        it('is empty for a list with nothing to copy', async () => {
            await load();

            expect(window.ScoutMagicLeadershipEmails.format([])).toBe('');
        });
    });

    describe('the button', () => {
        it('copies the list it names and says so', async () => {
            buildList(['a@example.org', 'b@example.org']);
            await load();

            document.querySelector('[data-copy-emails]').click();
            await Promise.resolve();
            await Promise.resolve();

            expect(written).toEqual(['a@example.org; b@example.org']);
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                'Adresses copiées.',
                { variant: 'success' }
            );
        });

        it('says so rather than copying nothing when no row carries an address', async () => {
            buildList([null, null]);
            await load();

            document.querySelector('[data-copy-emails]').click();
            await Promise.resolve();

            expect(written).toEqual([]);
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                expect.stringContaining('Aucune adresse'),
                { variant: 'warning' }
            );
        });

        it('hands the addresses over when the clipboard API is unavailable', async () => {
            buildList(['a@example.org']);
            Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
            await load();

            document.querySelector('[data-copy-emails]').click();
            await Promise.resolve();
            await Promise.resolve();

            // A button that silently does nothing is worse than no button.
            expect(window.ScoutMagicConfirm.prompt).toHaveBeenCalledWith(
                expect.objectContaining({ value: 'a@example.org', readonly: true })
            );
        });
    });
});
