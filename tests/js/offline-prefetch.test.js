// Isolated JavaScript unit test — jsdom only. Exercises the real
// public/assets/js/offline-prefetch.js against a fake Cache Storage and a
// mocked fetch: the pre-download runs once per launch of the installed
// app, in idle time, a bounded number of requests at a time, and not at
// all where it must not.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function createCachesFake() {
    const store = new Map();
    const cache = {
        match: vi.fn((url) => Promise.resolve(store.get(url))),
        put: vi.fn((url, res) => { store.set(url, res); return Promise.resolve(); }),
        keys: vi.fn(() => Promise.resolve([...store.keys()].map((url) => ({ url: 'https://example.test' + url })))),
        delete: vi.fn((req) => { store.delete(new URL(req.url).pathname); return Promise.resolve(true); }),
    };
    return { store, cache, open: vi.fn(() => Promise.resolve(cache)) };
}

function response(body, { status = 200, headers = {} } = {}) {
    const h = new Map(Object.entries(headers));
    return {
        ok: status >= 200 && status < 300,
        status,
        statusText: 'OK',
        headers: { get: (n) => h.get(n) ?? null },
        json: () => Promise.resolve(JSON.parse(body)),
        clone() { return response(body, { status, headers }); },
        blob: () => Promise.resolve(body),
    };
}

function config(overrides = {}) {
    const el = document.createElement('script');
    el.type = 'application/json';
    el.id = 'offline-config-data';
    el.textContent = JSON.stringify({ consent: true, accountScope: 'acct1', version: '1.2.3', whitelist: [], ...overrides });
    document.body.appendChild(el);
}

function standalone(on) {
    window.matchMedia = /** @type {any} */ ((query) => ({ matches: on && query === '(display-mode: standalone)', media: query }));
}

const MANIFEST = { pages: ['/', '/contact', '/calendar', '/groups', '/members/1'], images: ['/files/1/thumb', '/files/2/md'] };
let inFlight;
let maxInFlight;

function mockFetch(manifest = MANIFEST) {
    inFlight = 0;
    maxInFlight = 0;
    global.fetch = vi.fn((url) => {
        inFlight++;
        maxInFlight = Math.max(maxInFlight, inFlight);
        return new Promise((resolve) => setTimeout(() => {
            inFlight--;
            resolve(url === '/api/offline/manifest' ? response(JSON.stringify(manifest)) : response('<body>page</body>'));
        }, 10));
    });
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/offline-prefetch.js');
}

async function settle() {
    await vi.runAllTimersAsync();
}

