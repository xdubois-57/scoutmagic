// End-to-end: an album's media, from the chief's upload zone to the
// member's lightbox.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The gallery is the strongest remaining case of ARCHITECTURE.md §15's
// "the browser decides what the server receives":
//
//   1. gallery.js hand-builds every upload as a raw XMLHttpRequest with
//      its own FormData — and past 24 MB it switches to
//      chunked-upload.js, whose slice/index/reassembly wire format exists
//      NOWHERE except between that script and its PHP consumer. The same
//      chunker serves the maintenance restore, so a drifted contract
//      breaks two features at once. Both paths run here against the real
//      endpoint: two small photos plain, one 25 MB photo chunked.
//   2. Reordering is HTML5 drag-and-drop that mutates the DOM during
//      dragover and persists whatever order the grid ends up in on
//      dragend — unreachable from jsdom by construction.
//   3. The member-facing grid and lightbox (media_grid.html.twig +
//      lightbox.html.twig + gallery.js) are a shared component the groups
//      module reuses for delegated albums; the thumbnails also only exist
//      once the ProcessPhoto background task ran, so the member's view is
//      simultaneously the proof of the whole processing pipeline.
//
// The scenario tears its album down at the end — the shared instance's
// gallery stays as it was found.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { autoConfirm, collectToasts } from '../support/confirm-dialog.js';
import { loginAsAdmin, loginAsMember } from '../support/admin-login.js';
import { noisePngBuffer, pngBuffer } from '../support/png.js';
import { scaled } from '../support/timeouts.js';

const ALBUM_TITLE = `Camp d'été E2E ${Date.now()}`;

