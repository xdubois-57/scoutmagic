// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/fees-copy.js
// (imported below, never reimplemented here): « Copier pour Desk » on
// Cotisations > Justesse des tarifs.
//
// The file is an IIFE that binds a delegated listener at import time, so
// each test builds its DOM first and then imports the module via
// vi.resetModules() + await import() (the tests/js/leadership-copy-emails
// .test.js pattern).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function buildPage(texts) {
    document.body.innerHTML = `
        <button type="button" data-fees-copy="abc123">Copier pour Desk</button>
        <button type="button" data-fees-copy="missing">Copier pour Desk</button>
        <script type="application/json" id="fees-clipboard-data">${JSON.stringify(texts)}</script>
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
    await import('../../public/assets/js/fees-copy.js');
}

describe('fees-copy', () => {
    let written;

    afterEach(() => {
        listeners.splice(0).forEach(([type, handler, options]) =>
            document.removeEventListener(type, handler, options));
        vi.restoreAllMocks();
    });

    beforeEach(() => {
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
        window.ScoutMagicApi = {
            pageData: (id) => {
                const el = document.getElementById(id);
                if (!el) {
                    return null;
                }
                try {
                    return JSON.parse(el.textContent || 'null');
                } catch (e) {
                    return null;
                }
            },
        };
    });

    describe('blockFor', () => {
        it('returns the block the page carries for that household', async () => {
            await load();

            expect(window.ScoutMagicFeesCopy.blockFor({ abc123: 'Rue X 1\nJean' }, 'abc123'))
                .toBe('Rue X 1\nJean');
        });

        it('is empty for a household the page carries nothing for', async () => {
            await load();

            expect(window.ScoutMagicFeesCopy.blockFor({ abc123: 'x' }, 'nope')).toBe('');
        });

        it('is empty when the island is missing altogether', async () => {
            await load();

            expect(window.ScoutMagicFeesCopy.blockFor(null, 'abc123')).toBe('');
        });

        it('never returns a non-string a hand-edited island might carry', async () => {
            await load();

            expect(window.ScoutMagicFeesCopy.blockFor({ abc123: 42 }, 'abc123')).toBe('');
        });
    });

    describe('the button', () => {
        it('copies the household it names and says so', async () => {
            buildPage({ abc123: 'Rue de la Station 5, 1000 Bruxelles\n3 membres' });
            await load();

            document.querySelector('[data-fees-copy="abc123"]').click();
            await Promise.resolve();
            await Promise.resolve();

            expect(written).toEqual(['Rue de la Station 5, 1000 Bruxelles\n3 membres']);
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Foyer copié.', { variant: 'success' });
        });

        it('says so rather than copying nothing when the page carries no block', async () => {
            buildPage({ abc123: 'x' });
            await load();

            document.querySelector('[data-fees-copy="missing"]').click();
            await Promise.resolve();

            expect(written).toEqual([]);
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                expect.stringContaining('Rien à copier'),
                { variant: 'warning' }
            );
        });

        it('hands the block over when the clipboard API is unavailable', async () => {
            buildPage({ abc123: 'Rue X 1' });
            Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
            await load();

            document.querySelector('[data-fees-copy="abc123"]').click();
            await Promise.resolve();
            await Promise.resolve();

            expect(window.ScoutMagicConfirm.prompt).toHaveBeenCalledWith(
                expect.objectContaining({ value: 'Rue X 1', readonly: true })
            );
        });
    });
});
