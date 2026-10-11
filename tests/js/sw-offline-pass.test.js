// Isolated JavaScript unit test — jsdom with Node's own fetch primitives
// (Request, Response, Headers), no real network and no real service-worker
// runtime. Exercises the REAL public/sw.js through the
// `globalThis.ScoutMagicServiceWorkerInternals` hook: the pass that keeps
// the installed app's offline copies in line with the exact list (#883),
// the carry-over of the previous version's copies, and the images stored
// while browsing.
//
// A file of its own because tests/js/sw.test.js replaces `Response` with a
// stub for its own needs, and this pass reads and rebuilds real bodies.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import '../../public/sw.js';

const sw = globalThis.ScoutMagicServiceWorkerInternals;
const ORIGIN = self.location.origin;
const CONFIG_URL = 'https://offline-config.internal/v1';
const CONFIG = { type: 'offline-config', consent: true, standalone: true, account_scope: 'a', version: '1', staleness_days: 30 };

/** A Cache Storage over plain Maps, keyed by absolute URL, as the real one is. */
function createCacheStorage() {
    const stores = new Map();
    const keyOf = (request) => new URL(typeof request === 'string' ? request : request.url, ORIGIN).href;
    const cacheFor = (name) => {
        if (!stores.has(name)) {
            stores.set(name, new Map());
        }
        const store = stores.get(name);
        return {
            match: async (request) => store.get(keyOf(request))?.clone(),
            put: async (request, response) => { store.set(keyOf(request), response); },
            keys: async () => [...store.keys()].map((url) => new Request(url)),
            delete: async (request) => store.delete(keyOf(request)),
        };
    };
    return {
        stores,
        open: async (name) => cacheFor(name),
        has: async (name) => stores.has(name),
        keys: async () => [...stores.keys()],
        delete: async (name) => stores.delete(name),
        match: async (request) => {
            for (const store of stores.values()) {
                const hit = store.get(keyOf(request));
                if (hit) return hit.clone();
            }
            return undefined;
        },
    };
}

function html(body, headers = {}) {
    return new Response(`<!DOCTYPE html><body>${body}</body>`, { status: 200, headers: { 'Content-Type': 'text/html', ...headers } });
}

function redirected(response) {
    Object.defineProperty(response, 'redirected', { value: true });
    return response;
}

/** The content of the content cache, by path. */
function stored(name = 'content-a-1') {
    return [...(caches.stores.get(name)?.keys() ?? [])].map((url) => new URL(url).pathname + new URL(url).search).sort();
}

async function seed(config = CONFIG, extra = {}) {
    const offlineConfig = await caches.open('offline-config');
    await offlineConfig.put(CONFIG_URL, new Response(JSON.stringify(config)));
    for (const [url, value] of Object.entries(extra)) {
        await offlineConfig.put(url, new Response(JSON.stringify(value)));
    }
}

/** fetch() answering each path from $routes, and recording the order asked. */
function serve(routes) {
    const asked = [];
    global.fetch = vi.fn(async (input, init = {}) => {
        const path = new URL(typeof input === 'string' ? input : input.url, ORIGIN).pathname;
        asked.push(path);
        const route = routes[path];
        if (!route) {
            return new Response('not found', { status: 404 });
        }
        return typeof route === 'function' ? route(init) : route.clone();
    });
    return asked;
}

function manifest(pages, last = [], headers = {}) {
    return new Response(JSON.stringify({ pages, last }), { status: 200, headers: { 'Content-Type': 'application/json', ...headers } });
}

beforeEach(() => {
    vi.restoreAllMocks();
    global.caches = createCacheStorage();
});

