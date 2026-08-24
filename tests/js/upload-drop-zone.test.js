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
    <div id="orphan-zone" data-drop-zone-for="nowhere"></div>`;

/** @param {string} name */
function file(name) {
    return new File(['x'], name, { type: 'application/pdf' });
}

/** A FileList-alike, which is all the production code reads. */
function fileList(...files) {
    const list = { length: files.length, item: (i) => files[i] };
    files.forEach((f, i) => { list[i] = f; });

    return list;
}

async function load({ withDropZone = true } = {}) {
    vi.resetModules();
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
