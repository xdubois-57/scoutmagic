/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * The drawing pad of « Ma signature » (#708, IT-16): a canvas traced with a
 * finger, a stylus or a mouse, posted as the PNG it draws.
 *
 * Pointer events, so one code path serves touch and mouse alike, and
 * `touch-action: none` on the canvas (set here) so tracing on a phone draws
 * rather than scrolls the page. Nothing is kept in the browser: the PNG
 * goes into a hidden field only when the form is sent, and the server
 * re-encodes and encrypts it.
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
     * field on submit — only when something was drawn, so an untouched pad
     * leaves room for an imported image.
     *
     * @param {HTMLFormElement} form
     */
    function wirePad(form) {
        const canvas = /** @type {HTMLCanvasElement|null} */ (form.querySelector('[data-signature-pad]'));
        const field = /** @type {HTMLInputElement|null} */ (form.querySelector('input[name="signature_data"]'));
        const clear = form.querySelector('[data-signature-clear]');
        const context = canvas ? canvas.getContext('2d') : null;
        if (canvas === null || field === null || context === null) {
            return;
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

        clear?.addEventListener('click', () => {
            context.clearRect(0, 0, canvas.width, canvas.height);
            drawn = false;
            field.value = '';
        });

        form.addEventListener('submit', () => {
            field.value = drawn ? canvas.toDataURL('image/png') : '';
        });
    }

    document.querySelectorAll('form[data-signature-form]').forEach((form) => {
        wirePad(/** @type {HTMLFormElement} */ (form));
    });

    // --- Test seam (no behavioural effect) ---------------------------------
    // A classic script (AGENTS.md § CSS / frontend): everything above is
    // private to this IIFE. ONE namespaced global lets
    // tests/js/rental-signature.test.js reach the two functions directly —
    // same precedent as auth.js's ScoutMagicAuthInternals.
    //
    // Test-only: nothing in production reads this.
    globalThis.ScoutMagicRentalSignatureInternals = {
        canvasPoint: canvasPoint,
        wirePad: wirePad,
    };
})();