let caches;

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '';
    sessionStorage.clear();
    caches = createCachesFake();
    global.caches = caches;
    global.Headers = class { constructor(init) { this.map = new Map(Object.entries(init instanceof Map ? Object.fromEntries(init) : {})); } set(k, v) { this.map.set(k, v); } };
    global.Response = class { constructor(body, init) { this.body = body; this.status = init.status; this.headers = init.headers; } };
    delete window.requestIdleCallback;
    standalone(true);
    Object.defineProperty(document, 'readyState', { configurable: true, value: 'complete' });
    mockFetch();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('offline-prefetch.js: when it runs', () => {
    it('runs once on the first page of a launch of the installed app', async () => {
        config();
        await boot();
        await settle();

        expect(global.fetch).toHaveBeenCalledWith('/api/offline/manifest', expect.anything());
        expect(caches.store.size).toBe(MANIFEST.pages.length + MANIFEST.images.length);
    });

    it('does NOT run again on the next page of the same launch', async () => {
        config();
        await boot();
        await settle();
        const callsAfterFirstPage = global.fetch.mock.calls.length;

        document.body.innerHTML = '';
        config();
        await boot();
        await settle();

        expect(global.fetch.mock.calls.length).toBe(callsAfterFirstPage);
    });

    it('runs again once the last run is a day old — an app left open for days still refreshes', async () => {
        sessionStorage.setItem('scoutmagic-offline-prefetch', JSON.stringify({ scope: 'acct1|1.2.3', at: Date.now() - 25 * 60 * 60 * 1000 }));
        config();
        await boot();
        await settle();

        expect(global.fetch).toHaveBeenCalledWith('/api/offline/manifest', expect.anything());
    });

    it('runs for another account or another version even within the same session', async () => {
        sessionStorage.setItem('scoutmagic-offline-prefetch', JSON.stringify({ scope: 'someone-else|1.2.3', at: Date.now() }));
        config();
        await boot();
        await settle();

        expect(global.fetch).toHaveBeenCalledWith('/api/offline/manifest', expect.anything());
    });

    it('waits for idle time after load rather than competing with the page', async () => {
        Object.defineProperty(document, 'readyState', { configurable: true, value: 'loading' });
        const idle = vi.fn((cb) => cb());
        window.requestIdleCallback = idle;
        config();
        await boot();

        expect(global.fetch).not.toHaveBeenCalled();
        window.dispatchEvent(new Event('load'));
        await settle();

        expect(idle).toHaveBeenCalled();
        expect(global.fetch).toHaveBeenCalledWith('/api/offline/manifest', expect.anything());
    });

    it.each([
        ['a browser tab', () => standalone(false)],
        ['no consent', () => {}, { consent: false }],
        ['an anonymous visitor', () => {}, { accountScope: 'guest' }],
        ['Data Saver', () => Object.defineProperty(navigator, 'connection', { configurable: true, value: { saveData: true } })],
    ])('never runs in %s', async (_label, arrange, overrides = {}) => {
        arrange();
        config(overrides);
        await boot();
        await settle();

        expect(global.fetch).not.toHaveBeenCalled();
        Object.defineProperty(navigator, 'connection', { configurable: true, value: undefined });
    });
});

describe('offline-prefetch.js: how it runs', () => {
    it('keeps at most two requests in flight, pages before images', async () => {
        config();
        await boot();
        await settle();

        expect(maxInFlight).toBeLessThanOrEqual(2);
        const order = global.fetch.mock.calls.map((c) => c[0]);
        const lastPage = Math.max(...MANIFEST.pages.map((p) => order.indexOf(p)));
        const firstImage = Math.min(...MANIFEST.images.map((p) => order.indexOf(p)));
        expect(lastPage).toBeLessThan(firstImage);
    });

    it('re-validates a cached page with its ETag and only refreshes the date on a 304', async () => {
        caches.store.set('/', response('<body>old</body>', { headers: { ETag: '"abc"', Date: 'Mon, 01 Jan 2024 00:00:00 GMT' } }));
        global.fetch = vi.fn((url, init) => Promise.resolve(
            url === '/api/offline/manifest'
                ? response(JSON.stringify({ pages: ['/'], images: [] }))
                : (init && init.headers['If-None-Match'] === '"abc"' ? response('', { status: 304 }) : response('<body>new</body>'))
        ));
        config();
        await boot();
        await settle();

        const stored = caches.store.get('/');
        expect(stored.body).toBe('<body>old</body>');
        expect(stored.headers.map.get('Date')).not.toBe('Mon, 01 Jan 2024 00:00:00 GMT');
    });

    it('skips an image already cached and drops one the manifest no longer lists', async () => {
        caches.store.set('/files/1/thumb', response('img'));
        caches.store.set('/files/99/thumb', response('gone'));
        config();
        await boot();
        await settle();

        const fetched = global.fetch.mock.calls.map((c) => c[0]);
        expect(fetched).not.toContain('/files/1/thumb');
        expect(fetched).toContain('/files/2/md');
        expect(caches.store.has('/files/99/thumb')).toBe(false);
    });

    it('carries on when one fetch fails', async () => {
        global.fetch = vi.fn((url) => url === '/contact'
            ? Promise.reject(new TypeError('Failed to fetch'))
            : Promise.resolve(url === '/api/offline/manifest' ? response(JSON.stringify(MANIFEST)) : response('<body>ok</body>')));
        config();
        await boot();
        await settle();

        expect(caches.store.has('/calendar')).toBe(true);
        expect(caches.store.has('/contact')).toBe(false);
    });
});
