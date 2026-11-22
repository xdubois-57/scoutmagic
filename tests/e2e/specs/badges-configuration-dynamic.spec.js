// End-to-end: the badges configuration page answers in place (#1337).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Adding or renaming a badge used to call window.location.reload(), and a
// deletion confirmed itself in silence. The unit tests (tests/js/
// config-badges.test.js) run the script against a hand-written fixture; only
// a real page proves the three things that matter together: the row the
// script creates is the one the SERVER's template describes, it is live at
// once (rename, switch, delete), and nothing ever reloaded the page.
//
// "Nothing reloaded" is measured, not assumed: a marker is set on `window`
// before the first action, and a reload (or any navigation) would throw it
// away. The page is also checked after a real reload, so what the server
// stored is what the dynamic rows showed.
import { test, expect } from '@playwright/test';
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { answerCookieBanner } from '../support/cookie-banner.js';

test('a badge is created, renamed, switched and deleted without the page ever reloading', async ({ page }) => {
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
    await autoConfirm(page);
    await loginAsAdmin(page);
    await answerCookieBanner(page, { accept: true });
    await page.goto('/admin/badges/configuration', { waitUntil: 'domcontentloaded' });

    const list = page.locator('#badge-list');
    await expect(list).toBeVisible();
    await page.evaluate(() => { window.__badgesPageMarker = 'still-here'; });
    const stillHere = () => page.evaluate(() => window.__badgesPageMarker);
    const toast = page.locator('.toast-body');

    // --- create -----------------------------------------------------------
    const created = page.locator('.badge-row').filter({ has: page.locator('.badge-name-input[value="Communication E2E"]') });
    await page.locator('#new-badge-name').fill('Communication E2E');
    await page.locator('#badge-add-btn').click();

    await expect(created, 'the new badge is in the list at once').toHaveCount(1);
    await expect(toast.filter({ hasText: 'Badge ajouté.' })).toBeVisible();
    await expect(page.locator('#new-badge-name')).toHaveValue('');
    expect(await stillHere(), 'adding did not reload the page').toBe('still-here');

    // The clone is a real row: its own switch, its label and its bin. From here
    // on it is found by its id, which never changes (its name does).
    const badgeId = await created.getAttribute('data-id');
    expect(Number(badgeId)).toBeGreaterThan(0);
    const row = page.locator(`.badge-row[data-id="${badgeId}"]`);
    await expect(row.locator('.badge-active-input')).toHaveAttribute('id', `badge-active-${badgeId}`);
    await expect(row.locator('label')).toHaveAttribute('for', `badge-active-${badgeId}`);
    await expect(row.locator('.badge-delete-btn')).toBeEnabled();

    // --- rename (the new row, without a reload) ----------------------------
    const nameInput = row.locator('.badge-name-input');
    await nameInput.fill('Communication E2E 2');
    await nameInput.blur();
    await expect(toast.filter({ hasText: 'Enregistré.' }).first()).toBeVisible();
    await expect(nameInput).toHaveValue('Communication E2E 2');
    expect(await stillHere(), 'renaming did not reload the page').toBe('still-here');

    // --- switch off (the new row, without a reload) -------------------------
    const toggle = row.locator('.badge-active-input');
    await expect(toggle).toBeChecked();
    await toggle.uncheck();
    await expect(toggle).not.toBeChecked();
    await expect(toggle).toHaveAttribute('aria-checked', 'false');
    await expect(toggle).toBeEnabled();
    expect(await stillHere(), 'switching did not reload the page').toBe('still-here');

    // --- what the server stored is what the page showed ---------------------
    // The page is ordered by name by the server; without a reload the script has
    // to put a created or renamed row where the server would — so the order on
    // screen before the reload is the order the server gives after it.
    const listedNames = () => page.locator('#badge-list .badge-name-input').evaluateAll((inputs) => inputs.map((input) => input.value));
    const orderShown = await listedNames();
    await page.reload({ waitUntil: 'domcontentloaded' });
    expect(await listedNames(), 'the order shown without a reload is the server order').toEqual(orderShown);
    const stored = page.locator(`.badge-row[data-id="${badgeId}"]`);
    await expect(stored.locator('.badge-name-input')).toHaveValue('Communication E2E 2');
    await expect(stored.locator('.badge-active-input')).not.toBeChecked();
    await page.evaluate(() => { window.__badgesPageMarker = 'still-here'; });

    // --- delete (confirmation answered by autoConfirm) ----------------------
    await stored.locator('.badge-delete-btn').click();
    await expect(page.locator(`.badge-row[data-id="${badgeId}"]`)).toHaveCount(0);
    await expect(toast.filter({ hasText: 'Badge supprimé.' })).toBeVisible();
    expect(await stillHere(), 'deleting did not reload the page').toBe('still-here');
    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
