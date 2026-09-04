// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch is mocked, navigator.onLine is stubbed, and
// so is the `bootstrap` global (a vendored classic script absent under
// jsdom — same approach as tests/js/settings.test.js). Exercises the REAL
// implementation in public/assets/js/offline-nav.js (imported below, never
// reimplemented here).
//
// isWhitelisted() is the THIRD implementation of the same server-supplied
// whitelist rule — Core\Offline\OfflineWhitelist::matches() (PHP) and
// public/sw.js's own isWhitelisted() (the service worker, fixed and
// covered earlier in docs/js-test-coverage-plan.md's P1.2 after a real
// divergence was found there) are the other two. Before this file, this
// copy was pinned only by tests/Core/View/OfflineNavDialogTest.php's
// source-text grep for "entry.match === 'child'" — never actually
// exercised. Tested here indirectly through applyState()'s observable
// effect on real <a> elements (which greyed-out state a visitor actually
// sees) rather than by extracting it into a seam, since that is the real
// contract this file has with the page.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function setOnline(value) {
    Object.defineProperty(navigator, 'onLine', { configurable: true, value });
}

function buildConfig(whitelist, extraJson = {}) {
    const script = document.createElement('script');
    script.type = 'application/json';
    script.id = 'offline-config-data';
    script.textContent = JSON.stringify({ whitelist, ...extraJson });
    document.body.appendChild(script);
}

function buildDialog() {
    document.body.appendChild((() => {
        const d = document.createElement('div');
        d.id = 'offline-dialog';
        return d;
    })());
    const msg = document.createElement('div');
    msg.id = 'offline-dialog-message';
    document.body.appendChild(msg);
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/offline-nav.js');
}

const CORE_WHITELIST = [
    { path: '/', match: 'exact' },
    { path: '/contact', match: 'exact' },
    { path: '/account', match: 'exact' },
    { path: '/members/', match: 'child' },
];

let modalInstance;

// offline-nav.js registers every listener via delegation directly on
// `document`/`window` (document.addEventListener('click', ..., true), and
// window.addEventListener('online'/'offline', ...)) rather than on
// specific elements — unlike every other file covered in this plan so
// far. document.body.innerHTML = '' between tests destroys the DOM nodes
// but NOT these document/window-level listeners, so without explicit
// cleanup every test's boot() stacks another full set of handlers on top
// of every previous test's, and a later test's click can silently
// trigger a dozen closures from earlier tests. Caught exactly this way:
// showDialog()'s "instance reused" test failed because a much earlier
// test's stale, detached #offline-dialog was still being shown by a
// leaked listener. Track every addEventListener call here and remove
// them all after each test.
let trackedListeners = [];

function trackListeners(target) {
    const original = target.addEventListener.bind(target);
    target.addEventListener = (type, listener, options) => {
        trackedListeners.push({ target, type, listener, options });
        return original(type, listener, options);
    };
}

beforeEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    setOnline(true);
    modalInstance = { show: vi.fn() };
    global.bootstrap = { Modal: vi.fn(() => modalInstance) };
    trackedListeners = [];
    trackListeners(document);
    trackListeners(window);
});

afterEach(() => {
    trackedListeners.forEach(({ target, type, listener, options }) => {
        target.removeEventListener(type, listener, options);
    });
});

describe('offline-nav.js: setup guards', () => {
    it('does nothing when #offline-config-data is absent', async () => {
        expect(() => boot()).not.toThrow();
    });

    it('does nothing when the config JSON is malformed', async () => {
        const script = document.createElement('script');
        script.type = 'application/json';
        script.id = 'offline-config-data';
        script.textContent = 'not json';
        document.body.appendChild(script);
        setOnline(false);
        const originalFetch = global.fetch;
        await boot();
        // Proof the WHOLE setup bailed, not just one layer: layer 2 (the
        // window.fetch wrapper) is installed unconditionally right after
        // the whitelist parses, so if it was never touched, neither was
        // anything else below it — including the click/submit listeners,
        // which have no directly observable "not installed" signal of
        // their own without triggering jsdom's real anchor-navigation
        // machinery (a separate, noisy concern this isn't testing).
        expect(window.fetch).toBe(originalFetch);
    });
});

