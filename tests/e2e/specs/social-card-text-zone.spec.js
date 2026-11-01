// End-to-end: the card's text block sits inside the zone every common crop
// keeps (issue #929).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// One square goes to every destination and each of them crops it, centred
// and in « cover » mode: a discussion thread shows a 4:3 window (rows
// 135..945), Instagram's profile grid a 3:4 one (columns 135..945), a
// Facebook preview about 1.91:1 (rows 257..823). The title and the address
// used to sit at the card's foot, so the thread cut the address off and
// sometimes the title with it, although the full card was correct.
//
// Unit tests can state the geometry — jsdom has no pixels and no glyphs, so
// they bound the text by its em. Only a real browser, with the site's own
// face loaded, can say where the ink actually lands: so this reads it from
// the pixels, for one, two and three lines and for a title that has to be
// cut, against a flat dark photo on which nothing but the text is near white.
//
// These crops are scenarios, not a promise about what a platform will do
// tomorrow, and nothing here claims otherwise.
import { test, expect } from '@playwright/test';

// Loaded from the site itself, as the composer does: the content security
// policy (`script-src 'self'`) refuses a script injected inline.
const ENGINE = '/assets/js/social-card.js';

const TITLES = {
    'one line': 'Camp 2026',
    'two lines': 'Week-end de rentrée des Lutins',
    'three lines': 'Grand jeu de nuit dans les bois de la Hulpe',
    'cut with an ellipsis': 'Grand jeu de nuit dans les bois de la Hulpe '.repeat(6).trim(),
    'one word wider than the line': 'Supercalifragilisticexpialidocious',
};

/** The crops the common zone has to survive, as the pixels each one keeps. */
const CROPS = {
    '4:3 (discussion thread)': { x: [0, 1080], y: [135, 945] },
    '3:4 (Instagram profile tile)': { x: [135, 945], y: [0, 1080] },
    '1.91:1 (Facebook preview)': { x: [0, 1080], y: [257, 823] },
};

test.describe('the card text block stays inside the common zone', () => {
    test.beforeEach(async ({ page }) => {
        // Any page of the site: it is app.css that declares the card's face.
        await page.goto('/', { waitUntil: 'domcontentloaded' });
        await page.addScriptTag({ url: ENGINE });
        const loaded = await page.evaluate(async () => {
            // `check()` answers true for a family nobody declared, so it proves
            // nothing: the faces `load()` returns are what has to be there.
            const faces = await document.fonts.load('700 74.67px "ScoutMagic Card"');

            return faces.length > 0 && faces.every((face) => face.status === 'loaded');
        });
        // Without the site's face the measurement would be of a fallback.
        expect(loaded, 'the card\'s own face did not load').toBe(true);
    });

    for (const [name, title] of Object.entries(TITLES)) {
        test(`${name}: every glyph is in the zone, with room to spare`, async ({ page }) => {
            const bounds = await page.evaluate((text) => {
                const photo = document.createElement('canvas');
                photo.width = 1200;
                photo.height = 1200;
                const p = photo.getContext('2d');
                p.fillStyle = 'rgb(40, 40, 40)';
                p.fillRect(0, 0, 1200, 1200);

                const card = document.createElement('canvas');
                window.ScoutMagicCard.drawCard(card, {
                    image: photo, title: text, address: 'unite.example.be', blurRatio: 0, blurEngine: 'filter',
                });
                const data = card.getContext('2d').getImageData(0, 0, 1080, 1080).data;
                // Pixels above the address's band: the title's, and only its.
                let aboveAddress = 0;
                let minX = 1e9;
                let maxX = -1;
                let minY = 1e9;
                let maxY = -1;
                for (let y = 0; y < 1080; y++) {
                    for (let x = 0; x < 1080; x++) {
                        const i = (y * 1080 + x) * 4;
                        if (data[i] > 200 && data[i + 1] > 200 && data[i + 2] > 200) {
                            minX = Math.min(minX, x);
                            maxX = Math.max(maxX, x);
                            minY = Math.min(minY, y);
                            maxY = Math.max(maxY, y);
                            if (y < 700) {
                                aboveAddress++;
                            }
                        }
                    }
                }

                return { minX, maxX, minY, maxY, aboveAddress };
            }, title);

            expect(bounds.maxX, 'no text was drawn').toBeGreaterThan(-1);
            // The address alone must not be able to satisfy the checks below.
            expect(bounds.aboveAddress, 'the title is drawn above the address').toBeGreaterThan(0);
            // The zone, columns 135..945 and rows 257..823, and twenty
            // pixels of margin inside it for overshoot and a different crop.
            expect(bounds.minX, 'left').toBeGreaterThanOrEqual(135 + 20);
            expect(bounds.maxX, 'right').toBeLessThanOrEqual(945 - 20);
            expect(bounds.minY, 'top').toBeGreaterThanOrEqual(257 + 20);
            expect(bounds.maxY, 'foot').toBeLessThanOrEqual(823 - 20);

            for (const [crop, keeps] of Object.entries(CROPS)) {
                expect(bounds.minX, `${crop}: left`).toBeGreaterThanOrEqual(keeps.x[0]);
                expect(bounds.maxX, `${crop}: right`).toBeLessThanOrEqual(keeps.x[1]);
                expect(bounds.minY, `${crop}: top`).toBeGreaterThanOrEqual(keeps.y[0]);
                expect(bounds.maxY, `${crop}: foot`).toBeLessThanOrEqual(keeps.y[1]);
            }
            // Centred on the card's vertical axis.
            expect((bounds.minX + bounds.maxX) / 2, 'centred').toBeGreaterThan(540 - 15);
            expect((bounds.minX + bounds.maxX) / 2, 'centred').toBeLessThan(540 + 15);
        });
    }

    test('the veil is a glow around the text: the rest of the photo is untouched', async ({ page }) => {
        const greys = await page.evaluate(() => {
            const photo = document.createElement('canvas');
            photo.width = 1200;
            photo.height = 1200;
            const p = photo.getContext('2d');
            p.fillStyle = 'rgb(80, 80, 80)';
            p.fillRect(0, 0, 1200, 1200);
            const card = document.createElement('canvas');
            window.ScoutMagicCard.drawCard(card, {
                image: photo, title: 'Camp 2026', address: 'a.be', blurRatio: 0, blurEngine: 'filter',
            });
            const ctx = card.getContext('2d');
            const at = (x, y) => ctx.getImageData(x, y, 1, 1).data[0];

            return {
                topCorner: at(8, 40),
                farAbove: at(540, 200),
                sideLevelWithText: at(8, 700),
                footCorner: at(1070, 1070),
                besideText: at(300, 700),
            };
        });

        expect(greys.topCorner).toBe(80);
        expect(greys.farAbove).toBe(80);
        expect(greys.sideLevelWithText).toBe(80);
        expect(greys.footCorner).toBe(80);
        // Level with the title, left of its first letter: 80 × (70 / 127) ≈ 44.
        expect(greys.besideText).toBeGreaterThan(40);
        expect(greys.besideText).toBeLessThan(48);
    });
});
