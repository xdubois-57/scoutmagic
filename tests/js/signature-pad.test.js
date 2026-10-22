// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// implementation in public/assets/js/signature-pad.js: the drawing pad of
// « Ma signature » under Mon compte (issue #926), and the two pads of one
// form signed by two people (issue #989, lot D).
//
// A classic script: its two functions are reached through the namespaced
// globalThis.ScoutMagicSignaturePadInternals test seam it exposes.
import { describe, expect, it, vi } from 'vitest';
import '../../public/assets/js/signature-pad.js';

const { canvasPoint, wirePad } = /** @type {any} */ (globalThis).ScoutMagicSignaturePadInternals;

describe('canvasPoint()', () => {
    it('maps a pointer into the canvas’s own pixels', () => {
        // A 600 × 200 canvas shown at 300 × 100 CSS pixels, as on a
        // high-density screen.
        expect(canvasPoint(
            { clientX: 110, clientY: 70 },
            { left: 10, top: 20, width: 300, height: 100 },
            { width: 600, height: 200 }
        )).toEqual({ x: 200, y: 100 });
    });

    it('does not divide by a collapsed box', () => {
        expect(canvasPoint(
            { clientX: 5, clientY: 5 },
            { left: 0, top: 0, width: 0, height: 0 },
            { width: 600, height: 200 }
        )).toEqual({ x: 5, y: 5 });
    });
});

describe('wirePad()', () => {
    function form() {
        document.body.innerHTML = `
            <form data-signature-form>
                <canvas data-signature-pad width="600" height="200"></canvas>
                <input type="hidden" name="signature_data" value="">
                <button type="button" data-signature-clear>Effacer</button>
            </form>`;
        const element = /** @type {HTMLFormElement} */ (document.querySelector('form'));
        const canvas = /** @type {HTMLCanvasElement} */ (element.querySelector('canvas'));
        const context = {
            beginPath: vi.fn(), moveTo: vi.fn(), lineTo: vi.fn(), stroke: vi.fn(), clearRect: vi.fn(),
        };
        canvas.getContext = /** @type {any} */ (() => context);
        canvas.toDataURL = () => 'data:image/png;base64,AAAA';
        const field = /** @type {HTMLInputElement} */ (element.querySelector('input'));
        return { element, canvas, context, field };
    }

    /** @param {HTMLCanvasElement} canvas */
    function trace(canvas) {
        canvas.dispatchEvent(new MouseEvent('pointerdown', { clientX: 1, clientY: 1 }));
        canvas.dispatchEvent(new MouseEvent('pointermove', { clientX: 5, clientY: 5 }));
        canvas.dispatchEvent(new MouseEvent('pointerup'));
    }

    it('posts nothing for an untouched pad, leaving room for an imported image', () => {
        const { element, field } = form();
        wirePad(element);

        element.dispatchEvent(new Event('submit'));

        expect(field.value).toBe('');
    });

    it('posts nothing after a tap that drew nothing, so an imported image still counts', () => {
        const { element, canvas, field } = form();
        wirePad(element);

        canvas.dispatchEvent(new MouseEvent('pointerdown', { clientX: 1, clientY: 1 }));
        canvas.dispatchEvent(new MouseEvent('pointerup'));
        element.dispatchEvent(new Event('submit'));

        expect(field.value).toBe('');
    });

    it('posts the drawing as a PNG once something was traced, and « Effacer » forgets it', () => {
        const { element, canvas, field } = form();
        wirePad(element);

        trace(canvas);
        element.dispatchEvent(new Event('submit'));
        expect(field.value).toBe('data:image/png;base64,AAAA');

        /** @type {HTMLElement} */ (element.querySelector('[data-signature-clear]')).click();
        element.dispatchEvent(new Event('submit'));
        expect(field.value).toBe('');
    });

    it('posts only the picture: nothing of the stroke reaches the form', () => {
        const { element, canvas } = form();
        wirePad(element);

        trace(canvas);
        element.dispatchEvent(new Event('submit'));

        const fields = Array.from(element.querySelectorAll('input')).map((input) => input.name);
        expect(fields).toEqual(['signature_data']);
    });

    it('hands back what empties the pad, for a page restored from the back-forward cache', () => {
        const { element, canvas, context, field } = form();
        const reset = wirePad(element);

        trace(canvas);
        element.dispatchEvent(new Event('submit'));
        reset();

        expect(field.value).toBe('');
        expect(context.clearRect).toHaveBeenCalledWith(0, 0, 600, 200);
        element.dispatchEvent(new Event('submit'));
        expect(field.value).toBe('');
    });

    it('empties itself when the signature dialog sends it signature-pad:reset', () => {
        const { element, canvas, context, field } = form();
        wirePad(element);

        trace(canvas);
        element.dispatchEvent(new CustomEvent('signature-pad:reset'));

        expect(context.clearRect).toHaveBeenCalledWith(0, 0, 600, 200);
        element.dispatchEvent(new Event('submit'));
        expect(field.value).toBe('');
    });

    it('wires a form once, however often it is asked', () => {
        const { element } = form();

        expect(wirePad(element)).not.toBeNull();
        expect(wirePad(element)).toBeNull();
        expect(/** @type {any} */ (globalThis).ScoutMagicSignaturePad.wire).toBe(wirePad);
    });

    it('does nothing on a form without a pad', () => {
        document.body.innerHTML = '<form data-signature-form><input type="file" name="signature_file"></form>';

        expect(wirePad(/** @type {HTMLFormElement} */ (document.querySelector('form')))).toBeNull();
    });

    it('lets the page scroll nowhere while tracing on a phone', () => {
        const { element, canvas } = form();
        wirePad(element);

        expect(canvas.style.touchAction).toBe('none');
    });
});