describe('offline-nav.js: applyState() greys out unavailable links (isWhitelisted through its real contract)', () => {
    function buildLinks(hrefs) {
        hrefs.forEach((href) => {
            const a = document.createElement('a');
            a.href = href;
            document.body.appendChild(a);
        });
    }

    it('leaves every link untouched while online, regardless of the whitelist', async () => {
        buildConfig(CORE_WHITELIST);
        buildLinks(['/finance', '/members/12', '/members/12/emails/5']);
        setOnline(true);
        await boot();

        document.querySelectorAll('a').forEach((a) => {
            expect(a.classList.contains('offline-link-disabled')).toBe(false);
            expect(a.hasAttribute('aria-disabled')).toBe(false);
        });
    });

    it('exact match: greys out everything except the exact whitelisted paths', async () => {
        buildConfig(CORE_WHITELIST);
        buildLinks(['/', '/contact', '/finance', '/contacts']);
        setOnline(false);
        await boot();

        const disabled = (href) => document.querySelector(`a[href="${href}"]`).classList.contains('offline-link-disabled');
        expect(disabled('/')).toBe(false);
        expect(disabled('/contact')).toBe(false);
        expect(disabled('/finance')).toBe(true);
        // A path that merely STARTS WITH an exact-match entry must not
        // pass — exact means exact, not prefix.
        expect(disabled('/contacts')).toBe(true);
    });

    // The exact bug fixed in public/sw.js earlier in this plan
    // (docs/js-test-coverage-plan.md's P1.2): a match:'child' entry
    // accepts the path plus EXACTLY one more segment. This is the first
    // time this file's OWN copy of that rule is exercised directly rather
    // than through a PHP source-text grep.
    it('child match: accepts the path plus exactly one segment, rejects zero or two+', async () => {
        buildConfig(CORE_WHITELIST);
        buildLinks(['/members/', '/members/12', '/members/12/emails/5']);
        setOnline(false);
        await boot();

        const disabled = (href) => document.querySelector(`a[href="${href}"]`).classList.contains('offline-link-disabled');
        expect(disabled('/members/')).toBe(true); // bare prefix: zero extra segments
        expect(disabled('/members/12')).toBe(false); // exactly one: available
        expect(disabled('/members/12/emails/5')).toBe(true); // two extra segments
    });

    it('sets aria-disabled on a greyed link and removes it once back online', async () => {
        buildConfig(CORE_WHITELIST);
        buildLinks(['/finance']);
        setOnline(false);
        await boot();

        const link = document.querySelector('a[href="/finance"]');
        expect(link.getAttribute('aria-disabled')).toBe('true');

        setOnline(true);
        window.dispatchEvent(new Event('online'));
        expect(link.classList.contains('offline-link-disabled')).toBe(false);
        expect(link.hasAttribute('aria-disabled')).toBe(false);
    });

    it('re-greys links on the "offline" event too', async () => {
        buildConfig(CORE_WHITELIST);
        buildLinks(['/finance']);
        setOnline(true);
        await boot();

        setOnline(false);
        window.dispatchEvent(new Event('offline'));
        expect(document.querySelector('a[href="/finance"]').classList.contains('offline-link-disabled')).toBe(true);
    });

    it('re-evaluates on visibilitychange only when the tab becomes visible (an installed app resuming from a frozen state)', async () => {
        buildConfig(CORE_WHITELIST);
        buildLinks(['/finance']);
        setOnline(true);
        await boot();
        setOnline(false); // connectivity changed while frozen; no online/offline event fired

        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
        document.dispatchEvent(new Event('visibilitychange'));
        expect(document.querySelector('a[href="/finance"]').classList.contains('offline-link-disabled')).toBe(false);

        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
        document.dispatchEvent(new Event('visibilitychange'));
        expect(document.querySelector('a[href="/finance"]').classList.contains('offline-link-disabled')).toBe(true);
    });

    it('strips query strings and fragments before matching', async () => {
        buildConfig(CORE_WHITELIST);
        buildLinks(['/contact?ref=footer', '/finance#section']);
        setOnline(false);
        await boot();

        expect(document.querySelector('a[href="/contact?ref=footer"]').classList.contains('offline-link-disabled')).toBe(false);
        expect(document.querySelector('a[href="/finance#section"]').classList.contains('offline-link-disabled')).toBe(true);
    });

    it('treats an empty whitelist as nothing available while offline', async () => {
        buildConfig([]);
        buildLinks(['/']);
        setOnline(false);
        await boot();
        expect(document.querySelector('a[href="/"]').classList.contains('offline-link-disabled')).toBe(true);
    });
});

