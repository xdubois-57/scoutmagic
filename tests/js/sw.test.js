// Isolated JavaScript unit test — jsdom only. No PHP server, no MySQL, no
// real network and no real Service Worker runtime: fetch, Response and the
// Cache Storage API are all mocked below. Exercises the REAL implementation
// in public/sw.js (imported below, never reimplemented here) through the
// `globalThis.ScoutMagicServiceWorkerInternals` hook that file exposes for
// exactly this purpose — see the comment at the bottom of sw.js.
//
// sw.js is a classic service-worker script, so importing it here evaluates
// its top-level code and registers its install/activate/fetch/message/push
// handlers against jsdom's window. That is harmless: nothing in this spec
// dispatches those events, and no top-level statement touches caches.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '../../public/sw.js';

const sw = globalThis.ScoutMagicServiceWorkerInternals;

// Minimal Cache Storage fake. Keyed by cache name; each cache stores
// Request-or-string -> Response. Enough for open/match/put/keys/delete.
function createCachesFake(seed = {}) {
    const stores = new Map(Object.entries(seed).map(([k, v]) => [k, new Map(Object.entries(v))]));
    const keyOf = (req) => (typeof req === 'string' ? req : req.url);

    function cacheFor(name) {
        if (!stores.has(name)) stores.set(name, new Map());
        const store = stores.get(name);
        return {
            match: vi.fn((req) => Promise.resolve(store.get(keyOf(req)))),
            put: vi.fn((req, res) => { store.set(keyOf(req), res); return Promise.resolve(); }),
            addAll: vi.fn(() => Promise.resolve()),
            keys: vi.fn(() => Promise.resolve([...store.keys()])),
        };
    }

    return {
        stores,
        open: vi.fn((name) => Promise.resolve(cacheFor(name))),
        match: vi.fn((req) => {
            for (const store of stores.values()) {
                const hit = store.get(keyOf(req));
                if (hit) return Promise.resolve(hit);
            }
            return Promise.resolve(undefined);
        }),
        keys: vi.fn(() => Promise.resolve([...stores.keys()])),
        delete: vi.fn((name) => Promise.resolve(stores.delete(name))),
    };
}

// jsdom has no Response. Only the surface sw.js actually touches.
function makeResponse(body, { ok = true, redirected = false, status = 200, statusText = 'OK', headers = {} } = {}) {
    const h = new Map(Object.entries(headers).map(([k, v]) => [k.toLowerCase(), v]));
    return {
        ok, redirected, status, statusText,
        headers: { get: (n) => (h.has(n.toLowerCase()) ? h.get(n.toLowerCase()) : null) },
        text: () => Promise.resolve(body),
        clone() { return makeResponse(body, { ok, redirected, status, statusText, headers }); },
    };
}

beforeEach(() => {
    vi.restoreAllMocks();
    global.Response = class { constructor(body, init = {}) { Object.assign(this, { body, ...init }); } };
});

