// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network. Exercises the REAL implementation in
// public/assets/js/upload.js (imported below, never reimplemented here).
//
// jsdom ships none of the four browser APIs this file is built on —
// createImageBitmap, a painting 2D canvas context, DataTransfer, and
// URL.createObjectURL — so each is stubbed here. Every stub records what
// upload.js asked of it, which is exactly what these tests are about: the
// pixel budget it picks per upload context, and how many bytes therefore
// leave the phone.
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';

/** The canvas upload.js drew into, and the toBlob() call it ended with. */
let painted = null;

function stubBrowserImageApis() {
    painted = null;

    globalThis.createImageBitmap = vi.fn(function (file) {
        return Promise.resolve({
            width: file.__width,
            height: file.__height,
            close: vi.fn(),
        });
    });

    HTMLCanvasElement.prototype.getContext = vi.fn(function () {
        painted = { canvas: this, drawImage: vi.fn() };
        return { drawImage: painted.drawImage };
    });

    HTMLCanvasElement.prototype.toBlob = vi.fn(function (callback, type, quality) {
        painted.encodedAs = { type, quality };
        // 40 KB — a plausible WebP, and far enough from the sources below
        // that "which file was submitted" is unambiguous.
        callback(new Blob([new Uint8Array(40 * 1024)], { type }));
    });

    globalThis.DataTransfer = class {
        constructor() {
            this._files = [];
            this.items = { add: (file) => this._files.push(file) };
        }
        get files() {
            return this._files;
        }
    };

    URL.createObjectURL = vi.fn((file) => 'blob:http://localhost/' + file.name);
    URL.revokeObjectURL = vi.fn();
}

/**
 * A File the stubbed createImageBitmap() can report dimensions for.
 * `bytes` is what the real file weighs — the skip-below-this-size half of
 * a budget is decided on it.
 */
function imageFile({ name = 'photo.jpg', type = 'image/jpeg', width, height, bytes }) {
    const file = new File([new Uint8Array(1)], name, { type });
    Object.defineProperty(file, 'size', { value: bytes });
    file.__width = width;
    file.__height = height;
    return file;
}

async function boot(context) {
    document.body.innerHTML = `
        <form id="upload-form">
            <input type="hidden" name="context" value="${context}">
            <div id="drop-zone"></div>
            <input type="file" id="file-input">
            <input type="file" id="camera-input">
            <div id="preview-container" class="d-none">
                <img id="preview-img" alt="">
                <p id="preview-name"></p>
            </div>
            <button type="submit" id="upload-btn" disabled></button>
        </form>`;
    // jsdom has no writable HTMLInputElement.files; shadow it so the
    // submitted file is observable.
    const input = document.getElementById('file-input');
    Object.defineProperty(input, 'files', { value: null, writable: true, configurable: true });
    vi.resetModules();
    await import('../../public/assets/js/upload.js');
    return input;
}

/** Picks `file` through the file input and waits for the downscale chain. */
async function pick(input, file) {
    const dt = new globalThis.DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    input.dispatchEvent(new Event('change'));
    // loadImage() → toBlob() → applyFile(): three promise ticks, plus slack.
    for (let i = 0; i < 8; i++) await Promise.resolve();
}

/** The file upload.js finally pointed the form at. */
function submitted(input) {
    return input.files[0];
}

beforeEach(() => {
    vi.restoreAllMocks();
    stubBrowserImageApis();
});

afterEach(() => {
    delete globalThis.createImageBitmap;
    delete globalThis.DataTransfer;
});

describe('upload.js: avatar contexts pay for a 192×192 thumb, not for a phone photo', () => {
    it.each(['member_photo', 'account_photo'])(
        '%s re-encodes a 4 MB portrait down to a 512px short edge',
        async (context) => {
            const input = await boot(context);
            // A typical iPhone photo: under both of the content budget's
            // bars (5 MB, 2400px), which is precisely why it used to be
            // POSTed untouched.
            await pick(input, imageFile({ width: 3024, height: 4032, bytes: 4 * 1024 * 1024 }));

            expect(painted.canvas.width).toBe(512);
            expect(painted.canvas.height).toBe(683);
            expect(submitted(input).type).toBe('image/webp');
            expect(submitted(input).size).toBeLessThan(100 * 1024);
        }
    );

    it('budgets the SHORT edge, so the square the server crops keeps its pixels', async () => {
        const input = await boot('member_photo');
        // Budgeting the long edge would give 512×128 here, and the
        // server's 192×192 centre crop would be an upscale of 128px.
        await pick(input, imageFile({ width: 4000, height: 1000, bytes: 3 * 1024 * 1024 }));

        expect(painted.canvas.height).toBe(512);
        expect(painted.canvas.width).toBe(2048);
    });

    it('re-encodes even a small avatar — there is no "small enough to skip" for these', async () => {
        const input = await boot('account_photo');
        await pick(input, imageFile({ name: 'tiny.png', type: 'image/png', width: 900, height: 900, bytes: 200 * 1024 }));

        expect(painted.canvas.width).toBe(512);
        expect(submitted(input).name).toBe('tiny.webp');
    });

    it('never upscales a source already smaller than the budget', async () => {
        const input = await boot('member_photo');
        await pick(input, imageFile({ width: 300, height: 400, bytes: 30 * 1024 }));

        expect(painted.canvas.width).toBe(300);
        expect(painted.canvas.height).toBe(400);
    });

    it('encodes avatars at the avatar budget\'s quality', async () => {
        const input = await boot('member_photo');
        await pick(input, imageFile({ width: 2000, height: 2000, bytes: 2 * 1024 * 1024 }));

        expect(painted.encodedAs).toEqual({ type: 'image/webp', quality: 0.8 });
    });
});

