// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/document-reader.js.
//
// In the installed application a link marked data-document-reader opens
// its document in the shared dialog instead of replacing the form it sits
// in (issue #1431). A browser tab is never touched: target="_blank" opens a
// new tab there.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function pretendStandalone(on, how = 'media') {
    window.matchMedia = /** @type {any} */ ((query) => ({
        matches: on && how === 'media' && query === '(display-mode: standalone)',
        media: query,
        addEventListener() {},
        removeEventListener() {},
    }));
    /** @type {any} */ (window.navigator).standalone = on && how === 'ios' ? true : undefined;
}

const DIALOG = `
<div class="modal fade" id="document-reader" tabindex="-1" aria-labelledby="document-reader-title" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="document-reader-title">Document</h2>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
    <div class="modal-body"><div data-document-reader-body></div></div>
    <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Fermer et revenir</button></div>
  </div></div>
</div>`;

const FORM = `
<form id="request">
  <input id="name" name="name" value="">
  <input type="hidden" name="conditions_version" value="v2">
  <div class="form-check">
    <input class="form-check-input" type="checkbox" id="accept-conditions" name="accept_conditions" required>
    <label class="form-check-label" for="accept-conditions">
      J'accepte les <a id="conditions" href="/locations/chalet/conditions/v2" target="_blank" rel="noopener" data-document-reader="Conditions de location">conditions de location</a>
    </label>
  </div>
  <a id="plain" href="/rgpd" target="_blank" rel="noopener">politique</a>
</form>`;

function page(content) {
    return `<!doctype html><html><head><title>x</title><script>window.__leaked = true;</script></head><body>
      <nav id="nav">Menu</nav><form action="/logout"><button>Sortir</button></form>
      ${content}
    </body></html>`;
}

let shown;
let modalInstance;
let loaded = false;

async function load() {
    if (!loaded) {
        vi.resetModules();
        await import('../../public/assets/js/document-reader.js');
        loaded = true;
    }
}

function click(id, init = {}) {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
    document.getElementById(id).dispatchEvent(event);
    return event;
}

function respond(body, ok = true, status = 200, url = '') {
    window.fetch = vi.fn(() => Promise.resolve({ ok, status, url, text: () => Promise.resolve(body) }));
}

