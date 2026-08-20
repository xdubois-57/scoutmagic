// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: navigator.serviceWorker is mocked. Exercises the
// REAL implementation in public/assets/js/offline-cache.js (imported
// below, never reimplemented here). No test seam needed — the module's
// entire effect is one postMessage call per triggering event
// (import-time, form submit, logout), observable on the mocked
// registration.active.postMessage spy.
//
// This closes out the offline-correctness thread from
// docs/js-test-coverage-plan.md: P1.2 fixed a real divergence in sw.js's
// copy of the whitelist rule, this plan's Step 3 covered offline-nav.js's
// own copy, and this file is the piece that actually hands the whitelist
// (and every other bit of offline config) to the service worker in the
// first place.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function el(html) {
    const div = document.createElement('div');
    div.innerHTML = html.trim();
    return div.firstElementChild;
}

function buildConfig(config) {
    const script = document.createElement('script');
    script.type = 'application/json';
    script.id = 'offline-config-data';
    script.textContent = JSON.stringify(config);
    document.body.appendChild(script);
}

const BASE_CONFIG = {
    stalenessDays: 7,
    consent: true,
    whitelist: [{ path: '/', match: 'exact' }],
    accountScope: 'acct1',
    version: '1.2.3',
};

let activeSpy;

function stubServiceWorker({ ready = true } = {}) {
    activeSpy = { postMessage: vi.fn() };
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: {
            ready: ready ? Promise.resolve({ active: activeSpy }) : Promise.resolve({ active: null }),
        },
    });
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/offline-cache.js');
}

beforeEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    stubServiceWorker();
    Object.defineProperty(window, 'matchMedia', {
        configurable: true,
        value: vi.fn(() => ({ matches: false })),
    });
    delete window.navigator.standalone;
});

describe('offline-cache.js: setup guards', () => {
    it('does nothing when the browser has no serviceWorker support', async () => {
        // The production guard is `if (!('serviceWorker' in navigator))`,
        // an EXISTENCE check, not a truthiness check — setting the value
        // to undefined via defineProperty is not equivalent, since the
        // property still exists and 'in' still returns true. That
        // mistake here first threw synchronously (undefined.ready) and
        // then poisoned a LATER, unrelated test via an unhandled
        // rejection surfacing on whichever test happened to be running
        // when the microtask queue flushed — found via a false failure
        // three tests down, not here.
        delete navigator.serviceWorker;
        buildConfig(BASE_CONFIG);
        await boot();
        // No config-send attempt at all — nothing to assert against
        // without a registration, so prove no throw and no listeners:
        // a logout form submit must be inert too.
        const form = el('<form action="/logout"></form>');
        document.body.appendChild(form);
        expect(() => form.dispatchEvent(new Event('submit'))).not.toThrow();
    });

    it('does nothing when #offline-config-data is absent', async () => {
        await boot();
        await vi.waitFor(() => {}, { timeout: 10 }).catch(() => {});
        expect(activeSpy.postMessage).not.toHaveBeenCalled();
    });

    it('does nothing when the config JSON is malformed', async () => {
        const script = document.createElement('script');
        script.type = 'application/json';
        script.id = 'offline-config-data';
        script.textContent = 'not json';
        document.body.appendChild(script);
        await boot();
        await Promise.resolve();
        expect(activeSpy.postMessage).not.toHaveBeenCalled();
    });

    it('does not throw when the registration has no active worker yet', async () => {
        stubServiceWorker({ ready: false });
        buildConfig(BASE_CONFIG);
        await expect(boot()).resolves.toBeUndefined();
        expect(activeSpy.postMessage).not.toHaveBeenCalled();
    });
});

describe('offline-cache.js: sendConfig() on page load', () => {
    it('sends the whitelist, staleness, account scope, version and consent from the config blob', async () => {
        buildConfig(BASE_CONFIG);
        await boot();
        await vi.waitFor(() => expect(activeSpy.postMessage).toHaveBeenCalledWith({
            type: 'offline-config',
            staleness_days: 7,
            consent: true,
            whitelist: BASE_CONFIG.whitelist,
            account_scope: 'acct1',
            version: '1.2.3',
            standalone: false,
        }));
    });

    it('sends consent: false verbatim when the page config says consent has not been granted', async () => {
        buildConfig({ ...BASE_CONFIG, consent: false });
        await boot();
        await vi.waitFor(() => expect(activeSpy.postMessage).toHaveBeenCalledWith(expect.objectContaining({ consent: false })));
    });

    it('reports standalone: true when display-mode: standalone matches', async () => {
        buildConfig(BASE_CONFIG);
        Object.defineProperty(window, 'matchMedia', {
            configurable: true,
            value: vi.fn((query) => ({ matches: query === '(display-mode: standalone)' })),
        });
        await boot();
        await vi.waitFor(() => expect(activeSpy.postMessage).toHaveBeenCalledWith(expect.objectContaining({ standalone: true })));
    });

    it('reports standalone: true via navigator.standalone (iOS Safari) even without matchMedia support', async () => {
        buildConfig(BASE_CONFIG);
        Object.defineProperty(window, 'matchMedia', { configurable: true, value: undefined });
        window.navigator.standalone = true;
        await boot();
        await vi.waitFor(() => expect(activeSpy.postMessage).toHaveBeenCalledWith(expect.objectContaining({ standalone: true })));
    });
});