describe('upload.js: content contexts keep the budget they had', () => {
    it('leaves a file within both bars untouched', async () => {
        const input = await boot('editable_image');
        const original = imageFile({ width: 2000, height: 1500, bytes: 1024 * 1024 });
        await pick(input, original);

        expect(painted).toBe(null);
        expect(submitted(input)).toBe(original);
    });

    it('scales an oversized one by its LONG edge, at the content quality', async () => {
        const input = await boot('section_photo');
        await pick(input, imageFile({ width: 4800, height: 3600, bytes: 8 * 1024 * 1024 }));

        expect(painted.canvas.width).toBe(2400);
        expect(painted.canvas.height).toBe(1800);
        expect(painted.encodedAs.quality).toBe(0.85);
    });

    it('re-encodes a heavy file even when its dimensions already fit', async () => {
        const input = await boot('age_branch_logo');
        await pick(input, imageFile({ width: 1200, height: 900, bytes: 9 * 1024 * 1024 }));

        expect(painted.canvas.width).toBe(1200);
        expect(submitted(input).type).toBe('image/webp');
    });
});

describe('upload.js: contexts outside the table, and failures', () => {
    it('touches nothing for a context with no budget', async () => {
        const input = await boot('document');
        const original = imageFile({ width: 5000, height: 5000, bytes: 12 * 1024 * 1024 });
        await pick(input, original);

        expect(globalThis.createImageBitmap).not.toHaveBeenCalled();
        expect(submitted(input)).toBe(original);
    });

    it('falls back to the original file when the canvas cannot encode', async () => {
        const input = await boot('member_photo');
        HTMLCanvasElement.prototype.toBlob = vi.fn(function (callback) {
            callback(null);
        });
        const original = imageFile({ width: 3024, height: 4032, bytes: 4 * 1024 * 1024 });
        await pick(input, original);

        expect(submitted(input)).toBe(original);
        expect(document.getElementById('upload-btn').disabled).toBe(false);
    });

    it('asks for EXIF orientation explicitly — a sideways phone photo must not be baked in', async () => {
        const input = await boot('member_photo');
        await pick(input, imageFile({ width: 3024, height: 4032, bytes: 4 * 1024 * 1024 }));

        expect(globalThis.createImageBitmap).toHaveBeenCalledWith(
            expect.anything(),
            { imageOrientation: 'from-image' }
        );
    });

    it('closes the decoded bitmap once it is drawn', async () => {
        globalThis.ImageBitmap = class {};
        const bitmaps = [];
        globalThis.createImageBitmap = vi.fn(function (file) {
            const bitmap = Object.assign(new globalThis.ImageBitmap(), {
                width: file.__width,
                height: file.__height,
                close: vi.fn(),
            });
            bitmaps.push(bitmap);
            return Promise.resolve(bitmap);
        });

        const input = await boot('member_photo');
        await pick(input, imageFile({ width: 3024, height: 4032, bytes: 4 * 1024 * 1024 }));

        expect(bitmaps[0].close).toHaveBeenCalled();
        delete globalThis.ImageBitmap;
    });
});

describe('upload.js: the preview never copies the whole file into a string', () => {
    it('shows an object URL rather than a FileReader data URL', async () => {
        const input = await boot('member_photo');
        await pick(input, imageFile({ width: 3024, height: 4032, bytes: 4 * 1024 * 1024 }));

        expect(URL.createObjectURL).toHaveBeenCalledTimes(1);
        expect(document.getElementById('preview-img').src).toContain('blob:');
        expect(document.getElementById('preview-container').classList.contains('d-none')).toBe(false);
    });

    it('revokes the previous preview when a second file is picked', async () => {
        const input = await boot('member_photo');
        await pick(input, imageFile({ name: 'one.jpg', width: 1000, height: 1000, bytes: 900 * 1024 }));
        await pick(input, imageFile({ name: 'two.jpg', width: 1000, height: 1000, bytes: 900 * 1024 }));

        expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:http://localhost/one.webp');
    });

    it('reports the prepared size, not the original one', async () => {
        const input = await boot('member_photo');
        await pick(input, imageFile({ width: 3024, height: 4032, bytes: 4 * 1024 * 1024 }));

        expect(document.getElementById('preview-name').textContent).toBe('photo.webp (40 Ko)');
    });
});
