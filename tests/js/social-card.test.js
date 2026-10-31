// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no network. Exercises the REAL implementation in
// public/assets/js/social-card.js (imported below, never reimplemented
// here): the card that is published, drawn in the browser from issue #706,
// IT-02.
//
// **jsdom has no 2D context**: `getContext('2d')` returns null and logs
// « Not implemented: HTMLCanvasElement.prototype.getContext ». That is why
// the title's line breaking — the thing the chantier asks to be tested
// here — takes its measure function as an argument: `wrapTitle` and
// `fitLine` are pure, and the measures below are deliberately crude (a
// fixed width per character), which makes every expectation in this file
// arithmetic a reader can check rather than a rendering nobody can.
//
// The drawing itself is asserted against a recording double of the
// context, following `tests/js/upload-drop-zone.test.js`. `vi.spyOn` is
// used rather than assigning the prototype method, because
// `vitest.config.js` sets no `restoreMocks` and a bare assignment leaks
// into the rest of the file.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import '../../public/assets/js/social-card.js';

const card = () => window.ScoutMagicCard;

/** A measure of `perChar` pixels per character — crude on purpose. */
function evenMeasure(perChar) {
    return (text) => String(text).length * perChar;
}

/**
 * A measure that tells an emoji, a plain character and a LONE SURROGATE
 * apart — which is what it takes to see the truncation bug the review of
 * #850 found.
 *
 * A real engine draws an unpaired surrogate as U+FFFD, and that glyph is
 * narrower than the emoji the surrogate came from. So a loop that cuts
 * by UTF-16 code unit does not merely leave a broken character behind:
 * the line it leaves measures SHORTER, so the loop stops there and ships
 * it. `String.length` cannot show that, and neither can a measure that
 * charges every code point the same.
 */
function glyphMeasure(text) {
    return Array.from(String(text)).reduce((width, point) => {
        const code = point.codePointAt(0) ?? 0;
        if (code >= 0xD800 && code <= 0xDFFF) {
            return width + 5;   // tofu, narrower than what it replaced
        }

        return width + (code > 0xFFFF ? 20 : 10);
    }, 0);
}

/**
 * A context double that records what was drawn, in order. `measureText`
 * follows the font size the caller set, so the wrapping assertions below
 * exercise the same path a browser would.
 */
function recordingContext(perCharPerPx = 0.5) {
    const calls = [];
    let fontSize = 16;

    return {
        calls,
        get fontSize() {
            return fontSize;
        },
        set font(value) {
            fontSize = parseFloat(value) || 16;
            calls.push({ op: 'font', value });
        },
        get font() {
            return fontSize + 'px';
        },
        filter: 'none',
        fillStyle: '',
        textBaseline: '',
        textAlign: '',
        save() {
            calls.push({ op: 'save' });
        },
        restore() {
            calls.push({ op: 'restore' });
        },
        fillRect(x, y, w, h) {
            calls.push({ op: 'fillRect', x, y, w, h, fillStyle: this.fillStyle, filter: this.filter });
        },
        drawImage(image, sx, sy, sw, sh, dx, dy, dw, dh) {
            calls.push({ op: 'drawImage', sx, sy, sw, sh, dx, dy, dw, dh, filter: this.filter });
        },
        createLinearGradient(x0, y0, x1, y1) {
            const stops = [];
            calls.push({ op: 'gradient', x0, y0, x1, y1, stops });

            return {
                stops,
                addColorStop(offset, colour) {
                    stops.push({ offset, colour });
                },
            };
        },
        fillText(text, x, y) {
            calls.push({ op: 'fillText', text, x, y, fillStyle: this.fillStyle, size: fontSize, align: this.textAlign });
        },
        measureText(text) {
            return { width: String(text).length * fontSize * perCharPerPx };
        },
        createImageData(width, height) {
            return { width, height, data: new Uint8ClampedArray(width * height * 4) };
        },
        putImageData(image) {
            calls.push({ op: 'putImageData', image });
        },
    };
}

/**
 * Gives the engine a second 2D context — the one it builds the blur's
 * padding in — and hands back what is drawn there.
 *
 * jsdom has no 2D context at all, so the engine's own « no second
 * context » fallback is what every other test in this file takes. The
 * padded path needs one, and `document.createElement` is where the
 * engine asks for it. Called AFTER `canvasWith()`, whose canvas is a
 * real element with its own instance spy.
 */
function offscreenRecorder() {
    const ctx = recordingContext();
    const buffer = {
        width: 0,
        height: 0,
        getContext: () => ctx,
    };
    const real = document.createElement.bind(document);
    const made = { canvases: 0 };
    // Only the FIRST canvas the engine asks for is the double: that is the
    // blur's, built before the veil's own small canvas, which stays a real
    // (context-less) jsdom element and takes the veil's plain fallback.
    vi.spyOn(document, 'createElement').mockImplementation((tag) => {
        if (tag !== 'canvas') {
            return real(tag);
        }
        made.canvases += 1;

        return made.canvases === 1 ? buffer : real(tag);
    });

    return { ctx, buffer, made };
}

