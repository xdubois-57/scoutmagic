// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/photo-reduce.js
// (imported below, never reimplemented here): a phone photo reduced in the
// browser before it is sent (#989, lot B).
//
// jsdom ships neither createImageBitmap nor a painting canvas, so both are
// stubbed, as in upload.test.js; each stub records what the script asked
// of it — the size drawn, the type and quality encoded — which is what
// these tests are about.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/** The canvas the script drew into, and how it was encoded. */
let painted = null;

function stubBrowserImageApis() {
    painted = null;

    globalThis.createImageBitmap = vi.fn((file) => Promise.resolve({
        width: file.__width,
        height: file.__height,
        close: vi.fn(),
    }));

    HTMLCanvasElement.prototype.getContext = vi.fn(function () {
        painted = { canvas: this, drawImage: vi.fn(), fillRect: vi.fn() };
        return { drawImage: painted.drawImage, fillRect: painted.fillRect, fillStyle: '' };
    });

    HTMLCanvasElement.prototype.toBlob = vi.fn(function (callback, type, quality) {
        painted.encodedAs = { type, quality };
        callback(new Blob([new Uint8Array(300 * 1024)], { type }));
    });
}

function imageFile({ name = 'IMG_0042.JPG', type = 'image/jpeg', width = 4032, height = 3024 } = {}) {
    // The bytes of a JPEG whose EXIF block carries a GPS position: what
    // the reduced file holds must be the canvas's, never these.
    const file = new File([new Uint8Array([0xFF, 0xD8, 0xFF, 0xE1, 0x47, 0x50, 0x53])], name, { type });
    file.__width = width;
    file.__height = height;
    return file;
}

async function load() {
    vi.resetModules();
    delete window.ScoutMagicPhotoReduce;
    await import('../../public/assets/js/photo-reduce.js');
    return window.ScoutMagicPhotoReduce;
}

describe('photo-reduce', () => {
    beforeEach(() => {
        stubBrowserImageApis();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        delete globalThis.createImageBitmap;
    });

    it('brings the longest side down to 2 400 px, keeping the proportions', async () => {
        const { reducePhoto } = await load();

        await reducePhoto(imageFile({ width: 4032, height: 3024 }));

        expect(painted.canvas.width).toBe(2400);
        expect(painted.canvas.height).toBe(1800);
        expect(painted.drawImage).toHaveBeenCalledWith(expect.anything(), 0, 0, 2400, 1800);
    });

    it('measures a portrait photo by its height', async () => {
        const { targetSize } = await load();

        expect(targetSize(3024, 4032)).toEqual({ width: 1800, height: 2400 });
    });

    it('never enlarges a photo already small enough', async () => {
        const { reducePhoto } = await load();

        await reducePhoto(imageFile({ width: 1200, height: 900 }));

        expect(painted.canvas.width).toBe(1200);
        expect(painted.canvas.height).toBe(900);
    });

    it('re-encodes as a JPEG at 0.85, named .jpg — the canvas bytes, never the EXIF', async () => {
        const { reducePhoto } = await load();
        const original = imageFile({ name: 'degat.png', type: 'image/png' });

        const reduced = await reducePhoto(original);

        expect(painted.encodedAs).toEqual({ type: 'image/jpeg', quality: 0.85 });
        expect(reduced).not.toBe(original);
        expect(reduced.type).toBe('image/jpeg');
        expect(reduced.name).toBe('degat.jpg');
        expect(reduced.size).toBe(300 * 1024);
    });

    it('paints a white background, since a JPEG has no transparency', async () => {
        const { reducePhoto } = await load();

        await reducePhoto(imageFile({ type: 'image/png', width: 800, height: 600 }));

        expect(painted.fillRect).toHaveBeenCalledWith(0, 0, 800, 600);
    });

    it('asks for the EXIF orientation explicitly — a sideways photo must not be baked in', async () => {
        const { reducePhoto } = await load();

        await reducePhoto(imageFile());

        expect(globalThis.createImageBitmap).toHaveBeenCalledWith(expect.anything(), { imageOrientation: 'from-image' });
    });

    it('closes the decoded bitmap once it is drawn', async () => {
        globalThis.ImageBitmap = class {};
        const bitmap = Object.assign(new globalThis.ImageBitmap(), { width: 4000, height: 3000, close: vi.fn() });
        globalThis.createImageBitmap = vi.fn(() => Promise.resolve(bitmap));
        const { reducePhoto } = await load();

        await reducePhoto(imageFile());

        expect(bitmap.close).toHaveBeenCalled();
        delete globalThis.ImageBitmap;
    });

    it('tries a HEIC photo that came without a type, and gives it back when the engine cannot decode it', async () => {
        globalThis.createImageBitmap = vi.fn(() => Promise.reject(new Error('unsupported')));
        // The <img> fallback fails too, as in Chrome and Firefox.
        URL.createObjectURL = vi.fn(() => 'blob:http://localhost/heic');
        URL.revokeObjectURL = vi.fn();
        const image = vi.spyOn(window, 'Image').mockImplementation(function () {
            const img = {};
            Object.defineProperty(img, 'src', { set() { queueMicrotask(() => img.onerror()); } });
            return img;
        });
        const { reducePhoto } = await load();
        const heic = imageFile({ name: 'IMG_0042.HEIC', type: '' });

        const result = await reducePhoto(heic);

        expect(globalThis.createImageBitmap).toHaveBeenCalled();
        expect(image).toHaveBeenCalled();
        expect(result).toBe(heic);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:http://localhost/heic');
    });

    it('gives the original back when the canvas cannot encode', async () => {
        HTMLCanvasElement.prototype.toBlob = vi.fn((callback) => callback(null));
        const { reducePhoto } = await load();
        const original = imageFile();

        expect(await reducePhoto(original)).toBe(original);
    });

    it('gives the original back when there is no 2D context', async () => {
        HTMLCanvasElement.prototype.getContext = vi.fn(() => null);
        const { reducePhoto } = await load();
        const original = imageFile();

        expect(await reducePhoto(original)).toBe(original);
    });

    it('leaves what is not a photo alone', async () => {
        const { reducePhoto } = await load();
        const pdf = new File(['%PDF'], 'devis.pdf', { type: 'application/pdf' });

        expect(await reducePhoto(pdf)).toBe(pdf);
        expect(globalThis.createImageBitmap).not.toHaveBeenCalled();
    });

    it('names a file without an extension, or without a name, sensibly', async () => {
        const { jpegName } = await load();

        expect(jpegName('photo')).toBe('photo.jpg');
        expect(jpegName('')).toBe('photo.jpg');
        expect(jpegName('a.b.webp')).toBe('a.b.jpg');
    });
});
