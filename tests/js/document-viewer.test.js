// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/document-viewer.js.
//
// The buttons of the page the installed application gets instead of a
// file (core/View/templates/document_viewer.html.twig, issue #502): which
// one does what, on which platform, and that none of them navigates the
// window to the file.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
<div data-document-viewer>
  <a id="browser" href="/document/b1" target="_blank" rel="noopener" data-document-viewer-browser>Ouvrir dans le navigateur</a>
  <a id="download" href="/document/telecharger/a2" download="Fiche santé.pdf" data-document-viewer-download
     data-file-name="Fiche santé.pdf" data-file-type="application/pdf">Télécharger</a>
  <a id="back" href="/members/7" data-document-viewer-back>Retour</a>
  <p class="d-none" data-document-viewer-status></p>
</div>`;

const navigatorProps = ['userAgent', 'platform', 'maxTouchPoints', 'share', 'canShare', 'standalone'];

function pretendStandalone(on) {
    window.matchMedia = /** @type {any} */ ((query) => ({
        matches: on && query === '(display-mode: standalone)',
        media: query,
        addEventListener() {},
        removeEventListener() {},
    }));
}

function pretendIphone({ share = vi.fn(() => Promise.resolve()), canShare = vi.fn(() => true) } = {}) {
    Object.defineProperty(window.navigator, 'userAgent', { value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)', configurable: true });
    Object.defineProperty(window.navigator, 'share', { value: share, configurable: true });
    Object.defineProperty(window.navigator, 'canShare', { value: canShare, configurable: true });
    return { share, canShare };
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/document-viewer.js');
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

function click(id) {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    document.getElementById(id).dispatchEvent(event);
    return event;
}

const status = () => document.querySelector('[data-document-viewer-status]');

describe('document-viewer', () => {
    beforeEach(() => {
        document.body.innerHTML = PAGE;
        pretendStandalone(true);
        window.open = vi.fn();
        window.fetch = vi.fn(() => Promise.resolve(new Response('%PDF', { status: 200 })));
    });

    afterEach(() => {
        navigatorProps.forEach((name) => {
            delete /** @type {any} */ (window.navigator)[name];
        });
    });

    describe('« Ouvrir dans le navigateur »', () => {
        it('opens the phone\'s browser with the address minted with the page', async () => {
            await load();

            const event = click('browser');

            expect(event.defaultPrevented).toBe(true);
            expect(window.open).toHaveBeenCalledWith(expect.stringMatching(/\/document\/b1$/), '_blank');
            // Nothing asked of the server at the tap: iOS refuses a
            // window.open() that follows a wait.
            expect(window.fetch).not.toHaveBeenCalled();
        });

        // Issue #684: on an iPhone in the installed app, an address of this
        // site is inside the app's scope, so iOS opened it in the app's own
        // window — the raw file, with no way back. Safari's own scheme is
        // what leaves the app.
        it('hands the address to Safari on an iPhone in the installed app', async () => {
            pretendIphone();
            await load();

            click('browser');

            expect(window.open).toHaveBeenCalledWith(
                'x-safari-' + window.location.origin + '/document/b1',
                '_blank'
            );
        });

        it('keeps the plain address in a browser tab on an iPhone', async () => {
            pretendStandalone(false);
            pretendIphone();
            await load();

            click('browser');

            expect(window.open).toHaveBeenCalledWith(window.location.origin + '/document/b1', '_blank');
        });

        it('keeps the plain address in the installed app on Android', async () => {
            Object.defineProperty(window.navigator, 'userAgent', { value: 'Mozilla/5.0 (Linux; Android 15)', configurable: true });
            await load();

            click('browser');

            expect(window.open).toHaveBeenCalledWith(window.location.origin + '/document/b1', '_blank');
        });

        it('never rewrites an address of another site, even on an iPhone', async () => {
            document.body.innerHTML = `<div data-document-viewer>
              <a id="browser" href="https://elsewhere.example/document/b1" data-document-viewer-browser>x</a>
              <output class="d-none" data-document-viewer-status></output></div>`;
            pretendIphone();
            await load();

            click('browser');

            expect(window.open).toHaveBeenCalledWith('https://elsewhere.example/document/b1', '_blank');
        });

        it('is spent after one use, and says why', async () => {
            await load();

            click('browser');
            click('browser');

            expect(window.open).toHaveBeenCalledTimes(1);
            expect(document.getElementById('browser').getAttribute('aria-disabled')).toBe('true');
            expect(status().textContent).toContain('ne sert qu’une fois');
        });
    });

    it('keeps a public address usable: it is not a one-use key', async () => {
        document.body.innerHTML = `<div data-document-viewer>
          <a id="browser" href="/locations/suivi/1/abc/calendrier.ics" data-document-viewer-browser data-document-viewer-reusable>x</a>
          <output class="d-none" data-document-viewer-status></output></div>`;
        await load();

        click('browser');
        click('browser');

        expect(window.open).toHaveBeenCalledTimes(2);
        expect(document.getElementById('browser').hasAttribute('aria-disabled')).toBe(false);
    });

    describe('« Télécharger »', () => {
        it('opens the share sheet on an iPhone, with the file fetched in advance', async () => {
            const { share } = pretendIphone();
            await load();
            await flush();
            await flush();

            expect(window.fetch).toHaveBeenCalledWith(expect.stringMatching(/\/document\/telecharger\/a2$/), { credentials: 'same-origin' });

            const event = click('download');

            expect(event.defaultPrevented).toBe(true);
            expect(share).toHaveBeenCalledTimes(1);
            const shared = share.mock.calls[0][0].files[0];
            expect(shared.name).toBe('Fiche santé.pdf');
            expect(shared.type).toBe('application/pdf');
        });

        it('asks to wait rather than download while the file is still arriving', async () => {
            const { share } = pretendIphone();
            window.fetch = vi.fn(() => new Promise(() => {}));
            await load();

            const event = click('download');

            expect(event.defaultPrevented).toBe(true);
            expect(share).not.toHaveBeenCalled();
            expect(status().textContent).toContain('se prépare');
        });

        it('falls back to the plain download when the file cannot be shared', async () => {
            pretendIphone({ canShare: vi.fn(() => false) });
            await load();
            await flush();
            await flush();

            expect(click('download').defaultPrevented).toBe(false);
        });

        it('is a real download on Android or a computer', async () => {
            Object.defineProperty(window.navigator, 'userAgent', { value: 'Mozilla/5.0 (Linux; Android 15)', configurable: true });
            Object.defineProperty(window.navigator, 'share', { value: vi.fn(), configurable: true });
            Object.defineProperty(window.navigator, 'canShare', { value: vi.fn(() => true), configurable: true });
            await load();

            expect(window.fetch).not.toHaveBeenCalled();
            expect(click('download').defaultPrevented).toBe(false);
        });

        it('is a real download in a browser tab, even on an iPhone', async () => {
            pretendStandalone(false);
            pretendIphone();
            await load();

            expect(window.fetch).not.toHaveBeenCalled();
            expect(click('download').defaultPrevented).toBe(false);
        });
    });

    describe('« Retour »', () => {
        it('goes back in history, which after a form is the form', async () => {
            await load();
            window.history.pushState({}, '', '/document-viewer-test');
            const back = vi.spyOn(window.history, 'back').mockImplementation(() => {});

            const event = click('back');

            expect(event.defaultPrevented).toBe(true);
            expect(back).toHaveBeenCalledTimes(1);
            back.mockRestore();
        });
    });

    it('does nothing on a page that is not the viewer', async () => {
        document.body.innerHTML = '<a id="browser" href="/x" data-document-viewer-browser>x</a>';
        await load();

        expect(click('browser').defaultPrevented).toBe(false);
    });
});