function canvasWith(ctx) {
    const canvas = document.createElement('canvas');
    vi.spyOn(canvas, 'getContext').mockImplementation(() => ctx);

    return canvas;
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('the card geometry matches the server that drew it before', () => {
    // These are `Modules\Social\Card\CardRenderer`'s own constants. The
    // point is not that the file computes them — it is that the published
    // cards keep their shape, so the numbers are stated here as the
    // server's, and a change to either side has to face this test.
    it('reproduces CardRenderer to the pixel', () => {
        expect(card().cardConstants()).toMatchObject({
            size: 1080,
            // The zone every common crop keeps: columns 135..945, rows
            // 257..823 (issue #929), and the inset the text keeps inside it.
            safeLeft: 135,
            safeBottom: 823,
            textInset: 30,
            addressBaseline: 780,
            // 1080 - 2 * 135 - 2 * 30.
            maxTextWidth: 750,
            titleSize: 56,
            titleMaxLines: 3,
            // round(56 * 1.25) = 70.
            titleLineHeight: 70,
            addressSize: 30,
            // round(30 * 1.9) = 57.
            addressToTitle: 57,
            // The veil is a glow: full 20 px around the block, gone 160 px further out,
            // computed on a grid of 10 px cells.
            veilPad: 20,
            veilFeather: 160,
            veilCell: 10,
            backdrop: 'rgb(73, 80, 87)',
            jpegQuality: 0.88,
        });
    });

    it('converts GD\'s inverted alpha rather than inventing a darkness', () => {
        // GD: 127 is transparent, 0 opaque, and VEIL_ALPHA = 70 where the
        // veil is fullest. The same darkness on canvas's 0..1 scale is (127-70)/127.
        expect(card().cardConstants().veilFullAlpha).toBeCloseTo(57 / 127, 10);
        expect(card().cardConstants().veilFullAlpha).toBeCloseTo(0.4488, 4);
    });
});

describe('wrapTitle', () => {
    it('keeps a short title on one line', () => {
        expect(card().wrapTitle('Week-end de rentrée', evenMeasure(10), 950)).toEqual([
            'Week-end de rentrée',
        ]);
    });

    it('breaks between words, never inside one, while the words fit', () => {
        // 10 px a character, 100 px of room: four characters plus the
        // space is 50, so two short words share a line and the third
        // starts the next.
        expect(card().wrapTitle('abcd efgh ijkl', evenMeasure(10), 100)).toEqual([
            'abcd efgh',
            'ijkl',
        ]);
    });

    it('trims and collapses the whitespace a chief actually types', () => {
        expect(card().wrapTitle('  Week-end   de  rentrée  ', evenMeasure(10), 950)).toEqual([
            'Week-end de rentrée',
        ]);
    });

    it('answers nothing for a title that is only whitespace', () => {
        expect(card().wrapTitle('   ', evenMeasure(10), 950)).toEqual([]);
        expect(card().wrapTitle('', evenMeasure(10), 950)).toEqual([]);
    });

    it('cuts a single word wider than the line with an ellipsis, rather than breaking it', () => {
        // « Rassemble… » says more than « Rassembl / ement »: the word
        // becomes its own line and fitLine shortens it.
        const lines = card().wrapTitle('Rassemblement', evenMeasure(10), 60);
        expect(lines).toHaveLength(1);
        expect(lines[0]).toBe('Rasse…');
        expect(lines[0].length * 10).toBeLessThanOrEqual(60);
    });

    it('caps at three lines and marks the third as continuing', () => {
        // Eight four-letter words at 10 px a character in 100 px of room
        // wrap to FOUR lines — the cap has to have something to cut, or
        // the test passes without exercising it.
        const lines = card().wrapTitle('aaaa bbbb cccc dddd eeee ffff gggg hhhh', evenMeasure(10), 100);
        expect(lines).toHaveLength(3);
        expect(lines[2].endsWith('…')).toBe(true);
    });

    it('does not let the cap stack a second ellipsis on a line that already ends in one', () => {
        // The third line is itself too long, so fitLine ends it with « … »;
        // the cap then appends « … » again, and the result must still carry
        // exactly one.
        const lines = card().wrapTitle(
            'aaaa bbbb cccc Rassemblementtrèslong eeee',
            evenMeasure(10),
            100
        );
        expect(lines).toHaveLength(3);
        expect(lines[2]).not.toMatch(/…\s*…/u);
        expect((lines[2].match(/…/gu) || []).length).toBe(1);
    });

    it('never returns a line wider than the room it was given', () => {
        const measure = evenMeasure(10);
        const lines = card().wrapTitle(
            'Rassemblementtrèslong de toutes les unités de la fédération en juillet',
            measure,
            100
        );
        lines.forEach((line) => {
            expect(measure(line)).toBeLessThanOrEqual(100);
        });
    });
});

describe('fitLine', () => {
    it('leaves a line that fits exactly alone, with no ellipsis', () => {
        expect(card().fitLine('abcde', evenMeasure(10), 50, false)).toBe('abcde');
    });

    it('shortens until the line AND its ellipsis fit', () => {
        const measure = evenMeasure(10);
        const fitted = card().fitLine('abcdefghij', measure, 50, false);
        expect(fitted).toBe('abcd…');
        expect(measure(fitted)).toBeLessThanOrEqual(50);
    });

    it('forces an ellipsis onto a line that fits, for the three-line cap', () => {
        expect(card().fitLine('abcde', evenMeasure(10), 100, true)).toBe('abcde…');
    });

    it('drops the space before an existing ellipsis instead of keeping both', () => {
        expect(card().fitLine('abcd …', evenMeasure(10), 100, true)).toBe('abcd…');
    });

    it('does not leave a space before the ellipsis it adds', () => {
        // The shortening walks back one character at a time and can land
        // on a space: « ab …  » reads as a gap, not as a cut word. The
        // loop trims each step for that, and nothing covered it until
        // the regex it used was replaced (SonarCloud S8786 on #850).
        expect(card().fitLine('ab cdefgh', evenMeasure(10), 40, false)).toBe('ab…');
    });

    it('answers a bare ellipsis when there is room for nothing else', () => {
        expect(card().fitLine('abcdef', evenMeasure(10), 5, false)).toBe('…');
    });

    it('drops a whole emoji rather than half of its surrogate pair', () => {
        // `CardRenderer::fit()` uses `mb_substr($base, 0, -1)`, one code
        // POINT; this loop used `slice(0, -1)`, one UTF-16 code unit. On
        // « abc😀def » cut at the emoji it left « abc\uD83D… », whose
        // orphaned high surrogate draws as U+FFFD — baked into the
        // published JPEG, since this canvas is what ships from IT-02.
        const fitted = card().fitLine('abc\u{1F600}def', glyphMeasure, 50, false);

        // No high surrogate without its low half.
        expect(fitted).not.toMatch(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])/u);
        expect(fitted).toBe('abc…');
    });

    it('keeps an emoji that fits whole', () => {
        // The guard above must not cost a title its emoji when there is
        // room: « ab😀 » plus the ellipsis measures 10+10+20+10 = 50.
        expect(card().fitLine('ab\u{1F600}cd', glyphMeasure, 50, false)).toBe('ab\u{1F600}…');
    });
});

