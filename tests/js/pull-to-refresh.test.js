// Isolated JavaScript unit test — jsdom only, no PHP server, no network,
// no touch screen. Exercises the REAL public/assets/js/pull-to-refresh.js
// (issue #842), with the REAL display-mode.js in front of it for the
// « installed app? » question, exactly as base.html.twig orders them.
//
// Touches are plain events carrying `touches`: jsdom has no Touch
// constructor, and the script reads nothing but clientX/clientY.
//
// window.ScoutMagicConnectivity is a stand-in here — its real behaviour
// (the probe, the ordering protection, the repaint) is offline-nav.js's,
// pinned by tests/js/offline-nav.test.js. One test at the end boots the
// real offline-nav.js instead, for the iOS scenario the issue names.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

let reload;
let trackedListeners = [];

function trackListeners(target) {
    const original = target.addEventListener.bind(target);
    target.addEventListener = (type, listener, options) => {
        trackedListeners.push({ target, type, listener, options });
        return original(type, listener, options);
    };
}

function environment({ standalone = true, reducedMotion = false } = {}) {
    window.matchMedia = /** @type {any} */ ((query) => ({
        matches: (standalone && query === '(display-mode: standalone)')
            || (reducedMotion && query === '(prefers-reduced-motion: reduce)'),
        media: query,
        addEventListener() {},
        removeEventListener() {},
    }));
}

function buildIndicator() {
    document.body.insertAdjacentHTML('beforeend', `
        <div id="pull-to-refresh" class="pull-to-refresh" aria-hidden="true">
            <i class="bi bi-arrow-clockwise pull-to-refresh-icon"></i>
        </div>
        <div id="pull-to-refresh-status" class="visually-hidden" role="status"></div>
    `);
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/display-mode.js');
    await import('../../public/assets/js/pull-to-refresh.js');
}

function indicator() {
    return document.getElementById('pull-to-refresh');
}

function touch(type, target, points) {
    const event = new Event(type, { bubbles: true, cancelable: true });
    const list = points.map(([clientX, clientY]) => ({ clientX, clientY }));
    Object.defineProperty(event, 'touches', { value: type === 'touchend' ? [] : list });
    target.dispatchEvent(event);
    return event;
}

/**
 * A one-finger gesture from (x, y) through each step, then released.
 * @returns {Event[]} the touchmove events, to read defaultPrevented off
 */
function gesture(target, from, steps, { release = true } = {}) {
    touch('touchstart', target, [from]);
    const moves = steps.map((point) => touch('touchmove', target, [point]));
    if (release) {
        touch('touchend', target, []);
    }
    return moves;
}

/** Straight down, far enough to pass the threshold (64 px at 0.5). */
function pullPastThreshold(target = document.body) {
    return gesture(target, [100, 10], [[100, 30], [100, 90], [100, 170]]);
}

function settle() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

function setScrollY(value) {
    Object.defineProperty(window, 'scrollY', { configurable: true, value });
}

beforeEach(() => {
    document.body.innerHTML = '';
    document.body.className = '';
    document.cookie = 'sm_display=; path=/; Max-Age=0';
    delete /** @type {any} */ (window.navigator).standalone;
    delete window.ScoutMagicConnectivity;
    delete window.ScoutMagicDisplayMode;
    setScrollY(0);
    reload = vi.fn();
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { href: 'http://localhost/calendar', protocol: 'http:', reload },
    });
    trackedListeners = [];
    trackListeners(document);
    trackListeners(window);
    environment();
    buildIndicator();
});

afterEach(() => {
    trackedListeners.forEach(({ target, type, listener, options }) => {
        target.removeEventListener(type, listener, options);
    });
});

