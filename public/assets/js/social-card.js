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

    /**
     * **These two are POINT sizes, because `imagettftext()` takes
     * points.** GD renders at 96 dpi, so the size it is handed draws an
     * em of `size * 96 / 72` pixels: 66 draws an em of 88 px, not 66.
     * Canvas font sizes are CSS pixels, so copying the literal across
     * drew the card's text a quarter too small — and `wrapTitle()`
     * measured with that smaller face, so the line breaks and the
     * three-line cap diverged too. Found in the review of #850.
     *
     * Measured in GD's own terms rather than reasoned from a font's
     * metrics: the same string is 1704 px wide at size 66 and 1276 px at
     * size 49.5 (= 66 × 72/96), a ratio of 1.335 against the 1.333 the
     * conversion predicts — the remainder being per-glyph hinting.
     * `CardGeometryAgreementTest` measures it on every run.
     *
     * **Only the size converts.** Every other number `CardRenderer`
     * hands GD — the margin, the baselines, the line height, the veil —
     * is already in pixels.
     */
    const POINTS_TO_PIXELS = 96 / 72;
    const TITLE_SIZE = 66;
    const TITLE_PIXELS = TITLE_SIZE * POINTS_TO_PIXELS;
    const TITLE_MAX_LINES = 3;
    const TITLE_LINE_HEIGHT = Math.round(TITLE_SIZE * 1.25);
    const ADDRESS_SIZE = 34;
    const ADDRESS_PIXELS = ADDRESS_SIZE * POINTS_TO_PIXELS;
    /** The gap GD leaves between the address's baseline and the title's. */
    const ADDRESS_TO_TITLE = Math.round(ADDRESS_SIZE * 1.9);

    /** Where the veil starts, and how opaque it is at the foot. */
    const VEIL_START_Y = Math.trunc(SIZE * 0.35);
    // GD's VEIL_ALPHA is 70 on its 127-transparent scale; the same
    // darkness as a canvas alpha is (127 - 70) / 127.
    const VEIL_FOOT_ALPHA = (127 - 70) / 127;

    /** Behind a photo that does not cover the square — GD fills the same grey. */
    const BACKDROP = 'rgb(73, 80, 87)';

    /**
     * How far past the square a blurred draw has to reach, in multiples
     * of the radius.
     *
     * **`ctx.filter` filters onto an infinite TRANSPARENT surface and
     * composites the result afterwards.** So a photo drawn exactly edge
     * to edge has its own alpha ramped down at the square's border, and
     * the opaque backdrop beneath shows through as a grey vignette —
     * widening with the slider until it dominates the card, and baked
     * into the JPEG that is published. GD does nothing of the kind:
     * `imagefilter()` works on the bitmap itself and clamps at its
     * edges. Found in the review of #850.
     *
     * Three radii, because `blur(Npx)` is a Gaussian of sigma N and
     * three sigmas is both its practical support and the reach of the
     * box-blur passes browsers approximate it with.
     */
    const BLUR_REACH = 3;

    /**
     * Safari has no `CanvasRenderingContext2D.filter` (issue #928): the
     * assignment is accepted — it just sets an ordinary property — and
     * every draw after it is sharp. So the card looked blurred to nobody
     * and, worse, was EXPORTED sharp: a gallery photo leaving unblurred
     * while the slider said otherwise. The capability is therefore tested
     * by what it does to pixels ({@see probeFilterBlur}), never by the
     * property being there.
     *
     * Where the filter does not work the photo is blurred by hand
     * ({@see blurPixels}): reduced first, so the work is a few hundred
     * thousand pixels and not a million, then blurred with three box
     * passes — the textbook approximation of a Gaussian — and grown back.
     * That is what GD does on the server, in the same order.
     */
    const PROBE_SIZE = 16;
    /** The probe's blur radius, and where a pixel that moved is looked for. */
    const PROBE_RADIUS = 2;
    /** The reduction aims at a work radius of about this many pixels. */
    const PIXEL_BLUR_WORK_RADIUS = 2;
    /** Never reduce further than this, or the growing back shows its grid. */
    const PIXEL_BLUR_MAX_SHRINK = 8;
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
            // **One CODE POINT at a time, as `mb_substr($base, 0, -1)`
            // does on the PHP side.** `slice(0, -1)` walks back one
            // UTF-16 code unit, so a cut landing inside a surrogate pair
            // — every emoji — dropped the low half and left the high one
            // orphaned. A lone surrogate measures and draws as U+FFFD,
            // and narrower than the emoji it came from, so the loop then
            // EXITED with it in place and the tofu was baked into the
            // published JPEG where GD had cleanly dropped the whole
            // character. Found in the review of #850; the pair of
            // renderers agreeing is what `CardGeometryAgreementTest` is
            // for, and a literal-for-literal comparison cannot see a
            // difference of unit.
            base = Array.from(base).slice(0, -1).join('').trimEnd();
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
     * Whether `ctx.filter` really blurs in this browser, tested on a
     * 16 px canvas: a 4 px black square drawn through `blur(2px)` leaves
     * some alpha two pixels outside itself, and the same draw through a
     * browser that ignores the filter leaves exactly none.
     *
     * Any failure to run the probe — no second context, pixels that cannot
     * be read back — answers false, because every consumer of the answer
     * has a way of blurring that does not depend on it.
     *
     * @returns {boolean}
     */
    function probeFilterBlur() {
        filterBlurs = false;
        try {
            const probe = document.createElement('canvas');
            probe.width = PROBE_SIZE;
            probe.height = PROBE_SIZE;
            const ctx = probe.getContext('2d', { willReadFrequently: true });
            if (ctx) {
                ctx.filter = 'blur(' + PROBE_RADIUS + 'px)';
                ctx.fillStyle = 'rgb(0, 0, 0)';
                ctx.fillRect(6, 6, 4, 4);
                filterBlurs = ctx.getImageData(6 - PROBE_RADIUS, 8, 1, 1).data[3] > 0;
            }
        } catch (error) {
            filterBlurs = false;
        }

        return filterBlurs;
    }

    /** @type {boolean|null} The probe's answer, asked once per page. */
    let filterBlurs = null;

    /**
     * Which way this page blurs: the browser's own filter when it has one,
     * otherwise by hand.
     *
     * @returns {'filter'|'pixels'}
     */
    function blurEngine() {
        return (filterBlurs === null ? probeFilterBlur() : filterBlurs) ? 'filter' : 'pixels';
    }

    /**
     * The source image, centre-cropped to a square and drawn to fill the
     * card — which is also the « reduce before blurring » the chantier
     * asks for: a multi-megapixel photo is down to 1080 before the filter
     * ever runs, and blurring it at full size exhausts a phone's memory.
     *
     * Answers false only when the photo was meant to be blurred and could
     * not be: the caller must then draw nothing rather than a sharp card.
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {HTMLImageElement|ImageBitmap|HTMLCanvasElement} image
     * @param {number} blurRatio
     * @param {'filter'|'pixels'|undefined} [engine] forced by a test; the page lets the probe decide
     * @returns {boolean}
     */
    function drawBackground(ctx, image, blurRatio, engine) {
        let drawn = true;
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
            if (radius < 1) {
                // « Net ». Nothing is filtered, so nothing needs the
                // padding below either.
                ctx.filter = 'none';
                ctx.drawImage(image, sx, sy, side, side, 0, 0, SIZE, SIZE);
            } else if ((engine || blurEngine()) === 'filter') {
                drawBlurred(ctx, image, sx, sy, side, radius);
            } else {
                drawn = blurByPixels(ctx, image, sx, sy, side, radius);
            }
        }
        ctx.restore();

        return drawn;
    }

    /**
     * The crop, blurred, with its own edges carried out past the square
     * so that the filter's alpha ramp falls OUTSIDE what is published
     * ({@see BLUR_REACH}).
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {HTMLImageElement|ImageBitmap|HTMLCanvasElement} image
     * @param {number} sx
     * @param {number} sy
     * @param {number} side
     * @param {number} radius
     */
    function drawBlurred(ctx, image, sx, sy, side, radius) {
        const pad = Math.ceil(radius * BLUR_REACH);
        const padded = paddedCrop(image, sx, sy, side, pad);
        ctx.filter = 'blur(' + radius + 'px)';
        if (padded === null) {
            // No second context to build the padding in. The vignette
            // comes back, and that is still the better of the two: a
            // card drawn without the blur would send a gallery photo out
            // sharp, which is the one thing the slider must never do by
            // accident.
            ctx.drawImage(image, sx, sy, side, side, 0, 0, SIZE, SIZE);

            return;
        }

        const full = SIZE + 2 * pad;
        ctx.drawImage(padded, 0, 0, full, full, -pad, -pad, full, full);
    }

    /**
     * The crop on a square canvas `pad` larger on every side, the margin
     * filled by stretching the crop's outermost row and column outwards
     * — the same edge clamping GD's filter does, so the blur smears real
     * colour into the margin rather than transparency.
     *
     * The crop is scaled down into the buffer first, so « reduce before
     * blurring » still holds: a multi-megapixel photo never reaches the
     * filter at full size. The buffer itself grows with the radius, to
     * 2376 px square at the slider's far end — well under the photo it
     * replaces.
     *
     * @param {HTMLImageElement|ImageBitmap|HTMLCanvasElement} image
     * @param {number} sx
     * @param {number} sy
     * @param {number} side
     * @param {number} pad
     * @returns {HTMLCanvasElement|null}
     */
    function paddedCrop(image, sx, sy, side, pad) {
        const buffer = document.createElement('canvas');
        const full = SIZE + 2 * pad;
        buffer.width = full;
        buffer.height = full;
        const edge = buffer.getContext('2d');
        if (!edge) {
            return null;
        }

        edge.drawImage(image, sx, sy, side, side, pad, pad, SIZE, SIZE);

        const last = side - 1;
        // The four sides, from one row or column each.
        edge.drawImage(image, sx, sy, 1, side, 0, pad, pad, SIZE);
        edge.drawImage(image, sx + last, sy, 1, side, pad + SIZE, pad, pad, SIZE);
        edge.drawImage(image, sx, sy, side, 1, pad, 0, SIZE, pad);
        edge.drawImage(image, sx, sy + last, side, 1, pad, pad + SIZE, SIZE, pad);
        // The four corners, from one pixel each.
        edge.drawImage(image, sx, sy, 1, 1, 0, 0, pad, pad);
        edge.drawImage(image, sx + last, sy, 1, 1, pad + SIZE, 0, pad, pad);
        edge.drawImage(image, sx, sy + last, 1, 1, 0, pad + SIZE, pad, pad);
        edge.drawImage(image, sx + last, sy + last, 1, 1, pad + SIZE, pad + SIZE, pad, pad);

        return buffer;
    }

    /**
     * The crop blurred without `ctx.filter`: reduced, blurred in place
     * with {@see blurPixels}, grown back.
     *
     * The reduction is what makes this affordable on a phone — at the
     * default strength the work area is 135 px square — and it is also
     * exact enough: a blur of radius R on the full square is a blur of
     * R / k on a copy k times smaller. Edges are clamped by the blur
     * itself, so there is no vignette to pad against.
     *
     * Answers false when the pixels cannot be read or written (a context
     * that will not give them up, memory refused): the one outcome that
     * must not be papered over with a sharp draw.
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {HTMLImageElement|ImageBitmap|HTMLCanvasElement} image
     * @param {number} sx
     * @param {number} sy
     * @param {number} side
     * @param {number} radius
     * @returns {boolean}
     */
    function blurByPixels(ctx, image, sx, sy, side, radius) {
        const shrink = Math.min(
            PIXEL_BLUR_MAX_SHRINK,
            Math.max(1, Math.floor(radius / PIXEL_BLUR_WORK_RADIUS))
        );
        const work = Math.ceil(SIZE / shrink);
        try {
            const buffer = document.createElement('canvas');
            buffer.width = work;
            buffer.height = work;
            const edge = buffer.getContext('2d', { willReadFrequently: true });
            if (!edge) {
                return false;
            }
            edge.drawImage(image, sx, sy, side, side, 0, 0, work, work);
            const pixels = edge.getImageData(0, 0, work, work);
            blurPixels(pixels.data, work, work, radius * work / SIZE);
            edge.putImageData(pixels, 0, 0);

            ctx.filter = 'none';
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(buffer, 0, 0, work, work, 0, 0, SIZE, SIZE);
        } catch (error) {
            return false;
        }

        return true;
    }

    /**
     * A Gaussian of standard deviation `sigma`, as three box blurs along
     * each axis, applied in place to RGBA bytes. Edges repeat the outermost
     * pixel, which is what GD does and what keeps the border from
     * darkening.
     *
     * Three boxes of width w approximate a Gaussian of sigma
     * sqrt((w² − 1) / 4) — hence the width below.
     *
     * @param {Uint8ClampedArray} pixels
     * @param {number} width
     * @param {number} height
     * @param {number} sigma
     */
    function blurPixels(pixels, width, height, sigma) {
        const half = Math.max(1, Math.round((Math.sqrt(4 * sigma * sigma + 1) - 1) / 2));
        const scratch = new Uint8ClampedArray(pixels.length);
        // Rows into the scratch, columns back: each pass ends in `pixels`.
        for (let pass = 0; pass < 3; pass++) {
            boxBlurAxis(pixels, scratch, width, height, half, true);
            boxBlurAxis(scratch, pixels, width, height, half, false);
        }
    }

    /**
     * One box blur, along rows or columns, with a running sum: the cost
     * does not grow with the radius.
     *
     * @param {Uint8ClampedArray} from
     * @param {Uint8ClampedArray} to
     * @param {number} width
     * @param {number} height
     * @param {number} half
     * @param {boolean} horizontal
     */
    function boxBlurAxis(from, to, width, height, half, horizontal) {
        const lines = horizontal ? height : width;
        const length = horizontal ? width : height;
        const step = horizontal ? 4 : width * 4;
        const lineStep = horizontal ? width * 4 : 4;
        const span = 2 * half + 1;
        const last = length - 1;
        for (let line = 0; line < lines; line++) {
            const base = line * lineStep;
            for (let channel = 0; channel < 4; channel++) {
                const at = function (index) {
                    return from[base + Math.min(Math.max(index, 0), last) * step + channel];
                };
                let sum = 0;
                for (let k = -half; k <= half; k++) {
                    sum += at(k);
                }
                for (let i = 0; i < length; i++) {
                    to[base + i * step + channel] = Math.round(sum / span);
                    sum += at(i + half + 1) - at(i - half);
                }
            }
        }
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
            ctx.font = ADDRESS_PIXELS + 'px ' + FONT_FAMILY;
            ctx.fillStyle = ADDRESS_COLOUR;
            const measureAddress = measurer(ctx);
            ctx.fillText(fitLine(trimmedAddress, measureAddress, MAX_TEXT_WIDTH, false), MARGIN, baseline);
            baseline -= ADDRESS_TO_TITLE;
        }

        ctx.font = TITLE_PIXELS + 'px ' + FONT_FAMILY;
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
     * It also returns false when the photo had to be blurred and the
     * browser could not do it, by either means: nothing is drawn then, so
     * that no sharp card is ever mistaken for a blurred one (issue #928).
     *
     * @param {{image: (HTMLImageElement|ImageBitmap|HTMLCanvasElement|null), title: string, address: string, blurRatio: number, blurEngine?: ('filter'|'pixels')}} card
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
            if (!drawBackground(ctx, card.image, card.blurRatio, card.blurEngine)) {
                return false;
            }
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
            // `titleSize` and `addressSize` are the POINT sizes GD is
            // handed; `titlePixels` and `addressPixels` are what the
            // canvas draws with.
            titleSize: TITLE_SIZE,
            titlePixels: TITLE_PIXELS,
            titleMaxLines: TITLE_MAX_LINES,
            titleLineHeight: TITLE_LINE_HEIGHT,
            addressSize: ADDRESS_SIZE,
            addressPixels: ADDRESS_PIXELS,
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
        probeFilterBlur: probeFilterBlur,
        blurEngine: blurEngine,
        blurPixels: blurPixels,
    };
}());
