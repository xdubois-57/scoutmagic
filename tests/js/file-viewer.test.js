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

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/file-viewer.js');
}

const isOpen = () => viewer() !== null && !viewer().classList.contains('d-none');

describe('file-viewer', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
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

    it('protects links even on a page with no viewer markup at all', async () => {
        // The net must not depend on the overlay being present: a page
        // that renders no viewer still renders file links.
        document.body.innerHTML = '<a id="bare" href="/files/42">Contrat</a>';
        await load();

        expect(document.getElementById('bare').hasAttribute('download')).toBe(true);
    });
});