describe('sw.js: refreshOfflineCopies() — the pass (#883)', () => {
    it('stores the pages, then the images they show, then the help — and never an original', async () => {
        await seed();
        const asked = serve({
            '/api/offline/manifest': manifest(['/', '/contact'], ['/aide']),
            '/': html('<img src="/files/5/md" alt=""><a href="/files/7">original</a>'),
            '/contact': html('<img srcset="/files/6/thumb 1x" alt="">'),
            '/files/5/md': new Response('webp-5'),
            '/files/6/thumb': new Response('webp-6'),
            '/aide': html('Aide'),
        });

        expect(await sw.refreshOfflineCopies()).toBe(true);

        expect(stored()).toEqual(['/', '/aide', '/contact', '/files/5/md', '/files/6/thumb']);
        expect(asked).not.toContain('/files/7');
        // Pages, then images, then help: the help is asked after every image.
        expect(asked.indexOf('/aide')).toBeGreaterThan(asked.indexOf('/files/5/md'));
        expect(asked.indexOf('/aide')).toBeGreaterThan(asked.indexOf('/files/6/thumb'));
        expect(Math.max(asked.indexOf('/'), asked.indexOf('/contact'))).toBeLessThan(asked.indexOf('/files/5/md'));
    });

    it('re-asks a stored page with its ETag, and a « 304 » only moves its date', async () => {
        await seed();
        const old = new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toUTCString();
        await (await caches.open('content-a-1')).put('/contact', html('Ancienne copie', { ETag: '"v1"', Date: old }));
        serve({
            '/api/offline/manifest': manifest(['/contact']),
            '/contact': (init) => (init.headers?.['If-None-Match'] === '"v1"'
                ? new Response(null, { status: 304 })
                : html('Nouvelle copie')),
        });

        await sw.refreshOfflineCopies();

        const copy = await (await caches.open('content-a-1')).match('/contact');
        expect(await copy.text()).toContain('Ancienne copie');
        expect(Date.parse(copy.headers.get('Date'))).toBeGreaterThan(Date.parse(old));
        expect(copy.headers.get(sw.STORED_AT_HEADER)).not.toBeNull();
    });

    it('never stores a redirect — the login page answering for a session that ended', async () => {
        await seed();
        serve({
            '/api/offline/manifest': manifest(['/account']),
            '/account': () => redirected(html('Connexion')),
        });

        await sw.refreshOfflineCopies();

        expect(stored()).toEqual([]);
    });

    it('never keeps a list, nor a page, served to another account', async () => {
        await seed();
        serve({ '/api/offline/manifest': manifest(['/account'], [], { 'X-Offline-Scope': 'b' }) });

        expect(await sw.refreshOfflineCopies()).toBe(false);
        expect(stored()).toEqual([]);

        serve({
            '/api/offline/manifest': manifest(['/account'], [], { 'X-Offline-Scope': 'a' }),
            '/account': html('Mon compte de b', { 'X-Offline-Scope': 'b' }),
        });
        await sw.refreshOfflineCopies();

        expect(stored()).toEqual([]);
    });

    it('removes a page that left the list, and an image no page shows any more', async () => {
        await seed();
        const content = await caches.open('content-a-1');
        await content.put('/news/7', html('Sortie de la première page'));
        await content.put('/files/9/thumb', new Response('plus montrée'));
        serve({
            '/api/offline/manifest': manifest(['/news']),
            '/news': html('<img src="/files/8/thumb">'),
            '/files/8/thumb': new Response('webp-8'),
        });

        await sw.refreshOfflineCopies();

        expect(stored()).toEqual(['/files/8/thumb', '/news']);
    });

    it('resumes an interrupted pass, asking only for what it has not stored yet', async () => {
        const startedAt = Date.now() - 60 * 1000;
        await seed(CONFIG, { [sw.PASS_URL]: { scope: 'a', version: '1', startedAt, completedAt: null } });
        await (await caches.open('content-a-1')).put('/', html('Déjà fait', { [sw.STORED_AT_HEADER]: String(startedAt + 1000) }));
        const asked = serve({
            '/api/offline/manifest': manifest(['/', '/contact']),
            '/contact': html('Contact'),
        });

        await sw.refreshOfflineCopies();

        expect(asked).not.toContain('/');
        expect(asked).toContain('/contact');
        expect(stored()).toEqual(['/', '/contact']);
    });

    it('is marked done only once it has run to its end, help included', async () => {
        await seed();
        let releaseHelp;
        serve({
            '/api/offline/manifest': manifest(['/'], ['/aide']),
            '/': html('Accueil'),
            '/aide': () => new Promise((resolve) => { releaseHelp = () => resolve(html('Aide')); }),
        });

        const pass = sw.refreshOfflineCopies();
        await vi.waitFor(() => expect(releaseHelp).toBeTypeOf('function'));
        const during = await (await (await caches.open('offline-config')).match(sw.PASS_URL)).json();
        expect(during.completedAt).toBeNull();

        releaseHelp();
        expect(await pass).toBe(true);
        const after = await (await (await caches.open('offline-config')).match(sw.PASS_URL)).json();
        expect(after.completedAt).toBeTypeOf('number');
    });

    it('joins the pass already running rather than starting a second one', async () => {
        await seed();
        const asked = serve({ '/api/offline/manifest': manifest(['/']), '/': html('Accueil') });

        await Promise.all([sw.refreshOfflineCopies(), sw.refreshOfflineCopies()]);

        expect(asked.filter((path) => path === '/api/offline/manifest')).toHaveLength(1);
    });

    it.each([
        ['outside the installed app', { standalone: false }],
        ['without consent', { consent: false }],
        ['for a visitor not signed in', { account_scope: 'guest' }],
    ])('does nothing %s', async (_label, overrides) => {
        await seed({ ...CONFIG, ...overrides });
        const asked = serve({ '/api/offline/manifest': manifest(['/']) });

        expect(await sw.refreshOfflineCopies()).toBe(false);
        expect(asked).toEqual([]);
    });
});

