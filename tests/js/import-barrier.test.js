// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/import-barrier.js
// (imported below, never reimplemented here): the danger zone of the Desk
// import barrier.
//
// The file is an IIFE that binds a DOMContentLoaded listener at import
// time, so each test builds its DOM first and then imports the module via
// vi.resetModules() + await import().
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function buildDangerZone(word) {
    document.body.innerHTML = `
        <form>
            <input type="text" id="barrier-confirm-keyword" data-confirm-word="${word}">
            <button type="submit" id="barrier-submit" disabled>Remplacer le roster</button>
        </form>
    `;
}

const listeners = [];

async function load() {
    vi.resetModules();
    const register = document.addEventListener.bind(document);
    vi.spyOn(document, 'addEventListener').mockImplementation((type, handler, options) => {
        listeners.push([type, handler, options]);
        register(type, handler, options);
    });
    await import('../../public/assets/js/import-barrier.js');
    document.dispatchEvent(new Event('DOMContentLoaded'));
}

function type(value) {
    const input = document.getElementById('barrier-confirm-keyword');
    input.value = value;
    input.dispatchEvent(new Event('input'));
}

describe('import-barrier', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    afterEach(() => {
        listeners.splice(0).forEach(([type, handler, options]) =>
            document.removeEventListener(type, handler, options));
        vi.restoreAllMocks();
    });

    describe('matchesConfirmation', () => {
        it('accepts the exact word', async () => {
            await load();

            expect(window.ScoutMagicImportBarrier.matchesConfirmation('REMPLACER', 'REMPLACER')).toBe(true);
        });

        it('forgives surrounding whitespace and case', async () => {
            await load();

            const { matchesConfirmation } = window.ScoutMagicImportBarrier;
            expect(matchesConfirmation('  REMPLACER ', 'REMPLACER')).toBe(true);
            expect(matchesConfirmation('remplacer', 'REMPLACER')).toBe(true);
        });

        it('refuses a word that merely contains the confirmation', async () => {
            await load();

            const { matchesConfirmation } = window.ScoutMagicImportBarrier;
            expect(matchesConfirmation('REMPLACERA', 'REMPLACER')).toBe(false);
            expect(matchesConfirmation('JE REMPLACER', 'REMPLACER')).toBe(false);
        });

        it('refuses another word, an empty field, and a missing expectation', async () => {
            await load();

            const { matchesConfirmation } = window.ScoutMagicImportBarrier;
            expect(matchesConfirmation('CONFIRMER', 'REMPLACER')).toBe(false);
            expect(matchesConfirmation('', 'REMPLACER')).toBe(false);
            expect(matchesConfirmation('REMPLACER', '')).toBe(false);
        });
    });

    describe('the submit button', () => {
        it('stays disabled until the word is typed', async () => {
            buildDangerZone('REMPLACER');
            await load();

            const submit = document.getElementById('barrier-submit');
            expect(submit.disabled).toBe(true);

            type('REMPL');
            expect(submit.disabled).toBe(true);

            type('REMPLACER');
            expect(submit.disabled).toBe(false);
        });

        it('disables itself again when the word is edited away', async () => {
            buildDangerZone('REMPLACER');
            await load();

            const submit = document.getElementById('barrier-submit');
            type('REMPLACER');
            expect(submit.disabled).toBe(false);

            type('REMPLACE');
            expect(submit.disabled).toBe(true);
        });

        it('does nothing at all on a page without the danger zone', async () => {
            document.body.innerHTML = '<p>Import refusé</p>';

            await expect(load()).resolves.toBeUndefined();
        });
    });
});
