// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// implementation in public/assets/js/signature-dialog.js, with the REAL
// signature-pad.js beside it: the dialog a module opens to sign a document
// (issue #926, lot C1; core/View/templates/partials/signature_dialog.html.twig).
//
// Classic scripts: reached through the namespaced test seams they expose.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import '../../public/assets/js/signature-pad.js';
import '../../public/assets/js/signature-dialog.js';

const { PREVIEW_URL, wipe, preview, isEmpty, emptySigners, wire, restore } = /** @type {any} */ (globalThis).ScoutMagicSignatureDialogInternals;

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

describe('a signer without an account (ticket §6a, the witness mode)', () => {
    it('never fetches the stored signature: a witness has no account', () => {
        const { modal } = dialog('witness');

        modal.dispatchEvent(new Event('show.bs.modal', { bubbles: true }));

        expect(document.querySelector('img')).toBeNull();
    });

    it('says so in place when sent with nothing traced — there is no import to fall back on', () => {
        const { form } = dialog('witness');
        field(form, 'signer_name').value = 'Jeanne';
        const missing = /** @type {HTMLElement} */ (form.querySelector('[data-signature-missing]'));

        const event = submit(form);

        expect(event.defaultPrevented).toBe(true);
        expect(missing.hidden).toBe(false);
    });

    it('sends the drawing and the typed name only, then wipes both once sent', async () => {
        vi.useFakeTimers();
        const { form, canvas, context } = dialog('witness');
        field(form, 'signer_name').value = 'Jeanne';
        trace(/** @type {HTMLCanvasElement} */ (canvas));

        const event = submit(form);

        expect(event.defaultPrevented).toBe(false);
        expect(field(form, 'signature_data').value).toBe('data:image/png;base64,AAAA');
        const names = Array.from(form.querySelectorAll('input')).map((input) => input.name);
        expect(names).toEqual(['signature_source', 'signer_name', 'signature_data']);

        await vi.runAllTimersAsync();
        expect(field(form, 'signature_data').value).toBe('');
        expect(field(form, 'signer_name').value).toBe('');
        expect(context.clearRect).toHaveBeenCalled();
    });

    it('wipes the pad and the name when cancelled, for the next person holding the phone', () => {
        const { form, modal, canvas, context } = dialog('witness');
        field(form, 'signer_name').value = 'Jeanne';
        trace(/** @type {HTMLCanvasElement} */ (canvas));

        modal.dispatchEvent(new Event('hidden.bs.modal'));

        expect(context.clearRect).toHaveBeenCalledWith(0, 0, 600, 200);
        expect(field(form, 'signer_name').value).toBe('');
        submit(form);
        expect(field(form, 'signature_data').value).toBe(''); // a wiped pad posts no drawing
    });

    it('wipes the pad and the name of a page restored from the back-forward cache', () => {
        const { form, canvas, context } = dialog('witness');
        field(form, 'signer_name').value = 'Jeanne';
        trace(/** @type {HTMLCanvasElement} */ (canvas));

        restore();

        expect(context.clearRect).toHaveBeenCalled();
        expect(field(form, 'signer_name').value).toBe('');
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

describe('the dialog as the partial renders it, its fields in a container', () => {
    it('is wired, empties its signer when cancelled, and blocks a dialog sent empty', () => {
        document.body.innerHTML = `
            <div class="modal">
                <form data-signature-form data-signature-dialog-form data-signature-mode="new">
                    <input type="hidden" name="booking_id" value="12">
                    <div data-signature-field data-signature-mode="new">
                        <input type="hidden" name="signature_source" value="new">
                        <input type="hidden" name="signature_data" value="">
                        <canvas data-signature-pad width="900" height="300"></canvas>
                        <input type="file" name="signature_file">
                        <input type="checkbox" name="remember_signature" value="1">
                        <p data-signature-missing hidden></p>
                    </div>
                </form>
            </div>`;
        const form = /** @type {HTMLFormElement} */ (document.querySelector('form'));
        const modal = /** @type {HTMLElement} */ (document.querySelector('.modal'));
        const canvas = /** @type {HTMLCanvasElement} */ (form.querySelector('canvas'));
        const context = { beginPath: vi.fn(), moveTo: vi.fn(), lineTo: vi.fn(), stroke: vi.fn(), clearRect: vi.fn() };
        canvas.getContext = /** @type {any} */ (() => context);
        canvas.toDataURL = () => 'data:image/png;base64,AAAA';
        wire(form);

        const event = submit(form);
        expect(event.defaultPrevented).toBe(true);
        expect(/** @type {HTMLElement} */ (form.querySelector('[data-signature-missing]')).hidden).toBe(false);

        trace(canvas);
        field(form, 'remember_signature').checked = true;
        modal.dispatchEvent(new Event('hidden.bs.modal'));

        expect(context.clearRect).toHaveBeenCalledWith(0, 0, 900, 300);
        expect(field(form, 'remember_signature').checked).toBe(false);
        expect(field(form, 'booking_id').value).toBe('12');
        expect(/** @type {HTMLElement} */ (form.querySelector('[data-signature-missing]')).hidden).toBe(true);
    });
});

describe('two signers in a form of the page (issue #989, lot D)', () => {
    /**
     * The inventory's signing form, as partials/signature_fields.html.twig
     * renders it twice: the renter as a witness under `renter_`, the
     * manager with the account's signature or a new one — and a field of
     * the page's own beside them.
     *
     * @param {'stored'|'new'} manager
     */
    function page(manager) {
        document.body.innerHTML = `
            <form data-signature-form data-signature-inline-form>
                <input type="text" name="note" value="Rien à signaler">
                <input type="file" name="photo">
                <fieldset id="renter-block">
                    <div data-signature-field data-signature-mode="witness" id="renter">
                        <input type="hidden" name="renter_signature_source" value="new">
                        <input type="text" name="renter_signer_name">
                        <input type="hidden" name="renter_signature_data" value="">
                        <canvas data-signature-pad width="600" height="200"></canvas>
                        <p data-signature-missing hidden></p>
                    </div>
                </fieldset>
                <div data-signature-field data-signature-mode="${manager}" id="manager">
                    <input type="hidden" name="signature_source" value="${manager}">
                    ${manager === 'stored' ? '<div data-signature-preview></div>' : `
                        <input type="hidden" name="signature_data" value="">
                        <canvas data-signature-pad width="900" height="300"></canvas>
                        <input type="file" name="signature_file">
                        <input type="checkbox" name="remember_signature" value="1">
                        <p data-signature-missing hidden></p>`}
                </div>
            </form>`;
        const form = /** @type {HTMLFormElement} */ (document.querySelector('form'));
        /** @type {Record<string, {canvas: HTMLCanvasElement, context: any}>} */
        const pads = {};
        form.querySelectorAll('[data-signature-field]').forEach((container) => {
            const canvas = /** @type {HTMLCanvasElement|null} */ (container.querySelector('canvas'));
            if (canvas === null) {
                return;
            }
            const context = { beginPath: vi.fn(), moveTo: vi.fn(), lineTo: vi.fn(), stroke: vi.fn(), clearRect: vi.fn() };
            canvas.getContext = /** @type {any} */ (() => context);
            canvas.toDataURL = () => `data:image/png;base64,${container.id.toUpperCase()}`;
            pads[container.id] = { canvas, context };
        });
        /** @param {string} id */
        const missing = (id) => /** @type {HTMLElement} */ (form.querySelector(`#${id} [data-signature-missing]`));

        return { form, pads, missing };
    }

    it('is wired although it sits in no dialog', () => {
        const { form } = page('new');

        expect(wire(form)).toBe(true);
        expect(form.dataset.signatureDialogWired).toBe('1');
    });

    it('fetches the stored signature into the manager’s own block when the page shows it', () => {
        const { form } = page('stored');
        wire(form);

        const image = /** @type {HTMLImageElement} */ (form.querySelector('#manager [data-signature-preview] img'));
        expect(image.getAttribute('src')).toBe(PREVIEW_URL);
        expect(form.querySelectorAll('img')).toHaveLength(1);
    });

    it('tells the signer who left their frame empty, and only them', () => {
        const { form, pads, missing } = page('new');
        wire(form);
        trace(pads.manager.canvas);

        const event = submit(form);

        expect(event.defaultPrevented).toBe(true);
        expect(missing('renter').hidden).toBe(false);
        expect(missing('manager').hidden).toBe(true);
        expect(emptySigners(form).map((/** @type {HTMLElement} */ signer) => signer.id)).toEqual(['renter']);

        trace(pads.renter.canvas);
        expect(submit(form).defaultPrevented).toBe(false);
        expect(missing('renter').hidden).toBe(true);
    });

    it('asks nothing of a signer the page set aside — a renter who is absent', () => {
        const { form, pads } = page('new');
        wire(form);
        /** @type {HTMLFieldSetElement} */ (form.querySelector('#renter-block')).disabled = true;
        trace(pads.manager.canvas);

        expect(submit(form).defaultPrevented).toBe(false);
        // Read on the same submit, the pad having filled the manager's field.
        expect(isEmpty(form)).toBe(false);
    });

    it('sends each signer’s PNG in its own field, then empties both signers and nothing else', async () => {
        vi.useFakeTimers();
        const { form, pads } = page('new');
        wire(form);
        field(form, 'renter_signer_name').value = 'Jeanne Martin';
        trace(pads.renter.canvas);
        trace(pads.manager.canvas);
        field(form, 'remember_signature').checked = true;

        const event = submit(form);

        expect(event.defaultPrevented).toBe(false);
        expect(field(form, 'renter_signature_data').value).toBe('data:image/png;base64,RENTER');
        expect(field(form, 'signature_data').value).toBe('data:image/png;base64,MANAGER');

        await vi.runAllTimersAsync();
        expect(field(form, 'renter_signature_data').value).toBe('');
        expect(field(form, 'renter_signer_name').value).toBe('');
        expect(field(form, 'signature_data').value).toBe('');
        expect(field(form, 'remember_signature').checked).toBe(false);
        expect(pads.renter.context.clearRect).toHaveBeenCalledWith(0, 0, 600, 200);
        expect(pads.manager.context.clearRect).toHaveBeenCalledWith(0, 0, 900, 300);
        // The page's own fields are the page's.
        expect(field(form, 'note').value).toBe('Rien à signaler');
    });

    it('empties both signers of a page restored from the back-forward cache, and fetches the preview again', () => {
        const { form, pads } = page('stored');
        wire(form);
        field(form, 'renter_signer_name').value = 'Jeanne Martin';
        trace(pads.renter.canvas);

        const persisted = new Event('pageshow');
        Object.defineProperty(persisted, 'persisted', { value: true });
        window.dispatchEvent(persisted);

        expect(pads.renter.context.clearRect).toHaveBeenCalled();
        expect(field(form, 'renter_signer_name').value).toBe('');
        expect(form.querySelector('#manager [data-signature-preview] img')?.getAttribute('src')).toBe(PREVIEW_URL);
    });

    it('leaves the page alone on an ordinary pageshow', () => {
        const { form, pads } = page('new');
        wire(form);
        field(form, 'renter_signer_name').value = 'Jeanne Martin';
        trace(pads.renter.canvas);

        window.dispatchEvent(new Event('pageshow'));

        expect(pads.renter.context.clearRect).not.toHaveBeenCalled();
        expect(field(form, 'renter_signer_name').value).toBe('Jeanne Martin');
    });
});