// ---------------------------------------------------------------------------
// isWhitelisted — the rule that has three implementations
// ---------------------------------------------------------------------------
describe('sw.js: isWhitelisted()', () => {
    // Mirrors the real core entries: Core\Offline\OfflineWhitelist::CORE_ENTRIES.
    const WHITELIST = [
        { path: '/', match: 'exact' },
        { path: '/contact', match: 'exact' },
        { path: '/account', match: 'exact' },
        { path: '/members/', match: 'child' },
    ];

    it('returns false when no whitelist has been delivered yet', () => {
        expect(sw.isWhitelisted('/contact', null)).toBe(false);
        expect(sw.isWhitelisted('/contact', undefined)).toBe(false);
    });

    it('returns false for an empty whitelist', () => {
        expect(sw.isWhitelisted('/contact', [])).toBe(false);
    });

    it('matches an exact entry', () => {
        expect(sw.isWhitelisted('/contact', WHITELIST)).toBe(true);
        expect(sw.isWhitelisted('/', WHITELIST)).toBe(true);
    });

    it('rejects a path that merely starts with an exact entry', () => {
        expect(sw.isWhitelisted('/contacts', WHITELIST)).toBe(false);
        expect(sw.isWhitelisted('/account/settings', WHITELIST)).toBe(false);
    });

    it('rejects a non-whitelisted path outright', () => {
        expect(sw.isWhitelisted('/finance', WHITELIST)).toBe(false);
    });

    // The regression this file's isWhitelisted() used to have: entry.match
    // was ignored, so every child entry failed here while offline-nav.js
    // and the PHP matcher both accepted it.
    it('matches a child entry plus exactly one segment', () => {
        expect(sw.isWhitelisted('/members/12', WHITELIST)).toBe(true);
        expect(sw.isWhitelisted('/members/abc', WHITELIST)).toBe(true);
    });

    it('rejects a child entry with two or more extra segments', () => {
        expect(sw.isWhitelisted('/members/12/emails/5', WHITELIST)).toBe(false);
        expect(sw.isWhitelisted('/members/12/photos', WHITELIST)).toBe(false);
    });

    it('rejects the bare child prefix itself (no additional segment)', () => {
        expect(sw.isWhitelisted('/members/', WHITELIST)).toBe(false);
    });

    it('tolerates a trailing slash on a child match, as the PHP trim() does', () => {
        expect(sw.isWhitelisted('/members/12/', WHITELIST)).toBe(true);
    });

    it('treats an entry with no match key as exact', () => {
        expect(sw.isWhitelisted('/legacy', [{ path: '/legacy' }])).toBe(true);
        expect(sw.isWhitelisted('/legacy/1', [{ path: '/legacy' }])).toBe(false);
    });
});

// ---------------------------------------------------------------------------
// formatOfflineTimestamp
// ---------------------------------------------------------------------------
describe('sw.js: formatOfflineTimestamp()', () => {
    it('falls back to a generic label with no Date header', () => {
        expect(sw.formatOfflineTimestamp(null)).toBe('Version hors ligne');
        expect(sw.formatOfflineTimestamp(undefined)).toBe('Version hors ligne');
        expect(sw.formatOfflineTimestamp('')).toBe('Version hors ligne');
    });

    it('renders the French month name and zero-padded minutes', () => {
        // Construct via Date so the assertion is timezone-independent.
        const d = new Date(2026, 2, 7, 9, 5);
        expect(sw.formatOfflineTimestamp(d.toISOString()))
            .toBe('Version hors ligne du 7 mars, 9 h 05');
    });

    it('does not pad the hour', () => {
        const d = new Date(2026, 11, 25, 18, 42);
        expect(sw.formatOfflineTimestamp(d.toISOString()))
            .toBe('Version hors ligne du 25 décembre, 18 h 42');
    });
});

// ---------------------------------------------------------------------------
// injectOfflineBanner
// ---------------------------------------------------------------------------
describe('sw.js: injectOfflineBanner()', () => {
    it('injects the banner immediately after <body>, preserving its attributes', async () => {
        const res = await sw.injectOfflineBanner(
            makeResponse('<html><body class="x" data-y="1"><p>hi</p></body></html>'), null);

        expect(res.body).toContain('<body class="x" data-y="1"><div class="alert alert-warning');
        expect(res.body).toContain('Version hors ligne');
        expect(res.body).toContain('<p>hi</p>');
    });

    it('injects only once even when the markup mentions body again', async () => {
        const res = await sw.injectOfflineBanner(makeResponse('<body></body><body></body>'), null);
        expect(res.body.match(/alert-warning/g)).toHaveLength(1);
    });

    it('leaves markup untouched when there is no <body> tag to anchor to', async () => {
        const res = await sw.injectOfflineBanner(makeResponse('<p>fragment</p>'), null);
        expect(res.body).toBe('<p>fragment</p>');
    });

    it('carries the cached response status and headers through', async () => {
        const src = makeResponse('<body></body>', { status: 203, statusText: 'Cached' });
        const res = await sw.injectOfflineBanner(src, null);
        expect(res.status).toBe(203);
        expect(res.statusText).toBe('Cached');
        expect(res.headers).toBe(src.headers);
    });

    it('renders the cached copy age from the Date header', async () => {
        const d = new Date(2026, 2, 7, 9, 5);
        const res = await sw.injectOfflineBanner(makeResponse('<body></body>'), d.toISOString());
        expect(res.body).toContain('Version hors ligne du 7 mars, 9 h 05');
    });
});