describe('pull-to-refresh.js: only in the installed application', () => {
    it('does nothing at all in a browser tab', async () => {
        environment({ standalone: false });
        await boot();

        const moves = pullPastThreshold();

        expect(reload).not.toHaveBeenCalled();
        expect(indicator().classList.contains('is-pulling')).toBe(false);
        // The browser's own gesture is left alone: nothing was cancelled.
        expect(moves.some((event) => event.defaultPrevented)).toBe(false);
        expect(trackedListeners.filter(({ type }) => type.startsWith('touch'))).toHaveLength(0);
    });

    it('works in the installed application on iOS, recognised by navigator.standalone', async () => {
        environment({ standalone: false });
        Object.defineProperty(window.navigator, 'standalone', { value: true, configurable: true });
        await boot();

        pullPastThreshold();

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('does nothing on a page without the indicator', async () => {
        document.body.innerHTML = '';
        await boot();

        pullPastThreshold();

        expect(reload).not.toHaveBeenCalled();
    });
});

describe('pull-to-refresh.js: the gesture', () => {
    it('shows the indicator progressively while pulling, and retracts it below the threshold', async () => {
        await boot();

        gesture(document.body, [100, 10], [[100, 30]], { release: false });
        const first = parseFloat(indicator().style.getPropertyValue('--ptr-pull'));
        touch('touchmove', document.body, [[100, 70]]);
        const second = parseFloat(indicator().style.getPropertyValue('--ptr-pull'));

        expect(indicator().classList.contains('is-pulling')).toBe(true);
        expect(first).toBeGreaterThan(0);
        expect(second).toBeGreaterThan(first);
        expect(indicator().classList.contains('is-armed')).toBe(false);

        touch('touchend', document.body, []);

        expect(reload).not.toHaveBeenCalled();
        expect(indicator().classList.contains('is-pulling')).toBe(false);
        expect(indicator().style.getPropertyValue('--ptr-pull')).toBe('');
    });

    it('arms past the threshold and reloads exactly once on release', async () => {
        await boot();

        gesture(document.body, [100, 10], [[100, 30], [100, 170]], { release: false });
        expect(indicator().classList.contains('is-armed')).toBe(true);
        touch('touchend', document.body, []);

        expect(reload).toHaveBeenCalledTimes(1);
        expect(indicator().classList.contains('is-refreshing')).toBe(true);
        expect(document.getElementById('pull-to-refresh-status').textContent).toBe('Actualisation…');
    });

    it('claims the moves it uses, so the browser neither scrolls nor refreshes a second time', async () => {
        await boot();

        const moves = pullPastThreshold();

        expect(moves.slice(1).every((event) => event.defaultPrevented)).toBe(true);
    });

    it('does not start on a page that is already scrolled', async () => {
        setScrollY(240);
        await boot();

        const moves = pullPastThreshold();

        expect(reload).not.toHaveBeenCalled();
        expect(indicator().classList.contains('is-pulling')).toBe(false);
        expect(moves.some((event) => event.defaultPrevented)).toBe(false);
    });

    it('ignores a mostly horizontal movement', async () => {
        await boot();

        const moves = gesture(document.body, [10, 10], [[60, 25], [200, 60], [300, 150]]);

        expect(reload).not.toHaveBeenCalled();
        expect(moves.some((event) => event.defaultPrevented)).toBe(false);
    });

    it('ignores an upward movement', async () => {
        await boot();

        gesture(document.body, [100, 300], [[100, 250], [100, 100]]);

        expect(reload).not.toHaveBeenCalled();
    });

    it('ignores a pull started inside a modal or an offcanvas', async () => {
        document.body.insertAdjacentHTML('beforeend', `
            <div class="modal"><div class="modal-body"><p id="in-modal">x</p></div></div>
            <div class="offcanvas offcanvas-start"><div class="offcanvas-body"><a id="in-offcanvas" href="/">x</a></div></div>
            <div class="offcanvas-lg"><span id="in-responsive-offcanvas">x</span></div>
        `);
        await boot();

        pullPastThreshold(document.getElementById('in-modal'));
        pullPastThreshold(document.getElementById('in-offcanvas'));
        pullPastThreshold(document.getElementById('in-responsive-offcanvas'));

        expect(reload).not.toHaveBeenCalled();
    });

    it('ignores a pull on the page while a dialog or the menu is open over it', async () => {
        document.body.insertAdjacentHTML('beforeend', '<div class="offcanvas offcanvas-start show"></div>');
        await boot();

        pullPastThreshold();
        expect(reload).not.toHaveBeenCalled();

        document.querySelector('.offcanvas').classList.remove('show');
        document.body.classList.add('modal-open');
        pullPastThreshold();
        expect(reload).not.toHaveBeenCalled();
    });

    it('ignores a pull inside a list that is itself scrolled down', async () => {
        document.body.insertAdjacentHTML('beforeend', '<div id="list"><p id="row">x</p></div>');
        Object.defineProperty(document.getElementById('list'), 'scrollTop', { configurable: true, value: 120 });
        await boot();

        pullPastThreshold(document.getElementById('row'));

        expect(reload).not.toHaveBeenCalled();
    });

    it('ignores two fingers', async () => {
        await boot();

        touch('touchstart', document.body, [[100, 10], [200, 10]]);
        touch('touchmove', document.body, [[100, 170], [200, 170]]);
        touch('touchend', document.body, []);

        expect(reload).not.toHaveBeenCalled();
    });

    it('retracts on touchcancel', async () => {
        await boot();

        gesture(document.body, [100, 10], [[100, 30], [100, 170]], { release: false });
        touch('touchcancel', document.body, []);
        touch('touchend', document.body, []);

        expect(reload).not.toHaveBeenCalled();
        expect(indicator().classList.contains('is-armed')).toBe(false);
    });

    it('ignores a second pull while the first one is refreshing', async () => {
        await boot();

        pullPastThreshold();
        const moves = pullPastThreshold();

        expect(reload).toHaveBeenCalledTimes(1);
        expect(moves.some((event) => event.defaultPrevented)).toBe(false);
        expect(indicator().classList.contains('is-refreshing')).toBe(true);
    });

    it('comes back to rest when the page is restored from the back/forward cache', async () => {
        await boot();
        pullPastThreshold();

        const restored = new Event('pageshow');
        Object.defineProperty(restored, 'persisted', { value: true });
        window.dispatchEvent(restored);

        expect(indicator().classList.contains('is-refreshing')).toBe(false);
        expect(document.getElementById('pull-to-refresh-status').textContent).toBe('');
        pullPastThreshold();
        expect(reload).toHaveBeenCalledTimes(2);
    });
});

describe('pull-to-refresh.js: prefers-reduced-motion', () => {
    it('does not move the indicator with the finger, and still refreshes', async () => {
        environment({ reducedMotion: true });
        await boot();

        gesture(document.body, [100, 10], [[100, 30]], { release: false });
        const first = indicator().style.getPropertyValue('--ptr-pull');
        touch('touchmove', document.body, [[100, 90]]);
        const second = indicator().style.getPropertyValue('--ptr-pull');

        expect(indicator().classList.contains('is-reduced-motion')).toBe(true);
        expect(second).toBe(first);
        // Progress is still reported — as opacity and colour, not motion.
        expect(parseFloat(indicator().style.getPropertyValue('--ptr-progress'))).toBeGreaterThan(0);

        touch('touchmove', document.body, [[100, 170]]);
        touch('touchend', document.body, []);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('follows the finger when motion is allowed', async () => {
        await boot();

        gesture(document.body, [100, 10], [[100, 30]], { release: false });

        expect(indicator().classList.contains('is-reduced-motion')).toBe(false);
    });
});

describe('pull-to-refresh.js: the decision « reload now » or « ask the network first »', () => {
    function stubConnectivity({ offline, verdict }) {
        const recheck = vi.fn(() => (verdict instanceof Error ? Promise.reject(verdict) : Promise.resolve(verdict)));
        window.ScoutMagicConnectivity = { isOfflineConfirmed: vi.fn(() => offline), recheck };
        return recheck;
    }

    it('reloads directly, with no probe first, when the page is not confirmed offline', async () => {
        const recheck = stubConnectivity({ offline: false, verdict: true });
        await boot();

        pullPastThreshold();

        expect(recheck).not.toHaveBeenCalled();
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('re-asks the network when confirmed offline, and does not reload while it is still away', async () => {
        const recheck = stubConnectivity({ offline: true, verdict: false });
        await boot();

        pullPastThreshold();
        expect(recheck).toHaveBeenCalledTimes(1);
        await settle();

        expect(reload).not.toHaveBeenCalled();
        expect(indicator().classList.contains('is-refreshing')).toBe(false);
        expect(document.getElementById('pull-to-refresh-status').textContent).toBe('');
    });

    it('accepts a new pull once a failed re-check has retracted the indicator', async () => {
        const recheck = stubConnectivity({ offline: true, verdict: false });
        await boot();

        pullPastThreshold();
        await settle();
        pullPastThreshold();
        await settle();

        expect(recheck).toHaveBeenCalledTimes(2);
        expect(reload).not.toHaveBeenCalled();
    });

    it('reloads once when the re-check finds the network again', async () => {
        stubConnectivity({ offline: true, verdict: true });
        await boot();

        pullPastThreshold();
        await settle();

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('treats a re-check that fails as « still offline »', async () => {
        stubConnectivity({ offline: true, verdict: new Error('boom') });
        await boot();

        pullPastThreshold();
        await settle();

        expect(reload).not.toHaveBeenCalled();
        expect(indicator().classList.contains('is-refreshing')).toBe(false);
    });

    it('ignores a pull while the re-check is still in flight', async () => {
        let answer;
        const recheck = vi.fn(() => new Promise((resolve) => { answer = resolve; }));
        window.ScoutMagicConnectivity = { isOfflineConfirmed: () => true, recheck };
        await boot();

        pullPastThreshold();
        pullPastThreshold();
        expect(recheck).toHaveBeenCalledTimes(1);

        answer(true);
        await settle();
        expect(reload).toHaveBeenCalledTimes(1);
    });
});

/**
 * The scenario issue #842 is written around, with the REAL offline-nav.js:
 * iOS keeps an installed app « offline » after the network is back. The
 * page has confirmed it offline (the probe failed), the network returns
 * while navigator.onLine still says false, the visitor pulls — the page
 * must come back online and reload exactly once. And while the network
 * really is away, the pull must not reload onto the offline page.
 */
describe('pull-to-refresh.js with the real offline-nav.js (issue #353 / #842)', () => {
    let probeReachable;

    function setOnline(value) {
        Object.defineProperty(navigator, 'onLine', { configurable: true, value });
    }

    async function bootConfirmedOffline() {
        document.body.insertAdjacentHTML('beforeend', `
            <script type="application/json" id="offline-config-data">${JSON.stringify({ whitelist: [{ path: '/', match: 'exact' }] })}</script>
            <output id="offline-readonly-banner" class="d-none"></output>
            <a href="/finance">Finances</a>
        `);
        probeReachable = false;
        const probe = vi.fn((input, init) => (
            init && init.method === 'HEAD' && input === '/api/version' && probeReachable
                ? Promise.resolve({ ok: true })
                : Promise.reject(new TypeError('Failed to fetch'))
        ));
        global.fetch = window.fetch = probe;
        setOnline(false);

        vi.resetModules();
        await import('../../public/assets/js/display-mode.js');
        await import('../../public/assets/js/offline-nav.js');
        await import('../../public/assets/js/pull-to-refresh.js');
        await settle();

        return probe;
    }

    afterEach(() => {
        // Stops offline-nav.js's heartbeat (see offline-nav.test.js).
        setOnline(true);
        window.dispatchEvent(new Event('online'));
    });

    it('confirmed offline → pull → the probe answers despite navigator.onLine === false → online again, one reload', async () => {
        const probe = await bootConfirmedOffline();
        expect(window.ScoutMagicConnectivity.isOfflineConfirmed()).toBe(true);
        expect(document.getElementById('offline-readonly-banner').classList.contains('d-none')).toBe(false);
        const probesBefore = probe.mock.calls.length;

        probeReachable = true; // the network is back; iOS has not noticed
        pullPastThreshold();
        await settle();

        expect(probe.mock.calls.length).toBe(probesBefore + 1);
        expect(navigator.onLine).toBe(false);
        expect(window.ScoutMagicConnectivity.isOfflineConfirmed()).toBe(false);
        expect(document.getElementById('offline-readonly-banner').classList.contains('d-none')).toBe(true);
        expect(document.querySelector('a[href="/finance"]').classList.contains('offline-link-disabled')).toBe(false);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('confirmed offline → pull → the probe still fails → no reload, the page stays offline', async () => {
        const probe = await bootConfirmedOffline();
        const probesBefore = probe.mock.calls.length;

        pullPastThreshold();
        await settle();

        expect(probe.mock.calls.length).toBe(probesBefore + 1);
        expect(reload).not.toHaveBeenCalled();
        expect(document.getElementById('offline-readonly-banner').classList.contains('d-none')).toBe(false);
        expect(document.querySelector('a[href="/finance"]').classList.contains('offline-link-disabled')).toBe(true);
        expect(indicator().classList.contains('is-refreshing')).toBe(false);
    });
});
