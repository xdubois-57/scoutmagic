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

// #883: « Accueil » reloaded `/`, and without a saved copy the service
// worker answered with this very screen — a button that seemed to do
// nothing. It shows only when a recent copy of the home page exists.
describe('offline-page.js: « Accueil » only when the home page is saved', () => {
    const DOM = `
        <p id="offline-nothing-saved" class="d-none">Aucune page n'est encore enregistrée sur cet appareil.</p>
        <a href="/" id="offline-home-link" class="d-none">Accueil</a>
    `;

    /** Cache Storage holding $copies: {cacheName: {url: dateHeader}}. */
    function storage(copies) {
        return {
            keys: vi.fn(async () => Object.keys(copies)),
            open: vi.fn(async (name) => ({
                match: vi.fn(async (url) => (copies[name]?.[url] !== undefined
                    ? { headers: { get: (h) => (h === 'Date' ? copies[name][url] : null) } }
                    : undefined)),
            })),
        };
    }

    async function settle() {
        for (let i = 0; i < 10; i++) {
            await Promise.resolve();
        }
    }

    it('offers « Accueil » when a recent copy of the home page is saved', async () => {
        globalThis.caches = storage({ 'app-shell-1': {}, 'content-7-1.0': { '/': new Date().toUTCString() } });
        document.body.dataset.stalenessDays = '30';

        await loadOfflinePage(DOM);
        await settle();

        expect(document.getElementById('offline-home-link').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('offline-nothing-saved').classList.contains('d-none')).toBe(true);
    });

    it('says nothing is saved when there is no copy of the home page', async () => {
        globalThis.caches = storage({ 'content-7-1.0': { '/contact': new Date().toUTCString() } });

        await loadOfflinePage(DOM);
        await settle();

        expect(document.getElementById('offline-home-link').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('offline-nothing-saved').classList.contains('d-none')).toBe(false);
    });

    it('does not count a copy older than the service worker would serve', async () => {
        const fortyDaysAgo = new Date(Date.now() - 40 * 86400000).toUTCString();
        globalThis.caches = storage({ 'content-7-1.0': { '/': fortyDaysAgo } });
        document.body.dataset.stalenessDays = '30';

        await loadOfflinePage(DOM);
        await settle();

        expect(document.getElementById('offline-home-link').classList.contains('d-none')).toBe(true);
    });

    it('leaves both hidden when Cache Storage cannot be read', async () => {
        globalThis.caches = { keys: vi.fn(() => Promise.reject(new Error('SecurityError'))) };

        await loadOfflinePage(DOM);
        await settle();

        expect(document.getElementById('offline-home-link').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('offline-nothing-saved').classList.contains('d-none')).toBe(true);
    });
});
