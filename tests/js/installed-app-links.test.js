// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/installed-app-links.js.
//
// In the installed application a link to another site — written content
// included — opens in the phone's browser rather than inside the window,
// which has no way back (issue #502). A browser tab is never touched.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function pretendStandalone(on) {
    window.matchMedia = /** @type {any} */ ((query) => ({
        matches: on && query === '(display-mode: standalone)',
        media: query,
        addEventListener() {},
        removeEventListener() {},
    }));
}

let loaded = false;

async function load() {
    // One listener for the whole file: the script is loaded once, as a
    // page loads it once, and each test changes only the display mode.
    if (!loaded) {
        vi.resetModules();
        await import('../../public/assets/js/installed-app-links.js');
        loaded = true;
    }
}

function click(id, init = {}) {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
    document.getElementById(id).dispatchEvent(event);
    return event;
}

describe('installed-app-links', () => {
    beforeEach(async () => {
        await load();
        window.open = vi.fn();
    });

    it('opens a link to another site in the browser from the installed app', () => {
        pretendStandalone(true);
        document.body.innerHTML = '<article><p><a id="ext" href="https://www.lesscouts.be/fr">Les Scouts</a></p></article>';

        const event = click('ext');

        expect(event.defaultPrevented).toBe(true);
        expect(window.open).toHaveBeenCalledWith('https://www.lesscouts.be/fr', '_blank', 'noopener');
    });

    it('catches a link written with target _blank too', () => {
        // iOS handles target="_blank" inside the installed window anyway.
        pretendStandalone(true);
        document.body.innerHTML = '<a id="ext" href="https://example.org/" target="_blank"><span id="inner">x</span></a>';

        const event = click('inner');

        expect(event.defaultPrevented).toBe(true);
        expect(window.open).toHaveBeenCalledTimes(1);
    });

    it('leaves this site\'s own links to the server', () => {
        pretendStandalone(true);
        document.body.innerHTML = '<a id="own" href="/calendar">Agenda</a>'
            + `<a id="abs" href="${window.location.origin}/news">Actus</a>`;

        expect(click('own').defaultPrevented).toBe(false);
        expect(click('abs').defaultPrevented).toBe(false);
        expect(window.open).not.toHaveBeenCalled();
    });

    it('leaves mailto and tel links alone, which already leave for another app', () => {
        pretendStandalone(true);
        document.body.innerHTML = '<a id="mail" href="mailto:a@b.be">m</a><a id="tel" href="tel:+32">t</a>';

        expect(click('mail').defaultPrevented).toBe(false);
        expect(click('tel').defaultPrevented).toBe(false);
        expect(window.open).not.toHaveBeenCalled();
    });

    it('never touches a browser tab, which has a back button', () => {
        pretendStandalone(false);
        document.body.innerHTML = '<a id="ext" href="https://example.org/">x</a>';

        expect(click('ext').defaultPrevented).toBe(false);
        expect(window.open).not.toHaveBeenCalled();
    });

    it('leaves a click another script already handled, or a modified one', () => {
        pretendStandalone(true);
        document.body.innerHTML = '<a id="ext" href="https://example.org/">x</a>';
        const handled = (event) => event.preventDefault();
        document.getElementById('ext').addEventListener('click', handled);

        click('ext');
        document.getElementById('ext').removeEventListener('click', handled);
        click('ext', { metaKey: true });

        expect(window.open).not.toHaveBeenCalled();
    });
});
