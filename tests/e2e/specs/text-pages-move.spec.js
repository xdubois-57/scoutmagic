// End-to-end: a text page dragged from one menu section into another
// (issue #752).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// On /config/pages-de-texte each menu section is its own sortable list,
// and the lists are connected: dropping a page into another section moves
// it there. The section is not a filing detail — it decides who may read
// the page (TextPage::roleMin()). So the property worth a real browser is
// the whole chain: a native drag between two lists, the save the
// receiving list posts, the page still in its new section and rank after
// a reload, and an anonymous visitor now able to read a page that was
// members-only a minute earlier.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { waitForServerResponse } from '../support/response.js';

const STAMP = Date.now();
const MENU_LABEL = `Charte E2E ${STAMP}`;
const TITLE = `La charte déplacée ${STAMP}`;

test('a page dragged from « Espace membres » into « Notre unité » moves there, stays there, and becomes public', async ({ page, browser }) => {
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await autoConfirm(page);
    await loginAsAdmin(page);
    await answerCookieBanner(page);

    // A members-only page.
    await page.goto('/config/pages-de-texte/nouveau', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Nom dans le menu').fill(MENU_LABEL);
    await page.getByLabel('Titre de la page').fill(TITLE);
    await page.getByLabel('Section du menu').selectOption({ label: 'Espace membres' });
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/nouveau'), { waitUntil: 'domcontentloaded' }),
        page.locator('main form button[type="submit"]').click(),
    ]);

    await page.goto('/config/pages-de-texte', { waitUntil: 'domcontentloaded' });
    const members = page.locator('#text-page-list-espace_animes .list-editor-items');
    const unite = page.locator('#text-page-list-notre_unite .list-editor-items');
    const row = page.locator('.list-editor-item', { hasText: MENU_LABEL });
    await expect(members.locator('.list-editor-item', { hasText: MENU_LABEL })).toHaveCount(1);

    // A new page is active from the start.
    await expect(row.locator('.list-editor-active-toggle')).toHaveAttribute('data-active', '1');

    // The page's address, as the list shows it.
    const path = (await row.locator('code').textContent()).trim();

    // Before the move, an anonymous visitor cannot read it.
    const visitor = await browser.newContext();
    const anonymous = await visitor.newPage();
    await anonymous.goto(path, { waitUntil: 'domcontentloaded' });
    await expect(anonymous.getByRole('heading', { level: 1, name: TITLE })).toHaveCount(0);

    // The drag: to the very top of « Notre unité ».
    const saved = waitForServerResponse(page, (response) => response.url().endsWith('/config/pages-de-texte/ordre'));
    const firstTarget = unite.locator('.list-editor-item').first();
    if (await firstTarget.count()) {
        await row.dragTo(firstTarget, { targetPosition: { x: 20, y: 2 } });
    } else {
        await row.dragTo(unite);
    }
    expect((await (await saved).json()).success).toBe(true);
    await expect(unite.locator('.list-editor-item').first()).toContainText(MENU_LABEL);
    await expect(members.locator('.list-editor-item', { hasText: MENU_LABEL })).toHaveCount(0);

    // After a reload: still there, still first.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(unite.locator('.list-editor-item').first()).toContainText(MENU_LABEL);

    // And now public.
    await anonymous.goto(path, { waitUntil: 'domcontentloaded' });
    await expect(anonymous.getByRole('heading', { level: 1, name: TITLE })).toBeVisible();
    await visitor.close();

    // Clean up: the page leaves no trace for the specs after this one.
    const deleted = waitForServerResponse(page, (response) => response.url().endsWith('/config/pages-de-texte/suppression'));
    await page.locator('.list-editor-item', { hasText: MENU_LABEL }).locator('.list-editor-delete-btn').click();
    // The list reloads on success, so the answer's body is gone: the
    // status and the row's absence say it instead.
    expect((await deleted).ok()).toBe(true);
    await expect(page.locator('.list-editor-item', { hasText: MENU_LABEL })).toHaveCount(0);

    expect(pageErrors, 'no uncaught script error on the page').toEqual([]);
});
