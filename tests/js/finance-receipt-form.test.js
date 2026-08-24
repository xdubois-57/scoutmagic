// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network. Exercises the REAL implementation in
// public/assets/js/finance-receipt-form.js (imported below, never
// reimplemented here) — the receipt upload form, in BOTH the shapes the
// one template renders: the multi-file drop zone of « Nouveau reçu » and
// the single input of « Remplacer le reçu ». The file tells them apart from
// the DOM, which is what lets this suite reach both.
//
// There is no fetch here to mock: the upload is the form's own native
// multipart POST. What the script owns before that POST is the file
// selection itself — which files the <input> will carry, and whether the
// images among them were downscaled first — so that is what is asserted,
// down to the file the browser would actually send.
//
// jsdom has neither a canvas 2D context nor DataTransfer, the two things
// the compression path is built on. Both are stubbed here (and their
// absence is itself covered, because it is a real browser state — Safari
// had no DataTransfer constructor until 14.1, and the script must fall back
// to submitting the originals rather than failing to submit at all).
import { beforeEach, describe, expect, it, vi } from 'vitest';

const RealImage = window.Image;

/** A FileList-alike: what a real DataTransfer hands to input.files. */
function fileList(files) {
    const list = { length: files.length, item: (i) => files[i] || null };
    files.forEach((file, i) => { list[i] = file; });
    return list;
}

/** The DataTransfer the browser has and jsdom does not. */
class FakeDataTransfer {
    constructor() {
        this._files = [];
        this.items = { add: (file) => this._files.push(file) };
    }
    get files() {
        return fileList(this._files);
    }
}

/** input.files is read-only in jsdom; the script assigns to it. */
function makeFilesWritable(input, files = []) {
    Object.defineProperty(input, 'files', {
        configurable: true,
        writable: true,
        value: fileList(files),
    });
}

function imageFile(name = 'photo.jpeg') {
    return new File(['original bytes'], name, { type: 'image/jpeg' });
}

function pdfFile(name = 'facture.pdf') {
    return new File(['%PDF-1.4'], name, { type: 'application/pdf' });
}