describe('offline-nav.js: click interception (layer 1a — links)', () => {
    function buildLink(href) {
        const a = document.createElement('a');
        a.href = href;
        a.textContent = 'go';
        document.body.appendChild(a);
        return a;
    }

    it('passes a click through untouched while online, whitelisted or not', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const link = buildLink('/finance');
        setOnline(true);
        await boot();

        const evt = new MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(false);
        expect(modalInstance.show).not.toHaveBeenCalled();
    });

    it('passes a click through untouched while offline on a whitelisted link', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const link = buildLink('/contact');
        setOnline(false);
        await boot();

        const evt = new MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(false);
        expect(modalInstance.show).not.toHaveBeenCalled();
    });

    it('blocks a click and opens the dialog with the PAGE message while offline on a non-whitelisted link, once the network confirmed it is away', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const link = buildLink('/finance');
        setOnline(false);
        const probe = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
        window.fetch = probe;
        await boot();

        const evt = new MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(true);
        await vi.waitFor(() => expect(modalInstance.show).toHaveBeenCalled());
        expect(probe).toHaveBeenCalledWith('/api/version', expect.objectContaining({ method: 'HEAD', cache: 'no-store' }));
        expect(document.getElementById('offline-dialog-message').textContent).toBe("Cette page n'est pas disponible hors ligne.");
    });

    it('opens the page after all when navigator.onLine was stale and the server answers the probe', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const link = buildLink('/finance');
        setOnline(false);
        window.fetch = vi.fn(() => Promise.resolve({ ok: true }));
        const assign = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign });
        await boot();

        const evt = new MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(true);
        await vi.waitFor(() => expect(assign).toHaveBeenCalledWith(link.href));
        expect(modalInstance.show).not.toHaveBeenCalled();
        vi.unstubAllGlobals();
    });

    it('never cancels a click it could not explain — no dialog markup, or no Bootstrap yet', async () => {
        buildConfig(CORE_WHITELIST);
        const link = buildLink('/finance');
        setOnline(false);
        delete global.bootstrap;
        await boot();

        const evt = new MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(false);
    });

    it('re-evaluates the click against the CURRENT DOM, not a cached class, so a link added after boot is still covered', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        setOnline(false);
        await boot(); // applyState() ran once already, before this link existed
        const lateLink = buildLink('/finance');

        const evt = new MouseEvent('click', { bubbles: true, cancelable: true });
        lateLink.dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(true);
    });

    it('ignores a click that did not land on a link at all', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const div = document.createElement('div');
        document.body.appendChild(div);
        setOnline(false);
        await boot();

        const evt = new MouseEvent('click', { bubbles: true, cancelable: true });
        div.dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(false);
    });

    it('ignores an external link (no leading "/") — never in scope for this delegated handler', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const link = document.createElement('a');
        link.href = 'https://example.com/';
        document.body.appendChild(link);
        setOnline(false);
        await boot();

        const evt = new MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(false);
    });
});

