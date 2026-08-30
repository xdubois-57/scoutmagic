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
// The scenario also drives the waitlist switch on /config/inscriptions,
// which is the third shape of "the browser decides" on this module: the
// switch is a plain checkbox, so turning it OFF sends no field at all, and
// what the server does with that absence decides whether two thresholds
// the chief never saw survive the save. A round trip through a real form
// is the only place that is observable — a PHPUnit request array is
// written by the test, and would carry whatever the test believed.
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
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { chooseInSelectBar } from '../support/select-bar.js';

const MEMBER_NAME = 'Kaa Serpent';
const DEPARTURE_COMMENT = 'Déménage à Namur cet été.';

/**
 * Unfolds the « Capacités par branche » box of /config/inscriptions.
 * Collapsed is its default, so every control inside it — the waitlist
 * switch, the thresholds, the grid — is genuinely unreachable until this
 * runs.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openCapacitiesBox(page) {
    await page.getByRole('button', { name: 'Capacités par branche', exact: true }).click();
    await expect(page.locator('#registration-capacities-box')).toBeVisible();
}

/**
 * Submits that box and waits for the page its redirect renders.
 *
 * The barrier is the toggle going back to `aria-expanded="false"`, not the
 * URL: this POST redirects to the address the browser is ALREADY on, so
 * `waitForURL` would resolve instantly and let the next assertion read the
 * page from before the save. The box is open when the click happens and
 * folded again on the page that comes back.
 *
 * The button is scoped to the panel: the rich-text editor's modal on this
 * page carries an « Enregistrer » of its own.
 *
 * @param {import('@playwright/test').Page} page
 */
async function saveCapacitiesBox(page) {
    await Promise.all([
        page.waitForResponse((response) =>
            response.request().method() === 'POST' && response.url().endsWith('/config/inscriptions')),
        page.locator('#registration-capacities-box')
            .getByRole('button', { name: 'Enregistrer', exact: true }).click(),
    ]);
    await expect(page.getByRole('button', { name: 'Capacités par branche', exact: true }))
        .toHaveAttribute('aria-expanded', 'false');
}

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
    // « Revenir en attente » carries a data-confirm, answered by the
    // site's own modal now (base.html.twig → window.ScoutMagicConfirm),
    // which Playwright never sees as a dialog. native: false because the
    // grids themselves still report a failed save through window.alert()
    // — the handler above captures those, and two handlers answering one
    // native dialog is an error, not a redundancy.
    await autoConfirm(page, { native: false });

    await loginAsAdmin(page);
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // Départs. Checking the box saves on its own, reveals the comment
    // row, and the comment saves on blur — each as its own request.
    // ---------------------------------------------------------------
    await page.goto('/departs', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Départs' })).toBeVisible();

    // An admin staffs every section; walk to the one the harness seeded
    // both members into. The section picker is a select bar, so its
    // options live in a panel that has to be opened first — see
    // ../support/select-bar.js.
    await chooseInSelectBar(page, 'section-picker', 'Meute E2E');
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
    // `exact` because the contextual help panel on this page explains the
    // status transitions in prose — « En attente → Acceptée ou Retirée » —
    // so a substring match resolves to the badge AND that sentence.
    await expect(page.getByText('Retirée', { exact: true })).toBeVisible();

    // ---------------------------------------------------------------
    // The waitlist switch, inside the capacity box it governs. Off must
    // remove everything that depends on it — the two ratio thresholds and
    // the availability columns of « Vérification des capacités » — while
    // leaving the stored values alone: they come back unchanged when it
    // goes on again.
    // ---------------------------------------------------------------
    await page.goto('/config/inscriptions', { waitUntil: 'domcontentloaded' });
    await expect(
        page.getByRole('button', { name: 'Capacités par branche', exact: true }),
        'the capacity box opens on demand',
    ).toHaveAttribute('aria-expanded', 'false');
    await openCapacitiesBox(page);

    const availableThreshold = page.getByLabel('Seuil « places disponibles »');
    await expect(availableThreshold).toBeVisible();
    const storedThreshold = await availableThreshold.inputValue();
    expect(storedThreshold, 'the threshold must have a stored value to lose').not.toBe('');
    await expect(page.getByRole('columnheader', { name: 'Niveau public' })).toBeVisible();

    await page.getByRole('switch', { name: "Gérer les listes d'attente" }).uncheck();
    await saveCapacitiesBox(page);

    await openCapacitiesBox(page);
    await expect(
        page.getByLabel('Seuil « places disponibles »'),
        'a threshold that no longer means anything must not be on screen',
    ).toHaveCount(0);
    await expect(page.getByRole('columnheader', { name: 'Niveau public' })).toHaveCount(0);
    await expect(page.getByRole('columnheader', { name: 'Restant' })).toHaveCount(0);

    await page.getByRole('switch', { name: "Gérer les listes d'attente" }).check();
    await saveCapacitiesBox(page);

    await openCapacitiesBox(page);
    await expect(
        page.getByLabel('Seuil « places disponibles »'),
        'a threshold hidden while the waitlist was off must come back unchanged',
    ).toHaveValue(storedThreshold);
    await expect(page.getByRole('columnheader', { name: 'Niveau public' })).toBeVisible();

    expect(alerts, 'a grid reported a failed save through window.alert()').toEqual([]);
    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
