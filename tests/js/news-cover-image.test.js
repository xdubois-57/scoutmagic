/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/news-form-builder.js:
// the cover image of an article, processed in the browser before the
// multipart POST (issue #1357).
//
// jsdom has no canvas, so the canvas and the Image are stand-ins that record
// what the code asks of them; WHAT THE ENCODING SAVES is something only a
// real browser can show, and was measured in Chromium on a detailed
// synthetic image (see the pull request). What these tests pin is the
// decisions: which format is asked for, what the file is called, what is
// refused and where it is said.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '../../public/assets/js/api.js';
import '../../public/assets/js/toast.js';

const MB = 1024 * 1024;

/** The markup modules/news/views/editor.html.twig renders around the cover. */
function editorDom(maxBytes) {
    document.body.innerHTML = `
        <form id="news-editor-form">
            <input type="text" id="news-title" value="Camp d'été">
            <label for="image" id="news-image-label-wrapper">
                <img id="news-image-preview" class="d-none" alt="">
                <div id="news-image-placeholder"></div>
            </label>
            <span id="news-image-label">Image</span>
            <input type="file" id="image" name="image" ${maxBytes === null ? '' : 'data-max-bytes="' + maxBytes + '"'}>
            <div id="news-image-error" role="alert" hidden></div>
        </form>`;
}

let canvasCalls;
let nextBlob;
let nextPixels;
let imageFails;

/** A canvas that records the encoding it is asked for and answers `nextBlob`. */
function installCanvas() {
    canvasCalls = { toBlob: [], drawImage: 0 };
    HTMLCanvasElement.prototype.getContext = /** @type {any} */ (function () {
        return {
            drawImage() {
                canvasCalls.drawImage++;
            },
            getImageData(_x, _y, w, h) {
                return { data: nextPixels ?? new Uint8ClampedArray(w * h * 4).fill(255) };
            },
        };
    });
    HTMLCanvasElement.prototype.toBlob = /** @type {any} */ (function (callback, type, quality) {
        canvasCalls.toBlob.push({ type, quality });
        callback(typeof nextBlob === 'function' ? nextBlob(type) : nextBlob);
    });
}

/** An Image that loads at once, with the natural size of the source. */
function installImage(width, height) {
    globalThis.Image = /** @type {any} */ (class {
        set src(_value) {
            this.naturalWidth = width;
            this.naturalHeight = height;
            queueMicrotask(() => (imageFails ? this.onerror?.() : this.onload?.()));
        }
    });
}

function blobOf(size, type) {
    return new Blob([new Uint8Array(size)], { type });
}

/** The files an input was handed through DataTransfer, which jsdom lacks. */
function installDataTransfer() {
    globalThis.DataTransfer = /** @type {any} */ (class {
        constructor() {
            const list = [];
            this.items = { add: (file) => list.push(file) };
            this.files = list;
        }
    });
    Object.defineProperty(HTMLInputElement.prototype, 'files', {
        configurable: true,
        get() {
            return this._files ?? [];
        },
        set(value) {
            this._files = value;
        },
    });
    // Emptying a file input is what `input.value = ''` does in a browser.
    const original = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    Object.defineProperty(HTMLInputElement.prototype, 'value', {
        configurable: true,
        get() {
            return original.get.call(this);
        },
        set(value) {
            if (this.type === 'file' && value === '') {
                this._files = [];
            }
            original.set.call(this, value);
        },
    });
}

async function loadEditor(maxBytes) {
    editorDom(maxBytes);
    vi.resetModules();
    await import('../../public/assets/js/news-form-builder.js');
}

/** Choose a file: the change event a browser fires, once the file is in the input. */
async function choose(file) {
    const input = document.getElementById('image');
    input._files = [file];
    input.dispatchEvent(new Event('change'));
    // The image loads in a microtask, the canvas answers synchronously.
    await new Promise((resolve) => setTimeout(resolve, 0));
}

const photo = () => new File([new Uint8Array(3 * MB)], 'IMG_2041.jpg', { type: 'image/jpeg' });

