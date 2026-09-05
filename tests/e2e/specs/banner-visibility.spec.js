// End-to-end: a home-page banner — created through the three-fetch modal
// flow, shown to exactly the audience its role_min names, then removed.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Two things about this small module only exist in a browser:
//
//   1. The add flow is the one place in the application where a record is
//      created FIRST (POST /config/banner/add, just to obtain an id),
//      then filled through the shared rich-text dialog — and DELETED
//      AGAIN if that dialog is dismissed unsaved (config.html.twig's own
//      script). That compensating delete runs on a Bootstrap
//      `hidden.bs.modal` event; no other suite executes it, and a
//      regression leaves an invisible empty banner in the home page's
//      random rotation.
//   2. The visibility select auto-saves a role_min whose effect is only
//      observable as WHO SEES THE BANNER ON `/`: an anonymous visitor
//      must never see a banner reserved for chiefs. That is an RBAC
//      outcome rendered by Core\Module\HomeBannerProvider, checked here
//      across two differently-privileged browsers.
//
// With exactly one active banner, "random pick among visible" is
// deterministic — either it may show and does, or it must not and does
// not.
//
// ONE THING THIS SCENARIO LEANS ON, said out loud because it is invisible
// here: the editorial banner is the LAST of the home page's three bands
// in priority order (pages/home.html.twig), so it only renders for a
// visitor who owes nothing and has no unread group. That holds for the
// administrator this scenario drives — nothing has billed them or written
// in their groups yet at this point in the run — and the priority rule
// itself is checked in specs/public-home-page.spec.js, which deliberately
// creates both of those states and cleans them up again. A scenario that
// gave this administrator money to pay BEFORE this file runs would make
// the assertions below fail with "the banner is missing", which is why
// this paragraph is here rather than in a commit message.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { waitForServerResponse } from '../support/response.js';

const BANNER_TEXT = `Grand nettoyage du local samedi ${Date.now()}`;