async function settle() {
    // FileReader → Image → canvas.toBlob chains several async hops; a few
    // macrotask turns drain them all. Requires real timers.
    for (let i = 0; i < 6; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

// --- The compression pipeline, stubbed at the three points jsdom lacks ---

/** The natural size the stubbed Image reports, per test. */
const naturalSize = { width: 3200, height: 1600 };
/** 2048 bytes → « Image compressée (2 Ko). » */
let compressedBlob;
let drawImage;
/** The toBlob callbacks held back when a test wants to inspect mid-flight. */
let heldToBlob;

function stubCompressionPipeline({ noContext = false, hold = false } = {}) {
    drawImage = vi.fn();
    compressedBlob = new Blob([new Uint8Array(2048)], { type: 'image/jpeg' });
    heldToBlob = [];

    // An Image that "loads" as soon as a src is set — jsdom never fetches
    // the data: URL FileReader produced.
    window.Image = class {
        constructor() {
            this.width = naturalSize.width;
            this.height = naturalSize.height;
            this.onload = null;
            this.onerror = null;
        }
        set src(value) {
            this._src = value;
            setTimeout(() => { if (this.onload) this.onload(); }, 0);
        }
        get src() {
            return this._src;
        }
    };

    vi.spyOn(HTMLCanvasElement.prototype, 'getContext')
        .mockImplementation(() => (noContext ? null : { drawImage }));
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob')
        .mockImplementation(function (callback) {
            if (hold) {
                heldToBlob.push(() => callback(compressedBlob));
                return;
            }
            callback(compressedBlob);
        });
}

// --- The two DOM shapes of modules/finance/views/receipts/form.html.twig ---

function buildNewReceiptDom() {
    document.body.innerHTML = `
        <form method="post" action="/finance/receipts/new" enctype="multipart/form-data" id="receipt-form">
            <input type="hidden" name="_csrf_token" value="tok">
            <select id="receipt-account" name="account_id"><option value="4">Compte courant</option></select>
            <div id="drop-zone" class="receipt-drop-zone border border-2 border-dashed rounded-3 p-4 text-center">
                <span>Glissez-déposez vos reçus ici, ou cliquez pour parcourir</span>
                <input type="file" class="visually-hidden" id="receipt-files" name="receipts[]" accept=".pdf,.jpg,.jpeg,.png" multiple>
            </div>
            <ul class="list-unstyled small mt-2 mb-0" id="receipt-files-list"></ul>
            <div class="form-text small" id="receipt-file-status"></div>
            <button type="submit" class="btn btn-sm btn-primary">Ajouter</button>
        </form>
    `;
    makeFilesWritable(document.getElementById('receipt-files'));
}

function buildReplaceReceiptDom() {
    document.body.innerHTML = `
        <form method="post" action="/finance/receipts/12/replace" enctype="multipart/form-data" id="receipt-form">
            <input type="hidden" name="_csrf_token" value="tok">
            <input type="file" class="form-control form-control-sm" id="receipt-file" name="receipt" accept=".pdf,.jpg,.jpeg,.png" required>
            <div class="form-text small" id="receipt-file-status"></div>
            <button type="submit" class="btn btn-sm btn-primary">Remplacer</button>
        </form>
    `;
    makeFilesWritable(document.getElementById('receipt-file'));
}

async function boot(buildDom, { withDataTransfer = true } = {}) {
    buildDom();
    if (withDataTransfer) {
        window.DataTransfer = FakeDataTransfer;
    } else {
        delete window.DataTransfer;
    }
    // jsdom cannot navigate; the script calls form.submit() to hand the
    // (possibly rewritten) FileList to the browser.
    form().submit = vi.fn();
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/drop-zone.js');
    await import('../../public/assets/js/finance-receipt-form.js');
}

const form = () => document.getElementById('receipt-form');
const status = () => document.getElementById('receipt-file-status').textContent;
const filesInput = () => document.getElementById('receipt-files');
const singleInput = () => document.getElementById('receipt-file');
const listedNames = () => Array.from(document.querySelectorAll('#receipt-files-list li span')).map((s) => s.textContent);
const submittedNames = (input) => Array.from({ length: input.files.length }, (_, i) => input.files[i].name);
const submitBtn = () => form().querySelector('button[type="submit"]');

/** Hands the file input a selection the way the picker would, then fires change. */
function pick(input, files) {
    input.files = fileList(files);
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

function submitForm() {
    const event = new Event('submit', { bubbles: true, cancelable: true });
    form().dispatchEvent(event);
    return event;
}

beforeEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
    document.body.innerHTML = '';
    window.Image = RealImage;
    window.DataTransfer = FakeDataTransfer;
    naturalSize.width = 3200;
    naturalSize.height = 1600;
    // No native dialog is ever acceptable on this site (design.md §7.5);
    // these stubs exist so the assertions below can prove none is used.
    window.alert = vi.fn();
    window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    window.ScoutMagicToast = { show: vi.fn() };
});

describe('finance-receipt-form.js: boot guard', () => {
    it('does nothing on a page without the form', async () => {
        // The « Aucun compte visible pour votre rôle. » empty state renders
        // the page header and nothing else.
        document.body.innerHTML = '<div class="text-body-secondary">Aucun compte visible pour votre rôle.</div>';
        vi.resetModules();
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/drop-zone.js');
        await expect(import('../../public/assets/js/finance-receipt-form.js')).resolves.toBeDefined();
    });

    it('does nothing on a form that renders neither shape', async () => {
        document.body.innerHTML = `
            <form id="receipt-form"><div id="receipt-file-status"></div></form>
        `;
        form().submit = vi.fn();
        vi.resetModules();
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/drop-zone.js');
        await import('../../public/assets/js/finance-receipt-form.js');

        const event = submitForm();

        expect(event.defaultPrevented).toBe(false);
        expect(form().submit).not.toHaveBeenCalled();
    });
});

describe('finance-receipt-form.js: picking receipts (« Nouveau reçu »)', () => {
    it('opens the picker when the drop zone is clicked, but not when the click came from the input itself', async () => {
        await boot(buildNewReceiptDom);
        const click = vi.spyOn(filesInput(), 'click').mockImplementation(() => {});

        document.getElementById('drop-zone').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(click).toHaveBeenCalledTimes(1);

        // The input's own click bubbles up to the drop zone; reopening the
        // picker there would reopen it the moment it closed.
        filesInput().dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(click).toHaveBeenCalledTimes(1);
    });

    it('lists the picked files and writes them back into the input', async () => {
        await boot(buildNewReceiptDom);

        pick(filesInput(), [imageFile('ticket.jpeg'), pdfFile('facture.pdf')]);

        expect(listedNames()).toEqual(['ticket.jpeg', 'facture.pdf']);
        expect(submittedNames(filesInput())).toEqual(['ticket.jpeg', 'facture.pdf']);
        expect(status()).toBe('');
    });

    it('removes one receipt from the selection with « Retirer »', async () => {
        await boot(buildNewReceiptDom);
        pick(filesInput(), [imageFile('a.jpeg'), imageFile('b.jpeg'), pdfFile('c.pdf')]);

        document.querySelectorAll('#receipt-files-list button')[1].click();

        expect(listedNames()).toEqual(['a.jpeg', 'c.pdf']);
        expect(submittedNames(filesInput())).toEqual(['a.jpeg', 'c.pdf']);
    });

    it('keeps the first ten receipts and says so in French', async () => {
        await boot(buildNewReceiptDom);

        pick(filesInput(), Array.from({ length: 12 }, (_, i) => imageFile('r' + i + '.jpeg')));

        expect(status()).toBe('Maximum 10 reçus à la fois — les suivants ont été ignorés.');
        expect(listedNames()).toHaveLength(10);
        expect(listedNames()[9]).toBe('r9.jpeg');
    });

    it('adds dropped files and keeps the drag highlight in step', async () => {
        await boot(buildNewReceiptDom);
        const dropZone = document.getElementById('drop-zone');

        const dragover = new Event('dragover', { bubbles: true, cancelable: true });
        dropZone.dispatchEvent(dragover);
        expect(dragover.defaultPrevented).toBe(true);
        expect(dropZone.classList.contains('border-primary')).toBe(true);

        const drop = new Event('drop', { bubbles: true, cancelable: true });
        // jsdom's drop event carries no dataTransfer of its own.
        Object.defineProperty(drop, 'dataTransfer', { value: { files: [imageFile('drop.jpeg')] } });
        dropZone.dispatchEvent(drop);

        expect(drop.defaultPrevented).toBe(true);
        expect(dropZone.classList.contains('border-primary')).toBe(false);
        expect(listedNames()).toEqual(['drop.jpeg']);
    });

    it('refuses to submit an empty selection', async () => {
        await boot(buildNewReceiptDom);

        const event = submitForm();
        await settle();

        expect(event.defaultPrevented).toBe(true);
        expect(status()).toBe('Veuillez sélectionner au moins un reçu.');
        expect(form().submit).not.toHaveBeenCalled();
    });
});

describe('finance-receipt-form.js: compressing before submit (« Nouveau reçu »)', () => {
    it('downscales every image to 1600px wide, renames it .jpg and leaves the other files alone', async () => {
        stubCompressionPipeline();
        await boot(buildNewReceiptDom);
        pick(filesInput(), [imageFile('ticket.jpeg'), pdfFile('facture.pdf'), imageFile('note.png')]);

        const event = submitForm();
        expect(event.defaultPrevented).toBe(true);
        expect(status()).toBe('Compression des images…');
        await settle();

        // 3200×1600 → 1600×800: the ratio is kept, the width capped.
        expect(drawImage).toHaveBeenCalledWith(expect.anything(), 0, 0, 1600, 800);
        expect(submittedNames(filesInput())).toEqual(['ticket.jpg', 'facture.pdf', 'note.jpg']);
        expect(filesInput().files[0].type).toBe('image/jpeg');
        expect(filesInput().files[1].type).toBe('application/pdf');
        expect(listedNames()).toEqual(['ticket.jpg', 'facture.pdf', 'note.jpg']);
        expect(status()).toBe('');
        expect(form().submit).toHaveBeenCalledTimes(1);
    });

    it('never upscales an image that is already narrower than the cap', async () => {
        naturalSize.width = 800;
        naturalSize.height = 400;
        stubCompressionPipeline();
        await boot(buildNewReceiptDom);
        pick(filesInput(), [imageFile('petit.jpeg')]);

        submitForm();
        await settle();

        expect(drawImage).toHaveBeenCalledWith(expect.anything(), 0, 0, 800, 400);
    });

    it('submits the original file when its compression fails', async () => {
        stubCompressionPipeline({ noContext: true });
        await boot(buildNewReceiptDom);
        pick(filesInput(), [imageFile('ticket.jpeg')]);

        submitForm();
        await settle();

        expect(submittedNames(filesInput())).toEqual(['ticket.jpeg']);
        expect(status()).toBe('');
        expect(form().submit).toHaveBeenCalledTimes(1);
    });

    it('submits natively, without compressing, when nothing picked is an image', async () => {
        stubCompressionPipeline();
        await boot(buildNewReceiptDom);
        pick(filesInput(), [pdfFile('facture.pdf')]);

        const event = submitForm();
        await settle();

        expect(event.defaultPrevented).toBe(false);
        expect(drawImage).not.toHaveBeenCalled();
        expect(form().submit).not.toHaveBeenCalled();
    });

    it('keeps the submit button disabled until the compression is done', async () => {
        stubCompressionPipeline({ hold: true });
        await boot(buildNewReceiptDom);
        pick(filesInput(), [imageFile('ticket.jpeg')]);

        submitForm();
        await settle();

        expect(submitBtn().disabled).toBe(true);
        expect(form().submit).not.toHaveBeenCalled();

        heldToBlob.forEach((release) => release());
        await settle();

        expect(submitBtn().disabled).toBe(false);
        expect(form().submit).toHaveBeenCalledTimes(1);
    });
});

describe('finance-receipt-form.js: « Remplacer le reçu »', () => {
    it('compresses the replacement image and reports its size in Ko', async () => {
        stubCompressionPipeline();
        await boot(buildReplaceReceiptDom);
        singleInput().files = fileList([imageFile('photo.jpeg')]);

        const event = submitForm();
        expect(event.defaultPrevented).toBe(true);
        expect(status()).toBe('Compression de l\'image…');
        await settle();

        expect(submittedNames(singleInput())).toEqual(['photo.jpg']);
        // 2048 bytes → 2 Ko, rounded the way the form has always rounded it.
        expect(status()).toBe('Image compressée (2 Ko).');
        expect(form().submit).toHaveBeenCalledTimes(1);
        expect(submitBtn().disabled).toBe(false);
    });

    it('submits a non-image replacement natively', async () => {
        stubCompressionPipeline();
        await boot(buildReplaceReceiptDom);
        singleInput().files = fileList([pdfFile('facture.pdf')]);

        const event = submitForm();
        await settle();

        expect(event.defaultPrevented).toBe(false);
        expect(drawImage).not.toHaveBeenCalled();
        expect(form().submit).not.toHaveBeenCalled();
    });

    it('leaves an empty input to the browser\'s own required check', async () => {
        stubCompressionPipeline();
        await boot(buildReplaceReceiptDom);

        const event = submitForm();
        await settle();

        expect(event.defaultPrevented).toBe(false);
        expect(form().submit).not.toHaveBeenCalled();
    });

    it('submits the original image untouched where the browser has no DataTransfer', async () => {
        // Safari had no DataTransfer constructor until 14.1: the form must
        // still upload, uncompressed, rather than not upload at all.
        stubCompressionPipeline();
        await boot(buildReplaceReceiptDom, { withDataTransfer: false });
        singleInput().files = fileList([imageFile('photo.jpeg')]);

        const event = submitForm();
        await settle();

        expect(event.defaultPrevented).toBe(false);
        expect(drawImage).not.toHaveBeenCalled();
        expect(submittedNames(singleInput())).toEqual(['photo.jpeg']);
        expect(form().submit).not.toHaveBeenCalled();
    });

    it('submits the original image when the compression fails', async () => {
        stubCompressionPipeline({ noContext: true });
        await boot(buildReplaceReceiptDom);
        singleInput().files = fileList([imageFile('photo.jpeg')]);

        submitForm();
        await settle();

        expect(submittedNames(singleInput())).toEqual(['photo.jpeg']);
        expect(status()).toBe('');
        expect(form().submit).toHaveBeenCalledTimes(1);
    });

    it('never opens a native dialog on any path', async () => {
        stubCompressionPipeline();
        await boot(buildReplaceReceiptDom);
        singleInput().files = fileList([imageFile('photo.jpeg')]);

        submitForm();
        await settle();

        expect(window.alert).not.toHaveBeenCalled();
        expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
    });
});