describe('news-form-builder.js: the cover image (#1357)', () => {
    let toast;

    beforeEach(() => {
        toast = vi.fn();
        window.ScoutMagicToast = /** @type {any} */ ({ show: toast });
        URL.createObjectURL = /** @type {any} */ (vi.fn(() => 'blob:preview'));
        URL.revokeObjectURL = vi.fn();
        imageFails = false;
        nextPixels = null;
        nextBlob = (type) => blobOf(700 * 1024, type);
        installCanvas();
        installImage(4000, 3000);
        installDataTransfer();
    });

    afterEach(() => {
        document.body.innerHTML = '';
        imageFails = false;
        nextPixels = null;
    });

    describe('what is asked of the encoder', () => {
        it('sends an opaque photograph as a JPEG of reasonable quality, never a PNG', async () => {
            await loadEditor(10 * MB);

            await choose(photo());

            expect(canvasCalls.toBlob).toEqual([{ type: 'image/jpeg', quality: 0.85 }]);
        });

        it('keeps a PNG for an image that has transparency', async () => {
            await loadEditor(10 * MB);
            // 2048 × 1536 after the downscale; one transparent pixel is enough.
            nextPixels = new Uint8ClampedArray(2048 * 1536 * 4).fill(255);
            nextPixels[3] = 0;

            await choose(photo());

            expect(canvasCalls.toBlob).toEqual([{ type: 'image/png', quality: undefined }]);
        });

        it('keeps the 2 048 px ceiling and the aspect ratio of a landscape source', async () => {
            await loadEditor(10 * MB);
            let size = null;
            HTMLCanvasElement.prototype.getContext = /** @type {any} */ (function () {
                size = [this.width, this.height];
                return { drawImage() {}, getImageData: (_x, _y, w, h) => ({ data: new Uint8ClampedArray(w * h * 4).fill(255) }) };
            });

            await choose(photo());

            expect(size).toEqual([2048, 1536]);
        });

        it('center-crops a portrait to a square, as before', async () => {
            installImage(3000, 4000);
            await loadEditor(10 * MB);
            let size = null;
            HTMLCanvasElement.prototype.getContext = /** @type {any} */ (function () {
                size = [this.width, this.height];
                return { drawImage() {}, getImageData: (_x, _y, w, h) => ({ data: new Uint8ClampedArray(w * h * 4).fill(255) }) };
            });

            await choose(photo());

            expect(size).toEqual([2048, 2048]);
        });
    });

    describe('what is sent', () => {
        it('names the file after the bytes it holds: image/jpeg and .jpg', async () => {
            await loadEditor(10 * MB);

            await choose(photo());

            const sent = document.getElementById('image').files[0];
            expect(sent.type).toBe('image/jpeg');
            expect(sent.name).toBe('image.jpg');
        });

        it('names a transparent image a PNG', async () => {
            await loadEditor(10 * MB);
            nextPixels = new Uint8ClampedArray(2048 * 1536 * 4).fill(255);
            nextPixels[3] = 0;

            await choose(photo());

            const sent = document.getElementById('image').files[0];
            expect(sent.type).toBe('image/png');
            expect(sent.name).toBe('image.png');
        });

        /**
         * A browser that cannot produce the requested format answers a PNG
         * without saying so: the type of the blob it returns is what counts.
         */
        it('trusts the type the browser actually produced, not the one it was asked for', async () => {
            await loadEditor(10 * MB);
            nextBlob = () => blobOf(700 * 1024, 'image/png');

            await choose(photo());

            const sent = document.getElementById('image').files[0];
            expect(sent.type).toBe('image/png');
            expect(sent.name).toBe('image.png');
        });

        it('shows the processed image, not the original', async () => {
            await loadEditor(10 * MB);

            await choose(photo());

            const preview = document.getElementById('news-image-preview');
            expect(preview.src).toBe('blob:preview');
            expect(preview.classList.contains('d-none')).toBe(false);
            expect(toast).not.toHaveBeenCalled();
        });
    });

    describe('what is refused, immediately and in words', () => {
        it('refuses a processed file over the limit before anything is posted', async () => {
            await loadEditor(10 * MB);
            nextBlob = (type) => blobOf(11 * MB, type);

            await choose(photo());

            const input = document.getElementById('image');
            expect(input.files).toHaveLength(0);
            expect(toast).toHaveBeenCalledTimes(1);
            const [message, options] = toast.mock.calls[0];
            expect(message).toContain('trop lourde');
            expect(message).toContain('10 Mo');
            expect(options).toEqual({ variant: 'error' });
        });

        it('says it next to the field too, where a screen reader finds it', async () => {
            await loadEditor(10 * MB);
            nextBlob = (type) => blobOf(11 * MB, type);

            await choose(photo());

            const error = document.getElementById('news-image-error');
            expect(error.hidden).toBe(false);
            expect(error.textContent).toContain('trop lourde');
        });

        it('keeps what the author typed, and the picture already shown', async () => {
            await loadEditor(10 * MB);
            document.getElementById('news-title').value = 'Un titre déjà saisi';
            nextBlob = (type) => blobOf(11 * MB, type);

            await choose(photo());

            expect(document.getElementById('news-title').value).toBe('Un titre déjà saisi');
            expect(document.getElementById('news-image-preview').classList.contains('d-none')).toBe(true);
        });

        it('compares with the limit the server wrote into the page, not a number of its own', async () => {
            await loadEditor(2 * MB);
            nextBlob = (type) => blobOf(3 * MB, type);

            await choose(photo());

            expect(document.getElementById('image').files).toHaveLength(0);
            expect(toast.mock.calls[0][0]).toContain('2 Mo');
        });

        it('falls back on the server default when the page does not say', async () => {
            await loadEditor(null);
            nextBlob = (type) => blobOf(9 * MB, type);

            await choose(photo());

            expect(document.getElementById('image').files).toHaveLength(1);
            expect(toast).not.toHaveBeenCalled();
        });

        it('says so when the image cannot be read at all', async () => {
            await loadEditor(10 * MB);
            imageFails = true;

            await choose(photo());

            expect(document.getElementById('image').files).toHaveLength(0);
            expect(toast.mock.calls[0][0]).toBe('Impossible de traiter cette image.');
        });

        it('says so when the browser produces nothing', async () => {
            await loadEditor(10 * MB);
            nextBlob = null;

            await choose(photo());

            expect(toast.mock.calls[0][0]).toBe('Impossible de traiter cette image.');
        });

        it('refuses a type the server does not accept rather than sending it', async () => {
            await loadEditor(10 * MB);
            nextBlob = () => blobOf(700 * 1024, 'image/tiff');

            await choose(photo());

            expect(document.getElementById('image').files).toHaveLength(0);
            expect(toast).toHaveBeenCalledTimes(1);
        });

        /**
         * The previous choice was good and is still on the screen: a later,
         * refused one must not leave the preview showing a picture the form
         * would no longer send.
         */
        it('keeps the file already accepted when a later choice is refused', async () => {
            await loadEditor(10 * MB);
            await choose(photo());
            const accepted = document.getElementById('image').files[0];

            nextBlob = (type) => blobOf(11 * MB, type);
            await choose(photo());

            expect(document.getElementById('image').files).toEqual([accepted]);
            expect(document.getElementById('news-image-preview').classList.contains('d-none')).toBe(false);
        });

        it('clears the notice once a good file is chosen', async () => {
            await loadEditor(10 * MB);
            nextBlob = (type) => blobOf(11 * MB, type);
            await choose(photo());
            expect(document.getElementById('news-image-error').hidden).toBe(false);

            nextBlob = (type) => blobOf(700 * 1024, type);
            await choose(photo());

            expect(document.getElementById('news-image-error').hidden).toBe(true);
            expect(document.getElementById('image').files).toHaveLength(1);
        });
    });
});
