// Isolated JavaScript unit test — jsdom only. Exercises the real
// public/assets/js/offline-prefetch.js against a fake service worker: it
// decides WHEN the installed app's offline copies are refreshed, and asks
// the worker to do it (#883). The work itself — pages, images, help,
// pruning — is public/sw.js's refreshOfflineCopies(), covered in
// tests/js/sw.test.js.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function config(overrides = {}) {
    const el = document.createElement('script');
    el.type = 'application/json';
    el.id = 'offline-config-data';
    el.textContent = JSON.stringify({ consent: true, accountScope: 'acct1', version: '1.2.3', ...overrides });
    document.body.appendChild(el);
}

function standalone(on) {
    window.matchMedia = /** @type {any} */ ((query) => ({ matches: on && query === '(display-mode: standalone)', media: query }));
}

let postMessage;
let workerListeners;

function installWorker() {
    postMessage = vi.fn();
    workerListeners = [];
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: {
            ready: Promise.resolve({ active: { postMessage } }),
            addEventListener: (type, listener) => workerListeners.push({ type, listener }),
        },
    });
}

/** The worker telling every listening page that its pass finished. */
function workerSaysDone() {
    workerListeners
        .filter(({ type }) => type === 'message')
        .forEach(({ listener }) => listener({ data: { type: 'offline-refresh-done' } }));
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/offline-prefetch.js');
}

async function settle() {
    await vi.runAllTimersAsync();
}

const ASK = { type: 'offline-refresh' };

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '';
    sessionStorage.clear();
    delete /** @type {any} */ (navigator).connection;
    installWorker();
    standalone(true);
    Object.defineProperty(document, 'readyState', { configurable: true, value: 'complete' });
});

describe('offline-prefetch.js: when it asks', () => {
    it('asks the worker once the installed app has loaded and is idle', async () => {
        config();
        await boot();
        expect(postMessage).not.toHaveBeenCalled();

        await settle();

        expect(postMessage).toHaveBeenCalledTimes(1);
        expect(postMessage).toHaveBeenCalledWith(ASK);
    });

    it.each([
        ['outside the installed app', () => standalone(false), {}],
        ['without functional consent', () => {}, { consent: false }],
        ['for a visitor not signed in', () => {}, { accountScope: 'guest' }],
        ['under Data Saver', () => { /** @type {any} */ (navigator).connection = { saveData: true }; }, {}],
    ])('never asks %s', async (_label, arrange, overrides) => {
        arrange();
        config(overrides);
        await boot();
        await settle();

        expect(postMessage).not.toHaveBeenCalled();
    });

    it('never asks without a service worker', async () => {
        Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: undefined });
        delete /** @type {any} */ (navigator).serviceWorker;
        config();

        await expect(boot()).resolves.toBeUndefined();
    });
});

describe('offline-prefetch.js: a launch is done only once the pass finished (#883)', () => {
    it('does not ask again in the same launch once the worker said the pass finished', async () => {
        config();
        await boot();
        await settle();
        workerSaysDone();

        installWorker();
        await boot();
        await settle();

        expect(postMessage).not.toHaveBeenCalled();
    });

    // The pass used to be marked done when it STARTED: interrupted, nothing
    // resumed it for 24 hours. Now the next page asks again, and the
    // worker resumes it where it stopped.
    it('asks again on the next page while the pass has not finished', async () => {
        config();
        await boot();
        await settle();

        installWorker();
        await boot();
        await settle();

        expect(postMessage).toHaveBeenCalledWith(ASK);
    });

    it('asks again for another account or another version', async () => {
        config();
        await boot();
        await settle();
        workerSaysDone();

        document.body.innerHTML = '';
        config({ accountScope: 'acct2' });
        installWorker();
        await boot();
        await settle();

        expect(postMessage).toHaveBeenCalledWith(ASK);
    });

    it('asks again once the last finished pass is a day old — an app left open for days still refreshes', async () => {
        config();
        await boot();
        await settle();
        workerSaysDone();

        vi.setSystemTime(Date.now() + 24 * 60 * 60 * 1000 + 1);
        installWorker();
        await boot();
        await settle();

        expect(postMessage).toHaveBeenCalledWith(ASK);
    });

    it('asks every time when sessionStorage is refused', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied'); });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('denied'); });
        config();
        await boot();
        await settle();
        workerSaysDone();

        installWorker();
        await boot();
        await settle();

        expect(postMessage).toHaveBeenCalledWith(ASK);
        vi.restoreAllMocks();
    });
});