async function settle() {
    for (let i = 0; i < 5; i++) {
        await Promise.resolve();
    }
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function readerBody() {
    return document.querySelector('[data-document-reader-body]');
}

describe('document-reader', () => {
    beforeEach(async () => {
        delete /** @type {any} */ (window).ScoutMagicDisplayMode;
        document.body.innerHTML = FORM + DIALOG;
        shown = 0;
        modalInstance = { show: vi.fn(() => { shown++; }) };
        /** @type {any} */ (globalThis).bootstrap = { Modal: { getOrCreateInstance: vi.fn(() => modalInstance) } };
        await load();
    });

    it('leaves a browser tab alone: target="_blank" opens a new tab', () => {
        pretendStandalone(false);
        respond(page('<div data-document-reader-content>Texte</div>'));

        const event = click('conditions');

        expect(event.defaultPrevented).toBe(false);
        expect(window.fetch).not.toHaveBeenCalled();
        expect(shown).toBe(0);
    });

    it('opens the exact linked version in the dialog from the installed app', async () => {
        pretendStandalone(true);
        respond(page('<div data-document-reader-content data-document-reader-title="Conditions de location — Chalet"><p>Version 2</p></div>'));

        const event = click('conditions');
        await settle();

        expect(event.defaultPrevented).toBe(true);
        expect(window.fetch).toHaveBeenCalledWith('http://localhost:3000/locations/chalet/conditions/v2', expect.objectContaining({ credentials: 'same-origin' }));
        expect(shown).toBe(1);
        expect(readerBody().textContent).toContain('Version 2');
        expect(document.getElementById('document-reader-title').textContent).toBe('Conditions de location — Chalet');
    });

    it('recognises the iOS installed app through navigator.standalone', async () => {
        pretendStandalone(true, 'ios');
        respond(page('<div data-document-reader-content>RGPD</div>'));

        const event = click('conditions');
        await settle();

        expect(event.defaultPrevented).toBe(true);
        expect(readerBody().textContent).toContain('RGPD');
    });

    it('only intercepts links that are marked as documents to read', () => {
        pretendStandalone(true);
        respond(page('<div data-document-reader-content>x</div>'));

        const event = click('plain');

        expect(event.defaultPrevented).toBe(false);
        expect(window.fetch).not.toHaveBeenCalled();
    });

    it('leaves a modified click to the browser', () => {
        pretendStandalone(true);
        respond(page('<div data-document-reader-content>x</div>'));

        expect(click('conditions', { metaKey: true }).defaultPrevented).toBe(false);
        expect(click('conditions', { button: 1 }).defaultPrevented).toBe(false);
    });

    it('never ticks the consent box, and keeps what was typed', async () => {
        pretendStandalone(true);
        respond(page('<div data-document-reader-content>x</div>'));
        const name = /** @type {HTMLInputElement} */ (document.getElementById('name'));
        name.value = 'Famille Dupont';
        const box = /** @type {HTMLInputElement} */ (document.getElementById('accept-conditions'));

        click('conditions');
        await settle();
        document.getElementById('document-reader').dispatchEvent(new Event('hidden.bs.modal', { bubbles: true }));

        expect(box.checked).toBe(false);
        expect(name.value).toBe('Famille Dupont');
    });

    it('shows only the marked content, without anything that runs, loads or submits', async () => {
        pretendStandalone(true);
        respond(page(`<div data-document-reader-content>
            <h2 id="rgpd-title">Vos droits</h2>
            <p onclick="window.__leaked = true" style="position:fixed">Texte</p>
            <script>window.__leaked = true;</script>
            <img src="x" onerror="window.__leaked = true">
            <a href="javascript:window.__leaked = true">piège</a>
            <a href="/cookies">cookies</a>
            <form action="/x"><input name="y"></form>
            <iframe src="/x"></iframe>
        </div>`));

        click('conditions');
        await settle();
        const body = readerBody();

        expect(body.textContent).toContain('Vos droits');
        expect(body.textContent).not.toContain('Menu');
        expect(body.textContent).not.toContain('Sortir');
        expect(body.querySelector('script, form, input, iframe')).toBeNull();
        expect(body.querySelector('[onclick], [onerror], [style], [id]')).toBeNull();
        expect(body.querySelector('a[href^="javascript"]')).toBeNull();
        const cookies = body.querySelector('a[href="/cookies"]');
        expect(cookies.getAttribute('target')).toBe('_blank');
        expect(cookies.getAttribute('rel')).toBe('noopener');
        expect(/** @type {any} */ (window).__leaked).toBeUndefined();
    });

    it('keeps only allowed schemes, however the dangerous one is disguised', async () => {
        // The bypasses of a scheme blocklist (#1444 review): an embedded tab,
        // a leading control character, vbscript:, a data: page, and SVG's
        // own xlink:href, which no href check sees.
        pretendStandalone(true);
        respond(page(`<div data-document-reader-content>
            <a id="tab" href="java\tscript:window.__leaked = true">tab</a>
            <a id="ctl" href="\u0001javascript:window.__leaked = true">ctl</a>
            <a id="vb" href="vbscript:msgbox(1)">vb</a>
            <a id="data" href="data:text/html,<script>window.__leaked = true</script>">data</a>
            <svg><a xlink:href="javascript:window.__leaked = true"><text>svg</text></a></svg>
            <img id="img" src="data:image/png;base64,iVBORw0KGgo=" srcset="javascript:alert(1) 1x" alt="">
            <a id="mail" href="mailto:contact@example.test">mail</a>
            <a id="rel" href="/cookies">rel</a>
        </div>`));

        click('conditions');
        await settle();
        const body = readerBody();
        const hrefs = Array.from(body.querySelectorAll('a')).map((a) => a.getAttribute('href'));

        expect(hrefs).toEqual([null, null, null, null, 'mailto:contact@example.test', '/cookies']);
        expect(body.querySelector('svg, math')).toBeNull();
        expect(body.innerHTML).not.toMatch(/javascript|vbscript|xlink/i);
        expect(body.querySelector('img').getAttribute('src')).toBe('data:image/png;base64,iVBORw0KGgo=');
        expect(body.querySelector('img').hasAttribute('srcset')).toBe(false);
    });

    it('resolves an address relative to the document against the document, not the form', async () => {
        pretendStandalone(true);
        respond(
            page(`<div data-document-reader-content>
                <img src="scan.png" alt="">
                <a href="annexe.pdf">annexe</a>
                <a href="/cookies">cookies</a>
                <a href="#article-3">article 3</a>
            </div>`),
            true,
            200,
            'http://localhost:3000/locations/chalet/conditions/v2'
        );

        click('conditions');
        await settle();
        const body = readerBody();

        expect(body.querySelector('img').getAttribute('src')).toBe('http://localhost:3000/locations/chalet/conditions/scan.png');
        expect(body.querySelector('a[href$="annexe.pdf"]').getAttribute('href')).toBe('http://localhost:3000/locations/chalet/conditions/annexe.pdf');
        expect(body.querySelector('a[href="/cookies"]')).not.toBeNull();
        // An anchor names a place in the document, not in the form behind
        // the dialog: tapped, it opens the document at that place.
        const anchor = /** @type {HTMLAnchorElement} */ (body.querySelector('a[href$="#article-3"]'));
        expect(anchor.getAttribute('href')).toBe('http://localhost:3000/locations/chalet/conditions/v2#article-3');
        window.open = vi.fn();
        const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
        anchor.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
        expect(window.open).toHaveBeenCalledWith('http://localhost:3000/locations/chalet/conditions/v2#article-3', '_blank', 'noopener');
    });

    it('says so in the dialog when the document cannot be loaded, and can retry', async () => {
        pretendStandalone(true);
        window.fetch = vi.fn(() => Promise.reject(new TypeError('offline')));

        click('conditions');
        await settle();

        expect(readerBody().querySelector('[role="alert"]').textContent).toContain('Votre saisie est conservée');
        const separate = readerBody().querySelector('a');
        expect(separate.getAttribute('href')).toBe('http://localhost:3000/locations/chalet/conditions/v2');
        expect(separate.getAttribute('target')).toBe('_blank');

        respond(page('<div data-document-reader-content>Revenu</div>'));
        /** @type {HTMLButtonElement} */ (readerBody().querySelector('[data-document-reader-retry]')).click();
        await settle();

        expect(readerBody().textContent).toContain('Revenu');
    });

    it('sends a same-site link inside the document to a window of its own, never this one', async () => {
        // The notice's « page de préférences cookies »: target="_blank" is
        // ignored by the installed window for this site's own pages, so a
        // plain click would replace the form behind the dialog.
        pretendStandalone(true);
        window.open = vi.fn();
        respond(page('<div data-document-reader-content><p>Voir la <a href="/cookies">page de préférences cookies</a>.</p></div>'));

        click('conditions');
        await settle();
        const inner = /** @type {HTMLAnchorElement} */ (readerBody().querySelector('a[href="/cookies"]'));
        const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
        inner.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(window.open).toHaveBeenCalledWith('http://localhost:3000/cookies', '_blank', 'noopener');
    });

    it('opens « Ouvrir à part » in a window of its own too', async () => {
        pretendStandalone(true);
        window.open = vi.fn();
        window.fetch = vi.fn(() => Promise.reject(new TypeError('offline')));

        click('conditions');
        await settle();
        const separate = /** @type {HTMLAnchorElement} */ (readerBody().querySelector('a'));
        const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
        separate.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(window.open).toHaveBeenCalledWith('http://localhost:3000/locations/chalet/conditions/v2', '_blank', 'noopener');
    });

    it('leaves a link to another site in the document to installed-app-links.js', async () => {
        pretendStandalone(true);
        window.open = vi.fn();
        respond(page('<div data-document-reader-content><a href="https://www.lesscouts.be/fr">Les Scouts</a></div>'));

        click('conditions');
        await settle();
        const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
        readerBody().querySelector('a').dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(window.open).not.toHaveBeenCalled();
    });

    it('reads the offline copy when the network does not answer', async () => {
        // A fetch() is never a navigation, so the service worker does not
        // fall back for it: the reader asks the caches itself (#1444 review).
        pretendStandalone(true);
        window.fetch = vi.fn(() => Promise.reject(new TypeError('offline')));
        const match = vi.fn(() => Promise.resolve({ text: () => Promise.resolve(page('<div data-document-reader-content>Hors ligne</div>')) }));
        /** @type {any} */ (window).caches = { match };

        click('conditions');
        await settle();

        expect(match).toHaveBeenCalledWith('http://localhost:3000/locations/chalet/conditions/v2');
        expect(readerBody().textContent).toContain('Hors ligne');
        delete /** @type {any} */ (window).caches;
    });

    it('does not read an offline copy over an answer the server gave', async () => {
        pretendStandalone(true);
        respond(page('<p>Introuvable</p>'), false, 404);
        const match = vi.fn();
        /** @type {any} */ (window).caches = { match };

        click('conditions');
        await settle();

        expect(match).not.toHaveBeenCalled();
        expect(readerBody().querySelector('[role="alert"]')).not.toBeNull();
        delete /** @type {any} */ (window).caches;
    });

    it('gives up on a stalled connection instead of loading for ever', async () => {
        vi.useFakeTimers();
        pretendStandalone(true);
        window.fetch = vi.fn((_url, init) => new Promise((_resolve, reject) => {
            init.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
        }));

        click('conditions');
        await vi.advanceTimersByTimeAsync(8000);
        vi.useRealTimers();
        await settle();

        expect(readerBody().querySelector('[role="alert"]')).not.toBeNull();
        expect(readerBody().textContent).not.toContain('Chargement');
    });

    it('hands a link of this site to Safari on an iPhone, where window.open() would keep it in the app', async () => {
        pretendStandalone(true);
        const userAgent = Object.getOwnPropertyDescriptor(window.navigator, 'userAgent');
        Object.defineProperty(window.navigator, 'userAgent', { value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', configurable: true });
        window.open = vi.fn();
        respond(page('<div data-document-reader-content><a href="/cookies">cookies</a></div>'));

        click('conditions');
        await settle();
        readerBody().querySelector('a').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));

        expect(window.open).toHaveBeenCalledWith('x-safari-http://localhost:3000/cookies', '_blank', 'noopener');
        if (userAgent) {
            Object.defineProperty(window.navigator, 'userAgent', userAgent);
        } else {
            delete /** @type {any} */ (window.navigator).userAgent;
        }
    });

    it('treats an error page or a page without marked content as a failure', async () => {
        pretendStandalone(true);
        respond(page('<p>Introuvable</p>'), false, 404);
        click('conditions');
        await settle();
        expect(readerBody().querySelector('[role="alert"]')).not.toBeNull();

        respond(page('<main>Page de connexion</main>'));
        click('conditions');
        await settle();
        expect(readerBody().querySelector('[role="alert"]')).not.toBeNull();
        expect(readerBody().textContent).not.toContain('Page de connexion');
    });

    it('focuses the title on open and gives the focus back to the link on close', async () => {
        pretendStandalone(true);
        respond(page('<div data-document-reader-content>x</div>'));
        const dialog = document.getElementById('document-reader');

        click('conditions');
        await settle();
        dialog.dispatchEvent(new Event('shown.bs.modal', { bubbles: true }));
        expect(document.activeElement).toBe(document.getElementById('document-reader-title'));

        dialog.dispatchEvent(new Event('hidden.bs.modal', { bubbles: true }));
        expect(document.activeElement).toBe(document.getElementById('conditions'));
    });

    it('lets the link navigate when the dialog is not on the page', () => {
        pretendStandalone(true);
        document.getElementById('document-reader').remove();
        respond(page('<div data-document-reader-content>x</div>'));

        expect(click('conditions').defaultPrevented).toBe(false);
    });

    it('leaves a click another script already answered', () => {
        pretendStandalone(true);
        respond(page('<div data-document-reader-content>x</div>'));
        const link = document.getElementById('conditions');
        // offline-nav.js answers in the capture phase too, ahead of this one.
        const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
        event.preventDefault();
        link.dispatchEvent(event);

        expect(window.fetch).not.toHaveBeenCalled();
    });
});
