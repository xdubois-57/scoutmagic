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
            calls.push({ op: 'fillText', text, x, y, fillStyle: this.fillStyle, size: fontSize });
        },
        measureText(text) {
            return { width: String(text).length * fontSize * perCharPerPx };
        },
    };
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
            // round(1080 * 0.06) = 65, and 1080 - 2 * 65.
            margin: 65,
            maxTextWidth: 950,
            titleSize: 66,
            titleMaxLines: 3,
            // round(66 * 1.25) = 83 (82.5 rounds up, as PHP's round does).
            titleLineHeight: 83,
            addressSize: 34,
            // round(34 * 1.9) = 65 (64.6).
            addressToTitle: 65,
            // (int) (1080 * 0.35) = 378.
            veilStartY: 378,
            backdrop: 'rgb(73, 80, 87)',
            jpegQuality: 0.88,
        });
    });

    it('converts GD\'s inverted alpha rather than inventing a darkness', () => {
        // GD: 127 is transparent, 0 opaque, and VEIL_ALPHA = 70 at the
        // foot. The same darkness on canvas's 0..1 scale is (127-70)/127.
        expect(card().cardConstants().veilFootAlpha).toBeCloseTo(57 / 127, 10);
        expect(card().cardConstants().veilFootAlpha).toBeCloseTo(0.4488, 4);
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
        card().drawCard(canvas, {
            image: { width: 1200, height: 1200 },
            title: 'Week-end',
            address: 'unite.be',
            blurRatio: 0.2,
        });

        ctx.calls
            .filter((call) => call.op === 'fillText' || call.op === 'gradient')
            .forEach((call) => {
                expect(call.filter === undefined || call.filter === 'none').toBe(true);
            });
    });

    it('veils from 378 px down to the foot, transparent at the top', () => {
        card().drawCard(canvas, { image: null, title: 'T', address: '', blurRatio: 0 });

        const gradient = ctx.calls.find((call) => call.op === 'gradient');
        expect(gradient).toMatchObject({ x0: 0, y0: 378, x1: 0, y1: 1080 });
        expect(gradient.stops[0]).toMatchObject({ offset: 0, colour: 'rgba(0, 0, 0, 0)' });
        expect(gradient.stops[1].offset).toBe(1);
        expect(gradient.stops[1].colour).toContain('0.448');
    });

    it('fills the backdrop grey behind a photo that does not cover the square', () => {
        card().drawCard(canvas, { image: { width: 10, height: 10 }, title: 'T', address: '', blurRatio: 0 });

        expect(ctx.calls.some((call) => call.op === 'fillRect' && call.fillStyle === 'rgb(73, 80, 87)')).toBe(true);
    });

    it('lays the address at the foot and the title above it, bottom-up', () => {
        card().drawCard(canvas, {
            image: null,
            // At 66 px the recording context measures 33 px a character,
            // so 29 characters pass 950 and this wraps to exactly two
            // lines — which is what makes the two baselines below
            // checkable.
            title: 'aaaa bbbb cccc dddd eeee ffff',
            address: 'unite.be',
            blurRatio: 0,
        });

        const texts = ctx.calls.filter((call) => call.op === 'fillText');
        const address = texts[0];
        expect(address.text).toBe('unite.be');
        // 1080 - 65.
        expect(address).toMatchObject({ x: 65, y: 1015, size: 34 });

        const titleLines = texts.slice(1);
        expect(titleLines).toHaveLength(2);
        // Drawn bottom-up: the LAST line of the title is painted first,
        // at the baseline just above the address.
        expect(titleLines[0].y).toBe(1015 - 65);
        expect(titleLines[1].y).toBe(1015 - 65 - 83);
        titleLines.forEach((line) => {
            expect(line.size).toBe(66);
            expect(line.x).toBe(65);
        });
    });

    it('puts the title at the foot when there is no address to make room for', () => {
        card().drawCard(canvas, { image: null, title: 'Week-end', address: '   ', blurRatio: 0 });

        const texts = ctx.calls.filter((call) => call.op === 'fillText');
        expect(texts).toHaveLength(1);
        expect(texts[0]).toMatchObject({ text: 'Week-end', y: 1015, size: 66 });
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
