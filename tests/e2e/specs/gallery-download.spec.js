// End-to-end: the gallery, from an empty album to a photo saved on
// somebody's phone — and to the whole album saved as one archive.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// This module's whole point is getting pictures OUT again, and every step
// of that lives somewhere no other suite can reach:
//
// - the upload is XMLHttpRequest built by public/assets/js/gallery.js,
//   file by file, against a route that takes one file per request;
// - the renditions only exist after a background task has run
//   (`gallery`/`process_photo`), so "download the best quality" is
//   meaningless until the queue has actually turned — this is the first
//   scenario to turn it (support/scheduler.js);
// - the download itself is a browser behaviour. Whether a click SAVES a
//   file, and under WHAT NAME, is decided by the `download` attribute and
//   by the response's Content-Disposition working together. PHPUnit can
//   assert the header and Vitest can assert the attribute; neither can
//   state that a file lands in the folder, named what it should be.
//
// And the name is the point. Two photos an album holds may perfectly well
// have been called IMG_1234.jpg by two different phones; saved one at a
// time, the second used to overwrite the first. Both halves of that
// promise — the single download and the album's zip — now ask one rule
// (Modules\Gallery\Service\MediaFileName), so this checks the two against
// EACH OTHER rather than against a string written here.
//
// FIXTURE
// ----------------------------------------------------------------------------
// The harness's two accounts (scripts/e2e-support.php): the super-admin,
// who reaches `role_min: chief` and may create an album, and the ordinary
// member, who may only look at one. The album is deliberately unit-wide
// (no section), which is what makes it visible to that ordinary member —
// section scoping is PHPUnit's to check, one call at a time.
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text, except for the three ids gallery.js binds to
// itself and which are therefore a contract rather than incidental
// structure: the upload input (#gallery-upload-input), the lightbox
// (#gallery-lightbox) and its single download control
// (#gallery-lightbox-download).
import { expect, test } from '@playwright/test';
import fs from 'node:fs/promises';

import { loginAsAdmin, loginAsMember } from '../support/admin-login.js';
import { answerCookieBanner } from '../support/cookie-banner.js';
import { runScheduler } from '../support/scheduler.js';

// A real 1×1 PNG rather than random bytes: Core\File\UploadHandler sniffs
// the type from the content, so anything else is refused before it can
// prove anything.
const ONE_PIXEL_PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
);

// Both uploads carry the SAME name on purpose — the collision the naming
// rule exists for.
const PHONE_FILENAME = 'IMG_1234.png';

const ALBUM_TITLE = `Camp E2E ${Date.now()}`;

/** Set by the first test, read by the second — hence `.serial` below. */
let albumUrl = '';
/** @type {string[]} the two photos' download names, in album order. */
let downloadNames = [];

test.describe.configure({ mode: 'serial' });

