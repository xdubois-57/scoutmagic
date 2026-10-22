// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// implementation in public/assets/js/signature-dialog.js, with the REAL
// signature-pad.js beside it: the dialog a module opens to sign a document
// (issue #926, lot C1; core/View/templates/partials/signature_dialog.html.twig).
//
// Classic scripts: reached through the namespaced test seams they expose.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import '../../public/assets/js/signature-pad.js';
import '../../public/assets/js/signature-dialog.js';

const { PREVIEW_URL, wipe, preview, isEmpty, wire, restore } = /** @type {any} */ (globalThis).ScoutMagicSignatureDialogInternals;

/**
 * The dialog as the partial renders it, in one mode — wired as the page
 * wires it at load, unless `wired` is false (a dialog a fetch brought).
 *
 * @param {'stored'|'new'|'witness'} mode
 * @param {boolean} [wired]
 */
function dialog(mode, wired = true) {
    document.body.innerHTML = `
        <div class="modal" id="sign">
            <form data-signature-form data-signature-dialog-form data-signature-mode="${mode}">
                <input type="hidden" name="signature_source" value="${mode === 'stored' ? 'stored' : 'new'}">
                ${mode === 'stored' ? '<div data-signature-preview></div>' : `
                    ${mode === 'witness' ? '<input type="text" name="signer_name">' : ''}
                    <input type="hidden" name="signature_data" value="">
                    <canvas data-signature-pad width="600" height="200"></canvas>
                    ${mode === 'new' ? '<input type="file" name="signature_file"><input type="checkbox" name="remember_signature" value="1">' : ''}
                    <p data-signature-missing hidden></p>`}
            </form>
        </div>`;
    const form = /** @type {HTMLFormElement} */ (document.querySelector('form'));
    const modal = /** @type {HTMLElement} */ (document.querySelector('.modal'));
    const canvas = /** @type {HTMLCanvasElement|null} */ (form.querySelector('canvas'));
    const context = { beginPath: vi.fn(), moveTo: vi.fn(), lineTo: vi.fn(), stroke: vi.fn(), clearRect: vi.fn() };
    if (canvas !== null) {
        canvas.getContext = /** @type {any} */ (() => context);
        canvas.toDataURL = () => 'data:image/png;base64,AAAA';
    }
    if (wired) {
        wire(form);
    }

    return { form, modal, canvas, context };
}

/** @param {HTMLCanvasElement} canvas */
function trace(canvas) {
    canvas.dispatchEvent(new MouseEvent('pointerdown', { clientX: 1, clientY: 1 }));
    canvas.dispatchEvent(new MouseEvent('pointermove', { clientX: 5, clientY: 5 }));
    canvas.dispatchEvent(new MouseEvent('pointerup'));
}

/** @param {HTMLFormElement} form */
function submit(form) {
    const event = new Event('submit', { cancelable: true });
    form.dispatchEvent(event);

    return event;
}

/** @param {HTMLFormElement} form @param {string} name */
function field(form, name) {
    return /** @type {HTMLInputElement} */ (form.querySelector(`input[name="${name}"]`));
}

beforeEach(() => {
    vi.useRealTimers();
});

describe('the stored signature’s preview (decision Q5)', () => {
    it('is fetched from the owner’s route when the dialog opens, and dropped when it closes', () => {
        const { form, modal } = dialog('stored');
        const frame = /** @type {HTMLElement} */ (form.querySelector('[data-signature-preview]'));
        expect(frame.querySelector('img')).toBeNull();

        modal.dispatchEvent(new Event('show.bs.modal', { bubbles: true }));
        const image = /** @type {HTMLImageElement} */ (frame.querySelector('img'));
        expect(image.getAttribute('src')).toBe('/account/signature/image');
        expect(image.alt).toBe('Votre signature enregistrée');

        modal.dispatchEvent(new Event('hidden.bs.modal'));
        expect(frame.querySelector('img')).toBeNull();
    });

    it('is never fetched for a dialog asking for a new signature', () => {
        const { modal } = dialog('new');

        modal.dispatchEvent(new Event('show.bs.modal', { bubbles: true }));

        expect(document.querySelector('img')).toBeNull();
    });

    it('takes its address from the script, never from the page', () => {
        expect(PREVIEW_URL).toBe('/account/signature/image');
        document.body.innerHTML = '<form><div data-signature-preview data-src="javascript:alert(1)"></div></form>';
        preview(document.querySelector('form'), true);

        expect(document.querySelector('img')?.getAttribute('src')).toBe(PREVIEW_URL);
    });
});