// ---------------------------------------------------------------------------
// networkFirstWithCacheFallback — the caching decision
// ---------------------------------------------------------------------------
describe('sw.js: networkFirstWithCacheFallback()', () => {
    const url = new URL('https://example.test/members/12');
    const request = { url: url.href, mode: 'navigate' };
    const config = { account_scope: 'acct1', version: '1.2.3', staleness_days: 7, standalone: true };
    const cacheName = 'content-acct1-1.2.3';

    it('returns the live response and caches it when online and standalone', async () => {
        const caches = createCachesFake();
        global.caches = caches;
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>live</body>')));

        const res = await sw.networkFirstWithCacheFallback(request, url, config);

        expect(await res.text()).toBe('<body>live</body>');
        await vi.waitFor(() => expect(caches.stores.get(cacheName)?.size).toBe(1));
    });

    /**
     * The fast path settles respondWith() the instant the live response is
     * returned, so its cache write needs the event held open exactly as
     * the slow path's refresh does — otherwise the page is never cached
     * and the next offline visit finds nothing.
     */
    it('holds the event open for the cache write on the fast path too', async () => {
        const caches = createCachesFake();
        global.caches = caches;
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>live</body>')));
        const event = { waitUntil: vi.fn() };

        await sw.networkFirstWithCacheFallback(request, url, config, undefined, event);

        expect(event.waitUntil).toHaveBeenCalledTimes(1);
        await vi.waitFor(() => expect(caches.stores.get(cacheName)?.size).toBe(1));
    });

    it('does NOT write to cache from a plain browser tab (standalone false)', async () => {
        const caches = createCachesFake();
        global.caches = caches;
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>live</body>')));

        await sw.networkFirstWithCacheFallback(request, url, { ...config, standalone: false });

        expect(caches.stores.get(cacheName)?.size ?? 0).toBe(0);
    });

    it('never caches a redirected response (a bounce to /login)', async () => {
        const caches = createCachesFake();
        global.caches = caches;
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>login</body>', { redirected: true })));

        await sw.networkFirstWithCacheFallback(request, url, config);

        expect(caches.stores.get(cacheName)?.size ?? 0).toBe(0);
    });

    it('never caches a non-ok response', async () => {
        const caches = createCachesFake();
        global.caches = caches;
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('nope', { ok: false, status: 500 })));

        await sw.networkFirstWithCacheFallback(request, url, config);

        expect(caches.stores.get(cacheName)?.size ?? 0).toBe(0);
    });

    it('serves a fresh cached copy with the offline banner when the network fails', async () => {
        const cached = makeResponse('<body>cached</body>', { headers: { date: new Date().toUTCString() } });
        global.caches = createCachesFake({ [cacheName]: { [request.url]: cached } });
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        const res = await sw.networkFirstWithCacheFallback(request, url, config);

        expect(res.body).toContain('cached</body>');
        expect(res.body).toContain('alert-warning');
    });

    it('falls back to /offline when the network fails and nothing is cached', async () => {
        const offline = makeResponse('<body>offline page</body>');
        global.caches = createCachesFake({ 'app-shell': { '/offline': offline } });
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        const res = await sw.networkFirstWithCacheFallback(request, url, config);

        expect(res).toBe(offline);
    });

    it('discards a cached copy older than staleness_days in favour of /offline', async () => {
        const old = new Date(Date.now() - 8 * 24 * 60 * 60 * 1000).toUTCString();
        const offline = makeResponse('<body>offline page</body>');
        global.caches = createCachesFake({
            [cacheName]: { [request.url]: makeResponse('<body>stale</body>', { headers: { date: old } }) },
            'app-shell': { '/offline': offline },
        });
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        const res = await sw.networkFirstWithCacheFallback(request, url, config);

        expect(res).toBe(offline);
    });

    it('keeps a cached copy that is just inside the staleness window', async () => {
        const recent = new Date(Date.now() - 6 * 24 * 60 * 60 * 1000).toUTCString();
        global.caches = createCachesFake({
            [cacheName]: { [request.url]: makeResponse('<body>ok</body>', { headers: { date: recent } }) },
        });
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        const res = await sw.networkFirstWithCacheFallback(request, url, config);

        expect(res.body).toContain('ok</body>');
    });

    it('treats a cached copy with no Date header as infinitely stale', async () => {
        const offline = makeResponse('<body>offline page</body>');
        global.caches = createCachesFake({
            [cacheName]: { [request.url]: makeResponse('<body>undated</body>') },
            'app-shell': { '/offline': offline },
        });
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        expect(await sw.networkFirstWithCacheFallback(request, url, config)).toBe(offline);
    });

    // NaN is not > anything, so a bare `ageMs > threshold` would call an
    // unparseable date fresh and serve a copy of entirely unknown age.
    it('treats a cached copy with an unparseable Date header as stale', async () => {
        const offline = makeResponse('<body>offline page</body>');
        global.caches = createCachesFake({
            [cacheName]: { [request.url]: makeResponse('<body>bad date</body>', { headers: { date: 'not a date' } }) },
            'app-shell': { '/offline': offline },
        });
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        expect(await sw.networkFirstWithCacheFallback(request, url, config)).toBe(offline);
    });

    // A negative age is not > the threshold either. A clock that jumped
    // backwards (or a server dating into the future) must not make an
    // arbitrarily old copy look current.
    it('treats a future-dated cached copy as stale', async () => {
        const ahead = new Date(Date.now() + 3 * 24 * 60 * 60 * 1000).toUTCString();
        const offline = makeResponse('<body>offline page</body>');
        global.caches = createCachesFake({
            [cacheName]: { [request.url]: makeResponse('<body>ahead</body>', { headers: { date: ahead } }) },
            'app-shell': { '/offline': offline },
        });
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        expect(await sw.networkFirstWithCacheFallback(request, url, config)).toBe(offline);
    });

    it('defaults to a 7-day window when staleness_days is absent', async () => {
        const old = new Date(Date.now() - 8 * 24 * 60 * 60 * 1000).toUTCString();
        const offline = makeResponse('<body>offline page</body>');
        global.caches = createCachesFake({
            [cacheName]: { [request.url]: makeResponse('<body>stale</body>', { headers: { date: old } }) },
            'app-shell': { '/offline': offline },
        });
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        const res = await sw.networkFirstWithCacheFallback(
            request, url, { ...config, staleness_days: undefined });

        expect(res).toBe(offline);
    });

    it('scopes the cache name by account and version', async () => {
        const caches = createCachesFake();
        global.caches = caches;
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>x</body>')));

        await sw.networkFirstWithCacheFallback(request, url, { ...config, account_scope: 'other', version: '9' });

        expect(caches.open).toHaveBeenCalledWith('content-other-9');
    });
});

