// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/file-viewer.js.
//
// What it is about: in the installed application the manifest declares
// `display: standalone` with `scope: /`, so the window has no address bar
// and no back button, and every URL of this site stays inside it. A link
// to a file is a NAVIGATION — the window leaves the application and lands
// on the file, and on iOS nothing is left to press. The user force-quits
// the app. That is the report this file answers.
import { beforeEach, describe, expect, it, vi } from 'vitest';

// The overlay is NOT markup any page renders: the script builds it on the
// first click that needs one. A page therefore starts with nothing but its
// own links, which is what these tests set up.
function buildPage(inner) {
    document.body.innerHTML = inner;
}

const viewer = () => document.getElementById('file-viewer');
const el = (id) => document.getElementById(id);

/**
 * The installed application, as the browser reports it — the same idiom
 * assets/js/offline-cache.js already uses.
 */
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
    await import('../../public/assets/js/file-viewer.js');
}

const isOpen = () => viewer() !== null && !viewer().classList.contains('d-none');

describe('file-viewer', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        pretendStandalone(false);
    });

    describe('the safety net', () => {
        it('gives an undecorated file link download and _blank', async () => {
            // A single forgotten attribute, in any template, strands the
            // installed app. The net catches them rather than trusting ten
            // templates to remember.
            buildPage('<a id="bare" href="/files/42">Contrat</a>');
            await load();

            const link = document.getElementById('bare');
            expect(link.hasAttribute('download')).toBe(true);
            expect(link.getAttribute('target')).toBe('_blank');
            expect(link.getAttribute('rel')).toBe('noopener');
        });

        it('leaves a link that already names its download alone', async () => {
            buildPage('<a id="named" href="/files/42" download="contrat.pdf">Contrat</a>');
            await load();

            expect(document.getElementById('named').getAttribute('download')).toBe('contrat.pdf');
        });

        it('does not touch a viewer trigger, whose click never navigates', async () => {
            buildPage('<a id="t" href="/files/7" data-file-viewer="/files/7">Photo</a>');
            await load();

            expect(document.getElementById('t').hasAttribute('download')).toBe(false);
        });

        it('leaves links that are not files alone', async () => {
            buildPage('<a id="page" href="/chefs/camps">Camps</a>');
            await load();

            expect(document.getElementById('page').hasAttribute('download')).toBe(false);
        });

        it('covers a link inserted after it ran', async () => {
            // A modal opened later, or a list another script rendered:
            // covering only what was in the document is covering half.
            buildPage('');
            await load();

            const added = document.createElement('a');
            added.id = 'late';
            added.href = '/files/99';
            document.body.appendChild(added);
            await new Promise((resolve) => setTimeout(resolve, 0));

            expect(document.getElementById('late').hasAttribute('download')).toBe(true);
        });
    });

    describe('the in-app viewer', () => {
        beforeEach(async () => {
            buildPage(`
                <a id="photo" href="/files/7" data-file-viewer="/files/7"
                   data-file-name="recu.png" data-file-image="1">Reçu</a>
                <a id="pdf" href="/files/8" data-file-viewer="/files/8"
                   data-file-name="contrat.pdf" data-file-image="0">Contrat</a>
            `);
            await load();
        });

        it('shows an image here instead of navigating to it', async () => {
            const event = new MouseEvent('click', { bubbles: true, cancelable: true });
            document.getElementById('photo').dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
            expect(isOpen()).toBe(true);
            expect(viewer().querySelector('img').src).toContain('/files/7');
            expect(viewer().querySelector('p').textContent).toBe('recu.png');
        });

        it('says so rather than showing an empty frame for what it cannot render', () => {
            // An iframe of a PDF does not render reliably in a standalone
            // iOS window, and a viewer showing nothing is worse than a
            // plain button.
            document.getElementById('pdf').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

            expect(viewer().querySelector('img').classList.contains('d-none')).toBe(true);
            expect(viewer().querySelector('.file-viewer__content.text-center').classList.contains('d-none')).toBe(false);
            expect(viewer().querySelector('.file-viewer__content.text-center p').textContent).toContain('contrat.pdf');
        });

        it('always offers to save the file it is showing', () => {
            document.getElementById('photo').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

            const download = viewer().querySelector('a');
            expect(download.getAttribute('href')).toBe('/files/7');
            expect(download.getAttribute('download')).toBe('recu.png');
        });

        it('closes on the button, and empties what it was holding', () => {
            document.getElementById('photo').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
            viewer().querySelector('button').click();

            expect(isOpen()).toBe(false);
            // A large photo left in the DOM is a large photo held in
            // memory, and the next open would flash the previous one.
            expect(viewer().querySelector('img').getAttribute('src')).toBe('');
        });

        it('closes on Escape', () => {
            document.getElementById('photo').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

            expect(isOpen()).toBe(false);
        });

        it('closes on the backdrop but not on the image', () => {
            document.getElementById('photo').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
            viewer().querySelector('img').dispatchEvent(new MouseEvent('click', { bubbles: true }));
            expect(isOpen()).toBe(true);

            viewer().dispatchEvent(new MouseEvent('click', { bubbles: true }));
            expect(isOpen()).toBe(false);
        });

        it('stops the page behind from scrolling under the overlay', () => {
            document.getElementById('photo').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
            expect(document.body.style.overflow).toBe('hidden');

            viewer().querySelector('button').click();
            expect(document.body.style.overflow).toBe('');
        });
    });

    /**
     * The overlay is built on first use, never rendered on every page.
     *
     * A test caught why that matters: the public rental tracking page
     * asserts it offers NO download at all — an external renter downloads
     * nothing from this site — and an overlay carrying a `download`
     * attribute made that page fail its own rule while offering nothing.
     * Dead markup is never only dead.
     */
    it('adds nothing to a page until a file is actually opened', async () => {
        buildPage('<a id="photo" href="/files/7" data-file-viewer="/files/7" data-file-image="1">Reçu</a>');
        await load();

        expect(viewer()).toBeNull();
        expect(document.body.innerHTML.toLowerCase()).not.toContain('download');

        document.getElementById('photo').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(viewer()).not.toBeNull();
    });

    it('builds the overlay once, however many files are opened', async () => {
        buildPage(`
            <a id="a" href="/files/1" data-file-viewer="/files/1" data-file-image="1">Un</a>
            <a id="b" href="/files/2" data-file-viewer="/files/2" data-file-image="1">Deux</a>
        `);
        await load();

        document.getElementById('a').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        viewer().querySelector('button').click();
        document.getElementById('b').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(document.querySelectorAll('#file-viewer')).toHaveLength(1);
        expect(viewer().querySelector('img').src).toContain('/files/2');
    });

    describe('the installed application, where there is no back button', () => {
        beforeEach(() => {
            pretendStandalone(true);
        });

        /**
         * The report that produced this section, after the first fix.
         *
         * `download` and `target="_blank"` are enough on Android and on
         * the desktop. On iOS both are handled INSIDE the standalone
         * window: what came up was Safari's own download screen —
         * « image006.jpg, Image JPEG - 2 ko, Ouvrir dans Aperçu » — with
         * no back button either. The attribute meant to keep the page
         * still is what took it away.
         *
         * So there, the click must not navigate at all.
         */
        it('intercepts a plain file link instead of letting it navigate', async () => {
            buildPage('<a id="bare" href="/files/42">image006.jpg</a>');
            await load();

            const event = new MouseEvent('click', { bubbles: true, cancelable: true });
            document.getElementById('bare').dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
            expect(isOpen()).toBe(true);
            expect(viewer().querySelector('img').src).toContain('/files/42');
        });

        it('does not put download on a link there, since that is the trap', async () => {
            buildPage('<a id="bare" href="/files/42">image006.jpg</a>');
            await load();

            expect(document.getElementById('bare').hasAttribute('download')).toBe(false);
        });

        it('names the file from whatever the link could tell it', async () => {
            buildPage('<a id="bare" href="/files/42">image006.jpg</a>');
            await load();
            document.getElementById('bare').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

            expect(viewer().querySelector('p').textContent).toBe('image006.jpg');
        });

        it('falls back when the browser cannot draw the file', async () => {
            // The type is discovered by trying rather than declared: the
            // links it now intercepts carry nothing but an id, and a HEAD
            // request per click would cost a round trip to learn what one
            // failed image load says for free.
            buildPage('<a id="bare" href="/files/42" download="contrat.pdf">Contrat</a>');
            await load();
            document.getElementById('bare').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

            const img = viewer().querySelector('img');
            img.onerror(new Event('error'));

            expect(img.classList.contains('d-none')).toBe(true);
            expect(viewer().querySelector('.file-viewer__content.text-center p').textContent)
                .toContain('contrat.pdf');
        });

        /**
         * The viewer must not strand the app it exists to protect.
         *
         * A `download` link inside it would land on the very screen that
         * was reported. `window.open()` from a standalone web app
         * launches the BROWSER — a separate application — so ScoutMagic
         * stays put, one swipe away.
         */
        it('opens the browser rather than downloading in place', async () => {
            buildPage('<a id="bare" href="/files/42">image006.jpg</a>');
            await load();
            document.getElementById('bare').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

            const action = viewer().querySelector('a');
            expect(action.hasAttribute('download')).toBe(false);
            expect(action.textContent).toContain('navigateur');

            const opened = vi.fn();
            window.open = opened;
            action.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

            // `href` resolves to an absolute URL, which is what
            // window.open should be handed anyway.
            expect(opened).toHaveBeenCalledTimes(1);
            expect(opened.mock.calls[0][0]).toContain('/files/42');
            expect(opened.mock.calls[0][1]).toBe('_blank');
        });

        /**
         * The gallery's own « Télécharger en haute qualité » wears
         * `download`, and so does every link the browser-tab net
         * decorates. In the installed app that attribute is precisely
         * what strands the window, so a link wearing it is caught too.
         */
        it('catches any same-origin link already carrying download', async () => {
            buildPage('<a id="hq" href="/gallery/media/12/download" download>Télécharger</a>');
            await load();

            const event = new MouseEvent('click', { bubbles: true, cancelable: true });
            document.getElementById('hq').dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
            expect(isOpen()).toBe(true);
        });

        it('never intercepts a link to somebody else\'s site', async () => {
            // `download` is ignored cross-origin by the browser anyway,
            // and deciding where an external link goes is not this
            // script's business.
            buildPage('<a id="ext" href="https://example.org/x.pdf" download>Ailleurs</a>');
            await load();

            const event = new MouseEvent('click', { bubbles: true, cancelable: true });
            document.getElementById('ext').dispatchEvent(event);

            expect(event.defaultPrevented).toBe(false);
            expect(isOpen()).toBe(false);
        });

        it('still leaves links that are not files alone', async () => {
            buildPage('<a id="page" href="/chefs/camps">Camps</a>');
            await load();

            const event = new MouseEvent('click', { bubbles: true, cancelable: true });
            document.getElementById('page').dispatchEvent(event);

            expect(event.defaultPrevented).toBe(false);
            expect(isOpen()).toBe(false);
        });
    });

    describe('the URL a trigger hands the viewer', () => {
        // CodeQL js/xss-through-dom, two HIGH alerts on this file, and
        // they were reachable. `download.href` is navigable — the
        // standalone branch calls win.open() on it — and `image.src`
        // likewise. The plain-link path rejected anything carrying a
        // scheme; the `data-file-viewer` path passed its attribute
        // straight through, so markup rendered from user content could
        // put `javascript:` into a sink.
        it('refuses a javascript: URL from data-file-viewer', async () => {
            buildPage('<a id="x" href="/files/7" data-file-viewer="javascript:alert(1)">Piège</a>');
            await load();

            document.getElementById('x').dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true })
            );

            expect(isOpen()).toBe(false);
        });

        it('refuses a data: URL too', async () => {
            buildPage('<a id="x" href="/files/7" data-file-viewer="data:text/html,<script>x</script>">Piège</a>');
            await load();

            document.getElementById('x').dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true })
            );

            expect(isOpen()).toBe(false);
        });

        it('still opens on the ordinary same-origin path', async () => {
            // The guard must not cost the feature: a relative path
            // resolves against the page and inherits its http(s) scheme.
            buildPage('<a id="ok" href="/files/7" data-file-viewer="/files/7" data-file-name="Photo">Photo</a>');
            await load();

            document.getElementById('ok').dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true })
            );

            expect(isOpen()).toBe(true);
        });
    });

    it('protects links even on a page with no viewer markup at all', async () => {
        // The net must not depend on the overlay being present: a page
        // that renders no viewer still renders file links.
        document.body.innerHTML = '<a id="bare" href="/files/42">Contrat</a>';
        await load();

        expect(document.getElementById('bare').hasAttribute('download')).toBe(true);
    });
});