describe('drawCard', () => {
    let ctx;
    let canvas;

    beforeEach(() => {
        ctx = recordingContext();
        canvas = canvasWith(ctx);
    });

    it('sizes the canvas to the card', () => {
        expect(card().drawCard(canvas, { image: null, title: 'T', address: 'a.be', blurRatio: 0 })).toBe(true);
        expect(canvas.width).toBe(1080);
        expect(canvas.height).toBe(1080);
    });

    it('centre-crops the source to a square', () => {
        const image = { width: 4000, height: 3000 };
        card().drawCard(canvas, { image, title: 'T', address: '', blurRatio: 0 });

        const drawn = ctx.calls.find((call) => call.op === 'drawImage');
        expect(drawn).toMatchObject({
            // The short side is 3000, taken from the middle horizontally.
            sx: 500, sy: 0, sw: 3000, sh: 3000,
            dx: 0, dy: 0, dw: 1080, dh: 1080,
        });
    });

    it('scales the blur to the square\'s side, so one setting suits every camera', () => {
        card().drawCard(canvas, {
            image: { width: 4000, height: 4000 },
            title: 'T',
            address: '',
            blurRatio: 0.025,
            blurEngine: 'filter',
        });

        const drawn = ctx.calls.find((call) => call.op === 'drawImage');
        // 0.025 of 1080 — not of the photo's own 4000.
        expect(drawn.filter).toBe('blur(27px)');
    });

    it('draws a gallery photo SHARP when the slider is at « Net », with no floor', () => {
        // The floor (CardService::MIN_BLUR_RATIO, 0.05) is gone in IT-02:
        // « Net » means net, and the composer confirms it in words.
        card().drawCard(canvas, {
            image: { width: 1200, height: 1200 },
            title: 'T',
            address: '',
            blurRatio: 0,
        });

        const drawn = ctx.calls.find((call) => call.op === 'drawImage');
        expect(drawn.filter).toBe('none');
    });

    it('treats a radius under one pixel as no blur at all', () => {
        card().drawCard(canvas, {
            image: { width: 1200, height: 1200 },
            title: 'T',
            address: '',
            blurRatio: 0.0001,
        });

        expect(ctx.calls.find((call) => call.op === 'drawImage').filter).toBe('none');
    });

    it('never blurs the text or the veil, whatever the photo got', () => {
        // The filter engine is forced, and the draw's answer and the calls
        // it made are asserted: without them a draw that stopped before the
        // text (no context to blur in, as in jsdom) left nothing to loop
        // over, and the test passed having checked nothing.
        const drawn = card().drawCard(canvas, {
            image: { width: 1200, height: 1200 },
            title: 'Week-end',
            address: 'unite.be',
            blurRatio: 0.2,
            blurEngine: 'filter',
        });

        expect(drawn).toBe(true);
        // The text, and the veil — here the plain rectangle it falls back to
        // when there is no second canvas to build the glow in.
        const overlay = ctx.calls.filter((call) => call.op === 'fillText'
            || (call.op === 'fillRect' && String(call.fillStyle).startsWith('rgba(0, 0, 0,')));
        expect(overlay.length).toBeGreaterThan(1);
        overlay.forEach((call) => {
            expect(call.filter === undefined || call.filter === 'none').toBe(true);
        });
    });

    // Issue #929. The veil is a glow hugging the text block, in both languages.
    it('falls back to a plain darkened rectangle over the block when it cannot build the glow', () => {
        // jsdom hands out a canvas with no context, which is the case.
        card().drawCard(canvas, { image: null, title: 'Camp', address: '', blurRatio: 0 });

        const veil = ctx.calls.find((call) => call.op === 'fillRect' && String(call.fillStyle).startsWith('rgba(0, 0, 0,'));
        expect(veil).toBeDefined();
        // Around the one line of title, grown by the 20 px the glow is full for.
        expect(veil.y).toBeGreaterThan(257);
        expect(veil.y + veil.h).toBeLessThan(823 + 20 + 1);
        expect(veil.w).toBeLessThan(950);
    });

    it('builds the glow on a grid and stretches it over the whole card, smoothed', () => {
        const small = offscreenRecorder();
        card().drawCard(canvas, { image: null, title: 'Camp', address: 'unite.be', blurRatio: 0 });

        const put = small.ctx.calls.find((call) => call.op === 'putImageData');
        expect(put).toBeDefined();
        // 1080 / 10 cells a side, four bytes each.
        expect(put.image.width).toBe(108);
        expect(put.image.height).toBe(108);
        const alphaAt = (cx, cy) => put.image.data[(cy * 108 + cx) * 4 + 3];
        const full = Math.round(255 * card().cardConstants().veilFullAlpha);
        // Behind the block: as dark as the veil gets. At the corners: nothing.
        expect(alphaAt(54, 70)).toBe(full);
        expect(alphaAt(0, 0)).toBe(0);
        expect(alphaAt(107, 107)).toBe(0);
        expect(alphaAt(54, 5)).toBe(0);
        // And it is the grid that is drawn over the card, with smoothing on.
        const stretched = ctx.calls.find((call) => call.op === 'drawImage' && call.sw === 108);
        expect(stretched).toMatchObject({ sh: 108, dx: 0, dy: 0, dw: 1080, dh: 1080, filter: 'none' });
        expect(ctx.imageSmoothingEnabled).toBe(true);
    });

    it('lets a longer title dim more of the card, because the glow follows the block', () => {
        const dimmedCells = (title) => {
            vi.restoreAllMocks();
            const target = canvasWith(recordingContext());
            const small = offscreenRecorder();
            card().drawCard(target, { image: null, title, address: 'unite.be', blurRatio: 0 });
            const put = small.ctx.calls.find((call) => call.op === 'putImageData');

            return Array.from({ length: 108 * 108 }, (_, i) => put.image.data[i * 4 + 3]).filter((a) => a > 0).length;
        };

        expect(dimmedCells('aaaa bbbb cccc dddd eeee ffff gggg hhhh iiii'))
            .toBeGreaterThan(dimmedCells('Camp'));
    });

    // The same sample points, and the same strengths, as
    // `CardRendererTest::veilSamples()`: one function in two languages.
    it.each([
        [540, 650, 1.0],
        [290, 650, 1.0],
        [260, 650, 0.95703125],
        [200, 650, 0.5],
        [100, 650, 0.0],
        [540, 400, 0.31640625],
        [540, 300, 0.0],
        [880, 890, 0.2071067812],
        [800, 810, 1.0],
        [0, 0, 0.0],
    ])('computes the veil\'s strength at %i,%i as the server does', (x, y, expected) => {
        const box = { left: 300, top: 520, right: 780, bottom: 790 };

        expect(card().veilStrength(x, y, box)).toBeCloseTo(expected, 9);
    });

    it('fills the backdrop grey behind a photo that does not cover the square', () => {
        card().drawCard(canvas, { image: { width: 10, height: 10 }, title: 'T', address: '', blurRatio: 0 });

        expect(ctx.calls.some((call) => call.op === 'fillRect' && call.fillStyle === 'rgb(73, 80, 87)')).toBe(true);
    });

    // `ctx.filter` filters onto an infinite TRANSPARENT surface, so a
    // photo blurred edge to edge has its own alpha ramped down at the
    // square's border and the opaque backdrop shows through as a grey
    // vignette — in the published JPEG. GD clamps at its edges instead.
    // Found in the review of #850.
    it('blurs with its edges carried past the square, so no vignette appears', () => {
        const buffer = offscreenRecorder();
        const image = { width: 2000, height: 1500 };

        card().drawCard(canvas, { image, title: 'T', address: '', blurRatio: 0.025, blurEngine: 'filter' });

        // 0.025 of 1080 is a 27 px radius, and three radii of reach.
        const pad = 81;
        const full = 1080 + 2 * pad;
        expect(buffer.buffer.width).toBe(full);
        expect(buffer.buffer.height).toBe(full);

        // The crop lands INSIDE the padding, at its own size.
        const centre = buffer.ctx.calls.find((call) => call.op === 'drawImage');
        expect(centre).toMatchObject({ dx: pad, dy: pad, dw: 1080, dh: 1080 });

        // And the margin is the crop's own outermost row and column
        // stretched outwards — four sides, four corners, nothing left
        // transparent for the blur to smear.
        const draws = buffer.ctx.calls.filter((call) => call.op === 'drawImage');
        expect(draws).toHaveLength(9);
        const sides = draws.slice(1, 5);
        sides.forEach((draw) => {
            expect(draw.sw === 1 || draw.sh === 1).toBe(true);
        });
        draws.slice(5).forEach((draw) => {
            expect(draw).toMatchObject({ sw: 1, sh: 1, dw: pad, dh: pad });
        });

        // The padded buffer is then drawn blurred, offset so its own
        // ramp falls outside the 1080 square.
        const blurred = ctx.calls.filter((call) => call.op === 'drawImage');
        expect(blurred).toHaveLength(1);
        expect(blurred[0]).toMatchObject({
            dx: -pad,
            dy: -pad,
            dw: full,
            dh: full,
            filter: 'blur(27px)',
        });
    });

    it('pads nothing at « Net », where there is no filter to ramp', () => {
        const buffer = offscreenRecorder();
        const image = { width: 2000, height: 1500 };

        card().drawCard(canvas, { image, title: 'T', address: '', blurRatio: 0 });

        // The only canvas made is the veil's: nothing was built to pad in,
        // so nothing was drawn into it but the glow's own pixels.
        expect(buffer.made.canvases).toBe(1);
        expect(buffer.ctx.calls.filter((call) => call.op === 'drawImage')).toHaveLength(0);
        // The photo's one draw — the veil's stretched grid is the other.
        const drawn = ctx.calls.filter((call) => call.op === 'drawImage' && call.sw !== 108);
        expect(drawn).toHaveLength(1);
        expect(drawn[0]).toMatchObject({ dx: 0, dy: 0, dw: 1080, dh: 1080, filter: 'none' });
    });

    it('still blurs when there is no second context to pad in', () => {
        // The vignette comes back, and that is the better of the two: a
        // card drawn without the blur would send a gallery photo out
        // sharp, which is the one thing the slider must never do by
        // accident. jsdom is this case, which is why every other test
        // here sees a single draw.
        const image = { width: 2000, height: 1500 };

        card().drawCard(canvas, { image, title: 'T', address: '', blurRatio: 0.05, blurEngine: 'filter' });

        const drawn = ctx.calls.filter((call) => call.op === 'drawImage');
        expect(drawn).toHaveLength(1);
        expect(drawn[0]).toMatchObject({ dx: 0, dy: 0, dw: 1080, dh: 1080, filter: 'blur(54px)' });
    });

    it('centres the address on its baseline and the title above it, bottom-up', () => {
        card().drawCard(canvas, {
            image: null,
            // At 74.67 px — the title's point size converted to the
            // pixels GD actually renders — the recording context
            // measures 37.3 px a character, so the 750 px line holds 20
            // of them and this wraps to exactly two lines, which is what
            // makes the two baselines below checkable.
            title: 'aaaa bbbb cccc dddd eeee ffff',
            address: 'unite.be',
            blurRatio: 0,
        });

        const texts = ctx.calls.filter((call) => call.op === 'fillText');
        const address = texts[0];
        expect(address.text).toBe('unite.be');
        // The engine's own constant rather than the number: the point
        // size is 30 and the pixels are 40, and restating either here
        // would be a second copy free to drift.
        expect(address).toMatchObject({
            x: 540, y: 780, align: 'center', size: card().cardConstants().addressPixels,
        });

        const titleLines = texts.slice(1);
        expect(titleLines).toHaveLength(2);
        // Drawn bottom-up: the LAST line of the title is painted first,
        // at the baseline just above the address.
        expect(titleLines[0].y).toBe(780 - 57);
        expect(titleLines[1].y).toBe(780 - 57 - 70);
        titleLines.forEach((line) => {
            expect(line.size).toBe(card().cardConstants().titlePixels);
            expect(line.x).toBe(540);
            expect(line.align).toBe('center');
        });
    });

    it('puts the title on the address\'s baseline when there is no address to make room for', () => {
        card().drawCard(canvas, { image: null, title: 'Week-end', address: '   ', blurRatio: 0 });

        const texts = ctx.calls.filter((call) => call.op === 'fillText');
        expect(texts).toHaveLength(1);
        expect(texts[0]).toMatchObject({
            text: 'Week-end',
            x: 540,
            y: 780,
            size: card().cardConstants().titlePixels,
        });
    });

    // Issue #929. The text the card really draws, not its constants: for
    // one, two and three lines — and for a title cut with an ellipsis —
    // every line, measured the way the engine measured it, stays inside
    // the zone the common crops keep, with the inset to spare. The
    // recording context has no glyphs, so the vertical extent is bounded
    // by the em (ascent) and a quarter of it (descent), which a real face
    // never exceeds; `tests/e2e/specs/social-card-text-zone.spec.js` reads
    // the pixels in a real browser.
    it.each([
        ['one line', 'Camp 2026', 1],
        ['two lines', 'aaaa bbbb cccc dddd eeee ffff', 2],
        ['three lines', 'aaaa bbbb cccc dddd eeee ffff gggg hhhh iiii', 3],
        ['cut with an ellipsis', 'aaaa bbbb cccc dddd eeee ffff gggg hhhh iiii jjjj kkkk llll mmmm nnnn', 3],
    ])('keeps every line of a title on %s inside the common zone', (_name, title, lines) => {
        card().drawCard(canvas, { image: null, title, address: 'unite.example.be', blurRatio: 0 });

        const constants = card().cardConstants();
        const texts = ctx.calls.filter((call) => call.op === 'fillText');
        expect(texts).toHaveLength(lines + 1);
        texts.forEach((text) => {
            const width = text.text.length * text.size * 0.5;
            expect(text.x - width / 2, text.text).toBeGreaterThanOrEqual(constants.safeLeft + constants.textInset);
            expect(text.x + width / 2, text.text).toBeLessThanOrEqual(1080 - constants.safeLeft - constants.textInset);
            expect(text.y - text.size, text.text).toBeGreaterThanOrEqual(257 + constants.textInset);
            expect(text.y + text.size / 4, text.text).toBeLessThanOrEqual(constants.safeBottom - constants.textInset);
        });
        if (title.length > 60) {
            expect(texts[1].text.endsWith('…')).toBe(true);
        }
    });

    it('draws at the pixel size GD renders, not the point size GD is handed', () => {
        // `imagettftext()` takes POINTS and GD renders at 96 dpi, so the
        // 56 it is handed draws an em of 74.7 px. Copying the literal into
        // `ctx.font` drew the card's text a quarter too small and made
        // `wrapTitle()` measure with that smaller face — the line breaks
        // diverged from the server's as well as the size. Found in the
        // review of #850; CardGeometryAgreementTest measures the factor
        // against GD itself on every run.
        const constants = card().cardConstants();
        expect(constants.titlePixels).toBeCloseTo(constants.titleSize * 96 / 72, 10);
        expect(constants.addressPixels).toBeCloseTo(constants.addressSize * 96 / 72, 10);
        expect(constants.titlePixels).toBeCloseTo(74.6667, 3);

        card().drawCard(canvas, { image: null, title: 'Week-end', address: 'unite.be', blurRatio: 0 });
        const fonts = ctx.calls.filter((call) => call.op === 'font').map((call) => call.value);

        expect(fonts).toContain(constants.titlePixels + 'px "ScoutMagic Card", "DejaVu Sans", sans-serif');
        expect(fonts.some((font) => font.startsWith('56px'))).toBe(false);
    });

    it('draws the card with no title at all rather than refusing', () => {
        // « Donnez d'abord un titre » is the form's refusal to PUBLISH,
        // computed on the typing. The preview still has to render, or the
        // page shows an empty square while the chief is still typing.
        expect(card().drawCard(canvas, { image: null, title: '', address: 'unite.be', blurRatio: 0 })).toBe(true);
        const texts = ctx.calls.filter((call) => call.op === 'fillText');
        expect(texts).toHaveLength(1);
        expect(texts[0].text).toBe('unite.be');
    });

    it('says so rather than throwing when the browser gives no context', () => {
        const contextless = document.createElement('canvas');
        vi.spyOn(contextless, 'getContext').mockImplementation(() => null);

        expect(card().drawCard(contextless, { image: null, title: 'T', address: '', blurRatio: 0 })).toBe(false);
    });

    it('says so for a canvas that is not one at all', () => {
        expect(card().drawCard(null, { image: null, title: 'T', address: '', blurRatio: 0 })).toBe(false);
        expect(card().drawCard({}, { image: null, title: 'T', address: '', blurRatio: 0 })).toBe(false);
    });
});

