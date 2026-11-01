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
// **The geometry is the server's, to the pixel**, so that the preview, the
// fallback and the published card are one card: 1080 square, the text
// block centred inside the zone every common crop keeps (issue #929), a
// veil that is a band behind it, a title of at most three lines laid
// bottom-up above the address. The constants below are `CardRenderer`'s, converted where the
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
    /**
     * **The text lives in the zone every common crop keeps** (issue #929).
     *
     * One square goes to every destination and each crops it, centred and
     * in « cover » mode: a discussion thread shows a 4:3 window (rows
     * 135..945), Instagram's profile grid a 3:4 one (columns 135..945), a
     * Facebook preview about 1.91:1 (rows 257..823). Their intersection is
     * columns 135..945 by rows 257..823, and the whole block sits inside it
     * with an inset. Scenarios to test against, not promises about what a
     * platform will do next.
     */
    const SAFE_LEFT = 135;
    const SAFE_BOTTOM = 823;
    /** Between the zone's edge and the text: overshoot, rounding, a slightly different crop. */
    const TEXT_INSET = 30;
    /** The zone's foot less the inset, less 13 px for a descender at the address's size. */
    const ADDRESS_BASELINE = 780;
    const MAX_TEXT_WIDTH = SIZE - 2 * SAFE_LEFT - 2 * TEXT_INSET;

    /**
     * **These two are POINT sizes, because `imagettftext()` takes
     * points.** GD renders at 96 dpi, so the size it is handed draws an
     * em of `size * 96 / 72` pixels: 56 draws an em of about 74.7 px, not 56.
     * Canvas font sizes are CSS pixels, so copying the literal across
     * drew the card's text a quarter too small — and `wrapTitle()`
     * measured with that smaller face, so the line breaks and the
     * three-line cap diverged too. Found in the review of #850.
     *
     * Measured in GD's own terms rather than reasoned from a font's
     * metrics: when the title was 66 pt, the same string was 1704 px wide
     * at size 66 and 1276 px at size 49.5 (= 66 × 72/96), a ratio of 1.335
     * against the 1.333 the conversion predicts — the remainder being
     * per-glyph hinting.
     * `CardGeometryAgreementTest` measures it on every run.
     *
     * **Only the size converts.** Every other number `CardRenderer`
     * hands GD — the inset, the baselines, the line height, the veil —
     * is already in pixels.
     */
    const POINTS_TO_PIXELS = 96 / 72;
    const TITLE_SIZE = 56;
    const TITLE_PIXELS = TITLE_SIZE * POINTS_TO_PIXELS;
    const TITLE_MAX_LINES = 3;
    const TITLE_LINE_HEIGHT = Math.round(TITLE_SIZE * 1.25);
    const ADDRESS_SIZE = 30;
    const ADDRESS_PIXELS = ADDRESS_SIZE * POINTS_TO_PIXELS;
    /** The gap GD leaves between the address's baseline and the title's. */
    const ADDRESS_TO_TITLE = Math.round(ADDRESS_SIZE * 1.9);

    /**
     * **The veil is a soft glow hugging the text, not a stripe across the
     * photo** (issue #929): full over the block's box grown by `VEIL_PAD`,
     * then fading to nothing over `VEIL_FEATHER` pixels — by distance to
     * that box, so its corners are round. Computed on a grid of
     * `VEIL_CELL`-pixel cells and stretched back with smoothing, as the
     * server does, so a short title gets a short glow and the rest of the
     * photo is left alone.
     */
    const VEIL_PAD = 20;
    const VEIL_FEATHER = 160;
    const VEIL_CELL = 10;
    // GD's VEIL_ALPHA is 70 on its 127-transparent scale; the same
    // darkness as a canvas alpha is (127 - 70) / 127.
    const VEIL_FULL_ALPHA = (127 - 70) / 127;

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

    /** @type {boolean|null} The probe's answer, asked once per page. */
    let filterBlurs = null;

    /** @type {string} Why the last blur attempt failed, for whoever has to diagnose a device. */
    let lastBlurFailure = '';

    /**
     * Keeps the reason a blur step failed — readable from a console as
     * `ScoutMagicCard.blurFailure()`, which is how a real iPhone's
     * behaviour gets told apart from a guess (issue #928).
     *
     * @param {string} step
     * @param {unknown} error
     */
    function noteBlurFailure(step, error) {
        let reason = 'unknown';
        if (error instanceof Error) {
            reason = error.message;
        } else if (typeof error === 'string') {
            reason = error;
        }
        lastBlurFailure = step + ': ' + reason;
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
            // A context that cannot be read back is « not proven to blur »:
            // the pixel path takes over, and the reason is kept.
            filterBlurs = false;
            noteBlurFailure('probe', error);
        }

        return filterBlurs;
    }

    /**
     * Which way this page blurs: the browser's own filter when it has one,
     * otherwise by hand.
     *
     * @returns {'filter'|'pixels'}
     */
    function blurEngine() {
        const works = filterBlurs === null ? probeFilterBlur() : filterBlurs;

        return works ? 'filter' : 'pixels';
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
            // Unreadable pixels, refused memory: said to the caller as
            // « not blurred », never papered over with a sharp draw.
            noteBlurFailure('pixels', error);

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
     * Where the block's text goes, and the box it occupies: the address on
     * its fixed baseline, the title above it, each line centred on the
     * card's vertical axis — the same order, baselines and box as GD's, so
     * the preview is the card the server would compose.
     *
     * Laid out from the bottom up so the address is always in the same
     * place and a longer title grows upwards into the zone's spare height.
     * The box is the ink's extent estimated from the em: the first line's
     * top a full em above its baseline, the last's foot a quarter em below.
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {string} title
     * @param {string} address
     * @returns {{address: ({text: string, baseline: number}|null), title: Array<{text: string, baseline: number}>, box: ({left: number, top: number, right: number, bottom: number}|null)}}
     */
    function layoutText(ctx, title, address) {
        let baseline = ADDRESS_BASELINE;
        let left = SIZE;
        let right = 0;
        let top = null;
        let bottom = null;
        let addressLine = null;

        const trimmedAddress = String(address || '').trim();
        if (trimmedAddress !== '') {
            ctx.font = ADDRESS_PIXELS + 'px ' + FONT_FAMILY;
            const text = fitLine(trimmedAddress, measurer(ctx), MAX_TEXT_WIDTH, false);
            const width = ctx.measureText(text).width;
            addressLine = { text: text, baseline: baseline };
            left = Math.min(left, (SIZE - width) / 2);
            right = Math.max(right, (SIZE + width) / 2);
            bottom = baseline + 0.25 * ADDRESS_PIXELS;
            top = baseline - ADDRESS_PIXELS;
            baseline -= ADDRESS_TO_TITLE;
        }

        ctx.font = TITLE_PIXELS + 'px ' + FONT_FAMILY;
        const measure = measurer(ctx);
        const titleLines = [];
        wrapTitle(String(title || ''), measure, MAX_TEXT_WIDTH).slice().reverse().forEach(function (line) {
            const width = measure(line);
            titleLines.push({ text: line, baseline: baseline });
            left = Math.min(left, (SIZE - width) / 2);
            right = Math.max(right, (SIZE + width) / 2);
            bottom = bottom === null ? baseline + 0.25 * TITLE_PIXELS : bottom;
            top = baseline - TITLE_PIXELS;
            baseline -= TITLE_LINE_HEIGHT;
        });

        const box = top === null || bottom === null
            ? null
            : { left: left, top: top, right: right, bottom: bottom };

        return { address: addressLine, title: titleLines, box: box };
    }

    /**
     * How much of the veil is on one point, from 0 (none) to 1 (full): the
     * box grown by `VEIL_PAD` is full, and the strength falls to nothing
     * over `VEIL_FEATHER` pixels of distance to it, with a smoothstep so no
     * edge shows.
     *
     * The same function, constant for constant, as `CardRenderer::veilStrength()`
     * in PHP; both are tested against the same sample points.
     *
     * @param {number} x
     * @param {number} y
     * @param {{left: number, top: number, right: number, bottom: number}} box
     * @returns {number}
     */
    function veilStrength(x, y, box) {
        const dx = Math.max(box.left - VEIL_PAD - x, 0, x - (box.right + VEIL_PAD));
        const dy = Math.max(box.top - VEIL_PAD - y, 0, y - (box.bottom + VEIL_PAD));
        const t = Math.max(0, 1 - Math.hypot(dx, dy) / VEIL_FEATHER);

        return t * t * (3 - 2 * t);
    }

    /**
     * A glow of darkening behind the text, so a white title stays legible
     * over a white sky without dimming the rest of the photo.
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {{left: number, top: number, right: number, bottom: number}|null} box
     */
    function drawVeil(ctx, box) {
        if (box === null) {
            return;
        }
        ctx.save();
        ctx.filter = 'none';
        try {
            const cells = SIZE / VEIL_CELL;
            const small = document.createElement('canvas');
            small.width = cells;
            small.height = cells;
            const sctx = small.getContext('2d');
            if (!sctx) {
                throw new Error('no second context');
            }
            const image = sctx.createImageData(cells, cells);
            for (let cy = 0; cy < cells; cy++) {
                for (let cx = 0; cx < cells; cx++) {
                    const strength = veilStrength((cx + 0.5) * VEIL_CELL, (cy + 0.5) * VEIL_CELL, box);
                    image.data[(cy * cells + cx) * 4 + 3] = Math.round(255 * VEIL_FULL_ALPHA * strength);
                }
            }
            sctx.putImageData(image, 0, 0);
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(small, 0, 0, cells, cells, 0, 0, SIZE, SIZE);
        } catch (error) {
            // No way to build the glow: a plain darkened rectangle over the
            // block is hard-edged but keeps the text legible, which is what
            // the veil is for.
            noteBlurFailure('veil', error);
            ctx.fillStyle = 'rgba(0, 0, 0, ' + VEIL_FULL_ALPHA + ')';
            ctx.fillRect(
                box.left - VEIL_PAD,
                box.top - VEIL_PAD,
                box.right - box.left + 2 * VEIL_PAD,
                box.bottom - box.top + 2 * VEIL_PAD
            );
        }
        ctx.restore();
    }

    /**
     * @param {CanvasRenderingContext2D} ctx
     * @param {{address: ({text: string, baseline: number}|null), title: Array<{text: string, baseline: number}>}} layout
     */
    function drawText(ctx, layout) {
        ctx.save();
        ctx.filter = 'none';
        ctx.textBaseline = 'alphabetic';
        ctx.textAlign = 'center';

        if (layout.address !== null) {
            ctx.font = ADDRESS_PIXELS + 'px ' + FONT_FAMILY;
            ctx.fillStyle = ADDRESS_COLOUR;
            ctx.fillText(layout.address.text, SIZE / 2, layout.address.baseline);
        }
        ctx.font = TITLE_PIXELS + 'px ' + FONT_FAMILY;
        ctx.fillStyle = TITLE_COLOUR;
        layout.title.forEach(function (line) {
            ctx.fillText(line.text, SIZE / 2, line.baseline);
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
        const layout = layoutText(ctx, card ? card.title : '', card ? card.address : '');
        drawVeil(ctx, layout.box);
        drawText(ctx, layout);

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
            safeLeft: SAFE_LEFT,
            safeBottom: SAFE_BOTTOM,
            textInset: TEXT_INSET,
            addressBaseline: ADDRESS_BASELINE,
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
            veilPad: VEIL_PAD,
            veilFeather: VEIL_FEATHER,
            veilCell: VEIL_CELL,
            veilFullAlpha: VEIL_FULL_ALPHA,
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
        veilStrength: veilStrength,
        probeFilterBlur: probeFilterBlur,
        blurEngine: blurEngine,
        blurPixels: blurPixels,
        blurFailure: function () {
            return lastBlurFailure;
        },
    };
}());
