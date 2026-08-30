// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// public/assets/js/attestations-deposit.js (imported below, never
// reimplemented): the file is an IIFE that installs
// window.ScoutMagicAttestationsDeposit at import time.
//
// The behaviour worth covering is the one a drop zone gets wrong silently:
// a dropped file is not the input's own FileList, and only the second is
// submitted with a classic multipart POST. A zone that looks alive and
// posts nothing is exactly the failure this file exists to avoid.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadDeposit() {
    vi.resetModules();
    await import('../../public/assets/js/attestations-deposit.js');
    return window.ScoutMagicAttestationsDeposit;
}

function renderForm() {
    document.body.innerHTML = `
        <div id="attestations-drop-zone">
            <input type="file" id="attestations-pdf" name="pdf_file" accept=".pdf">
            <p id="attestations-file-name" hidden></p>
        </div>`;
}

function pdfFile(name) {
    return new File(['%PDF-1.4'], name, { type: 'application/pdf' });
}

/** jsdom has no DataTransfer, so a FileList is faked the way the DOM shapes one. */
function fileListOf(files) {
    const list = { length: files.length, item: (i) => files[i] || null };
    files.forEach((file, i) => { list[i] = file; });

    return list;
}

beforeEach(() => {
    document.body.innerHTML = '';
    delete window.ScoutMagicDropZone;
});

describe('naming the chosen file', () => {
    it('shows the file name once a file is chosen', async () => {
        const api = await loadDeposit();
        renderForm();
        const target = document.getElementById('attestations-file-name');

        api.showFileName(target, pdfFile('attestations-2025.pdf'));

        expect(target.textContent).toBe('attestations-2025.pdf');
        expect(target.hidden).toBe(false);
    });

    it('says nothing at all when there is no file', async () => {
        const api = await loadDeposit();
        renderForm();
        const target = document.getElementById('attestations-file-name');

        api.showFileName(target, pdfFile('x.pdf'));
        api.showFileName(target, null);

        expect(target.textContent).toBe('');
        expect(target.hidden).toBe(true);
    });

    it('never interprets a file name as markup', async () => {
        const api = await loadDeposit();
        renderForm();
        const target = document.getElementById('attestations-file-name');

        api.showFileName(target, pdfFile('<img src=x onerror=alert(1)>.pdf'));

        expect(target.querySelector('img')).toBeNull();
        expect(target.textContent).toContain('<img');
    });

    it('names the file the picker reports, through the real change listener', async () => {
        renderForm();
        vi.resetModules();
        await import('../../public/assets/js/attestations-deposit.js');

        const input = document.getElementById('attestations-pdf');
        Object.defineProperty(input, 'files', {
            value: fileListOf([pdfFile('depuis-le-selecteur.pdf')]),
            configurable: true,
        });
        input.dispatchEvent(new Event('change'));

        expect(document.getElementById('attestations-file-name').textContent)
            .toBe('depuis-le-selecteur.pdf');
    });
});

describe('a dropped file', () => {
    it('is put back into the input, or the form would post nothing', async () => {
        const api = await loadDeposit();
        renderForm();
        const input = document.getElementById('attestations-pdf');

        const assigned = [];
        Object.defineProperty(input, 'files', {
            get: () => assigned[0] || null,
            set: (value) => { assigned[0] = value; },
            configurable: true,
        });

        // A DataTransfer that behaves like the browser's.
        const added = [];
        globalThis.DataTransfer = function DataTransferStub() {
            this.items = { add: (file) => added.push(file) };
            this.files = added;
        };

        const dropped = pdfFile('depose.pdf');
        expect(api.adoptFiles(input, fileListOf([dropped]))).toBe(true);
        expect(added[0]).toBe(dropped);

        delete globalThis.DataTransfer;
    });

    it('degrades to "the picker still works" where DataTransfer is unavailable', async () => {
        const api = await loadDeposit();
        renderForm();
        const input = document.getElementById('attestations-pdf');

        // jsdom ships no DataTransfer, which is exactly the case: the drop
        // does not stick, and nothing breaks.
        expect(api.adoptFiles(input, fileListOf([pdfFile('depose.pdf')]))).toBe(false);
    });

    it('takes nothing from an empty drop', async () => {
        const api = await loadDeposit();
        renderForm();

        expect(api.adoptFiles(document.getElementById('attestations-pdf'), fileListOf([]))).toBe(false);
    });
});

describe('wiring', () => {
    it('binds the shared drop zone to the form\'s own input', async () => {
        renderForm();
        const bound = [];
        window.ScoutMagicDropZone = {
            bind: (zone, onFiles, options) => bound.push({ zone, onFiles, options }),
        };

        vi.resetModules();
        await import('../../public/assets/js/attestations-deposit.js');

        expect(bound).toHaveLength(1);
        expect(bound[0].zone.id).toBe('attestations-drop-zone');
        expect(bound[0].options.input.id).toBe('attestations-pdf');
    });

    it('does nothing at all on a page that has no deposit form', async () => {
        document.body.innerHTML = '<p>Une autre page</p>';
        window.ScoutMagicDropZone = { bind: () => { throw new Error('must not bind'); } };

        vi.resetModules();
        await expect(import('../../public/assets/js/attestations-deposit.js')).resolves.toBeDefined();
    });
});