describe('nothing stays behind', () => {
    it('« Annuler » empties the pad, the field, the file and the box — and posted nothing', () => {
        const { form, modal, canvas, context } = dialog('new');
        const box = field(form, 'remember_signature');
        trace(/** @type {HTMLCanvasElement} */ (canvas));
        box.checked = true;

        modal.dispatchEvent(new Event('hidden.bs.modal'));

        expect(context.clearRect).toHaveBeenCalledWith(0, 0, 600, 200);
        expect(field(form, 'signature_data').value).toBe('');
        expect(field(form, 'signature_file').value).toBe('');
        expect(box.checked).toBe(false);
        // A pad emptied posts no drawing either.
        submit(form);
        expect(field(form, 'signature_data').value).toBe('');
    });

    it('sends only the PNG, then empties the dialog once the browser has gathered it', async () => {
        vi.useFakeTimers();
        const { form, canvas, context } = dialog('new');
        trace(/** @type {HTMLCanvasElement} */ (canvas));

        const event = submit(form);

        expect(event.defaultPrevented).toBe(false);
        expect(field(form, 'signature_data').value).toBe('data:image/png;base64,AAAA');
        const names = Array.from(form.querySelectorAll('input')).map((input) => input.name);
        expect(names).toEqual(['signature_source', 'signature_data', 'signature_file', 'remember_signature']);

        await vi.runAllTimersAsync();
        expect(field(form, 'signature_data').value).toBe('');
        expect(context.clearRect).toHaveBeenCalled();
    });

    it('forgets the name a witness typed', () => {
        const { form } = dialog('witness');
        field(form, 'signer_name').value = 'Jeanne';

        wipe(form);

        expect(field(form, 'signer_name').value).toBe('');
    });

    it('closes and empties every dialog of a page restored from the back-forward cache', () => {
        const { form, modal, canvas, context } = dialog('new');
        trace(/** @type {HTMLCanvasElement} */ (canvas));
        field(form, 'remember_signature').checked = true;
        const hide = vi.fn();
        /** @type {any} */ (window).bootstrap = { Modal: { getInstance: (/** @type {Element} */ element) => (element === modal ? { hide } : null) } };

        const persisted = new Event('pageshow');
        Object.defineProperty(persisted, 'persisted', { value: true });
        window.dispatchEvent(persisted);

        expect(hide).toHaveBeenCalledOnce();
        expect(context.clearRect).toHaveBeenCalled();
        expect(field(form, 'remember_signature').checked).toBe(false);
        delete (/** @type {any} */ (window).bootstrap);
    });

    it('leaves the page alone on an ordinary pageshow', () => {
        const { form, canvas, context } = dialog('new');
        trace(/** @type {HTMLCanvasElement} */ (canvas));

        window.dispatchEvent(new Event('pageshow'));

        expect(context.clearRect).not.toHaveBeenCalled();
        restore();
        expect(field(form, 'signature_data').value).toBe('');
    });
});

describe('a dialog that arrived after the page loaded', () => {
    it('is wired when it opens: its pad draws, and closing it empties it', () => {
        const { form, modal, canvas, context } = dialog('new', false);
        trace(/** @type {HTMLCanvasElement} */ (canvas));
        expect(context.lineTo).not.toHaveBeenCalled();

        modal.dispatchEvent(new Event('show.bs.modal', { bubbles: true }));
        trace(/** @type {HTMLCanvasElement} */ (canvas));
        submit(form);

        expect(context.lineTo).toHaveBeenCalled();
        expect(field(form, 'signature_data').value).toBe('data:image/png;base64,AAAA');
        modal.dispatchEvent(new Event('hidden.bs.modal'));
        expect(field(form, 'signature_data').value).toBe('');
    });

    it('is wired once, however often it opens', () => {
        const { form, modal } = dialog('new', false);
        modal.dispatchEvent(new Event('show.bs.modal', { bubbles: true }));
        modal.dispatchEvent(new Event('show.bs.modal', { bubbles: true }));

        const event = submit(form);

        expect(event.defaultPrevented).toBe(true);
        expect(form.dataset.signatureDialogWired).toBe('1');
        expect(form.dataset.signaturePadWired).toBe('1');
    });
});

describe('a dialog sent with nothing to sign with', () => {
    it('says so in place rather than posting', () => {
        const { form } = dialog('new');
        const missing = /** @type {HTMLElement} */ (form.querySelector('[data-signature-missing]'));

        const event = submit(form);

        expect(event.defaultPrevented).toBe(true);
        expect(missing.hidden).toBe(false);
    });

    it('lets a chosen file through without a drawing', () => {
        const { form } = dialog('new');
        const file = field(form, 'signature_file');
        Object.defineProperty(file, 'files', { value: [new File(['x'], 'signature.png')] });

        expect(isEmpty(form)).toBe(false);
    });

    it('never blocks the stored mode, which has nothing to trace', () => {
        const { form } = dialog('stored');

        expect(submit(form).defaultPrevented).toBe(false);
    });

    it('does nothing for a form outside a dialog', () => {
        document.body.innerHTML = '<form data-signature-dialog-form></form>';

        expect(wire(document.querySelector('form'))).toBe(false);
    });
});