// Issue #928. Safari has no `CanvasRenderingContext2D.filter`: assigning it
// is accepted and every draw stays sharp, so the capability can only be
// found out by looking at pixels. These doubles are the two browsers.
describe('knowing whether the browser can blur', () => {
    /** A probe canvas whose pixel two to the left of the square has `alpha`. */
    function probeCanvas(alpha, options = {}) {
        const ctx = {
            filter: 'none',
            fillStyle: '',
            fillRect: vi.fn(),
            getImageData: options.throws
                ? () => {
                    throw new Error('blocked');
                }
                : vi.fn(() => ({ data: [0, 0, 0, alpha] })),
        };
        const canvas = { width: 0, height: 0, getContext: () => (options.noContext ? null : ctx) };
        const real = document.createElement.bind(document);
        vi.spyOn(document, 'createElement').mockImplementation(
            (tag) => (tag === 'canvas' ? canvas : real(tag))
        );

        return ctx;
    }

    it('says yes when the filter smeared the square outside itself', () => {
        const ctx = probeCanvas(40);

        expect(card().probeFilterBlur()).toBe(true);
        expect(ctx.filter).toBe('blur(2px)');
        // Looked at two pixels left of the 6..10 square, on its middle row.
        expect(ctx.getImageData).toHaveBeenCalledWith(4, 8, 1, 1);
        expect(card().blurEngine()).toBe('filter');
    });

    it('says no when the property is accepted but nothing was blurred', () => {
        // Safari: the assignment sticks (it is an ordinary property
        // there), so reading it back proves nothing — the pixel is the
        // only witness.
        const ctx = probeCanvas(0);

        expect(card().probeFilterBlur()).toBe(false);
        expect(ctx.filter).toBe('blur(2px)');
        expect(card().blurEngine()).toBe('pixels');
    });

    it('says no, rather than throwing, when the probe cannot run', () => {
        probeCanvas(255, { throws: true });
        expect(card().probeFilterBlur()).toBe(false);
        // And keeps why, so a device can be diagnosed from its console.
        expect(card().blurFailure()).toBe('probe: blocked');

        probeCanvas(255, { noContext: true });
        expect(card().probeFilterBlur()).toBe(false);
    });
});

