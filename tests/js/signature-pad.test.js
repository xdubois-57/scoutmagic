// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// implementation in public/assets/js/signature-pad.js: the drawing pad of
// « Ma signature » under Mon compte (issue #926).
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