test('a chief uploads plain and chunked, reorders by drag, and a member browses the lightbox', async ({ page, browser }) => {
    // Chunked upload of 25 MB + background thumbnail processing driven by
    // a once-a-minute scheduler: this scenario is structurally slower
    // than the default budget.
    test.setTimeout(scaled(300_000));

    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    /** @type {string[]} */
    const serverErrors = [];
    page.on('response', (response) => {
        if (response.status() >= 500) {
            serverErrors.push(`HTTP ${response.status()} on ${response.url()}`);
        }
    });
    // « Supprimer ce média ? » is the site's own modal now
    // (public/assets/js/gallery.js → window.ScoutMagicConfirm), and every
    // failure this page can report is a toast — both invisible to
    // page.on('dialog'). Installed before the first navigation: both ride
    // in on an init script.
    await autoConfirm(page);
    const toasts = await collectToasts(page);

    await loginAsAdmin(page);
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // The album.
    // ---------------------------------------------------------------
    await page.goto('/gallery/manage', { waitUntil: 'domcontentloaded' });
    await page.getByRole('link', { name: 'Nouvel album' }).click();
    await page.waitForURL('**/gallery/create', { waitUntil: 'domcontentloaded' });

    await page.locator('#album-title').fill(ALBUM_TITLE);
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await page.waitForURL(/\/gallery\/\d+\/edit$/, { waitUntil: 'load' });
    const albumId = Number(/\/gallery\/(\d+)\/edit$/.exec(page.url())?.[1]);
    expect(albumId).toBeGreaterThan(0);

    // ---------------------------------------------------------------
    // Two photos through the plain XHR path — gallery.js reloads the
    // page once the batch is done, and the grid shows both.
    // ---------------------------------------------------------------
    await page.locator('#gallery-upload-input').setInputFiles([
        { name: 'feu-de-camp.png', mimeType: 'image/png', buffer: pngBuffer(800, 600, [180, 90, 30, 255]) },
        { name: 'grand-jeu.png', mimeType: 'image/png', buffer: pngBuffer(640, 480, [40, 120, 60, 255]) },
    ]);
    const items = page.locator('#gallery-existing-media .gallery-media-item');
    await expect(items).toHaveCount(2, { timeout: scaled(30_000) });
    // The batch ended in the page's own reload — wait it fully out, or
    // the next setInputFiles fires its change event before gallery.js
    // has re-attached to the input.
    await page.waitForLoadState('load');

    // ---------------------------------------------------------------
    // One 25 MB photo through the chunked path (threshold: 24 MB) — the
    // slice/reassemble contract between chunked-upload.js and its PHP
    // consumer, exercised over the real wire.
    // ---------------------------------------------------------------
    // Its own action budget: handing a 25 MB buffer to the browser goes
    // over the CDP connection base64-encoded (~33 MB), which does not fit
    // in the config's 10 s actionTimeout on a loaded machine — this spec
    // failed here in a full run and passed on its own, which is the
    // signature of a transfer budget, not of the page.
    await page.locator('#gallery-upload-input').setInputFiles([
        { name: 'panorama.png', mimeType: 'image/png', buffer: noisePngBuffer(2500, 2500) },
    ], { timeout: scaled(120_000) });
    await expect(items, 'the chunked upload must reassemble into a third media').toHaveCount(3, { timeout: scaled(90_000) });
    await page.waitForLoadState('load');

    /** @returns {Promise<string[]>} the grid's media ids, in DOM order */
    const gridOrder = () => items.evaluateAll(
        (nodes) => nodes.map((node) => /** @type {HTMLElement} */ (node).dataset.mediaId ?? ''),
    );
    const orderBefore = await gridOrder();

    // ---------------------------------------------------------------
    // Drag the first item onto the last: the grid re-slots it during
    // dragover and dragend persists the full new order.
    //
    // The drop point is deliberately NOT the target's centre.
    // sortable.js's dragover decides "before or after" with
    // `(clientX - rect.left) > rect.width / 2` — a STRICT comparison
    // whose boundary is exactly the centre, which is where dragTo() aims
    // by default. Landing on that boundary makes the outcome depend on
    // sub-pixel rounding: the same drag inserts before the target on one
    // run and after it on the next. Aiming at 90% of the width puts the
    // pointer unambiguously past the midpoint, so this asserts one exact
    // order rather than "something changed" — and a drag that stops
    // working now fails HERE, saying what it expected, instead of
    // surfacing twelve lines later as a control that never appears.
    // ---------------------------------------------------------------
    const dropTarget = items.nth(2);
    const targetBox = await dropTarget.boundingBox();
    if (targetBox === null) {
        throw new Error('the third tile has no box — the grid did not render');
    }

    const reorderSaved = page.waitForResponse((response) => response.url().includes('/media/reorder'));
    await items.first().dragTo(dropTarget, {
        targetPosition: { x: targetBox.width * 0.9, y: targetBox.height / 2 },
    });
    expect((await reorderSaved).ok()).toBe(true);

    await page.reload({ waitUntil: 'load' });
    const orderAfter = await gridOrder();
    const [first, ...rest] = orderBefore;
    expect(orderAfter, 'the dragged tile must have moved to the end, and nothing else moved')
        .toEqual([...rest, first]);

    // ---------------------------------------------------------------
    // Cover: picked from a tile, confirmed by the badge on THAT tile
    // after the page's own reload.
    //
    // The tile is chosen by the button rather than by position.
    // album_form.html.twig renders « Définir comme couverture » only on
    // the tiles that are NOT already the cover — and the first upload
    // became the cover automatically (Gallery\Service\MediaService) — so
    // `items.first()` is a tile with no such button whenever the drag
    // leaves the cover in front. That coupling was invisible: the click
    // simply waited out its timeout on an element the page was right not
    // to render.
    // ---------------------------------------------------------------
    const settable = items.filter({ has: page.locator('.gallery-media-set-cover') });
    await expect(settable, 'some tile must not already be the cover').not.toHaveCount(0);

    const newCoverId = await settable.first().evaluate(
        (node) => /** @type {HTMLElement} */ (node).dataset.mediaId ?? '',
    );
    await settable.first().locator('.gallery-media-set-cover').click();
    await expect(
        page.locator(`.gallery-media-item[data-media-id="${newCoverId}"]`).getByText('Couverture'),
        'the badge must land on the tile that was clicked, not merely somewhere',
    ).toBeVisible({ timeout: scaled(15_000) });

    // ---------------------------------------------------------------
    // Delete one media (its own confirm) — down to two. Setting the
    // cover ended in the page's own location.reload(); wait for the
    // fresh document to finish loading, or this click races gallery.js
    // re-attaching its listeners and lands on a dead button.
    // ---------------------------------------------------------------
    await page.waitForLoadState('load');
    await items.nth(2).locator('.gallery-media-delete').click();
    await expect(items).toHaveCount(2);

    // ---------------------------------------------------------------
    // The member's side: the album in the list, the grid, the lightbox.
    // Thumbnails exist once ProcessPhoto ran — reloading is also what
    // lets the poor-man's cron drive that task.
    // ---------------------------------------------------------------
    const member = await browser.newContext();
    try {
        const memberPage = await member.newPage();
        await loginAsMember(memberPage);
        await answerCookieBanner(memberPage);

        await memberPage.goto('/gallery', { waitUntil: 'domcontentloaded' });
        await memberPage.getByRole('link', { name: new RegExp(ALBUM_TITLE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) }).click();
        await memberPage.waitForURL(`**/gallery/${albumId}`, { waitUntil: 'domcontentloaded' });

        // Wait the processing pipeline out: every tile a real <img>, no
        // spinner left.
        await expect.poll(async () => {
            await memberPage.reload({ waitUntil: 'load' });
            return memberPage.locator('.gallery-lightbox-trigger img').count();
        }, {
            message: 'both thumbnails must appear once the processing task ran',
            timeout: scaled(120_000),
            intervals: [3_000],
        }).toBe(2);

        // The lightbox: opens on the tile, shows a real decoded image,
        // walks to the next one, closes on Escape.
        await memberPage.locator('.gallery-lightbox-trigger').first().click();
        const lightbox = memberPage.locator('#gallery-lightbox');
        await expect(lightbox).toBeVisible();
        const lightboxImage = memberPage.locator('#gallery-lightbox-image');
        await expect(lightboxImage).toBeVisible();
        await expect.poll(() => lightboxImage.evaluate(
            (img) => /** @type {HTMLImageElement} */ (img).naturalWidth,
        )).toBeGreaterThan(0);

        await memberPage.locator('#gallery-lightbox-next').click();
        await expect(lightboxImage).toBeVisible();
        await memberPage.keyboard.press('Escape');
        await expect(lightbox).toBeHidden();

        // The whole album as a ZIP — a real download with a real name.
        const downloadPromise = memberPage.waitForEvent('download');
        await memberPage.getByRole('link', { name: /Télécharger l'album/ }).click();
        expect((await downloadPromise).suggestedFilename()).toMatch(/\.zip$/);
    } finally {
        await member.close();
    }

    // ---------------------------------------------------------------
    // Teardown through the UI: the album and its media disappear.
    // ---------------------------------------------------------------
    await page.goto(`/gallery/${albumId}/edit`, { waitUntil: 'load' });
    await page.locator('#gallery-delete-album').click();
    await page.waitForURL('**/gallery/manage', { waitUntil: 'domcontentloaded' });
    // Scoped to the page's content: the notification bell still holds
    // "processing finished" notices that quote the album's name.
    await expect(page.locator('main').getByText(ALBUM_TITLE)).toHaveCount(0);

    expect(await toasts(), 'the gallery reported an error through a toast').toEqual([]);
    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