describe('blurPixels', () => {
    /** A `width` × 1 strip, left half black, right half white. */
    function strip(width) {
        const pixels = new Uint8ClampedArray(width * 4);
        for (let x = 0; x < width; x++) {
            const value = x < width / 2 ? 0 : 255;
            pixels.set([value, value, value, 255], x * 4);
        }

        return pixels;
    }

    it('turns a hard edge into a ramp, and keeps the ends where they were', () => {
        const pixels = strip(64);
        card().blurPixels(pixels, 64, 1, 3);

        const red = (x) => pixels[x * 4];
        expect(red(0)).toBe(0);
        expect(red(63)).toBe(255);
        // Across the edge the values climb without a jump.
        for (let x = 1; x < 64; x++) {
            expect(red(x)).toBeGreaterThanOrEqual(red(x - 1));
        }
        expect(red(31)).toBeGreaterThan(0);
        expect(red(32)).toBeLessThan(255);
    });

    it('conserves the average, edges included, so the border does not darken', () => {
        const pixels = new Uint8ClampedArray(16 * 16 * 4).fill(120);
        card().blurPixels(pixels, 16, 16, 4);

        // A flat photo stays exactly flat: clamping repeats the border.
        expect(Array.from(pixels).every((value) => value === 120)).toBe(true);
    });

    it('spreads a wider blur wider', () => {
        const narrow = strip(128);
        const wide = strip(128);
        card().blurPixels(narrow, 128, 1, 2);
        card().blurPixels(wide, 128, 1, 8);

        // Eight pixels from the edge a wide blur is still far from white.
        expect(wide[72 * 4]).toBeLessThan(narrow[72 * 4]);
    });
});