test('a banner is created via the modal (or rolled back on dismiss), and its role_min decides who sees it on the home page', async ({ page, browser }) => {
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
    /** @type {string[]} */
    const alerts = [];
    page.on('dialog', async (dialog) => {
        if (dialog.type() === 'confirm') {
            await dialog.accept();
            return;
        }
        alerts.push(dialog.message());
        await dialog.dismiss();
    });
    // « Supprimer » in the list editor now asks through the site's own
    // modal (public/assets/js/list-editor.js → window.ScoutMagicConfirm),
    // which Playwright never sees as a dialog. native: false because this
    // page's own script still reports its failures through window.alert()
    // — the handler above captures those, and two handlers answering one
    // native dialog is an error, not a redundancy.
    await autoConfirm(page, { native: false });

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto('/config/banner', { waitUntil: 'load' });
    await expect(page.getByRole('heading', { level: 1, name: 'Bannière' })).toBeVisible();

    // The random-pick assertions below need this banner to be the only
    // one. A canonical run starts empty; clearing leftovers keeps a
    // developer's re-run against a lived-in instance meaningful too.
    for (;;) {
        const rows = page.locator('#banner-list [data-id]');
        const count = await rows.count();
        if (count === 0) {
            break;
        }
        await rows.first().getByRole('button', { name: 'Supprimer' }).click();
        await expect.poll(() => rows.count()).toBeLessThan(count);
        await page.goto('/config/banner', { waitUntil: 'load' });
    }

    const itemCountBefore = 0;

    // ---------------------------------------------------------------
    // Abandoning the dialog must leave nothing behind: the banner row
    // created for its id is deleted again by the dismiss handler.
    // ---------------------------------------------------------------
    await page.getByRole('button', { name: 'Ajouter une bannière' }).click();
    const modal = page.locator('#richTextEditorModal');
    await expect(modal).toBeVisible();

    // The compensating delete is the request to watch — waiting on it is
    // what makes the later count assertion about the server, not timing.
    // The compensating delete's body is never read by the page (its fetch
    // is deliberately fire-and-forget), so this waits on the response
    // status only; the reload below asserts the actual effect.
    const rollback = waitForServerResponse(page, (response) => response.url().includes('/config/banner/delete'));
    // The dialog's only dismissal is its header ✕ (Bootstrap's .btn-close,
    // which the shared partial gives no accessible name).
    await modal.locator('.btn-close').click();
    expect((await rollback).ok(), 'the abandoned banner must be deleted again').toBe(true);

    await page.reload({ waitUntil: 'domcontentloaded' });
    expect(
        await page.locator('#banner-list [data-id]').count(),
        'an abandoned dialog must not leave an empty banner behind',
    ).toBe(itemCountBefore);

    // ---------------------------------------------------------------
    // The real one: add, type, save. The page reloads itself on save.
    // ---------------------------------------------------------------
    await page.getByRole('button', { name: 'Ajouter une bannière' }).click();
    await expect(modal).toBeVisible();
    await page.locator('#richTextEditorContent').fill(BANNER_TEXT);
    await page.locator('#richTextEditorSave').click();

    await expect(page.locator('#banner-list').getByText(BANNER_TEXT)).toBeVisible();

    // ---------------------------------------------------------------
    // Reserved for chiefs: the anonymous home page must not carry it,
    // the admin's must.
    // ---------------------------------------------------------------
    // A fresh 'load'-complete navigation, not the tail of the script's own
    // location.reload(): the select's change listener attaches on
    // DOMContentLoaded, and picking an option before that silently saves
    // nothing.
    await page.goto('/config/banner', { waitUntil: 'load' });
    const bannerRow = page.locator('#banner-list [data-id]', { hasText: BANNER_TEXT });
    const roleSave = waitForServerResponse(page, (response) => response.url().includes('/config/banner/role-min'));
    await bannerRow.getByLabel('Visibilité minimale').selectOption('chief');
    expect((await roleSave).ok()).toBe(true);

    const visitor = await browser.newContext();
    try {
        const visitorPage = await visitor.newPage();
        await visitorPage.goto('/', { waitUntil: 'domcontentloaded' });
        await answerCookieBanner(visitorPage);
        await expect(
            visitorPage.getByText(BANNER_TEXT),
            'an anonymous visitor must never see a chief-only banner',
        ).toHaveCount(0);

        await page.goto('/', { waitUntil: 'domcontentloaded' });
        await expect(page.getByText(BANNER_TEXT), 'the chief-level admin does see it').toBeVisible();

        // Opened to the public, the same visitor now gets it.
        await page.goto('/config/banner', { waitUntil: 'load' });
        const publicSave = waitForServerResponse(page, (response) => response.url().includes('/config/banner/role-min'));
        await page.locator('#banner-list [data-id]', { hasText: BANNER_TEXT })
            .getByLabel('Visibilité minimale').selectOption('public');
        expect((await publicSave).ok()).toBe(true);

        await visitorPage.goto('/', { waitUntil: 'domcontentloaded' });
        await expect(visitorPage.getByText(BANNER_TEXT)).toBeVisible();

        // ---------------------------------------------------------------
        // Removed (through the list editor's confirm), it is gone for
        // everyone and the config list is back where it started.
        // ---------------------------------------------------------------
        await page.goto('/config/banner', { waitUntil: 'domcontentloaded' });
        await page.locator('#banner-list [data-id]', { hasText: BANNER_TEXT })
            .getByRole('button', { name: 'Supprimer' }).click();
        // list-editor.js reloads the page after a successful delete.
        await expect(page.locator('#banner-list').getByText(BANNER_TEXT)).toHaveCount(0);

        await visitorPage.goto('/', { waitUntil: 'domcontentloaded' });
        await expect(visitorPage.getByText(BANNER_TEXT)).toHaveCount(0);
    } finally {
        await visitor.close();
    }

    expect(alerts, 'the page reported an error through window.alert()').toEqual([]);
    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
