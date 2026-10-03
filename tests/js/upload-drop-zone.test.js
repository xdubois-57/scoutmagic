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

/** @param {string} name @param {string} [type] */
function image(name, type = 'image/jpeg') {
    return new File(['\u00ff\u00d8\u00ff'], name, { type });
}

/** A FileList-alike, which is all the production code reads. */
function fileList(...files) {
    const list = { length: files.length, item: (i) => files[i] };
    files.forEach((f, i) => { list[i] = f; });

    return list;
}

/**
 * jsdom implements neither createObjectURL nor revokeObjectURL, and what
 * matters about them here is the PAIRING — an object URL handed out and
 * not given back is a decoded image pinned in memory for the life of the
 * page. So they are counted rather than stubbed away.
 *
 * @type {{ created: string[], revoked: string[] }}
 */
let objectUrls = { created: [], revoked: [] };

function trackObjectUrls() {
    objectUrls = { created: [], revoked: [] };
    let next = 0;
    URL.createObjectURL = (/** @type {Blob} */ blob) => {
        const url = `blob:test/${++next}`;
        objectUrls.created.push(url);

        return url;
    };
    URL.revokeObjectURL = (/** @type {string} */ url) => {
        objectUrls.revoked.push(url);
    };
}

/** The object URLs handed out and never given back. */
function leaked() {
    return objectUrls.created.filter((url) => !objectUrls.revoked.includes(url));
}

function thumbnails() {
    return Array.from(document.querySelectorAll('#photo-drop-zone-selection img'));
}

/** @param {File[]} files */
function pick(files) {
    const input = /** @type {HTMLInputElement} */ (document.getElementById('photo-file'));
    Object.defineProperty(input, 'files', { configurable: true, value: fileList(...files) });
    input.dispatchEvent(new Event('change'));
}

async function load({ withDropZone = true } = {}) {
    vi.resetModules();
    trackObjectUrls();
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

        const thumbs = thumbnails();
        expect(thumbs).toHaveLength(1);
        // The name on the image rather than « Aper\u00e7u »: a screen reader
        // reading "image" tells nobody which photo this is.
        expect(thumbs[0].alt).toBe('IMG_4821.jpg');
        expect(thumbs[0].src).toBe(objectUrls.created[0]);
        // Secondary, not replaced \u2014 the picture says which photo, the
        // name says which file.
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('IMG_4821.jpg');
    });

    it('produces the same preview from a drop as from the picker', async () => {
        await load();

        const drop = new Event('drop', { bubbles: true });
        Object.defineProperty(drop, 'dataTransfer', {
            value: { files: fileList(image('glissee.png', 'image/png')) },
        });
        document.getElementById('photo-drop-zone').dispatchEvent(drop);

        expect(thumbnails().map((img) => img.alt)).toEqual(['glissee.png']);
    });

    it('replaces the thumbnail and gives the old object URL back', async () => {
        // Picking three photos in a row must not pin three decoded images
        // in memory for the life of the page.
        await load();

        pick([image('premiere.jpg')]);
        const first = objectUrls.created[0];
        pick([image('seconde.jpg')]);

        expect(thumbnails().map((img) => img.alt)).toEqual(['seconde.jpg']);
        expect(objectUrls.revoked).toContain(first);
        expect(leaked()).toEqual([thumbnails()[0].src]);
    });

    it('gives every object URL back when the selection is cleared', async () => {
        await load();

        const zone = document.getElementById('photo-drop-zone');
        window.ScoutMagicUploadDropZone.describe(zone, fileList(image('a.jpg'), image('b.jpg')));
        window.ScoutMagicUploadDropZone.describe(zone, fileList());

        expect(thumbnails()).toHaveLength(0);
        expect(leaked()).toEqual([]);
    });

    it('falls back to the name alone for a file that is not an image', async () => {
        // The mixed zones \u2014 a rental document, a receipt \u2014 take a PDF or a
        // photograph of one indifferently, and a broken frame is worse
        // than no frame.
        await load();

        pick([file('contrat.pdf')]);

        expect(thumbnails()).toHaveLength(0);
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('contrat.pdf');
        expect(objectUrls.created).toEqual([]);
    });

    it('removes the thumbnail and releases its URL when the image will not decode', async () => {
        // A file declared image/* whose bytes are not one: a renamed file,
        // or a format this engine cannot draw.
        await load();

        pick([image('cassee.jpg')]);
        const img = thumbnails()[0];
        img.dispatchEvent(new Event('error'));

        expect(thumbnails()).toHaveLength(0);
        expect(leaked()).toEqual([]);
        // The name survives the picture, which is the whole point of
        // keeping it.
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('cassee.jpg');
    });

    it('shows one thumbnail per image on a multi-file zone', async () => {
        await load();

        window.ScoutMagicUploadDropZone.describe(
            document.getElementById('photo-drop-zone'),
            fileList(image('un.jpg'), file('deux.pdf'), image('trois.png', 'image/png')),
        );

        expect(thumbnails().map((img) => img.alt)).toEqual(['un.jpg', 'trois.png']);
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('un.jpg, deux.pdf, trois.png');
    });

    /**
     * The guard at the sink. `img.src` is a navigable sink, and CodeQL
     * flagged this assignment HIGH for it — « it came from our own code »
     * is not an argument that survives the next caller, so the only value
     * this ever carries is checked to be a browser-minted blob URL
     * (AGENTS.md § CodeQL: validate at the sink, not at the call sites).
     *
     * Unfalsifiable without this: jsdom's own stub always answers
     * `blob:…`, so the guard never rejected anything and dropping it left
     * every other test in this file green.
     */
    it('refuses a preview URL that is not a blob, and gives it back', async () => {
        await load();
        const handed = [];
        URL.createObjectURL = () => {
            const url = 'javascript:alert(1)';
            handed.push(url);
            objectUrls.created.push(url);

            return url;
        };

        pick([image('piegee.jpg')]);

        expect(thumbnails()).toHaveLength(0);
        // The name still names the file; only the picture is refused.
        expect(document.getElementById('photo-drop-zone-selection').textContent)
            .toBe('piegee.jpg');
        expect(objectUrls.revoked).toEqual(handed);
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

        expect(document.querySelectorAll('#document-drop-zone-selection img')).toHaveLength(0);
        expect(selection()).toBe('photo-du-contrat.jpg');
        expect(objectUrls.created).toEqual([]);
    });
});