describe('drawCard without ctx.filter', () => {
    /** Two canvases: the card, and the work area the pixel blur reads and writes. */
    function withWorkArea(options = {}) {
        const ctx = recordingContext();
        const canvas = canvasWith(ctx);
        const work = recordingContext();
        const data = new Uint8ClampedArray(135 * 135 * 4);
        for (let i = 0; i < 135 * 135; i++) {
            const value = i % 135 < 68 ? 0 : 255;
            data.set([value, value, value, 255], i * 4);
        }
        work.getImageData = options.cannotRead
            ? () => {
                throw new Error('blocked');
            }
            : () => ({ data });
        work.putImageData = vi.fn();
        const buffer = { width: 0, height: 0, getContext: () => (options.noContext ? null : work) };
        const real = document.createElement.bind(document);
        let canvases = 0;
        // Only the first canvas is the work area; the veil's own small
        // canvas stays a real, context-less one and takes its fallback.
        vi.spyOn(document, 'createElement').mockImplementation((tag) => {
            if (tag !== 'canvas') {
                return real(tag);
            }
            canvases += 1;

            return canvases === 1 ? buffer : real(tag);
        });

        return { ctx, canvas, work, buffer, data };
    }

    const photo = { width: 2000, height: 1500 };

    it('shrinks, blurs the pixels and grows the result back to the square', () => {
        const { ctx, canvas, work, buffer, data } = withWorkArea();

        const drawn = card().drawCard(canvas, {
            image: photo, title: 'T', address: '', blurRatio: 0.025, blurEngine: 'pixels',
        });

        expect(drawn).toBe(true);
        // 27 px radius → reduced eightfold (the cap) to 135 px.
        expect(buffer.width).toBe(135);
        expect(work.calls.find((call) => call.op === 'drawImage'))
            .toMatchObject({ sx: 250, sy: 0, sw: 1500, sh: 1500, dx: 0, dy: 0, dw: 135, dh: 135 });
        expect(work.putImageData).toHaveBeenCalledTimes(1);
        // The hard edge in the work area really was softened.
        expect(data[67 * 4]).toBeGreaterThan(0);
        expect(data[68 * 4]).toBeLessThan(255);
        // And the result is drawn unfiltered, over the whole square.
        const grown = ctx.calls.find((call) => call.op === 'drawImage');
        expect(grown).toMatchObject({ sw: 135, sh: 135, dx: 0, dy: 0, dw: 1080, dh: 1080, filter: 'none' });
    });

    it('still lays the veil and the text over a photo it blurred by hand', () => {
        const { ctx, canvas } = withWorkArea();

        const drawn = card().drawCard(canvas, {
            image: photo, title: 'Week-end', address: 'unite.be', blurRatio: 0.1, blurEngine: 'pixels',
        });

        expect(drawn).toBe(true);
        const overlay = ctx.calls.filter((call) => call.op === 'fillText' || call.op === 'gradient');
        expect(overlay.length).toBeGreaterThan(0);
        overlay.forEach((call) => {
            expect(call.filter === undefined || call.filter === 'none').toBe(true);
        });
    });

    it('reduces less for a small radius, where the grid would show', () => {
        const { buffer, canvas } = withWorkArea();

        card().drawCard(canvas, {
            image: photo, title: 'T', address: '', blurRatio: 0.005, blurEngine: 'pixels',
        });

        // 5.4 px → shrink 2 → 540 px.
        expect(buffer.width).toBe(540);
    });

    it('draws nothing, and says so, when the pixels cannot be read', () => {
        const { ctx, canvas } = withWorkArea({ cannotRead: true });

        const drawn = card().drawCard(canvas, {
            image: photo, title: 'T', address: '', blurRatio: 0.05, blurEngine: 'pixels',
        });

        // Never a sharp photo standing in for a blurred one.
        expect(drawn).toBe(false);
        expect(card().blurFailure()).toBe('pixels: blocked');
        expect(ctx.calls.filter((call) => call.op === 'drawImage')).toHaveLength(0);
        expect(ctx.calls.filter((call) => call.op === 'fillText')).toHaveLength(0);
    });

    it('draws nothing when there is no second context to work in', () => {
        const { canvas } = withWorkArea({ noContext: true });

        expect(card().drawCard(canvas, {
            image: photo, title: 'T', address: '', blurRatio: 0.05, blurEngine: 'pixels',
        })).toBe(false);
    });

    it('still draws a photo at « Net » in a browser that cannot blur at all', () => {
        const { ctx, canvas, buffer } = withWorkArea({ noContext: true });

        expect(card().drawCard(canvas, {
            image: photo, title: 'T', address: '', blurRatio: 0, blurEngine: 'pixels',
        })).toBe(true);
        // No work area was built (135 or 540 px): the only canvas is the
        // veil's own 108-cell grid.
        expect(buffer.width).toBe(108);
        expect(ctx.calls.filter((call) => call.op === 'drawImage')).toHaveLength(1);
    });
});

describe('toJpeg', () => {
    it('exports at the server\'s own quality', async () => {
        const canvas = document.createElement('canvas');
        const blob = { size: 1234, type: 'image/jpeg' };
        const toBlob = vi.fn((callback) => callback(blob));
        canvas.toBlob = toBlob;

        await expect(card().toJpeg(canvas)).resolves.toBe(blob);
        expect(toBlob.mock.calls[0][1]).toBe('image/jpeg');
        expect(toBlob.mock.calls[0][2]).toBe(0.88);
    });

    it('resolves null rather than hanging when the browser hands back nothing', async () => {
        const canvas = document.createElement('canvas');
        canvas.toBlob = (callback) => callback(null);

        await expect(card().toJpeg(canvas)).resolves.toBeNull();
    });

    it('resolves null when toBlob is absent or throws', async () => {
        const canvas = document.createElement('canvas');
        canvas.toBlob = undefined;
        await expect(card().toJpeg(canvas)).resolves.toBeNull();

        const throwing = document.createElement('canvas');
        throwing.toBlob = () => {
            throw new Error('tainted canvas');
        };
        await expect(card().toJpeg(throwing)).resolves.toBeNull();
    });
});