describe('sw.js: imageVariantsIn()', () => {
    it('finds the variants a page shows, and nothing else', () => {
        const page = '<img src="/files/5/md"><img srcset="/files/6/thumb 1x, /files/6/md 2x">'
            + '<div style="background-image:url(/files/8/md)"></div>'
            + '<a href="/files/7">PDF</a><img src="/files/9/mdx"><span>/files/10/thumb/extra</span>';

        expect(sw.imageVariantsIn(page)).toEqual(['/files/5/md', '/files/6/thumb', '/files/6/md', '/files/8/md']);
    });
});

describe('sw.js: adoptPreviousVersion() — an update no longer starts from nothing (#883)', () => {
    it('carries the previous version\'s copies over, dates intact, and drops the old cache', async () => {
        const date = new Date(Date.now() - 2 * 24 * 60 * 60 * 1000).toUTCString();
        await (await caches.open('content-a-1')).put('/contact', html('Contact', { Date: date }));
        await (await caches.open('content-b-1')).put('/contact', html('Autre compte'));

        await sw.adoptPreviousVersion('a', '2');

        const copy = await (await caches.open('content-a-2')).match('/contact');
        expect(copy.headers.get('Date')).toBe(date);
        expect(caches.stores.has('content-a-1')).toBe(false);
        // Another account's cache is not this function's to touch.
        expect(caches.stores.has('content-b-1')).toBe(true);
    });

    it('keeps the current cache as it is when it already exists', async () => {
        await (await caches.open('content-a-1')).put('/', html('Ancienne'));
        await (await caches.open('content-a-2')).put('/', html('Actuelle'));

        await sw.adoptPreviousVersion('a', '2');

        expect(await (await (await caches.open('content-a-2')).match('/')).text()).toContain('Actuelle');
        expect(caches.stores.has('content-a-1')).toBe(false);
    });
});

describe('sw.js: variantResponse() — an image stored while browsing, only for a listed page (#883)', () => {
    async function seedList(pages, config = CONFIG) {
        await seed(config, { [sw.LIST_URL]: { scope: 'a', pages, last: [] } });
    }

    function imageRequest(referrerPath) {
        return new Request(ORIGIN + '/files/5/md', { referrer: ORIGIN + referrerPath });
    }

    it('stores the image of a page on the list', async () => {
        await seedList(['/groups']);
        serve({ '/files/5/md': new Response('webp') });

        await sw.variantResponse(imageRequest('/groups'), undefined);

        await vi.waitFor(() => expect(stored()).toEqual(['/files/5/md']));
    });

    it('stores nothing for a page off the list', async () => {
        await seedList(['/groups']);
        serve({ '/files/5/md': new Response('webp') });

        const response = await sw.variantResponse(imageRequest('/members/13'), undefined);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(await response.text()).toBe('webp');
        expect(stored()).toEqual([]);
    });

    it('stores nothing outside the installed app, and never a refusal', async () => {
        await seedList(['/groups'], { ...CONFIG, standalone: false });
        serve({ '/files/5/md': new Response('webp') });
        await sw.variantResponse(imageRequest('/groups'), undefined);

        await seedList(['/groups']);
        serve({ '/files/5/md': new Response('interdit', { status: 403 }) });
        await sw.variantResponse(imageRequest('/groups'), undefined);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(stored()).toEqual([]);
    });

    it('answers from the cache when the network is down', async () => {
        await (await caches.open('content-a-1')).put('/files/5/md', new Response('gardée'));
        global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));

        const response = await sw.variantResponse(imageRequest('/groups'), undefined);

        expect(await response.text()).toBe('gardée');
    });
});