describe('offline-nav.js: submit interception (layer 1b — forms, unconditional)', () => {
    function buildForm() {
        const form = document.createElement('form');
        document.body.appendChild(form);
        return form;
    }

    it('passes a submit through while online', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const form = buildForm();
        setOnline(true);
        await boot();

        const evt = new Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(false);
    });

    // No whitelist check at all for forms — every POST needs the network,
    // there is no "safe, cacheable" form action, unlike links.
    it('blocks every form submission while offline, with no whitelist exception', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const form = buildForm();
        setOnline(false);
        await boot();

        const evt = new Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(true);
        expect(modalInstance.show).toHaveBeenCalled();
        expect(document.getElementById('offline-dialog-message').textContent).toBe('Cette action nécessite une connexion.');
    });

    // Mutation-checked: jsdom's default test document path is '/', which
    // happens to be exact-whitelisted in CORE_WHITELIST — so a test that
    // never varies the page's own path could pass even if a whitelist
    // check were accidentally added to form submission (matching against
    // location.pathname, a form's action, or anything else). Explicitly
    // proves blocking is identical whether the CURRENT page is whitelisted
    // or not, since correct behaviour never consults the whitelist here at
    // all.
    it.each([
        { path: '/', whitelisted: true },
        { path: '/finance', whitelisted: false },
    ])('blocks submission the same way whether the current page ($path) is whitelisted or not', async ({ path }) => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        Object.defineProperty(window, 'location', { configurable: true, value: { pathname: path, href: '' } });
        const form = buildForm();
        form.setAttribute('action', path);
        setOnline(false);
        await boot();

        const evt = new Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(true);
    });
});

describe('offline-nav.js: window.fetch wrapper (layer 2)', () => {
    it('passes a GET through untouched while offline', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const inner = vi.fn(() => Promise.resolve('ok'));
        global.fetch = inner;
        setOnline(false);
        await boot();

        const result = await window.fetch('/api/x');
        expect(result).toBe('ok');
        expect(modalInstance.show).not.toHaveBeenCalled();
    });

    it('passes any method through while online', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const inner = vi.fn(() => Promise.resolve('ok'));
        global.fetch = inner;
        setOnline(true);
        await boot();

        const result = await window.fetch('/api/x', { method: 'POST' });
        expect(result).toBe('ok');
    });

    it('blocks a POST while offline, rejects, opens the dialog, and never calls the real fetch', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const inner = vi.fn(() => Promise.resolve('ok'));
        global.fetch = inner;
        setOnline(false);
        await boot();

        await expect(window.fetch('/api/x', { method: 'POST' })).rejects.toThrow('Failed to fetch');
        expect(inner).not.toHaveBeenCalled();
        expect(modalInstance.show).toHaveBeenCalled();
        expect(document.getElementById('offline-dialog-message').textContent).toBe('Cette action nécessite une connexion.');
    });

    it('reads the method off a Request object when init is absent (fetch(new Request(...)) form)', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        global.fetch = vi.fn(() => Promise.resolve('ok'));
        setOnline(false);
        await boot();

        const request = { method: 'DELETE' };
        await expect(window.fetch(request)).rejects.toThrow();
    });

    it('treats a missing method as GET and lets it through', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const inner = vi.fn(() => Promise.resolve('ok'));
        global.fetch = inner;
        setOnline(false);
        await boot();

        await window.fetch('/api/x', {});
        expect(inner).toHaveBeenCalled();
    });

    it('does nothing (no wrapper installed) when window.fetch was not a function to begin with', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        global.fetch = undefined;
        setOnline(false);
        await boot();
        expect(window.fetch).toBeUndefined();
    });
});

describe('offline-nav.js: showDialog()', () => {
    it('does nothing when the dialog element is absent from the page', async () => {
        buildConfig(CORE_WHITELIST);
        // No buildDialog() call.
        const link = document.createElement('a');
        link.href = '/finance';
        document.body.appendChild(link);
        setOnline(false);
        await boot();

        expect(() => link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))).not.toThrow();
        expect(global.bootstrap.Modal).not.toHaveBeenCalled();
    });

    it('does nothing when the bootstrap global is unavailable', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        global.bootstrap = undefined;
        const link = document.createElement('a');
        link.href = '/finance';
        document.body.appendChild(link);
        setOnline(false);
        await boot();

        expect(() => link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))).not.toThrow();
    });

    it('constructs the bootstrap.Modal instance only once and reuses it across multiple dialogs', async () => {
        buildConfig(CORE_WHITELIST);
        buildDialog();
        const link = document.createElement('a');
        link.href = '/finance';
        document.body.appendChild(link);
        setOnline(false);
        window.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
        await boot();

        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        await vi.waitFor(() => expect(modalInstance.show).toHaveBeenCalledTimes(2));
        expect(global.bootstrap.Modal).toHaveBeenCalledTimes(1);
    });
});

