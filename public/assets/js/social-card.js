/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The card that is published, drawn in the browser (issue #706, IT-02).
//
// **One engine, not two.** Until IT-02 the server composed the card with
// GD (`Modules\Social\Card\CardRenderer`) and the composer showed the
// result as an <img>: the preview was a round trip, and « what you see is
// what leaves » held only because the same code ran twice. Now the browser
// draws the preview AND the image that is published — the canvas is
// exported at « Publier » and posted with the form — so there is one
// composition and it is the one on screen. The server stops composing and
// only CHECKS what arrives (format, the exact card dimensions, weight);
// `CardRenderer` leaves the publication path.
//
// That is not a new power, and it is worth saying plainly: a chief can
// already upload any image they like. What they could not do before was
// see the blur they were choosing, because there was nothing to choose.
//
// **The geometry is the server's, to the pixel**, because the cards
// already published must not become a different shape: 1080 square, a
// 6 % margin, a veil from 35 % of the height growing towards the foot, a
// 66 px title of at most three lines laid bottom-up above a 34 px
// address. The constants below are `CardRenderer`'s, converted where the
// two drawing models differ — GD's alpha runs 127 (transparent) to 0
// (opaque), canvas's globalAlpha runs 0 to 1 — and `cardConstants` exposes
// them so a test can state the correspondence rather than restate the
// numbers.
//
// **The blur will not match GD's pixel for pixel**, and it does not have
// to: GD reaches a wide radius by shrinking, smoothing with a 3×3 kernel
// and growing back, while the browser has a real Gaussian in
// `ctx.filter`. What is promised is the SCALE — a radius proportional to
// the square's side, so the same setting looks the same whatever the
// camera — not an identical output. The server compares no pixels.
//
// **The title's line breaking takes its measure function as an
// argument** rather than reaching for the context itself. jsdom has no 2D
// context at all (`getContext('2d')` returns null and logs « Not
// implemented »), so a wrapper that measured through `ctx` internally
// could not be unit-tested — and the line breaking is precisely what the
// chantier asks to be tested with Vitest. Injected, `wrapTitle` and
// `fitLine` are pure functions of (text, measure, width).
(function () {
    const SIZE = 1080;
    /** Inner margin, as a share of the side — 65 px at 1080, as GD rounds it. */
    const MARGIN = Math.round(SIZE * 0.06);
    const MAX_TEXT_WIDTH = SIZE - 2 * MARGIN;

    const TITLE_SIZE = 66;
    const TITLE_MAX_LINES = 3;
    const TITLE_LINE_HEIGHT = Math.round(TITLE_SIZE * 1.25);
    const ADDRESS_SIZE = 34;
    /** The gap GD leaves between the address's baseline and the title's. */
    const ADDRESS_TO_TITLE = Math.round(ADDRESS_SIZE * 1.9);

    /** Where the veil starts, and how opaque it is at the foot. */
    const VEIL_START_Y = Math.trunc(SIZE * 0.35);
    // GD's VEIL_ALPHA is 70 on its 127-transparent scale; the same
    // darkness as a canvas alpha is (127 - 70) / 127.
    const VEIL_FOOT_ALPHA = (127 - 70) / 127;

    /** Behind a photo that does not cover the square — GD fills the same grey. */
    const BACKDROP = 'rgb(73, 80, 87)';
    const TITLE_COLOUR = 'rgb(255, 255, 255)';
    const ADDRESS_COLOUR = 'rgb(230, 233, 236)';

    const JPEG_QUALITY = 0.88;
    const FONT_FAMILY = '"ScoutMagic Card", "DejaVu Sans", sans-serif';

    /**
     * One line, shortened with an ellipsis until it fits.
     *
     * `forceEllipsis` is what the three-line cap uses: the line already
     * fits, and has to end in « … » anyway to say the title goes on.
     *
     * @param {string} line
     * @param {(text: string) => number} measure
     * @param {number} maxWidth
     * @param {boolean} [forceEllipsis]
     * @returns {string}
     */
    function fitLine(line, measure, maxWidth, forceEllipsis) {
        if (!forceEllipsis && measure(line) <= maxWidth) {
            return line;
        }

        // A line the cap already ended with « … » must not grow a second
        // one, so the existing ellipsis and the space before it go first.
        //
        // `trimEnd()` rather than `/\s+$/`: a greedy quantifier anchored
        // at the end backtracks from every start position, which is
        // quadratic on a run of spaces and cubic inside this loop. The
        // title is capped at 120 characters so nothing was ever at risk
        // — but the shape is the one that bites when a cap moves, and
        // trimming without a regex is also the plainer way to say it.
        let base = line.endsWith('…') ? line.slice(0, -1) : line;
        base = base.trimEnd();
        while (base !== '' && measure(base + '…') > maxWidth) {
            base = base.slice(0, -1).trimEnd();
        }

        return base + '…';
    }

    /**
     * The title, word-wrapped to the width, at most three lines, the last
     * ending in an ellipsis when the title is longer than that.
     *
     * A single word wider than the line is not broken mid-word by the
     * wrap: it becomes its own line, and `fitLine` then cuts it with an
     * ellipsis. That is GD's behaviour too, and it is the readable one —
     * « Rassemble… » says more than « Rassembl / ement ».
     *
     * @param {string} text
     * @param {(text: string) => number} measure
     * @param {number} maxWidth
     * @returns {string[]}
     */
    function wrapTitle(text, measure, maxWidth) {
        const words = String(text).trim().split(/\s+/u).filter(function (word) {
            return word !== '';
        });
        const lines = [];
        let current = '';

        words.forEach(function (word) {
            const candidate = current === '' ? word : current + ' ' + word;
            if (current !== '' && measure(candidate) > maxWidth) {
                lines.push(current);
                current = word;
            } else {
                current = candidate;
            }
        });
        if (current !== '') {
            lines.push(current);
        }

        const fitted = lines.map(function (line) {
            return fitLine(line, measure, maxWidth, false);
        });
        if (fitted.length <= TITLE_MAX_LINES) {
            return fitted;
        }

        const capped = fitted.slice(0, TITLE_MAX_LINES);
        const last = TITLE_MAX_LINES - 1;
        capped[last] = fitLine(capped[last] + ' …', measure, maxWidth, true);

        return capped;
    }

    /**
     * The source image, centre-cropped to a square and drawn to fill the
     * card — which is also the « reduce before blurring » the chantier
     * asks for: a multi-megapixel photo is down to 1080 before the filter
     * ever runs, and blurring it at full size exhausts a phone's memory.
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {HTMLImageElement|ImageBitmap|HTMLCanvasElement} image
     * @param {number} blurRatio
     */
    function drawBackground(ctx, image, blurRatio) {
        ctx.save();
        ctx.fillStyle = BACKDROP;
        ctx.fillRect(0, 0, SIZE, SIZE);

        const width = image.width;
        const height = image.height;
        if (width > 0 && height > 0) {
            const side = Math.min(width, height);
            const sx = Math.trunc((width - side) / 2);
            const sy = Math.trunc((height - side) / 2);
            // The radius is a share of the SQUARE's side, never pixels: a
            // fixed radius barely veils a 4000 px photo and wipes out an
            // 800 px one. Below 1 px there is nothing to see, which is
            // what « Net » means — and there is no floor above that any
            // more (issue #706, IT-02).
            const radius = Math.max(0, Number(blurRatio) || 0) * SIZE;
            ctx.filter = radius >= 1 ? 'blur(' + radius + 'px)' : 'none';
            ctx.drawImage(image, sx, sy, side, side, 0, 0, SIZE, SIZE);
        }
        ctx.restore();
    }

    /**
     * A darkening that grows towards the foot, where the text sits, so a
     * white title stays legible over a white sky.
     *
     * @param {CanvasRenderingContext2D} ctx
     */
    function drawVeil(ctx) {
        ctx.save();
        ctx.filter = 'none';
        const gradient = ctx.createLinearGradient(0, VEIL_START_Y, 0, SIZE);
        gradient.addColorStop(0, 'rgba(0, 0, 0, 0)');
        gradient.addColorStop(1, 'rgba(0, 0, 0, ' + VEIL_FOOT_ALPHA + ')');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, VEIL_START_Y, SIZE, SIZE - VEIL_START_Y);
        ctx.restore();
    }

    /**
     * The address at the foot, then the title above it, laid out from the
     * bottom up — the same order and the same baselines as GD, so a card
     * drawn here sits where the published ones already do.
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {string} title
     * @param {string} address
     */
    function drawText(ctx, title, address) {
        ctx.save();
        ctx.filter = 'none';
        ctx.textBaseline = 'alphabetic';
        ctx.textAlign = 'left';

        let baseline = SIZE - MARGIN;
        const trimmedAddress = String(address || '').trim();
        if (trimmedAddress !== '') {
            ctx.font = ADDRESS_SIZE + 'px ' + FONT_FAMILY;
            ctx.fillStyle = ADDRESS_COLOUR;
            const measureAddress = measurer(ctx);
            ctx.fillText(fitLine(trimmedAddress, measureAddress, MAX_TEXT_WIDTH, false), MARGIN, baseline);
            baseline -= ADDRESS_TO_TITLE;
        }

        ctx.font = TITLE_SIZE + 'px ' + FONT_FAMILY;
        ctx.fillStyle = TITLE_COLOUR;
        const lines = wrapTitle(String(title || ''), measurer(ctx), MAX_TEXT_WIDTH);
        lines.slice().reverse().forEach(function (line) {
            ctx.fillText(line, MARGIN, baseline);
            baseline -= TITLE_LINE_HEIGHT;
        });
        ctx.restore();
    }

    /**
     * The measure function the pure helpers take, bound to a context whose
     * `font` is already set.
     *
     * @param {CanvasRenderingContext2D} ctx
     * @returns {(text: string) => number}
     */
    function measurer(ctx) {
        return function (text) {
            return ctx.measureText(text).width;
        };
    }

    /**
     * Draws the whole card into `canvas`, which is sized to the card.
     *
     * Returns false when the browser gives no 2D context — which is a real
     * state, not a theoretical one, and the caller has to keep the form
     * usable rather than publish a blank square.
     *
     * @param {HTMLCanvasElement} canvas
     * @param {{image: (HTMLImageElement|ImageBitmap|HTMLCanvasElement|null), title: string, address: string, blurRatio: number}} card
     * @returns {boolean}
     */
    function drawCard(canvas, card) {
        if (!canvas || typeof canvas.getContext !== 'function') {
            return false;
        }
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return false;
        }

        canvas.width = SIZE;
        canvas.height = SIZE;
        if (card?.image) {
            drawBackground(ctx, card.image, card.blurRatio);
        } else {
            ctx.save();
            ctx.fillStyle = BACKDROP;
            ctx.fillRect(0, 0, SIZE, SIZE);
            ctx.restore();
        }
        drawVeil(ctx);
        drawText(ctx, card ? card.title : '', card ? card.address : '');

        return true;
    }

    /**
     * The card as the JPEG that is published, at the server's own quality.
     *
     * @param {HTMLCanvasElement} canvas
     * @returns {Promise<Blob|null>}
     */
    function toJpeg(canvas) {
        return new Promise(function (resolve) {
            if (!canvas || typeof canvas.toBlob !== 'function') {
                resolve(null);

                return;
            }
            try {
                canvas.toBlob(function (blob) {
                    resolve(blob || null);
                }, 'image/jpeg', JPEG_QUALITY);
            } catch (error) {
                resolve(null);
            }
        });
    }

    /**
     * The geometry, so a test can pin the correspondence with
     * `CardRenderer`'s constants instead of repeating the numbers — a
     * second copy of them would be free to drift.
     */
    function cardConstants() {
        return {
            size: SIZE,
            margin: MARGIN,
            maxTextWidth: MAX_TEXT_WIDTH,
            titleSize: TITLE_SIZE,
            titleMaxLines: TITLE_MAX_LINES,
            titleLineHeight: TITLE_LINE_HEIGHT,
            addressSize: ADDRESS_SIZE,
            addressToTitle: ADDRESS_TO_TITLE,
            veilStartY: VEIL_START_Y,
            veilFootAlpha: VEIL_FOOT_ALPHA,
            backdrop: BACKDROP,
            jpegQuality: JPEG_QUALITY,
        };
    }

    // One literal, like public/assets/js/map.js: nothing else
    // contributes to this namespace, and the defensive
    // `window.X = window.X || {}` of the shared namespaces would only
    // widen the type to `{} | {…}` for no one's benefit.
    window.ScoutMagicCard = {
        wrapTitle: wrapTitle,
        fitLine: fitLine,
        drawCard: drawCard,
        toJpeg: toJpeg,
        cardConstants: cardConstants,
    };
}());