// ---------------------------------------------------------------------------
// handleNavigate — routing into the branches above
// ---------------------------------------------------------------------------
describe('sw.js: handleNavigate()', () => {
    const url = new URL('https://example.test/members/12');
    const request = { url: url.href, mode: 'navigate' };

    // Cleanup that a failing expectation cannot skip. Deleting the stub on
    // the last line of a test means a single real failure leaves
    // `self.navigator.onLine === false` set for everything after it —
    // retryOnce() then refuses to fetch in unrelated tests, and one failure
    // becomes a cascade that buries its own cause.
    afterEach(() => {
        delete global.self;
    });

    function seedConfig(config) {
        const stored = config === null ? {} : {
            'offline-config': { 'https://offline-config.internal/v1': { json: () => Promise.resolve(config) } },
        };
        global.caches = createCachesFake({ ...stored, 'app-shell': { '/offline': makeResponse('<body>offline</body>') } });
    }

    it('bypasses the cache entirely when no config has been delivered', async () => {
        seedConfig(null);
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>live</body>')));

        await sw.handleNavigate(request, url);

        expect(fetch).toHaveBeenCalledWith(request);
        expect(caches.open).not.toHaveBeenCalledWith(expect.stringContaining('content-'));
    });

    it('bypasses the cache when consent is absent', async () => {
        seedConfig({ consent: false, whitelist: [{ path: '/members/', match: 'child' }], account_scope: 'a', version: '1' });
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>live</body>')));

        await sw.handleNavigate(request, url);

        expect(caches.open).not.toHaveBeenCalledWith(expect.stringContaining('content-'));
    });

    it('serves /offline rather than a network error for a non-whitelisted path', async () => {
        seedConfig({ consent: true, whitelist: [{ path: '/contact', match: 'exact' }], account_scope: 'a', version: '1' });
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        const res = await sw.handleNavigate(request, url);

        expect(res.body ?? await res.text()).toContain('offline');
    });

    /**
     * Cache Storage can be denied or evicted outright, and getOfflineConfig()
     * rejects when it is. Letting that through would reject respondWith()
     * and land the installed app on the browser's own network-error
     * interstitial — the one thing the precached page exists to replace.
     */
    it('still answers when the offline configuration cannot be read at all', async () => {
        const offline = makeResponse('<body>offline</body>');
        global.caches = {
            open: vi.fn(() => Promise.reject(new Error('storage denied'))),
            match: vi.fn(() => Promise.resolve(offline)),
        };
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        global.self = { navigator: { onLine: false } };

        const res = await sw.handleNavigate(request, url, undefined);

        expect(res).toBe(offline);
    });

    // The end-to-end shape of the bug: a child-matched member page must take
    // the caching path, not the bypass path.
    it('routes a child-matched whitelisted page into the content cache', async () => {
        seedConfig({ consent: true, whitelist: [{ path: '/members/', match: 'child' }], account_scope: 'a', version: '1', standalone: true });
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>member</body>')));

        await sw.handleNavigate(request, url);

        await vi.waitFor(() => expect(caches.open).toHaveBeenCalledWith('content-a-1'));
    });
});

