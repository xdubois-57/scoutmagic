// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/display-mode.js.
//
// The cookie that tells the server this window is the installed
// application (issue #502): Core\File\Held\InstalledAppFileInterceptor
// answers a navigation that would end on a file with a viewer page there,
// and only there.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function pretendStandalone(on) {
    window.matchMedia = /** @type {any} */ ((query) => ({
        matches: on && query === '(display-mode: standalone)',
        media: query,
        addEventListener() {},
        removeEventListener() {},
    }));
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/display-mode.js');
}

function clearCookie() {
    document.cookie = 'sm_display=; path=/; Max-Age=0';
}

describe('display-mode', () => {
    beforeEach(() => {
        clearCookie();
        delete /** @type {any} */ (window.navigator).standalone;
    });

    it('announces the installed application', async () => {
        pretendStandalone(true);
        await load();

        expect(document.cookie).toContain('sm_display=standalone');
    });

    it('recognises the installed application on iOS by navigator.standalone', async () => {
        pretendStandalone(false);
        Object.defineProperty(window.navigator, 'standalone', { value: true, configurable: true });
        await load();

        expect(document.cookie).toContain('sm_display=standalone');
    });

    it('removes a stale announcement in a browser tab', async () => {
        // On a desktop the installed app and the browser share their
        // cookies: left behind, it would turn a tab's download into a
        // viewer page.
        document.cookie = 'sm_display=standalone; path=/';
        pretendStandalone(false);
        await load();

        expect(document.cookie).not.toContain('sm_display');
    });

    // Issue #842: the one answer the page's other scripts read
    // (offline-cache.js, pull-to-refresh.js), instead of copies of it.
    it('exposes the same answer as window.ScoutMagicDisplayMode.isStandalone()', async () => {
        pretendStandalone(false);
        await load();
        expect(window.ScoutMagicDisplayMode.isStandalone()).toBe(false);

        pretendStandalone(true);
        expect(window.ScoutMagicDisplayMode.isStandalone()).toBe(true);

        pretendStandalone(false);
        Object.defineProperty(window.navigator, 'standalone', { value: true, configurable: true });
        expect(window.ScoutMagicDisplayMode.isStandalone()).toBe(true);
    });

    it('writes nothing at all in a browser tab that never had it', async () => {
        pretendStandalone(false);
        await load();

        expect(document.cookie).not.toContain('sm_display');
    });
});
