// Isolated JavaScript unit test — jsdom only, no server. Exercises the real
// public/assets/js/navigation-feedback.js: the offcanvas closing on a menu
// tap, the progress bar shown a moment after a navigation link is
// followed, and the bfcache restore that puts every open overlay away.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

let trackedListeners = [];
function trackListeners(target) {
    const original = target.addEventListener.bind(target);
    target.addEventListener = (type, listener, options) => {
        trackedListeners.push({ target, type, listener, options });
        return original(type, listener, options);
    };
}

let offcanvasInstance;
let modalInstance;

function page(html) {
    document.body.innerHTML = `<div id="navigation-progress" hidden></div>${html}`;
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/navigation-feedback.js');
}

// jsdom cannot navigate; a listener registered AFTER the script's own (so
// the script still sees an uncancelled click) stops the default action.
let navigationStopper = null;
function click(el, init = {}) {
    if (navigationStopper === null) {
        navigationStopper = (e) => { e.cancelledBeforeTheStopper = e.defaultPrevented; e.preventDefault(); };
        document.addEventListener('click', navigationStopper);
    }
    const evt = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
    el.dispatchEvent(evt);
    return evt;
}

beforeEach(() => {
    vi.useFakeTimers();
    trackedListeners = [];
    trackListeners(document);
    trackListeners(window);
    offcanvasInstance = { hide: vi.fn() };
    modalInstance = { hide: vi.fn() };
    global.bootstrap = {
        Offcanvas: { getInstance: vi.fn(() => offcanvasInstance) },
        Modal: { getInstance: vi.fn(() => modalInstance) },
    };
});

afterEach(() => {
    trackedListeners.forEach(({ target, type, listener, options }) => target.removeEventListener(type, listener, options));
    navigationStopper = null;
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('navigation-feedback.js: the mobile menu closes on a tap', () => {
    it('hides the offcanvas a followed link sits in, without cancelling the navigation', async () => {
        page('<div class="offcanvas show" id="navOffcanvas"><a id="l" href="/calendar">Calendrier</a></div>');
        await boot();

        const evt = click(document.getElementById('l'));

        expect(evt.cancelledBeforeTheStopper).toBe(false);
        expect(global.bootstrap.Offcanvas.getInstance).toHaveBeenCalledWith(document.getElementById('navOffcanvas'));
        expect(offcanvasInstance.hide).toHaveBeenCalled();
    });

    it('leaves a link outside any offcanvas alone', async () => {
        page('<a id="l" href="/calendar">Calendrier</a>');
        await boot();

        click(document.getElementById('l'));

        expect(offcanvasInstance.hide).not.toHaveBeenCalled();
    });

    it('does nothing for a click another script already cancelled (offline-nav.js refusing a page)', async () => {
        page('<div class="offcanvas show"><a id="l" href="/finance">Finances</a></div>');
        await boot();
        document.addEventListener('click', (e) => e.preventDefault(), true);

        click(document.getElementById('l'));
        vi.advanceTimersByTime(500);

        expect(offcanvasInstance.hide).not.toHaveBeenCalled();
        expect(document.getElementById('navigation-progress').hidden).toBe(true);
    });
});

describe('navigation-feedback.js: the progress bar', () => {
    it('appears a moment after a same-origin navigation link is followed, never at once', async () => {
        page('<a id="l" href="/calendar">Calendrier</a>');
        await boot();
        const bar = document.getElementById('navigation-progress');

        click(document.getElementById('l'));
        expect(bar.hidden).toBe(true);

        vi.advanceTimersByTime(200);
        expect(bar.hidden).toBe(false);
        expect(bar.classList.contains('is-active')).toBe(true);
    });

    it.each([
        ['a modified click (new tab)', { ctrlKey: true }, '/calendar', {}],
        ['a middle click', { button: 1 }, '/calendar', {}],
        ['a link opening elsewhere', {}, '/calendar', { target: '_blank' }],
        ['a download', {}, '/files/12', { download: '' }],
        ['a viewer trigger', {}, '/files/12', { 'data-file-viewer': '' }],
        ['a fragment on the same page', {}, '#top', {}],
        ['another site', {}, 'https://example.org/', {}],
    ])('stays hidden for %s', async (_label, init, href, attrs) => {
        page('<a id="l">x</a>');
        const link = document.getElementById('l');
        link.setAttribute('href', href);
        Object.entries(attrs).forEach(([k, v]) => link.setAttribute(k, v));
        await boot();

        click(link, init);
        vi.advanceTimersByTime(500);

        expect(document.getElementById('navigation-progress').hidden).toBe(true);
    });

    it('disappears the instant a page is shown again', async () => {
        page('<a id="l" href="/calendar">Calendrier</a>');
        await boot();
        const bar = document.getElementById('navigation-progress');
        click(document.getElementById('l'));
        vi.advanceTimersByTime(200);
        expect(bar.hidden).toBe(false);

        window.dispatchEvent(new Event('pageshow'));

        expect(bar.hidden).toBe(true);
        expect(bar.classList.contains('is-active')).toBe(false);
    });

    it('is cancelled by pagehide before it ever showed', async () => {
        page('<a id="l" href="/calendar">Calendrier</a>');
        await boot();
        click(document.getElementById('l'));

        window.dispatchEvent(new Event('pagehide'));
        vi.advanceTimersByTime(500);

        expect(document.getElementById('navigation-progress').hidden).toBe(true);
    });

    it('survives a page without the bar element', async () => {
        document.body.innerHTML = '<a id="l" href="/calendar">Calendrier</a>';
        await boot();

        click(document.getElementById('l'));
        vi.advanceTimersByTime(500);
        window.dispatchEvent(new Event('pageshow'));
    });
});

describe('navigation-feedback.js: a page restored from the back/forward cache', () => {
    function pageshow(persisted) {
        const evt = new Event('pageshow');
        Object.defineProperty(evt, 'persisted', { value: persisted });
        window.dispatchEvent(evt);
    }

    it('puts away the drawer, the modal, their backdrops and the scroll lock', async () => {
        page(`
            <div class="offcanvas show" id="navOffcanvas"></div>
            <div class="offcanvas-backdrop show"></div>
            <div class="modal show" id="m"></div>
            <div class="modal-backdrop show"></div>`);
        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';
        await boot();

        pageshow(true);

        expect(offcanvasInstance.hide).toHaveBeenCalled();
        expect(modalInstance.hide).toHaveBeenCalled();
        expect(document.querySelector('.offcanvas-backdrop')).toBeNull();
        expect(document.querySelector('.modal-backdrop')).toBeNull();
        expect(document.body.classList.contains('modal-open')).toBe(false);
        expect(document.body.style.overflow).toBe('');
    });

    it('touches nothing on an ordinary page load', async () => {
        page('<div class="offcanvas show" id="navOffcanvas"></div><div class="offcanvas-backdrop show"></div>');
        await boot();

        pageshow(false);

        expect(offcanvasInstance.hide).not.toHaveBeenCalled();
        expect(document.querySelector('.offcanvas-backdrop')).not.toBeNull();
    });
});
