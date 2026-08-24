// Isolated JavaScript unit test — jsdom-simulated DOM only. Exercises the
// REAL implementation in public/assets/js/pdf-thumbnail.js (imported
// below, never reimplemented here).
//
// This toolbox is the one that used to exist three times over
// (finance-receipts.js, finance-movements.js, and inline in the finance
// dashboard's template). jsdom never loads an image, so a test fires the
// `error` event itself — which is also the only way to reach this code in
// a real browser: the event says the thumbnail is not there.
import { beforeEach, describe, expect, it } from 'vitest';

const THUMBNAIL = '<img class="pdf-thumbnail" src="/files/9/thumbnail" alt="reçu.pdf">';

describe('pdf-thumbnail.js', () => {
    beforeEach(async () => {
        document.body.innerHTML = '';
        await import('../../public/assets/js/pdf-thumbnail.js');
    });

    const img = () => document.querySelector('img.pdf-thumbnail');
    const fail = (el) => el.dispatchEvent(new Event('error'));

    it('exposes itself as a global so any page can reach it', () => {
        expect(typeof window.ScoutMagicPdfThumbnail.bind).toBe('function');
    });

    it('leaves the image alone until it actually fails', () => {
        document.body.innerHTML = THUMBNAIL;
        window.ScoutMagicPdfThumbnail.bind(document);

        expect(img()).not.toBeNull();
    });

    it('replaces a failed thumbnail with the file icon', () => {
        document.body.innerHTML = THUMBNAIL;
        window.ScoutMagicPdfThumbnail.bind(document);
        fail(img());

        expect(img()).toBeNull();
        const fallback = document.querySelector('.bg-body-secondary');
        expect(fallback.querySelector('i.bi-file-earmark-pdf')).not.toBeNull();
        expect(fallback.querySelector('i').getAttribute('aria-hidden')).toBe('true');
    });

    it('defaults to the 160px card size', () => {
        document.body.innerHTML = THUMBNAIL;
        window.ScoutMagicPdfThumbnail.bind(document);
        fail(img());

        const fallback = document.querySelector('.bg-body-secondary');
        expect(fallback.style.height).toBe('160px');
        expect(fallback.style.width).toBe('');
    });

    it('takes the movements list\'s 64px square and smaller icon', () => {
        document.body.innerHTML = THUMBNAIL;
        window.ScoutMagicPdfThumbnail.bind(document, {
            width: '64px', height: '64px', iconClass: 'fs-4',
        });
        fail(img());

        const fallback = document.querySelector('.bg-body-secondary');
        expect(fallback.style.width).toBe('64px');
        expect(fallback.style.height).toBe('64px');
        expect(fallback.querySelector('i').classList.contains('fs-4')).toBe(true);
    });

    it('only touches thumbnails inside the root it was given', () => {
        document.body.innerHTML = `<div id="a">${THUMBNAIL}</div><div id="b">${THUMBNAIL}</div>`;
        window.ScoutMagicPdfThumbnail.bind(document.getElementById('a'));

        fail(document.querySelector('#b img.pdf-thumbnail'));
        expect(document.querySelector('#b img.pdf-thumbnail')).not.toBeNull();

        fail(document.querySelector('#a img.pdf-thumbnail'));
        expect(document.querySelector('#a img.pdf-thumbnail')).toBeNull();
    });

    it('wires an image once, however many times bind() runs over it', () => {
        document.body.innerHTML = THUMBNAIL;
        window.ScoutMagicPdfThumbnail.bind(document);
        window.ScoutMagicPdfThumbnail.bind(document);
        window.ScoutMagicPdfThumbnail.bind(document);
        fail(img());

        expect(document.querySelectorAll('.bg-body-secondary').length).toBe(1);
    });

    it('wires an image inserted after the first call', () => {
        document.body.innerHTML = THUMBNAIL;
        window.ScoutMagicPdfThumbnail.bind(document);
        document.body.insertAdjacentHTML('beforeend', THUMBNAIL);
        window.ScoutMagicPdfThumbnail.bind(document);

        document.querySelectorAll('img.pdf-thumbnail').forEach(fail);
        expect(document.querySelectorAll('.bg-body-secondary').length).toBe(2);
    });

    it('does nothing, and throws nothing, on a null root', () => {
        expect(() => window.ScoutMagicPdfThumbnail.bind(null)).not.toThrow();
    });

    it('replaces the image only once even if error fires twice', () => {
        document.body.innerHTML = THUMBNAIL;
        window.ScoutMagicPdfThumbnail.bind(document);
        const el = img();
        fail(el);
        fail(el);

        expect(document.querySelectorAll('.bg-body-secondary').length).toBe(1);
    });
});
