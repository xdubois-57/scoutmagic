/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * The drawing pad of a personal signature (issue #926; generalised from
 * rental-signature.js, #708 IT-16): a canvas traced with a finger, a
 * stylus or a mouse, posted as the PNG it draws.
 *
 * Pointer events, so one code path serves touch and mouse alike, and
 * `touch-action: none` on the canvas (set here) so tracing on a phone draws
 * rather than scrolls the page.
 *
 * **Nothing but the picture leaves, and nothing stays.** The pad keeps no
 * point, no timing and no pressure — only the canvas, exported with
 * `toDataURL()` into a hidden field when the form is sent, and only when
 * something was drawn, so an untouched pad leaves room for an imported
 * image. The server re-encodes and encrypts it. A page brought back from
 * the browser's back-forward cache comes back with the pad and the field
 * emptied: a signature drawn and abandoned is not left on screen for the
 * next person holding the phone.
 *
 * Without this script the pad is inert and the import field still works:
 * the page says both ways, and the server accepts either.
 */

(function () {
    'use strict';

    /**
     * Where a pointer event lands on the canvas, in the canvas's own pixels —
     * its CSS size and its drawing size differ on any high-density screen.
     *
     * @param {{ clientX: number, clientY: number }} event
     * @param {{ left: number, top: number, width: number, height: number }} rect
     * @param {{ width: number, height: number }} canvas
     * @returns {{ x: number, y: number }}
     */
    function canvasPoint(event, rect, canvas) {
        const scaleX = rect.width > 0 ? canvas.width / rect.width : 1;
        const scaleY = rect.height > 0 ? canvas.height / rect.height : 1;

        return {
            x: (event.clientX - rect.left) * scaleX,
            y: (event.clientY - rect.top) * scaleY,
        };
    }

    /**
     * Wires one pad: drawing, « Effacer », and the PNG put into the hidden
     * field on submit — only when something was drawn.
     *
     * @param {HTMLFormElement} form
     * @returns {(() => void)|null} what empties the pad and the field, or
     *          null when the form carries no pad
     */
    function wirePad(form) {
        const canvas = /** @type {HTMLCanvasElement|null} */ (form.querySelector('[data-signature-pad]'));
        const field = /** @type {HTMLInputElement|null} */ (form.querySelector('input[name="signature_data"]'));
        const clear = form.querySelector('[data-signature-clear]');
        const context = canvas ? canvas.getContext('2d') : null;
        if (canvas === null || field === null || context === null) {
            return null;
        }

        canvas.style.touchAction = 'none';
        context.lineWidth = 3;
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.strokeStyle = '#1a1a1a';

        let drawing = false;
        let drawn = false;

        /** @param {PointerEvent} event */
        const point = (event) => canvasPoint(event, canvas.getBoundingClientRect(), canvas);

        canvas.addEventListener('pointerdown', (event) => {
            drawing = true;
            drawn = true;
            canvas.setPointerCapture?.(event.pointerId);
            const at = point(event);
            context.beginPath();
            context.moveTo(at.x, at.y);
            event.preventDefault();
        });
        canvas.addEventListener('pointermove', (event) => {
            if (!drawing) {
                return;
            }
            const at = point(event);
            context.lineTo(at.x, at.y);
            context.stroke();
            event.preventDefault();
        });
        const stop = () => {
            drawing = false;
        };
        canvas.addEventListener('pointerup', stop);
        canvas.addEventListener('pointercancel', stop);
        canvas.addEventListener('pointerleave', stop);

        const reset = () => {
            context.clearRect(0, 0, canvas.width, canvas.height);
            drawing = false;
            drawn = false;
            field.value = '';
        };
        clear?.addEventListener('click', reset);

        form.addEventListener('submit', () => {
            field.value = drawn ? canvas.toDataURL('image/png') : '';
        });

        return reset;
    }

    /** @type {Array<() => void>} */
    const resets = [];
    document.querySelectorAll('form[data-signature-form]').forEach((form) => {
        const reset = wirePad(/** @type {HTMLFormElement} */ (form));
        if (reset !== null) {
            resets.push(reset);
        }
    });

    // A page restored from the back-forward cache keeps its canvas pixels
    // and its field values: empty both.
    window.addEventListener('pageshow', (event) => {
        if (/** @type {PageTransitionEvent} */ (event).persisted) {
            resets.forEach((reset) => reset());
        }
    });

    // --- Test seam (no behavioural effect) ---------------------------------
    // A classic script (AGENTS.md § CSS / frontend): everything above is
    // private to this IIFE. ONE namespaced global lets
    // tests/js/signature-pad.test.js reach the two functions directly —
    // same precedent as auth.js's ScoutMagicAuthInternals.
    //
    // Test-only: nothing in production reads this.
    globalThis.ScoutMagicSignaturePadInternals = {
        canvasPoint: canvasPoint,
        wirePad: wirePad,
    };
})();
