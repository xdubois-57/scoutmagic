// End-to-end: the two auto-saving grids of the registration module —
// Départs and Passage.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Both templates carry the codebase's own CSP post-mortem in their
// comments: an inline `on*` handler reintroduced on these pages dies
// silently under the site's nonce-only Content-Security-Policy — the
// selects "were unable to save anything at all" while every PHPUnit and
// Vitest suite stayed green, because no other stack executes a page's
// inline script under the real CSP against the real endpoint. These two
// grids also have no submit button anywhere: every checkbox change and
// every comment blur IS the save (departures.html.twig says so to the
// user), so "the page still saves" is a property only a browser can hold.
//
// The departure grid additionally pins the module's own concurrency rule:
// `leaving` and `comment` travel as separate single-field saves so two
// animateurs on the same section never clobber each other — the reload
// asserting BOTH fields is what notices a regression to a whole-form save
// that dropped one.
//
// ORDERING
// ----------------------------------------------------------------------------
// Runs after registration-flow.spec.js (alphabetical: -flow < -grids),
// and reuses the registration request that scenario left WITHDRAWN: the
// Passage page lists accepted requests, so this one reverts it, accepts
// it, drives the grid, and withdraws it again — ending, like its
// predecessor, in the final state that keeps the scout-year transition
// unvetoed for the spec that runs it later.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';

const MEMBER_NAME = 'Kaa Serpent';
const DEPARTURE_COMMENT = 'Déménage à Namur cet été.';

test('the departures and passage grids save on change, with no save button anywhere', async ({ page }) => {
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
        // The grids report a failed save through window.alert(); the
        // request detail's revert uses a data-confirm dialog. Confirms are
        // accepted, everything else is a failure recorded for the final
        // assertion.
        if (dialog.type() === 'confirm') {
            await dialog.accept();
            return;
        }
        alerts.push(dialog.message());
        await dialog.dismiss();
    });

    await loginAsAdmin(page);
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // Départs. Checking the box saves on its own, reveals the comment
    // row, and the comment saves on blur — each as its own request.
    // ---------------------------------------------------------------
    await page.goto('/departs', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Départs' })).toBeVisible();

    // An admin staffs every section; walk to the one the harness seeded
    // both members into.
    await page.getByRole('link', { name: 'Meute E2E' }).click();
    await page.waitForURL(/\/departs\?section_id=\d+/, { waitUntil: 'domcontentloaded' });

    const leavingBox = page.getByRole('checkbox', { name: `Ne sera plus là l'année prochaine — ${MEMBER_NAME}` });
    await expect(leavingBox).not.toBeChecked();

    const saveOfLeaving = page.waitForResponse((response) => response.url().includes('/departs/') && response.request().method() === 'POST');
    await leavingBox.check();
    expect ((await (await saveOfLeaving).json()).success, 'checking the box must save on its own').toBe(true);

    const commentField = page.getByLabel(`Commentaire de départ — ${MEMBER_NAME}`);
    await expect(commentField).toBeVisible();

    const saveOfComment = page.waitForResponse((response) => response.url().includes('/departs/') && response.request().method() === 'POST');
    await commentField.fill(DEPARTURE_COMMENT);
    await commentField.blur();
    expect((await (await saveOfComment).json()).success).toBe(true);

    // The reload is the point: both single-field saves must have landed —
    // a regression to one whole-form save typically drops one of them.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('checkbox', { name: `Ne sera plus là l'année prochaine — ${MEMBER_NAME}` })).toBeChecked();
    await expect(page.getByLabel(`Commentaire de départ — ${MEMBER_NAME}`)).toHaveValue(DEPARTURE_COMMENT);

    // Put the member back: a standing "leaving" mark would surface in the
    // passage and forecast views every spec after this one reads.
    await page.getByRole('checkbox', { name: `Ne sera plus là l'année prochaine — ${MEMBER_NAME}` }).uncheck();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('checkbox', { name: `Ne sera plus là l'année prochaine — ${MEMBER_NAME}` })).not.toBeChecked();

    // ---------------------------------------------------------------
    // Passage. Bring the withdrawn request of registration-flow.spec.js
    // back to ACCEPTED so the grid has a row to save.
    // ---------------------------------------------------------------
    await page.goto('/config/inscriptions', { waitUntil: 'domcontentloaded' });
    // One request exists on a canonical run — registration-flow's. first()
    // keeps a developer's re-run against a lived-in instance from tripping
    // on its own older copies.
    await page.getByRole('row', { name: /Zoé/ }).first().getByRole('link', { name: 'Ouvrir' }).click();
    await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });

    await page.getByRole('button', { name: 'Revenir en attente' }).click();
    await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Accepter', exact: true }).click();
    await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });

    await page.goto('/passage', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Passage' })).toBeVisible();
    await expect(page.getByRole('heading', { name: /Nouvelles inscriptions/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: /Changements de branche/ })).toBeVisible();

    // The accepted child is in the grid, with the same select+save pair
    // the CSP post-mortem is about.
    await expect(page.getByRole('row', { name: /Zoé/ }).first()).toBeVisible();

    // ---------------------------------------------------------------
    // Back to the final state.
    // ---------------------------------------------------------------
    await page.goto('/config/inscriptions', { waitUntil: 'domcontentloaded' });
    await page.getByRole('row', { name: /Zoé/ }).first().getByRole('link', { name: 'Ouvrir' }).click();
    await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Retirer' }).click();
    await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Retirée')).toBeVisible();

    expect(alerts, 'a grid reported a failed save through window.alert()').toEqual([]);
    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