describe('offline-cache.js: logout purges every content cache', () => {
    it('sends purge-content-caches when a logout form is submitted', async () => {
        buildConfig(BASE_CONFIG);
        const form = el('<form action="/logout"></form>');
        document.body.appendChild(form);
        await boot();
        activeSpy.postMessage.mockClear(); // clear the import-time sendConfig() call

        form.dispatchEvent(new Event('submit'));

        await vi.waitFor(() => expect(activeSpy.postMessage).toHaveBeenCalledWith({ type: 'purge-content-caches' }));
    });

    // There can be a desktop AND a mobile nav logout form on the same
    // page — both must purge, not just whichever one the visitor used.
    it('wires EVERY logout form on the page, not just the first', async () => {
        buildConfig(BASE_CONFIG);
        const desktopForm = el('<form action="/logout" id="desktop"></form>');
        const mobileForm = el('<form action="/logout" id="mobile"></form>');
        document.body.appendChild(desktopForm);
        document.body.appendChild(mobileForm);
        await boot();
        activeSpy.postMessage.mockClear();

        mobileForm.dispatchEvent(new Event('submit'));
        await vi.waitFor(() => expect(activeSpy.postMessage).toHaveBeenCalledWith({ type: 'purge-content-caches' }));
        activeSpy.postMessage.mockClear();

        desktopForm.dispatchEvent(new Event('submit'));
        await vi.waitFor(() => expect(activeSpy.postMessage).toHaveBeenCalledWith({ type: 'purge-content-caches' }));
    });

    it('a form with a different action is never wired', async () => {
        buildConfig(BASE_CONFIG);
        const form = el('<form action="/login"></form>');
        document.body.appendChild(form);
        await boot();
        activeSpy.postMessage.mockClear();

        form.dispatchEvent(new Event('submit'));
        await Promise.resolve();
        expect(activeSpy.postMessage).not.toHaveBeenCalled();
    });
});

describe('offline-cache.js: withdrawing functional cookie consent', () => {
    function buildCookiesForm(functionalChecked) {
        const form = el('<form action="/cookies/save"></form>');
        const checkbox = el(`<input type="checkbox" id="cookie-functional" ${functionalChecked ? 'checked' : ''}>`);
        form.appendChild(checkbox);
        document.body.appendChild(form);
        return form;
    }

    // A bare purge message alone would empty the caches but leave the
    // service worker still believing consent is granted, so it would
    // start writing to cache again on the very next whitelisted page —
    // this is why the file sends a FULL offline-config update with
    // consent: false instead, per the file's own comment. That is the one
    // real behavioural contract worth pinning here.
    it('sends a full config update with consent: false when functional consent is withdrawn', async () => {
        buildConfig(BASE_CONFIG);
        buildCookiesForm(false);
        await boot();
        activeSpy.postMessage.mockClear();

        document.querySelector('form[action="/cookies/save"]').dispatchEvent(new Event('submit'));

        await vi.waitFor(() => expect(activeSpy.postMessage).toHaveBeenCalledWith(expect.objectContaining({
            type: 'offline-config',
            consent: false,
            whitelist: BASE_CONFIG.whitelist,
        })));
    });

    it('sends nothing at all when functional consent stays granted', async () => {
        buildConfig(BASE_CONFIG);
        buildCookiesForm(true);
        await boot();
        activeSpy.postMessage.mockClear();

        document.querySelector('form[action="/cookies/save"]').dispatchEvent(new Event('submit'));
        await Promise.resolve();
        expect(activeSpy.postMessage).not.toHaveBeenCalled();
    });

    it('does nothing when there is no cookies-save form on the page', async () => {
        buildConfig(BASE_CONFIG);
        await expect(boot()).resolves.toBeUndefined();
    });
});