// ---------------------------------------------------------------------------
// startNetworkRequest — the navigation's fetch starts before the config read
// ---------------------------------------------------------------------------
describe('sw.js: startNetworkRequest()', () => {
    const request = { url: 'https://example.test/calendar', mode: 'navigate' };

    it('uses the response the browser preloaded and never fetches again', async () => {
        const preloaded = makeResponse('<body>preloaded</body>');
        global.fetch = vi.fn();

        const res = await sw.startNetworkRequest(request, Promise.resolve(preloaded));

        expect(res).toBe(preloaded);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('falls back to fetch() when preload resolves to nothing (unsupported, or not used)', async () => {
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>fetched</body>')));

        const res = await sw.startNetworkRequest(request, Promise.resolve(undefined));

        expect(await res.text()).toBe('<body>fetched</body>');
        expect(fetch).toHaveBeenCalledWith(request);
    });

    // A preload that resolves to nothing sends this into fetch(). When THAT
    // fetch fails, the failure belongs to retryOnce() — firing a second
    // identical request here first only spends another round trip on the
    // connection that just failed.
    it('does not fire a second fetch when the fallback one fails', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        await expect(sw.startNetworkRequest(request, Promise.resolve(undefined))).rejects.toThrow('offline');
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    // The case the guard exists for stays intact: the PRELOAD itself failed,
    // nothing was fetched, so fetch() is the first attempt and not a second.
    it('still falls back to fetch() when the preload promise rejects', async () => {
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>fetched</body>')));

        const res = await sw.startNetworkRequest(request, Promise.reject(new Error('preload failed')));

        expect(await res.text()).toBe('<body>fetched</body>');
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('fetches when there is no preload promise at all', async () => {
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>fetched</body>')));

        await sw.startNetworkRequest(request, undefined);

        expect(fetch).toHaveBeenCalledWith(request);
    });

    it('is started before the offline configuration has been read, so the read costs the navigation nothing', async () => {
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>live</body>')));
        let releaseConfig;
        const configRead = new Promise((resolve) => { releaseConfig = resolve; });
        global.caches = { open: vi.fn(() => configRead), match: vi.fn(() => Promise.resolve(undefined)) };
        const url = new URL(request.url);

        const navigation = sw.handleNavigate(request, url, undefined);
        await Promise.resolve();
        await Promise.resolve();
        expect(fetch).toHaveBeenCalledWith(request);

        releaseConfig({ match: () => Promise.resolve(undefined) });
        expect(await (await navigation).text()).toBe('<body>live</body>');
    });

    // The reported bug, at its source: a rejected preload is NOT proof of
    // being offline. The browser made that request before this worker was
    // even awake, and it fails for reasons that are not connectivity.
    it('retries with a plain fetch() when the browser preload rejects', async () => {
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>fetched</body>')));

        const res = await sw.startNetworkRequest(request, Promise.reject(new Error('preload cancelled')));

        expect(await res.text()).toBe('<body>fetched</body>');
        expect(fetch).toHaveBeenCalledWith(request);
    });

    it('does not double-fetch when there was no preload to fail', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        await expect(sw.startNetworkRequest(request, undefined)).rejects.toThrow();

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('hands a preloaded response through handleNavigate on the whitelisted path too', async () => {
        const url = new URL('https://example.test/members/12');
        const req = { url: url.href, mode: 'navigate' };
        global.caches = createCachesFake({
            'offline-config': { 'https://offline-config.internal/v1': { json: () => Promise.resolve({ consent: true, whitelist: [{ path: '/members/', match: 'child' }], account_scope: 'a', version: '1', standalone: true }) } },
        });
        global.fetch = vi.fn();
        const preloaded = makeResponse('<body>member</body>');

        const res = await sw.handleNavigate(req, url, Promise.resolve(preloaded));

        expect(res).toBe(preloaded);
        expect(fetch).not.toHaveBeenCalled();
    });
});

// ---------------------------------------------------------------------------
// retryOnce — one more attempt before the app calls itself offline
// ---------------------------------------------------------------------------
describe('sw.js: retryOnce()', () => {
    const request = { url: 'https://example.test/groups/3', mode: 'navigate' };

    afterEach(() => {
        delete global.self;
    });

    it('tries the request again when the browser still believes it is online', async () => {
        global.self = { navigator: { onLine: true } };
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>second try</body>')));

        const res = await sw.retryOnce(request);

        expect(await res.text()).toBe('<body>second try</body>');
    });

    // navigator.onLine is a reliable NO: flight mode must reach the
    // offline page immediately, not after a doomed extra round trip.
    it('does not retry at all when the device is known to be offline', async () => {
        global.self = { navigator: { onLine: false } };
        global.fetch = vi.fn();

        await expect(sw.retryOnce(request)).rejects.toThrow();

        expect(fetch).not.toHaveBeenCalled();
    });
});

// ---------------------------------------------------------------------------
// A failed navigation gets that second attempt before /offline is shown
// ---------------------------------------------------------------------------
describe('sw.js: handleNavigate() retries before showing the offline page', () => {
    const url = new URL('https://example.test/groups/3');
    const request = { url: url.href, mode: 'navigate' };

    afterEach(() => {
        delete global.self;
    });

    it('serves the page a retry succeeds in fetching, not "Pas de connexion"', async () => {
        global.self = { navigator: { onLine: true } };
        global.caches = createCachesFake({ 'app-shell': { '/offline': makeResponse('<body>offline</body>') } });
        let attempt = 0;
        global.fetch = vi.fn(() => {
            attempt++;
            return attempt === 1
                ? Promise.reject(new Error('resumed from frozen'))
                : Promise.resolve(makeResponse('<body>live</body>'));
        });

        const res = await sw.handleNavigate(request, url, undefined);

        expect(await res.text()).toBe('<body>live</body>');
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('still shows the offline page when the retry fails too', async () => {
        global.self = { navigator: { onLine: true } };
        const offline = makeResponse('<body>offline</body>');
        global.caches = createCachesFake({ 'app-shell': { '/offline': offline } });
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        const res = await sw.handleNavigate(request, url, undefined);

        expect(res).toBe(offline);
    });
});

// ---------------------------------------------------------------------------
// offlinePage — respondWith(undefined) is a TypeError, so never undefined
// ---------------------------------------------------------------------------
describe('sw.js: offlinePage()', () => {
    it('returns the precached page when it is there', async () => {
        const offline = makeResponse('<body>offline</body>');
        global.caches = createCachesFake({ 'app-shell': { '/offline': offline } });

        expect(await sw.offlinePage()).toBe(offline);
    });

    // A shell cache purged out from under this call must not turn into
    // the browser's own network-error interstitial — the very thing the
    // precached page exists to replace in an installed app.
    it('builds a minimal French page when the shell cache has nothing', async () => {
        global.caches = createCachesFake();

        const res = await sw.offlinePage();

        expect(res).toBeTruthy();
        expect(res.body).toContain('Pas de connexion');
    });
});

// ---------------------------------------------------------------------------
// Cache Storage itself can be unavailable — private browsing, site data
// blocked, a quota error. Every read below feeds respondWith(), so a
// rejection that escapes lands the installed app on the browser's own
// network-error interstitial. A cache that cannot be read holds nothing.
// ---------------------------------------------------------------------------
describe('sw.js: a Cache Storage that rejects instead of missing', () => {
    const url = new URL('https://example.test/members/12');
    const request = { url: url.href, mode: 'navigate' };
    const config = { account_scope: 'acct1', version: '1.2.3', staleness_days: 7, standalone: true };

    it('still builds the offline page when caches.match() rejects', async () => {
        global.caches = { match: vi.fn(() => Promise.reject(new Error('SecurityError'))) };

        const res = await sw.offlinePage();

        expect(res.body).toContain('Pas de connexion');
    });

    it('answers the navigation when caches.open() rejects', async () => {
        const offline = makeResponse('<body>offline page</body>');
        global.caches = {
            open: vi.fn(() => Promise.reject(new Error('QuotaExceededError'))),
            match: vi.fn(() => Promise.resolve(offline)),
        };
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        expect(await sw.networkFirstWithCacheFallback(request, url, config)).toBe(offline);
    });

    it('answers the navigation when cache.match() rejects', async () => {
        const offline = makeResponse('<body>offline page</body>');
        global.caches = {
            open: vi.fn(() => Promise.resolve({
                match: vi.fn(() => Promise.reject(new Error('SecurityError'))),
                put: vi.fn(() => Promise.resolve()),
            })),
            match: vi.fn(() => Promise.resolve(offline)),
        };
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        expect(await sw.networkFirstWithCacheFallback(request, url, config)).toBe(offline);
    });

    // Both reads broken at once: the last resort is the generated page,
    // and it must still be a real Response — respondWith(undefined) is a
    // TypeError, which is the interstitial all over again.
    it('never resolves to undefined when nothing at all can be read', async () => {
        global.caches = {
            open: vi.fn(() => Promise.reject(new Error('SecurityError'))),
            match: vi.fn(() => Promise.reject(new Error('SecurityError'))),
        };
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));

        const res = await sw.networkFirstWithCacheFallback(request, url, config);

        expect(res).toBeTruthy();
        expect(res.body).toContain('Pas de connexion');
    });
});

// ---------------------------------------------------------------------------
// The slow network: a cached copy beats a blank screen
// ---------------------------------------------------------------------------
describe('sw.js: networkFirstWithCacheFallback() on a slow network', () => {
    const url = new URL('https://example.test/members/12');
    const request = { url: url.href, mode: 'navigate' };
    const config = { account_scope: 'acct1', version: '1.2.3', staleness_days: 7, standalone: true };
    const cacheName = 'content-acct1-1.2.3';

    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('serves the cached copy, labelled as such, rather than waiting out a hanging request', async () => {
        const cached = makeResponse('<body>cached</body>', { headers: { date: new Date().toUTCString() } });
        global.caches = createCachesFake({ [cacheName]: { [request.url]: cached } });
        // Never settles: the reader would otherwise stare at a blank
        // screen for as long as the connection stays bad.
        global.fetch = vi.fn(() => new Promise(() => {}));

        const navigation = sw.networkFirstWithCacheFallback(request, url, config);
        await vi.advanceTimersByTimeAsync(sw.NETWORK_TIMEOUT_MS + 10);
        const res = await navigation;

        expect(res.body).toContain('cached</body>');
        // And it says which of the two things happened: the device is not
        // offline, the network is slow, and the live request is still on.
        expect(res.body).toContain('Réseau lent');
    });

    /**
     * The reader stops waiting; the refresh must not. respondWith()
     * settling ends the fetch event, and a worker whose event is done may
     * be terminated at once — which would abort the live request and lose
     * the cache.put() it was about to make.
     */
    it('holds the fetch event open for the refresh it no longer answers with', async () => {
        const cached = makeResponse('<body>cached</body>', { headers: { date: new Date().toUTCString() } });
        global.caches = createCachesFake({ [cacheName]: { [request.url]: cached } });
        global.fetch = vi.fn(() => new Promise(() => {}));
        const event = { waitUntil: vi.fn() };

        const navigation = sw.networkFirstWithCacheFallback(request, url, config, undefined, event);
        await vi.advanceTimersByTimeAsync(sw.NETWORK_TIMEOUT_MS + 10);
        await navigation;

        expect(event.waitUntil).toHaveBeenCalledTimes(1);
        expect(event.waitUntil.mock.calls[0][0]).toBeInstanceOf(Promise);
    });

    it('does not fail the navigation when the event can no longer be extended', async () => {
        const cached = makeResponse('<body>cached</body>', { headers: { date: new Date().toUTCString() } });
        global.caches = createCachesFake({ [cacheName]: { [request.url]: cached } });
        global.fetch = vi.fn(() => new Promise(() => {}));
        // What a real worker throws once the event is already settled.
        const event = { waitUntil: vi.fn(() => { throw new Error('InvalidStateError'); }) };

        const navigation = sw.networkFirstWithCacheFallback(request, url, config, undefined, event);
        await vi.advanceTimersByTimeAsync(sw.NETWORK_TIMEOUT_MS + 10);

        expect((await navigation).body).toContain('cached</body>');
    });

    it('keeps waiting when there is nothing cached to show instead', async () => {
        global.caches = createCachesFake({ 'app-shell': { '/offline': makeResponse('<body>offline</body>') } });
        let release;
        global.fetch = vi.fn(() => new Promise((resolve) => { release = resolve; }));

        const navigation = sw.networkFirstWithCacheFallback(request, url, config);
        await vi.advanceTimersByTimeAsync(sw.NETWORK_TIMEOUT_MS + 10);
        release(makeResponse('<body>late but live</body>'));

        expect(await (await navigation).text()).toBe('<body>late but live</body>');
    });

    it('lets a merely slow-but-answering network win the race', async () => {
        const cached = makeResponse('<body>cached</body>', { headers: { date: new Date().toUTCString() } });
        global.caches = createCachesFake({ [cacheName]: { [request.url]: cached } });
        global.fetch = vi.fn(() => new Promise((resolve) => {
            setTimeout(() => resolve(makeResponse('<body>live</body>')), sw.NETWORK_TIMEOUT_MS - 1000);
        }));

        const navigation = sw.networkFirstWithCacheFallback(request, url, config);
        await vi.advanceTimersByTimeAsync(sw.NETWORK_TIMEOUT_MS + 10);

        expect(await (await navigation).text()).toBe('<body>live</body>');
    });
});
