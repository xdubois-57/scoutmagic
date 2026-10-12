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

/** The pass record the worker stored. */
async function readPass() {
    return (await (await caches.open('offline-config')).match(sw.PASS_URL)).json();
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

    it('finishes despite an image refused for good — a file deleted while an article still shows it', async () => {
        await seed();
        serve({
            '/api/offline/manifest': manifest(['/news/3']),
            '/news/3': html('<img src="/files/5/md"><img src="/files/6/md">'),
            '/files/5/md': new Response('interdit', { status: 403 }),
            '/files/6/md': new Response('webp-6'),
        });

        expect(await sw.refreshOfflineCopies()).toBe(true);

        expect(stored()).toEqual(['/files/6/md', '/news/3']);
        expect((await readPass()).completedAt).toBeTypeOf('number');
    });

    it.each([
        ['a server error', 503],
        ['a « come back later »', 429],
    ])('stays open on %s for an image: a later pass may get it', async (_label, status) => {
        await seed();
        serve({
            '/api/offline/manifest': manifest(['/news/3']),
            '/news/3': html('<img src="/files/5/md">'),
            '/files/5/md': new Response('plus tard', { status }),
        });

        expect(await sw.refreshOfflineCopies()).toBe(false);
        expect((await readPass()).completedAt).toBeNull();
    });

    // The list came, then the network went: every page fails. Such a pass
    // must not count as done — the page would remember the launch and not
    // ask again for a day — and the next one resumes it.
    it('is not done when a fetch failed, and the next pass resumes it', async () => {
        await seed();
        serve({
            '/api/offline/manifest': manifest(['/', '/contact']),
            '/': html('Accueil'),
            '/contact': () => { throw new TypeError('Failed to fetch'); },
        });

        expect(await sw.refreshOfflineCopies()).toBe(false);
        const open = await readPass();
        expect(open.completedAt).toBeNull();
        expect(stored()).toEqual(['/']);

        const asked = serve({
            '/api/offline/manifest': manifest(['/', '/contact']),
            '/contact': html('Contact'),
        });
        expect(await sw.refreshOfflineCopies()).toBe(true);

        expect(asked).not.toContain('/');
        expect(stored()).toEqual(['/', '/contact']);
        const done = await readPass();
        expect(done.startedAt).toBe(open.startedAt);
        expect(done.completedAt).toBeTypeOf('number');
    });

    // An answer that stores nothing is a failure too, not a skip: a 500,
    // or the login page a session that ended redirects to.
    it('is not done when a page answered with an error or a redirect', async () => {
        await seed();
        serve({
            '/api/offline/manifest': manifest(['/', '/account']),
            '/': html('Accueil'),
            '/account': () => redirected(html('Connexion')),
        });

        expect(await sw.refreshOfflineCopies()).toBe(false);
        expect((await readPass()).completedAt).toBeNull();

        serve({
            '/api/offline/manifest': manifest(['/', '/contact']),
            '/contact': new Response('Erreur', { status: 500 }),
        });
        expect(await sw.refreshOfflineCopies()).toBe(false);
    });

    // Signed out and back in as someone else while a pass ran: the new
    // account's request starts its own pass instead of taking the other's.
    it('never lets another account join a pass that is running', async () => {
        await seed();
        let release;
        serve({
            '/api/offline/manifest': manifest(['/']),
            '/': () => new Promise((resolve) => { release = () => resolve(html('Accueil de a')); }),
        });
        const first = sw.refreshOfflineCopies();
        await vi.waitFor(() => expect(release).toBeTypeOf('function'));

        await seed({ ...CONFIG, account_scope: 'b' });
        const asked = serve({ '/api/offline/manifest': manifest(['/'], [], { 'X-Offline-Scope': 'b' }), '/': html('Accueil de b', { 'X-Offline-Scope': 'b' }) });
        const second = sw.refreshOfflineCopies();

        expect(second).not.toBe(first);
        expect(await second).toBe(true);
        expect(asked).toContain('/api/offline/manifest');
        release();
        await first;
    });

    // A pass left open for hours — one that keeps failing — is started
    // again, so a page stamped early in it is asked again too.
    it('starts again rather than resume a pass left open for more than an hour', async () => {
        const startedAt = Date.now() - 2 * 60 * 60 * 1000;
        await seed(CONFIG, { [sw.PASS_URL]: { scope: 'a', version: '1', startedAt, completedAt: null } });
        await (await caches.open('content-a-1')).put('/', html('Ancienne', { [sw.STORED_AT_HEADER]: String(startedAt + 1000) }));
        const asked = serve({
            '/api/offline/manifest': manifest(['/']),
            '/': html('Accueil'),
        });

        expect(await sw.refreshOfflineCopies()).toBe(true);

        expect(asked).toContain('/');
        expect((await readPass()).startedAt).toBeGreaterThan(startedAt);
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
    ])('does nothing %s', async (_label, overrides) => {
        await seed({ ...CONFIG, ...overrides });
        const asked = serve({ '/api/offline/manifest': manifest(['/']) });

        expect(await sw.refreshOfflineCopies()).toBe(false);
        expect(asked).toEqual([]);
    });

    it('keeps only the list when asked for it (Data Saver), even signed in', async () => {
        await seed();
        const asked = serve({ '/api/offline/manifest': manifest(['/', '/contact']), '/': html('Accueil') });

        expect(await sw.refreshOfflineCopies(true)).toBe(true);

        expect(asked).toEqual(['/api/offline/manifest']);
        const list = await (await (await caches.open('offline-config')).match(sw.LIST_URL)).json();
        expect(list.pages).toEqual(['/', '/contact']);
    });

    it('removes what left the list when it keeps only the list, fetching nothing', async () => {
        await seed();
        const content = await caches.open('content-a-1');
        await content.put('/members/12', html('Plus lié à ce compte'));
        await content.put('/contact', html('Contact'));
        const asked = serve({ '/api/offline/manifest': manifest(['/', '/contact']) });

        expect(await sw.refreshOfflineCopies(true)).toBe(true);

        expect(asked).toEqual(['/api/offline/manifest']);
        expect(stored()).toEqual(['/contact']);
    });

    it('keeps only the list for a visitor not signed in, so the public pages they visit are kept', async () => {
        await seed({ ...CONFIG, account_scope: 'guest' });
        const asked = serve({ '/api/offline/manifest': manifest(['/', '/contact']), '/': html('Accueil') });

        expect(await sw.refreshOfflineCopies()).toBe(true);

        // Nothing downloaded ahead: the manifest alone was asked for.
        expect(asked).toEqual(['/api/offline/manifest']);
        const list = await (await (await caches.open('offline-config')).match(sw.LIST_URL)).json();
        expect(list.scope).toBe('guest');
        expect(list.pages).toEqual(['/', '/contact']);
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

describe('sw.js: announcePassDone() — every window hears that the pass finished (#883)', () => {
    // The page that asked may have been left before a long pass ends; the
    // launch is remembered only on this message, so every open window of
    // the app gets it, with whose pass it was.
    it('tells every open window, with the scope and version of the pass', async () => {
        await seed();
        const windows = [{ postMessage: vi.fn() }, { postMessage: vi.fn() }];
        const matchAll = vi.fn(async () => windows);
        self.clients = /** @type {any} */ ({ matchAll });

        // The pass's own record, never the configuration current when it
        // ends: another account may have signed in since.
        await seed({ ...CONFIG, account_scope: 'b' });
        await sw.announcePassDone({ scope: 'a', version: '1' });

        expect(matchAll).toHaveBeenCalledWith({ type: 'window', includeUncontrolled: true });
        windows.forEach((client) => {
            expect(client.postMessage).toHaveBeenCalledWith({ type: 'offline-refresh-done', scope: 'a|1' });
        });
        delete self.clients;
    });
});

describe('sw.js: adoptPreviousVersion() — an update no longer starts from nothing (#883)', () => {
    // What account a's list holds: what an update may carry over.
    beforeEach(async () => {
        await seed(CONFIG, { [sw.LIST_URL]: { scope: 'a', pages: ['/', '/contact'], last: [] } });
    });

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

    // The new cache exists as soon as a fetch has opened it — which any
    // fetch does once the configuration naming the new version is stored,
    // before the adoption runs. Existing is not « already adopted »: what
    // it lacks is still copied, and what it holds, fresher, is kept.
    it('still copies into a current cache that already exists, without replacing what it holds', async () => {
        await (await caches.open('content-a-1')).put('/', html('Ancienne'));
        await (await caches.open('content-a-1')).put('/contact', html('Contact'));
        await (await caches.open('content-a-2')).put('/', html('Actuelle'));

        await sw.adoptPreviousVersion('a', '2');

        expect(await (await (await caches.open('content-a-2')).match('/')).text()).toContain('Actuelle');
        expect(stored('content-a-2')).toEqual(['/', '/contact']);
        expect(caches.stores.has('content-a-1')).toBe(false);
    });

    // An empty current cache — opened by a fetch, nothing stored yet — is
    // the case the review found: the copies were deleted, never copied.
    it('copies into an empty current cache rather than losing the previous copies', async () => {
        await (await caches.open('content-a-1')).put('/contact', html('Contact'));
        await caches.open('content-a-2');

        await sw.adoptPreviousVersion('a', '2');

        expect(stored('content-a-2')).toEqual(['/contact']);
        expect(caches.stores.has('content-a-1')).toBe(false);
    });

    // A previous build kept pages under broader rules — another member's
    // fiche under « /members/ ». The new list never named it: it is not
    // carried over, and nor is an image no kept page shows.
    it('carries over only what the account\'s list holds, and the images its pages show', async () => {
        const previous = await caches.open('content-a-1');
        await previous.put('/contact', html('<img src="/files/5/md" alt="">'));
        await previous.put('/files/5/md', new Response('webp-5'));
        await previous.put('/members/99', html('Une autre fiche'));
        await previous.put('/files/9/thumb', new Response('plus montrée'));

        await sw.adoptPreviousVersion('a', '2');

        expect(stored('content-a-2')).toEqual(['/contact', '/files/5/md']);
        expect(caches.stores.has('content-a-1')).toBe(false);
    });

    it('carries nothing over before the account has a list, and still drops the old cache', async () => {
        await seed(CONFIG, { [sw.LIST_URL]: { scope: 'b', pages: ['/contact'], last: [] } });
        await (await caches.open('content-a-1')).put('/contact', html('Contact'));

        await sw.adoptPreviousVersion('a', '2');

        expect(stored('content-a-2')).toEqual([]);
        expect(caches.stores.has('content-a-1')).toBe(false);
    });

    it('takes a page from the newest previous version when two hold it', async () => {
        await (await caches.open('content-a-1')).put('/', html('Plus ancienne'));
        await (await caches.open('content-a-2')).put('/', html('Plus récente'));

        await sw.adoptPreviousVersion('a', '3');

        expect(await (await (await caches.open('content-a-3')).match('/')).text()).toContain('Plus récente');
        expect(caches.stores.has('content-a-1')).toBe(false);
        expect(caches.stores.has('content-a-2')).toBe(false);
    });
});

describe('sw.js: the exact list goes with the copies it names (#883)', () => {
    async function seedListAndPass(scope) {
        await seed(CONFIG, {
            [sw.LIST_URL]: { scope, pages: ['/members/12'], last: [] },
            [sw.PASS_URL]: { scope, version: '1', startedAt: 1, completedAt: 2 },
        });
    }

    function held() {
        return [...(caches.stores.get('offline-config')?.keys() ?? [])].sort();
    }

    it('forgets the list and the pass record on logout or consent withdrawn, and keeps the configuration', async () => {
        await seedListAndPass('a');

        await sw.purgeAllContentCaches();

        expect(held()).toEqual([CONFIG_URL]);
    });

    it('forgets another account\'s list when the scope changes', async () => {
        await seedListAndPass('a');

        await sw.purgeContentCachesExcept('b');

        expect(held()).toEqual([CONFIG_URL]);
    });

    it('keeps the list of the scope being kept', async () => {
        await seedListAndPass('a');

        await sw.purgeContentCachesExcept('a');

        expect(held()).toEqual([CONFIG_URL, sw.LIST_URL, sw.PASS_URL].sort());
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
