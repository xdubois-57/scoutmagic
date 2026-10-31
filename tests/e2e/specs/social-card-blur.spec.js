// End-to-end: the social card is blurred in a browser that has no
// `CanvasRenderingContext2D.filter` (issue #928).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Safari does not implement `ctx.filter`. Assigning to it is accepted — it
// becomes an ordinary property — and every draw afterwards is sharp, so the
// composer showed a photo that did not follow the slider and EXPORTED a
// gallery photo unblurred. No jsdom double can show that: a double blurs
// when it is told to. Only a real canvas can say whether pixels moved.
//
// WebKit is not installed in CI (playwright.config.js, « cross-engine
// compatibility coverage »), so Safari is reproduced the way it differs
// from Chromium: the `filter` accessor is removed from the context's
// prototype. What this proves is that the engine finds out from the pixels
// and blurs by hand; that real WebKit then behaves as the stand-in does is
// verified on a device, not here.
//
// The photo is synthetic — vertical white strokes on a gradient, and a black
// disc — so no real picture is involved, and a stroke edge is the clearest
// thing to measure a blur by: sharp, it jumps by about 150 levels between
// neighbouring pixels; blurred, it cannot.
import { test, expect } from '@playwright/test';
import path from 'node:path';

const ENGINE = path.resolve('public/assets/js/social-card.js');

/** Draws the synthetic photo and reports how sharp the card is. */
async function measure(page, { blurRatio, withoutFilter = false }) {
    return page.evaluate(({ ratio, remove }) => {
        if (remove) {
            delete CanvasRenderingContext2D.prototype.filter;
        }
        const photo = document.createElement('canvas');
        photo.width = 1600;
        photo.height = 1200;
        const p = photo.getContext('2d');
        const gradient = p.createLinearGradient(0, 0, 1600, 1200);
        gradient.addColorStop(0, '#d33');
        gradient.addColorStop(1, '#36d');
        p.fillStyle = gradient;
        p.fillRect(0, 0, 1600, 1200);
        p.fillStyle = '#fff';
        for (let i = 0; i < 20; i++) {
            p.fillRect(80 * i, 0, 8, 1200);
        }
        p.fillStyle = '#000';
        p.beginPath();
        p.arc(800, 600, 220, 0, 7);
        p.fill();

        const card = document.createElement('canvas');
        const engine = window.ScoutMagicCard;
        const drawn = engine.drawCard(card, {
            image: photo, title: '', address: '', blurRatio: ratio,
        });
        const data = card.getContext('2d').getImageData(0, 0, 1080, 1080).data;
        // The sharpest step between horizontal neighbours, on the red channel.
        let steepest = 0;
        for (let y = 100; y < 700; y += 37) {
            for (let x = 1; x < 1080; x++) {
                const step = Math.abs(data[(y * 1080 + x) * 4] - data[(y * 1080 + x - 1) * 4]);
                steepest = Math.max(steepest, step);
            }
        }

        return {
            drawn,
            steepest,
            filterReported: engine.probeFilterBlur(),
            engineChosen: engine.blurEngine(),
            corner: data[0],
        };
    }, { ratio: blurRatio, remove: withoutFilter });
}

test.describe('the card engine blurs with or without ctx.filter', () => {
    test.beforeEach(async ({ page }) => {
        await page.setContent('<!doctype html><title>card</title><body></body>');
        await page.addScriptTag({ path: ENGINE });
    });

    test('Chromium: the filter is found to work, and the photo is blurred', async ({ page }) => {
        const sharp = await measure(page, { blurRatio: 0 });
        const blurred = await measure(page, { blurRatio: 0.06 });

        expect(blurred.drawn).toBe(true);
        expect(blurred.filterReported).toBe(true);
        expect(blurred.engineChosen).toBe('filter');
        // « Net » really is sharp, and the same photo at 0.06 is not.
        expect(sharp.steepest).toBeGreaterThan(100);
        expect(blurred.steepest).toBeLessThan(20);
    });

    test('without ctx.filter: found out from the pixels, then blurred by hand', async ({ page }) => {
        const blurred = await measure(page, { blurRatio: 0.06, withoutFilter: true });

        expect(blurred.filterReported).toBe(false);
        expect(blurred.engineChosen).toBe('pixels');
        expect(blurred.drawn).toBe(true);
        // The whole point: a browser that ignores the filter used to leave
        // this at the sharp photo's steepness.
        expect(blurred.steepest).toBeLessThan(20);
    });

    test('without ctx.filter, every position of the slider blurs more than « Net »', async ({ page }) => {
        const net = await measure(page, { blurRatio: 0, withoutFilter: true });
        expect(net.steepest).toBeGreaterThan(100);

        for (const ratio of [0.005, 0.025, 0.055, 0.06, 0.065, 0.2]) {
            const blurred = await measure(page, { blurRatio: ratio, withoutFilter: true });
            expect(blurred.drawn, `drawn at ${ratio}`).toBe(true);
            expect(blurred.steepest, `blurred at ${ratio}`).toBeLessThan(net.steepest / 4);
        }
    });

    test('without ctx.filter, the border does not darken into a vignette', async ({ page }) => {
        const sharp = await measure(page, { blurRatio: 0, withoutFilter: true });
        const blurred = await measure(page, { blurRatio: 0.2, withoutFilter: true });

        // The top-left pixel stays within a few levels of the photo's own.
        expect(Math.abs(blurred.corner - sharp.corner)).toBeLessThan(25);
    });
});
