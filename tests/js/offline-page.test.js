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
        <p id="offline-nothing-saved" class="d-none">La page d'accueil n'est pas enregistrée sur cet appareil.</p>
        <a href="/" id="offline-home-link" class="d-none">Accueil</a>
    `;

    /** The config public/sw.js keeps: account scope 7, release 1.0. */
    const CONFIG = { consent: true, account_scope: '7', version: '1.0', staleness_days: 30 };

    /**
     * Cache Storage holding $copies ({cacheName: {url: dateHeader}}) and,
     * unless $config is null, the service worker's config.
     */
    function storage(copies, config = CONFIG) {
        const all = { ...copies };
        if (config !== null) {
            all['offline-config'] = { 'https://offline-config.internal/v1': config };
        }
        return {
            keys: vi.fn(async () => Object.keys(all)),
            has: vi.fn(async (name) => name in all),
            open: vi.fn(async (name) => ({
                match: vi.fn(async (url) => {
                    const entry = all[name]?.[url];
                    if (entry === undefined) {
                        return undefined;
                    }
                    return {
                        headers: { get: (h) => (h === 'Date' ? entry : null) },
                        json: async () => entry,
                    };
                }),
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

    // A deploy changes the version, and the previous release's content
    // cache stays: the worker no longer reads it, so it must not count.
    it('does not count a copy in a cache the service worker no longer reads', async () => {
        globalThis.caches = storage({ 'content-7-0.9': { '/': new Date().toUTCString() } });

        await loadOfflinePage(DOM);
        await settle();

        expect(document.getElementById('offline-home-link').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('offline-nothing-saved').classList.contains('d-none')).toBe(false);
    });

    it('does not count a copy once consent is withdrawn, nor without a config at all', async () => {
        const copies = { 'content-7-1.0': { '/': new Date().toUTCString() } };

        globalThis.caches = storage(copies, { ...CONFIG, consent: false });
        await loadOfflinePage(DOM);
        await settle();
        expect(document.getElementById('offline-home-link').classList.contains('d-none')).toBe(true);

        globalThis.caches = storage(copies, null);
        await loadOfflinePage(DOM);
        await settle();
        expect(document.getElementById('offline-home-link').classList.contains('d-none')).toBe(true);
    });

    it('does not count a copy dated ahead of this device\'s clock, as the service worker does not', async () => {
        const anHourAhead = new Date(Date.now() + 3600000).toUTCString();
        globalThis.caches = storage({ 'content-7-1.0': { '/': anHourAhead } });

        await loadOfflinePage(DOM);
        await settle();

        expect(document.getElementById('offline-home-link').classList.contains('d-none')).toBe(true);
    });

    // The figure baked into the page when it was precached outlives a
    // change of the setting; the worker reads its config, and so must this.
    it('judges a copy by the service worker\'s own threshold, not the one baked into the page', async () => {
        const tenDaysAgo = new Date(Date.now() - 10 * 86400000).toUTCString();
        globalThis.caches = storage({ 'content-7-1.0': { '/': tenDaysAgo } }, { ...CONFIG, staleness_days: 3 });
        document.body.dataset.stalenessDays = '30';

        await loadOfflinePage(DOM);
        await settle();

        expect(document.getElementById('offline-home-link').classList.contains('d-none')).toBe(true);
    });

    it('falls back to the service worker\'s own default of seven days', async () => {
        const tenDaysAgo = new Date(Date.now() - 10 * 86400000).toUTCString();
        const { staleness_days: _unset, ...withoutThreshold } = CONFIG;
        globalThis.caches = storage({ 'content-7-1.0': { '/': tenDaysAgo } }, withoutThreshold);

        await loadOfflinePage(DOM);
        await settle();

        expect(document.getElementById('offline-home-link').classList.contains('d-none')).toBe(true);
    });

    it('leaves both hidden when Cache Storage cannot be read', async () => {
        globalThis.caches = { open: vi.fn(() => Promise.reject(new Error('SecurityError'))) };

        await loadOfflinePage(DOM);
        await settle();

        expect(document.getElementById('offline-home-link').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('offline-nothing-saved').classList.contains('d-none')).toBe(true);
    });
});
