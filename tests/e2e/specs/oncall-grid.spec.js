// End-to-end: the SOS on-call duty grid — a three-state cell cycle whose
// every click re-posts the whole month.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The grid's state lives in a JS object seeded from Twig
// (`cellStates = {{ states|json_encode }}`), mutated in place on every
// click, and saved by serialising the ENTIRE month back to
// /admin/sos/oncall — there is no per-cell endpoint and no save button.
// The same click also has to keep two renderings of the same cell in
// step (a desktop <td> and a phone-layout <button> share one class and
// are updated together). A serialization drift, a CSP regression or a
// listener mishap silently turns the roster read-only, while PHPUnit —
// posting its own arrays — stays green. The on-call roster is what the
// unit's public emergency number redirects to, so "the grid still
// saves" is worth its own scenario.
//
// WHAT IT DELIBERATELY LEAVES ALONE
// ----------------------------------------------------------------------------
// /config/sos (the OVH credential wizard) — every step of it calls the
// real OVH API, which a hermetic suite cannot depend on. The redirect
// application task is Tests\Modules\SosStaff territory.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';

test('the duty grid cycles a cell through its three states, saving the month on every click', async ({ page }) => {
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

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto('/admin/sos', { waitUntil: 'load' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // The desktop table renders one clickable cell per staff member per
    // day; the harness's unit chief (Baden Powell) is its one column.
    // A mid-month day keeps the assertion independent of month edges.
    const cell = page.locator('td.sos-oncall-cell[data-date$="-15"]').first();
    await expect(cell).toBeVisible();
    const cellDate = await cell.getAttribute('data-date');
    const cellMember = await cell.getAttribute('data-member-id');
    /** The same cell, re-located after a reload. */
    const cellAgain = () => page.locator(`td.sos-oncall-cell[data-date="${cellDate}"][data-member-id="${cellMember}"]`);

    const saveStatus = page.locator('#oncall-save-status');

    /** Click the cell once and wait for the full-month save to answer. */
    async function clickAndSave() {
        const saved = page.waitForResponse((response) => response.url().includes('/admin/sos/oncall'));
        await cellAgain().click();
        expect((await saved).ok()).toBe(true);
        await expect(saveStatus).toHaveText('Enregistré.');
    }

    // ---------------------------------------------------------------
    // First click: on call. The reload proves the month really landed.
    // ---------------------------------------------------------------
    await expect(cellAgain()).toHaveText('');
    await clickAndSave();
    await expect(cellAgain()).toHaveText('✓');

    await page.reload({ waitUntil: 'load' });
    await expect(cellAgain(), 'the on-call mark must survive a reload').toHaveText('✓');

    // The phone rendering of the same cell moved with it — one class,
    // two layouts, updated together.
    await expect(
        page.locator(`#sos-mobile-grid .sos-oncall-cell[data-date="${cellDate}"][data-member-id="${cellMember}"]`),
    ).toContainText('✓');

    // ---------------------------------------------------------------
    // Second click: unavailable. Third: back to blank — which also
    // leaves the shared instance's roster exactly as provisioned.
    // ---------------------------------------------------------------
    await clickAndSave();
    await expect(cellAgain()).toHaveText('✗');

    await clickAndSave();
    await expect(cellAgain()).toHaveText('');

    await page.reload({ waitUntil: 'load' });
    await expect(cellAgain(), 'the cleared cell must survive a reload too').toHaveText('');

    // The planned-transitions block rendered (its pagination is AJAX-fed
    // when there is more than a page of them; an empty roster just says
    // so).
    await expect(page.locator('#planned-transitions-list')).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