describe('two signers in one form (issue #989, lot D)', () => {
    /**
     * A form as partials/signature_fields.html.twig renders it twice: the
     * renter as a witness under `renter_`, the manager without a prefix.
     */
    function form() {
        document.body.innerHTML = `
            <form data-signature-form data-signature-inline-form>
                <div data-signature-field data-signature-mode="witness" id="renter">
                    <input type="hidden" name="renter_signature_source" value="new">
                    <input type="text" name="renter_signer_name">
                    <input type="hidden" name="renter_signature_data" value="">
                    <canvas data-signature-pad width="600" height="200"></canvas>
                    <button type="button" data-signature-clear>Effacer</button>
                </div>
                <div data-signature-field data-signature-mode="new" id="manager">
                    <input type="hidden" name="signature_source" value="new">
                    <input type="hidden" name="signature_data" value="">
                    <canvas data-signature-pad width="900" height="300"></canvas>
                    <button type="button" data-signature-clear>Effacer</button>
                    <input type="file" name="signature_file">
                </div>
            </form>`;
        const element = /** @type {HTMLFormElement} */ (document.querySelector('form'));
        /** @param {string} id @param {string} png */
        const pad = (id, png) => {
            const container = /** @type {HTMLElement} */ (element.querySelector('#' + id));
            const canvas = /** @type {HTMLCanvasElement} */ (container.querySelector('canvas'));
            const context = {
                beginPath: vi.fn(), moveTo: vi.fn(), lineTo: vi.fn(), stroke: vi.fn(), clearRect: vi.fn(),
            };
            canvas.getContext = /** @type {any} */ (() => context);
            canvas.toDataURL = () => png;
            return { container, canvas, context };
        };
        const renter = pad('renter', 'data:image/png;base64,RENTER');
        const manager = pad('manager', 'data:image/png;base64,MANAGER');
        /** @param {string} name */
        const field = (name) => /** @type {HTMLInputElement} */ (element.querySelector(`input[name="${name}"]`));
        return { element, renter, manager, field };
    }

    /** @param {HTMLCanvasElement} canvas */
    function trace(canvas) {
        canvas.dispatchEvent(new MouseEvent('pointerdown', { clientX: 1, clientY: 1 }));
        canvas.dispatchEvent(new MouseEvent('pointermove', { clientX: 5, clientY: 5 }));
        canvas.dispatchEvent(new MouseEvent('pointerup'));
    }

    it('gives each signer a pad of its own, exported into its own hidden field', () => {
        const { element, renter, manager, field } = form();
        wirePad(element);

        trace(renter.canvas);
        element.dispatchEvent(new Event('submit'));

        expect(field('renter_signature_data').value).toBe('data:image/png;base64,RENTER');
        expect(field('signature_data').value).toBe('');
        expect(manager.context.lineTo).not.toHaveBeenCalled();

        trace(manager.canvas);
        element.dispatchEvent(new Event('submit'));
        expect(field('renter_signature_data').value).toBe('data:image/png;base64,RENTER');
        expect(field('signature_data').value).toBe('data:image/png;base64,MANAGER');
    });

    it('takes no ink from a signer set aside, and takes it again once they are back', () => {
        const { element, renter, field } = form();
        const fieldset = document.createElement('fieldset');
        renter.container.replaceWith(fieldset);
        fieldset.append(renter.container);
        fieldset.disabled = true;
        wirePad(element);

        trace(renter.canvas);
        expect(renter.context.lineTo).not.toHaveBeenCalled();

        fieldset.disabled = false;
        trace(renter.canvas);
        expect(renter.context.lineTo).toHaveBeenCalled();
        element.dispatchEvent(new Event('submit'));
        expect(field('renter_signature_data').value).toBe('data:image/png;base64,RENTER');
    });

    it('lets « Effacer » forget one signer’s drawing and keep the other’s', () => {
        const { element, renter, manager, field } = form();
        wirePad(element);
        trace(renter.canvas);
        trace(manager.canvas);

        /** @type {HTMLElement} */ (renter.container.querySelector('[data-signature-clear]')).click();
        element.dispatchEvent(new Event('submit'));

        expect(field('renter_signature_data').value).toBe('');
        expect(field('signature_data').value).toBe('data:image/png;base64,MANAGER');
        expect(manager.context.clearRect).not.toHaveBeenCalled();
    });

    it('empties every pad when the form is sent signature-pad:reset, one pad when its container is', () => {
        const { element, renter, manager, field } = form();
        wirePad(element);
        trace(renter.canvas);
        trace(manager.canvas);

        renter.container.dispatchEvent(new CustomEvent('signature-pad:reset'));
        element.dispatchEvent(new Event('submit'));
        expect(field('renter_signature_data').value).toBe('');
        expect(field('signature_data').value).toBe('data:image/png;base64,MANAGER');

        element.dispatchEvent(new CustomEvent('signature-pad:reset'));
        expect(renter.context.clearRect).toHaveBeenCalledWith(0, 0, 600, 200);
        expect(manager.context.clearRect).toHaveBeenCalledWith(0, 0, 900, 300);
        element.dispatchEvent(new Event('submit'));
        expect(field('signature_data').value).toBe('');
    });

    it('hands back what empties both pads, for a page restored from the back-forward cache', () => {
        const { element, renter, manager, field } = form();
        const reset = wirePad(element);
        trace(renter.canvas);
        trace(manager.canvas);
        element.dispatchEvent(new Event('submit'));

        reset();

        expect(field('renter_signature_data').value).toBe('');
        expect(field('signature_data').value).toBe('');
        expect(renter.context.clearRect).toHaveBeenCalled();
        expect(manager.context.clearRect).toHaveBeenCalled();
    });

    it('wires each container once, however often the form is asked', () => {
        const { element, renter, manager } = form();

        expect(wirePad(element)).not.toBeNull();
        expect(wirePad(element)).toBeNull();
        expect(renter.container.dataset.signaturePadWired).toBe('1');
        expect(manager.container.dataset.signaturePadWired).toBe('1');
        trace(renter.canvas);
        expect(renter.context.lineTo).toHaveBeenCalledOnce();
    });

    it('wires only the signer that has a pad: a stored signature has none', () => {
        const { element, manager } = form();
        manager.container.innerHTML = '<input type="hidden" name="signature_source" value="stored"><div data-signature-preview></div>';

        expect(wirePad(element)).not.toBeNull();
        expect(manager.container.dataset.signaturePadWired).toBeUndefined();
    });
});