test.describe('Galerie', () => {
    test('a chief fills an album, and each photo saves under a name of its own', async ({ page }) => {
        /** @type {string[]} */
        const serverErrors = [];
        page.on('response', (response) => {
            if (response.status() >= 500) {
                serverErrors.push(`HTTP ${response.status()} on ${response.url()}`);
            }
        });

        await loginAsAdmin(page);
        await answerCookieBanner(page);

        // ---------------------------------------------------------------
        // An album, then two photos into it. The upload is gallery.js's
        // own XHR loop, which reloads the page when the last one lands.
        // ---------------------------------------------------------------
        await page.goto('/gallery/create', { waitUntil: 'domcontentloaded' });
        // By role and accessible name rather than by label: the field's
        // <label> carries a hint span that is hidden for a local album,
        // and getByLabel() reads the label's own text (hidden part
        // included) while the accessible name is just "Titre". `exact`
        // keeps it off "Sous-titre".
        await page.getByRole('textbox', { name: 'Titre', exact: true }).fill(ALBUM_TITLE);
        await page.getByRole('button', { name: 'Enregistrer' }).click();
        await page.waitForURL(/\/gallery\/\d+\/edit$/);

        const albumId = (/\/gallery\/(\d+)\/edit$/.exec(page.url()) ?? [])[1];
        expect(albumId, 'the album was created and its page reached').toBeTruthy();
        albumUrl = `/gallery/${albumId}`;

        await page.locator('#gallery-upload-input').setInputFiles([
            { name: PHONE_FILENAME, mimeType: 'image/png', buffer: ONE_PIXEL_PNG },
            { name: PHONE_FILENAME, mimeType: 'image/png', buffer: ONE_PIXEL_PNG },
        ]);
        await expect(page.getByRole('heading', { name: /Médias \(2 \// })).toBeVisible();

        // ---------------------------------------------------------------
        // Renditions are made by a background task, so nothing is
        // downloadable until the queue turns. Turned here through the
        // instance's real cron entry point, never by writing a rendition
        // by hand.
        // ---------------------------------------------------------------
        await runScheduler();

        await page.goto(albumUrl, { waitUntil: 'domcontentloaded' });
        const triggers = page.locator('.gallery-lightbox-trigger');
        await expect(triggers).toHaveCount(2);
        // Processed, so each cell shows its thumbnail rather than a spinner.
        await expect(triggers.first().locator('img')).toBeVisible();

        // ---------------------------------------------------------------
        // The lightbox offers ONE control, and it saves. Two buttons stood
        // here before — "Voir en haute qualité" beside "Télécharger cette
        // image" — and on a phone the pair filled the bottom of the screen
        // while neither could reliably save a correctly-named file.
        // ---------------------------------------------------------------
        await triggers.first().click();
        const lightbox = page.locator('#gallery-lightbox');
        await expect(lightbox).toBeVisible();
        await expect(lightbox.getByRole('link')).toHaveCount(1);

        const download = lightbox.getByRole('link', { name: 'Télécharger en haute qualité' });
        await expect(download).toBeVisible();

        downloadNames = [];
        for (const index of [0, 1]) {
            if (index > 0) {
                // Back to the grid, then open the other photo.
                await page.keyboard.press('Escape');
                await expect(lightbox).toBeHidden();
                await triggers.nth(index).click();
                await expect(lightbox).toBeVisible();
            }

            const [saved] = await Promise.all([
                page.waitForEvent('download'),
                lightbox.getByRole('link', { name: 'Télécharger en haute qualité' }).click(),
            ]);
            downloadNames.push(saved.suggestedFilename());

            // A real file, not an empty response the browser politely
            // accepted: the rendition's bytes actually arrived.
            const savedPath = await saved.path();
            expect(savedPath).toBeTruthy();
            const bytes = await fs.readFile(String(savedPath));
            expect(bytes.length).toBeGreaterThan(0);
        }

        // Both phones called it IMG_1234; the folder they land in keeps
        // two files, and each name says which media it is.
        expect(downloadNames[0]).not.toBe(downloadNames[1]);
        for (const name of downloadNames) {
            expect(name).toMatch(/^IMG_1234_\d+\.jpg$/);
        }

        // ---------------------------------------------------------------
        // …and the whole album, as one archive, names those very same two
        // files. Checked against the names captured above rather than
        // against a string written here: the two paths are supposed to
        // agree with each other, and this is what would catch them
        // drifting apart.
        // ---------------------------------------------------------------
        await page.keyboard.press('Escape');
        const [zip] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: /Télécharger l'album/ }).click(),
        ]);
        expect(zip.suggestedFilename()).toMatch(/\.zip$/);

        const archive = await fs.readFile(String(await zip.path()));
        expect(archive.subarray(0, 2).toString('latin1'), 'a real zip archive').toBe('PK');
        // Entry names are stored uncompressed in each local file header,
        // so they are readable without unpacking anything.
        const rawArchive = archive.toString('latin1');
        for (const name of downloadNames) {
            expect(rawArchive).toContain(name);
        }

        expect(serverErrors, 'the application returned a server error').toEqual([]);
    });

    test('an ordinary member finds the album and leaves with a photo', async ({ page }) => {
        /** @type {string[]} */
        const serverErrors = [];
        page.on('response', (response) => {
            if (response.status() >= 500) {
                serverErrors.push(`HTTP ${response.status()} on ${response.url()}`);
            }
        });

        await loginAsMember(page);
        await answerCookieBanner(page);

        // The album is unit-wide, so it is listed for an ordinary member —
        // and reaching it from the listing is the path a member actually
        // takes, rather than a URL typed by a test.
        await page.goto('/gallery', { waitUntil: 'domcontentloaded' });
        await page.getByRole('link', { name: new RegExp(ALBUM_TITLE) }).click();
        await page.waitForURL(new RegExp(`${albumUrl}$`));

        await page.locator('.gallery-lightbox-trigger').first().click();
        const lightbox = page.locator('#gallery-lightbox');
        await expect(lightbox).toBeVisible();

        const [saved] = await Promise.all([
            page.waitForEvent('download'),
            lightbox.getByRole('link', { name: 'Télécharger en haute qualité' }).click(),
        ]);

        // The same file the chief got, under the same name: what a media
        // is called is a property of the media, not of who saved it.
        expect(downloadNames).toContain(saved.suggestedFilename());

        expect(serverErrors, 'the application returned a server error').toEqual([]);
    });
});
