// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network. Exercises the REAL implementation in
// public/assets/js/upload-drop-zone.js (imported below, never
// reimplemented here) on top of the REAL public/assets/js/drop-zone.js,
// which it binds — the pair is the whole behaviour, and faking one of the
// two would leave the interesting half untested.
//
// The fixture mirrors what `partials/drop_zone.html.twig` renders on the
// camps documents page: the zone, its visually-hidden input, and the
// element that names what was picked.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <div id="document-drop-zone" class="drop-zone" data-drop-zone-for="document-file">
        <input type="file" class="visually-hidden" id="document-file" name="document">
    </div>
    <div class="form-text" id="document-drop-zone-selection"></div>
    <div id="photo-drop-zone" class="drop-zone"
         data-drop-zone-for="photo-file" data-drop-zone-preview="image">
        <input type="file" class="visually-hidden" id="photo-file" name="photo" accept="image/*">
    </div>
    <div class="form-text" id="photo-drop-zone-selection"></div>
    <div id="orphan-zone" data-drop-zone-for="nowhere"></div>`;

/** @param {string} name */
function file(name) {
    return new File(['x'], name, { type: 'application/pdf' });
}

/**
 * @param {string} name
 * @param {string} [type]
 * @param {{ width?: number, height?: number, undecodable?: boolean }} [bitmap]
 *        what the stubbed createImageBitmap() will answer for this file
 */
function image(name, type = 'image/jpeg', bitmap = {}) {
    const file = new File(['\u00ff\u00d8\u00ff'], name, { type });
    file.__width = bitmap.width;
    file.__height = bitmap.height;
    file.__undecodable = bitmap.undecodable === true;

    return file;
}

/** A FileList-alike, which is all the production code reads. */
function fileList(...files) {
    const list = { length: files.length, item: (i) => files[i] };
    files.forEach((f, i) => { list[i] = f; });

    return list;
}

/**
 * jsdom ships neither createImageBitmap nor a painting 2D context, so both
 * are stubbed — same shape as tests/js/upload.test.js, which exercises the
 * other half of this pair. Every stub records what the module asked of it,
 * because that IS the behaviour here: which decode options it requests,
 * what size it draws at, and whether it lets the bitmap go afterwards.
 *
 * `URL.createObjectURL` is stubbed too, and counted — not because the
 * module uses it but because it must not: see « mints no object URL ».
 *
 * @type {{ decoded: Array<{ file: File, options: object }>, drawn: Array<object>, closed: number }}
 */
let browser = { decoded: [], drawn: [], closed: 0 };

/** @type {string[]} */
let objectUrlsCreated = [];

function stubBrowserImageApis() {
    browser = { decoded: [], drawn: [], closed: 0 };
    objectUrlsCreated = [];

    globalThis.createImageBitmap = (/** @type {File} */ file, options) => {
        browser.decoded.push({ file, options });
        if (file.__undecodable) {
            return Promise.reject(new Error('not an image'));
        }

        return Promise.resolve({
            width: file.__width ?? 40,
            height: file.__height ?? 30,
            close: () => { browser.closed += 1; },
        });
    };

    HTMLCanvasElement.prototype.getContext = function () {
        const canvas = this;

        return {
            drawImage: (bitmap, x, y, width, height) => {
                browser.drawn.push({ canvas, bitmap, x, y, width, height });
            },
        };
    };

    URL.createObjectURL = (/** @type {Blob} */ blob) => {
        const url = 'blob:test/forbidden';
        objectUrlsCreated.push(url);

        return url;
    };
    URL.revokeObjectURL = () => {};
}

/** Lets the decode promises and their `.then` callbacks run. */
function settle() {
    return new Promise((resolve) => { setTimeout(resolve, 0); });
}

function thumbnails() {
    return Array.from(document.querySelectorAll('#photo-drop-zone-selection canvas'));
}

/** Only the thumbnails that actually got pixels — a hidden one shows nothing. */
function visibleThumbnails() {
    return thumbnails().filter((canvas) => !canvas.hidden);
}

/** @param {File[]} files */
function pick(files) {
    const input = /** @type {HTMLInputElement} */ (document.getElementById('photo-file'));
    Object.defineProperty(input, 'files', { configurable: true, value: fileList(...files) });
    input.dispatchEvent(new Event('change'));
}

async function load({ withDropZone = true } = {}) {
    vi.resetModules();
    stubBrowserImageApis();
    document.body.innerHTML = PAGE;
    if (withDropZone) {
        await import('../../public/assets/js/drop-zone.js');
    } else {
        delete window.ScoutMagicDropZone;
    }
    await import('../../public/assets/js/upload-drop-zone.js');
}

function selection() {
    return document.getElementById('document-drop-zone-selection').textContent;
}

describe('upload-drop-zone.js', () => {
    beforeEach(() => {
        delete window.ScoutMagicDropZone;
    });

    it('names the file a drop delivered', async () => {
        // The zone's input is visually hidden, so a zone that says nothing
        // looks identical before and after a drop — which reads as a zone
        // that swallowed the file.
        await load();

        const drop = new Event('drop', { bubbles: true });
        Object.defineProperty(drop, 'dataTransfer', { value: { files: fileList(file('contrat.pdf')) } });
        document.getElementById('document-drop-zone').dispatchEvent(drop);

        expect(selection()).toBe('contrat.pdf');
    });

    it('names the file the picker delivered', async () => {
        await load();

        const input = document.getElementById('document-file');
        Object.defineProperty(input, 'files', {
            configurable: true,
            value: fileList(file('facture.pdf')),
        });
        input.dispatchEvent(new Event('change'));

        expect(selection()).toBe('facture.pdf');
    });

    it('still names the file without drop-zone.js on the page', async () => {
        // The zone is then a plain label around a hidden input; picking a
        // file must still say which one.
        await load({ withDropZone: false });

        const input = document.getElementById('document-file');
        Object.defineProperty(input, 'files', {
            configurable: true,
            value: fileList(file('sans-glisser.pdf')),
        });
        input.dispatchEvent(new Event('change'));

        expect(selection()).toBe('sans-glisser.pdf');
    });

    it('lists every name rather than counting them', async () => {
        // « 2 fichiers » does not tell somebody they picked the holiday
        // snap instead of the invoice.
        await load();

        window.ScoutMagicUploadDropZone.describe(
            document.getElementById('document-drop-zone'),
            fileList(file('a.pdf'), file('b.pdf')),
        );

        expect(selection()).toBe('a.pdf, b.pdf');
    });

    it('clears the name when nothing is selected any more', async () => {
        await load();

        const zone = document.getElementById('document-drop-zone');
        window.ScoutMagicUploadDropZone.describe(zone, fileList(file('a.pdf')));
        window.ScoutMagicUploadDropZone.describe(zone, fileList());

        expect(selection()).toBe('');
    });

    it('ignores a zone whose input does not exist', async () => {
        await expect(load()).resolves.toBeUndefined();
        expect(document.getElementById('orphan-zone')).not.toBeNull();
    });

    it('survives a zone with no place to write the name', async () => {
        await load();
        document.getElementById('document-drop-zone-selection').remove();

        expect(() => window.ScoutMagicUploadDropZone.describe(
            document.getElementById('document-drop-zone'),
            fileList(file('a.pdf')),
        )).not.toThrow();
    });
});

// Issue #756: a phone photo is called IMG_4821.HEIC, which tells its owner
// nothing about which one they just picked.
describe('upload-drop-zone.js \u2014 the image preview', () => {
    beforeEach(() => {
        delete window.ScoutMagicDropZone;
    });

    it('shows a thumbnail of a picked image, with its name still there', async () => {
        await load();

        pick([image('IMG_4821.jpg')]);
        await settle();

        expect(visibleThumbnails()).toHaveLength(1);
        // Secondary, not replaced \u2014 the picture says which photo, the
        // name says which file, and the name is the accessible one.
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('IMG_4821.jpg');
        expect(visibleThumbnails()[0].getAttribute('aria-hidden')).toBe('true');
    });

    it('produces the same preview from a drop as from the picker', async () => {
        await load();

        const drop = new Event('drop', { bubbles: true });
        Object.defineProperty(drop, 'dataTransfer', {
            value: { files: fileList(image('glissee.png', 'image/png')) },
        });
        document.getElementById('photo-drop-zone').dispatchEvent(drop);
        await settle();

        expect(visibleThumbnails()).toHaveLength(1);
        expect(browser.decoded.map(({ file }) => file.name)).toEqual(['glissee.png']);
    });

    /**
     * The reason this file no longer assigns a string to an `src`. CodeQL
     * rated that assignment a HIGH \u00ab DOM text reinterpreted as HTML \u00bb,
     * twice: the File is reached through an element looked up from a DOM
     * attribute, so the object URL derived from it is DOM-derived text, and
     * a guard at the sink does not change that. Decoded pixels have no URL.
     *
     * Falsifiable: put `img.src = URL.createObjectURL(file)` back and both
     * halves of this fail.
     */
    it('mints no object URL and inserts no <img>', async () => {
        await load();

        pick([image('IMG_4821.jpg')]);
        await settle();

        expect(objectUrlsCreated).toEqual([]);
        expect(document.querySelectorAll('#photo-drop-zone-selection img')).toHaveLength(0);
    });

    /**
     * A bordered canvas with nothing in it is a grey box, and a file whose
     * bytes are not an image would leave that box standing. So the element
     * exists immediately \u2014 which is what keeps the thumbnails in the order
     * the files were picked \u2014 but shows nothing until it has pixels.
     */
    it('shows nothing until the pixels are in it', async () => {
        await load();

        pick([image('IMG_4821.jpg')]);

        // Before the decode resolves: present, in order, and invisible.
        expect(thumbnails()).toHaveLength(1);
        expect(visibleThumbnails()).toHaveLength(0);

        await settle();

        expect(visibleThumbnails()).toHaveLength(1);
    });

    /**
     * createImageBitmap's own default is `imageOrientation: 'none'`, so an
     * unconfigured call draws a phone photo on its side \u2014 upload.js writes
     * that out at length and this is the same decode.
     */
    it('asks for the file\u2019s own EXIF orientation', async () => {
        await load();

        pick([image('couchee.jpg')]);
        await settle();

        expect(browser.decoded).toHaveLength(1);
        expect(browser.decoded[0].options).toEqual({ imageOrientation: 'from-image' });
    });

    it('scales a big photo into the thumbnail box, keeping its shape', async () => {
        await load();

        pick([image('grande.jpg', 'image/jpeg', { width: 4000, height: 3000 })]);
        await settle();

        const canvas = visibleThumbnails()[0];
        // 96 on the longest side, and 4:3 kept rather than squashed.
        expect([canvas.width, canvas.height]).toEqual([96, 72]);
        expect(browser.drawn[0].width).toBe(96);
        expect(browser.drawn[0].height).toBe(72);
    });

    it('never enlarges an image smaller than the box', async () => {
        // A 32-pixel icon blown up to 96 is a blurry mess.
        await load();

        pick([image('minuscule.png', 'image/png', { width: 32, height: 24 })]);
        await settle();

        const canvas = visibleThumbnails()[0];
        expect([canvas.width, canvas.height]).toEqual([32, 24]);
    });

    it('lets the decoded bitmap go once it is drawn', async () => {
        // It is several megabytes of phone photo with nothing left to do;
        // the pixels live in the canvas now.
        await load();

        pick([image('lourde.jpg', 'image/jpeg', { width: 4000, height: 3000 })]);
        await settle();

        expect(browser.closed).toBe(1);
    });

    it('replaces the previous thumbnail on a new pick', async () => {
        // Picking three photos in a row must leave one thumbnail, not
        // three stacked up.
        await load();

        pick([image('premiere.jpg')]);
        await settle();
        pick([image('seconde.jpg')]);
        await settle();

        expect(visibleThumbnails()).toHaveLength(1);
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('seconde.jpg');
    });

    /**
     * The decode of a selection that has already been replaced resolves
     * onto a canvas that is no longer in the document \u2014 which is what
     * replaced the list of object URLs this file used to have to give back.
     *
     * Falsifiable: a `describe()` that appended instead of replacing, or a
     * draw that re-attached its canvas, leaves two here.
     */
    it('shows nothing from a decode that finishes after its selection is gone', async () => {
        await load();

        pick([image('abandonnee.jpg')]);
        pick([image('gardee.jpg')]);
        await settle();

        expect(visibleThumbnails()).toHaveLength(1);
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('gardee.jpg');
    });

    it('clears the thumbnails when the selection is cleared', async () => {
        await load();

        const zone = document.getElementById('photo-drop-zone');
        window.ScoutMagicUploadDropZone.describe(zone, fileList(image('a.jpg'), image('b.jpg')));
        await settle();
        window.ScoutMagicUploadDropZone.describe(zone, fileList());
        await settle();

        expect(thumbnails()).toHaveLength(0);
    });

    it('falls back to the name alone for a file that is not an image', async () => {
        // The mixed zones \u2014 a rental document, a receipt \u2014 take a PDF or a
        // photograph of one indifferently, and a broken frame is worse
        // than no frame.
        await load();

        pick([file('contrat.pdf')]);
        await settle();

        expect(thumbnails()).toHaveLength(0);
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('contrat.pdf');
        expect(browser.decoded).toEqual([]);
    });

    it('takes the thumbnail back out when the image will not decode', async () => {
        // A file declared image/* whose bytes are not one: a renamed file,
        // or a format this engine cannot draw.
        await load();

        pick([image('cassee.jpg', 'image/jpeg', { undecodable: true })]);
        await settle();

        expect(thumbnails()).toHaveLength(0);
        // The name survives the picture, which is the whole point of
        // keeping it.
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('cassee.jpg');
    });

    /**
     * `typeof`, not a truthiness check: on an engine that never had
     * createImageBitmap the global does not exist at all, so a bare
     * reference throws a ReferenceError rather than reading as undefined.
     *
     * Called through `describe()` rather than by picking a file, because
     * that is the only way the throw is visible: an exception inside a
     * `change` listener is reported and swallowed, and the filename is
     * written before the thumbnails, so every other assertion here passes
     * just as well with the guard deleted \u2014 which is what a mutation
     * showed.
     */
    it('shows the name alone on an engine without createImageBitmap', async () => {
        // No fallback to an <img>: that is the sink this file no longer
        // has. Those engines get what they had before #756.
        await load();
        delete globalThis.createImageBitmap;
        const zone = document.getElementById('photo-drop-zone');

        expect(() => window.ScoutMagicUploadDropZone.describe(
            zone,
            fileList(image('sans-decodeur.jpg')),
        )).not.toThrow();
        await settle();

        expect(thumbnails()).toHaveLength(0);
        expect(objectUrlsCreated).toEqual([]);
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('sans-decodeur.jpg');
    });

    it('takes the thumbnail back out when there is no 2D context to paint into', async () => {
        // An engine that refuses a context \u2014 or simply has none, which is
        // jsdom's own answer without the optional canvas binding \u2014 must
        // leave the name alone rather than an empty bordered box.
        await load();
        HTMLCanvasElement.prototype.getContext = () => null;

        pick([image('sans-contexte.jpg')]);
        await settle();

        expect(thumbnails()).toHaveLength(0);
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('sans-contexte.jpg');
    });

    it('shows one thumbnail per image on a multi-file zone', async () => {
        await load();

        window.ScoutMagicUploadDropZone.describe(
            document.getElementById('photo-drop-zone'),
            fileList(image('un.jpg'), file('deux.pdf'), image('trois.png', 'image/png')),
        );
        await settle();

        expect(visibleThumbnails()).toHaveLength(2);
        expect(browser.decoded.map(({ file }) => file.name)).toEqual(['un.jpg', 'trois.png']);
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('un.jpg, deux.pdf, trois.png');
    });

    it('draws nothing on a zone that did not ask for a preview', async () => {
        // The document zones are the majority, and they must come out of
        // this change untouched.
        await load();

        const input = document.getElementById('document-file');
        Object.defineProperty(input, 'files', {
            configurable: true,
            value: fileList(image('photo-du-contrat.jpg')),
        });
        input.dispatchEvent(new Event('change'));
        await settle();

        expect(document.querySelectorAll('#document-drop-zone-selection canvas')).toHaveLength(0);
        expect(selection()).toBe('photo-du-contrat.jpg');
        expect(browser.decoded).toEqual([]);
    });
});