/**
 * The visible half of layer 1b: every cached page is READ ONLY offline,
 * and says so up front rather than letting a member fill in a form and
 * only then discover it cannot be sent.
 *
 * The fields themselves must stay editable throughout — a member may
 * legitimately write a message offline and send it when the signal comes
 * back, which is exactly what the groups composer's own localStorage
 * draft cache exists for. Disabling the text would defeat it.
 */
describe('offline-nav.js: read-only offline (submit controls + banner)', () => {
    function buildBanner() {
        const banner = document.createElement('div');
        banner.id = 'offline-readonly-banner';
        banner.className = 'd-none';
        document.body.appendChild(banner);
        return banner;
    }

    function buildForm() {
        document.body.insertAdjacentHTML('beforeend', `
            <form id="composer">
                <textarea name="body"></textarea>
                <input type="text" name="title">
                <button type="button" id="not-a-submit">Annuler</button>
                <button type="submit" id="send">Publier</button>
            </form>
        `);
        return document.getElementById('composer');
    }

    it('disables every submit control and marks the form while offline', async () => {
        buildConfig(CORE_WHITELIST);
        const form = buildForm();
        setOnline(false);
        await boot();

        expect(document.getElementById('send').disabled).toBe(true);
        expect(form.classList.contains('offline-form-disabled')).toBe(true);
    });

    it('leaves the fields editable so an offline draft can still be written', async () => {
        buildConfig(CORE_WHITELIST);
        buildForm();
        setOnline(false);
        await boot();

        expect(document.querySelector('textarea[name="body"]').disabled).toBe(false);
        expect(document.querySelector('input[name="title"]').disabled).toBe(false);
        expect(document.getElementById('not-a-submit').disabled).toBe(false);
    });

    it('re-enables the submit control when the connection comes back', async () => {
        buildConfig(CORE_WHITELIST);
        const form = buildForm();
        setOnline(false);
        await boot();
        expect(document.getElementById('send').disabled).toBe(true);

        setOnline(true);
        window.dispatchEvent(new Event('online'));

        expect(document.getElementById('send').disabled).toBe(false);
        expect(form.classList.contains('offline-form-disabled')).toBe(false);
    });

    /**
     * A control something else disabled for its own reasons (a composer
     * mid-submit) must not be silently released by coming back online.
     */
    it('never re-enables a control it did not disable itself', async () => {
        buildConfig(CORE_WHITELIST);
        buildForm();
        document.getElementById('send').disabled = true;
        setOnline(false);
        await boot();

        setOnline(true);
        window.dispatchEvent(new Event('online'));

        expect(document.getElementById('send').disabled).toBe(true);
    });

    it('leaves a form marked data-offline-safe alone', async () => {
        buildConfig(CORE_WHITELIST);
        document.body.insertAdjacentHTML('beforeend',
            '<form id="filter" data-offline-safe><button type="submit" id="apply">Filtrer</button></form>');
        setOnline(false);
        await boot();

        expect(document.getElementById('apply').disabled).toBe(false);
        expect(document.getElementById('filter').classList.contains('offline-form-disabled')).toBe(false);
    });

    it('reveals the read-only banner offline and hides it again online', async () => {
        buildConfig(CORE_WHITELIST);
        const banner = buildBanner();
        setOnline(false);
        await boot();
        expect(banner.classList.contains('d-none')).toBe(false);

        setOnline(true);
        window.dispatchEvent(new Event('online'));
        expect(banner.classList.contains('d-none')).toBe(true);
    });

    it('does nothing at all while online', async () => {
        buildConfig(CORE_WHITELIST);
        const form = buildForm();
        const banner = buildBanner();
        setOnline(true);
        await boot();

        expect(document.getElementById('send').disabled).toBe(false);
        expect(form.classList.contains('offline-form-disabled')).toBe(false);
        expect(banner.classList.contains('d-none')).toBe(true);
    });

    it('works on a page with no banner element at all', async () => {
        buildConfig(CORE_WHITELIST);
        buildForm();
        setOnline(false);

        await expect(boot()).resolves.not.toThrow();
        expect(document.getElementById('send').disabled).toBe(true);
    });
});
