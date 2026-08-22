// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch() is mocked below. Exercises the REAL
// implementation in public/assets/js/gallery.js (imported below, never
// reimplemented here). That file is a plain IIFE that reads the DOM at
// import time rather than waiting for DOMContentLoaded, so every test builds
// its DOM first and then imports the module through a reset registry.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadGallery() {
    vi.resetModules();
    await import('../../public/assets/js/gallery.js');
}

function renderLightbox(triggers) {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <div class="row" id="gallery-media-grid">
            ${triggers.map((t, i) => `
                <button type="button" class="gallery-lightbox-trigger" data-index="${i}"
                        data-type="${t.type}" data-medium-url="${t.mediumUrl}" data-download-url="${t.downloadUrl ?? ''}">
                    <img src="${t.mediumUrl}" alt="">
                </button>
            `).join('')}
        </div>
        <div id="gallery-lightbox" class="d-none">
            <button id="gallery-lightbox-close"></button>
            <button id="gallery-lightbox-prev"></button>
            <button id="gallery-lightbox-next"></button>
            <img id="gallery-lightbox-image" class="d-none" src="" alt="">
            <video id="gallery-lightbox-video" class="d-none"></video>
            <a href="#" id="gallery-lightbox-download" class="d-none" download>Télécharger en haute qualité</a>
        </div>
    `;
}

const isOpen = () => !document.getElementById('gallery-lightbox').classList.contains('d-none');

describe('gallery.js lightbox', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.head.innerHTML = '';
        document.body.innerHTML = '';
        // jsdom implements neither of these on HTMLMediaElement.
        window.HTMLMediaElement.prototype.pause = vi.fn();
        window.HTMLMediaElement.prototype.load = vi.fn();
    });

    it('opens on a processed thumbnail and shows its medium rendition', async () => {
        renderLightbox([{ type: 'photo', mediumUrl: '/gallery/media/1/medium', downloadUrl: '/gallery/media/1/download' }]);
        await loadGallery();

        document.querySelector('.gallery-lightbox-trigger').click();

        expect(isOpen()).toBe(true);
        expect(document.getElementById('gallery-lightbox-image').getAttribute('src')).toBe('/gallery/media/1/medium');
    });

    // Regression: every trigger used to be mapped onto a URL-filtered list, so
    // a still-processing thumbnail resolved to index -1 and open(-1) un-hid the
    // overlay anyway — a fullscreen black screen with nothing in it.
    it('does not open on a thumbnail that has no rendition yet', async () => {
        renderLightbox([
            { type: 'photo', mediumUrl: '' },
            { type: 'photo', mediumUrl: '/gallery/media/2/medium', downloadUrl: '/gallery/media/2/download' },
        ]);
        await loadGallery();

        document.querySelectorAll('.gallery-lightbox-trigger')[0].click();

        expect(isOpen()).toBe(false);
    });

    it('marks an unopenable thumbnail as disabled for assistive tech', async () => {
        renderLightbox([{ type: 'photo', mediumUrl: '' }]);
        await loadGallery();

        expect(document.querySelector('.gallery-lightbox-trigger').getAttribute('aria-disabled')).toBe('true');
    });

    it('never opens when no thumbnail has a rendition at all', async () => {
        renderLightbox([
            { type: 'photo', mediumUrl: '' },
            { type: 'photo', mediumUrl: '' },
        ]);
        await loadGallery();

        document.querySelectorAll('.gallery-lightbox-trigger').forEach((b) => b.click());

        expect(isOpen()).toBe(false);
    });

    // The processed thumbnail is the SECOND trigger but the FIRST item, so a
    // trigger-index/item-index mix-up shows the wrong media.
    it('keeps trigger and item indexes aligned when an earlier thumbnail is skipped', async () => {
        renderLightbox([
            { type: 'photo', mediumUrl: '' },
            { type: 'photo', mediumUrl: '/gallery/media/7/medium', downloadUrl: '/gallery/media/7/download' },
            { type: 'photo', mediumUrl: '/gallery/media/8/medium', downloadUrl: '/gallery/media/8/download' },
        ]);
        await loadGallery();

        document.querySelectorAll('.gallery-lightbox-trigger')[2].click();

        expect(document.getElementById('gallery-lightbox-image').getAttribute('src')).toBe('/gallery/media/8/medium');
    });

    it('plays a video rendition in the video element, and offers to save the video itself', async () => {
        renderLightbox([{ type: 'video', mediumUrl: '/gallery/media/3/medium', downloadUrl: '/gallery/media/3/download' }]);
        await loadGallery();

        document.querySelector('.gallery-lightbox-trigger').click();

        expect(document.getElementById('gallery-lightbox-video').classList.contains('d-none')).toBe(false);
        const download = document.getElementById('gallery-lightbox-download');
        expect(download.classList.contains('d-none')).toBe(false);
        expect(download.textContent).toBe('Télécharger la vidéo');
    });

    // One control now, and it SAVES rather than showing: the two buttons it
    // replaced filled the bottom of a phone screen between them, and neither
    // could actually save a correctly-named file with S3 storage (the
    // rendition URL is cross-origin there, where `download` is ignored).
    it('points the single download control at the application route for this media', async () => {
        renderLightbox([{ type: 'photo', mediumUrl: '/gallery/media/9/medium', downloadUrl: '/gallery/media/9/download' }]);
        await loadGallery();

        document.querySelector('.gallery-lightbox-trigger').click();

        const download = document.getElementById('gallery-lightbox-download');
        expect(download.classList.contains('d-none')).toBe(false);
        expect(download.getAttribute('href')).toBe('/gallery/media/9/download');
        expect(download.hasAttribute('download')).toBe(true);
        expect(download.textContent).toBe('Télécharger en haute qualité');
    });

    it('offers no download at all for a media that carries no download URL', async () => {
        renderLightbox([{ type: 'photo', mediumUrl: '/gallery/media/9/medium' }]);
        await loadGallery();

        document.querySelector('.gallery-lightbox-trigger').click();

        expect(document.getElementById('gallery-lightbox-download').classList.contains('d-none')).toBe(true);
    });

    it('closes on Escape', async () => {
        renderLightbox([{ type: 'photo', mediumUrl: '/gallery/media/1/medium', downloadUrl: '/gallery/media/1/download' }]);
        await loadGallery();
        document.querySelector('.gallery-lightbox-trigger').click();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(isOpen()).toBe(false);
    });
});

describe('gallery.js media actions', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok">';
        document.body.innerHTML = `
            <div class="row" id="gallery-existing-media" data-reorder-url="/gallery/5/media/reorder">
                <div class="gallery-media-item" data-media-id="11" draggable="true">
                    <button class="gallery-media-delete" data-url="/gallery/5/media/11/delete"></button>
                </div>
            </div>
        `;
        window.confirm = vi.fn(() => true);
        window.alert = vi.fn();
    });

    it('sends the CSRF token from the meta tag with a delete', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
        await loadGallery();

        document.querySelector('.gallery-media-delete').click();
        await vi.waitFor(() => expect(document.querySelector('.gallery-media-item')).toBeNull());

        expect(JSON.parse(fetch.mock.calls[0][1].body)._csrf_token).toBe('tok');
    });

    // Regression: postJson() had no rejection handler, so a dropped connection
    // became an unhandled rejection and the click silently did nothing.
    it('reports a network failure instead of failing silently', async () => {
        global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
        await loadGallery();

        document.querySelector('.gallery-media-delete').click();
        await vi.waitFor(() => expect(window.alert).toHaveBeenCalled());

        expect(window.alert.mock.calls[0][0]).toContain('réseau');
        expect(document.querySelector('.gallery-media-item')).not.toBeNull();
    });

    it('reports a non-JSON response instead of failing silently', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.reject(new SyntaxError('Unexpected token <')) }));
        await loadGallery();

        document.querySelector('.gallery-media-delete').click();
        await vi.waitFor(() => expect(window.alert).toHaveBeenCalled());

        expect(window.alert.mock.calls[0][0]).toContain('serveur');
    });
});

describe('gallery.js upload zone — chunked delegation for large files (audit M2)', () => {
    function buildUploadZone() {
        document.head.innerHTML = '<meta name="csrf-token" content="tok">';
        document.body.innerHTML = `
            <div id="gallery-upload-zone" data-upload-url="/gallery/5/media">
                <input type="file" id="gallery-upload-input" multiple>
            </div>
            <div id="gallery-upload-progress" class="d-none">
                <div id="gallery-upload-progress-bar"></div>
                <span id="gallery-upload-progress-label"></span>
            </div>
        `;
    }

    function selectFiles(files) {
        const input = document.getElementById('gallery-upload-input');
        Object.defineProperty(input, 'files', { configurable: true, value: files });
        input.dispatchEvent(new Event('change'));
    }

    function fileOfSize(size, name) {
        const file = new File(['x'], name);
        Object.defineProperty(file, 'size', { value: size });
        return file;
    }

    beforeEach(() => {
        vi.restoreAllMocks();
        window.alert = vi.fn();
        delete window.ScoutMagicChunkedUpload;
    });

    it('routes a file above CHUNK_THRESHOLD through uploadInChunks with the CSRF token and its name', async () => {
        buildUploadZone();
        window.ScoutMagicChunkedUpload = {
            CHUNK_THRESHOLD: 24 * 1024 * 1024,
            // Never resolves — keeps the test clear of uploadAll's reload().
            uploadInChunks: vi.fn(() => new Promise(() => {})),
        };
        await loadGallery();

        selectFiles([fileOfSize(100 * 1024 * 1024, 'camp.mp4')]);

        expect(window.ScoutMagicChunkedUpload.uploadInChunks).toHaveBeenCalledWith(
            expect.anything(), '/gallery/5/media',
            expect.objectContaining({ csrfToken: 'tok', lastFields: { name: 'camp.mp4' } }),
        );
    });

    it('keeps a small file on the single-request XHR path', async () => {
        buildUploadZone();
        window.ScoutMagicChunkedUpload = {
            CHUNK_THRESHOLD: 24 * 1024 * 1024,
            uploadInChunks: vi.fn(),
        };
        const sent = [];
        global.XMLHttpRequest = class {
            open() {}
            send(body) { sent.push(body); }
        };
        await loadGallery();

        selectFiles([fileOfSize(1024, 'photo.jpg')]);

        expect(window.ScoutMagicChunkedUpload.uploadInChunks).not.toHaveBeenCalled();
        expect(sent).toHaveLength(1);
    });
});
