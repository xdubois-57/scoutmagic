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
                        data-type="${t.type}" data-medium-url="${t.mediumUrl}" data-large-url="${t.largeUrl}">
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
            <button id="gallery-lightbox-hq" class="d-none"></button>
            <a href="#" id="gallery-lightbox-download" class="d-none" download></a>
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
        renderLightbox([{ type: 'photo', mediumUrl: '/gallery/media/1/medium', largeUrl: '/gallery/media/1/large' }]);
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
            { type: 'photo', mediumUrl: '', largeUrl: '' },
            { type: 'photo', mediumUrl: '/gallery/media/2/medium', largeUrl: '/gallery/media/2/large' },
        ]);
        await loadGallery();

        document.querySelectorAll('.gallery-lightbox-trigger')[0].click();

        expect(isOpen()).toBe(false);
    });

    it('marks an unopenable thumbnail as disabled for assistive tech', async () => {
        renderLightbox([{ type: 'photo', mediumUrl: '', largeUrl: '' }]);
        await loadGallery();

        expect(document.querySelector('.gallery-lightbox-trigger').getAttribute('aria-disabled')).toBe('true');
    });

    it('never opens when no thumbnail has a rendition at all', async () => {
        renderLightbox([
            { type: 'photo', mediumUrl: '', largeUrl: '' },
            { type: 'photo', mediumUrl: '', largeUrl: '' },
        ]);
        await loadGallery();

        document.querySelectorAll('.gallery-lightbox-trigger').forEach((b) => b.click());

        expect(isOpen()).toBe(false);
    });

    // The processed thumbnail is the SECOND trigger but the FIRST item, so a
    // trigger-index/item-index mix-up shows the wrong media.
    it('keeps trigger and item indexes aligned when an earlier thumbnail is skipped', async () => {
        renderLightbox([
            { type: 'photo', mediumUrl: '', largeUrl: '' },
            { type: 'photo', mediumUrl: '/gallery/media/7/medium', largeUrl: '/gallery/media/7/large' },
            { type: 'photo', mediumUrl: '/gallery/media/8/medium', largeUrl: '/gallery/media/8/large' },
        ]);
        await loadGallery();

        document.querySelectorAll('.gallery-lightbox-trigger')[2].click();

        expect(document.getElementById('gallery-lightbox-image').getAttribute('src')).toBe('/gallery/media/8/medium');
    });

    it('plays a video rendition in the video element and hides the high-quality button', async () => {
        renderLightbox([{ type: 'video', mediumUrl: '/gallery/media/3/medium', largeUrl: '/gallery/media/3/large' }]);
        await loadGallery();

        document.querySelector('.gallery-lightbox-trigger').click();

        expect(document.getElementById('gallery-lightbox-video').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('gallery-lightbox-hq').classList.contains('d-none')).toBe(true);
    });

    it('closes on Escape', async () => {
        renderLightbox([{ type: 'photo', mediumUrl: '/gallery/media/1/medium', largeUrl: '/gallery/media/1/large' }]);
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
