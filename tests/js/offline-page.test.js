// Isolated JavaScript unit test — jsdom DOM only, no PHP/DB/network.
// Exercises the REAL public/assets/js/offline-page.js (imported below,
// never reimplemented).
//
// Small file, but the one page on the site where a broken script has no
// fallback: it is served from the precache with no network at all, so if
// its two buttons do nothing the visitor is stuck on a dead end with no
// way back. That is also why it must never assume more than the DOM it
// ships with — both guards below are load-bearing, not defensive habit.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadOfflinePage(html) {
    document.body.innerHTML = html;
    vi.resetModules();
    await import('../../public/assets/js/offline-page.js');
}

const OFFLINE_DOM = `
    <button id="offline-retry-btn">Réessayer</button>
    <button id="offline-back-btn">Revenir en arrière</button>
`;

let reload;
let back;

beforeEach(() => {
    document.body.innerHTML = '';
    reload = vi.fn();
    back = vi.fn();
    // jsdom's location.reload is not configurable by assignment.
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { ...window.location, reload },
    });
    Object.defineProperty(window, 'history', {
        configurable: true,
        value: { ...window.history, back },
    });
});

describe('offline-page.js', () => {
    it('retries the page when the visitor asks', async () => {
        await loadOfflinePage(OFFLINE_DOM);

        document.getElementById('offline-retry-btn').click();

        expect(reload).toHaveBeenCalledTimes(1);
        expect(back).not.toHaveBeenCalled();
    });

    it('goes back through the history, not to a hardcoded page', async () => {
        await loadOfflinePage(OFFLINE_DOM);

        document.getElementById('offline-back-btn').click();

        // history.back() returns to whatever the visitor came from, which
        // offline is likely the only other page still in the cache.
        expect(back).toHaveBeenCalledTimes(1);
        expect(reload).not.toHaveBeenCalled();
    });

    it('wires whichever button it finds, alone', async () => {
        await loadOfflinePage('<button id="offline-back-btn">Revenir</button>');

        document.getElementById('offline-back-btn').click();
        expect(back).toHaveBeenCalledTimes(1);
    });

    it('throws nothing when neither button is there', async () => {
        await expect(loadOfflinePage('<p>Hors ligne</p>')).resolves.not.toThrow();
        expect(reload).not.toHaveBeenCalled();
        expect(back).not.toHaveBeenCalled();
    });
});
