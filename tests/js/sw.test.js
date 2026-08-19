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
import { beforeEach, describe, expect, it, vi } from 'vitest';
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

    // The end-to-end shape of the bug: a child-matched member page must take
    // the caching path, not the bypass path.
    it('routes a child-matched whitelisted page into the content cache', async () => {
        seedConfig({ consent: true, whitelist: [{ path: '/members/', match: 'child' }], account_scope: 'a', version: '1', standalone: true });
        global.fetch = vi.fn(() => Promise.resolve(makeResponse('<body>member</body>')));

        await sw.handleNavigate(request, url);

        await vi.waitFor(() => expect(caches.open).toHaveBeenCalledWith('content-a-1'));
    });
});
