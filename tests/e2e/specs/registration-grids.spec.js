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
// The scenario also drives the waitlist switch on /config/inscriptions/
// reglages, the third save-on-change control of this module (issue #843,
// IT-03): turning it off greys the two thresholds out on the spot — still
// shown, disabled, their stored values kept — and the page reloaded says
// the same. It then saves the thresholds and the « Priorités » with their
// own buttons: the priorities' fields are renamed by the page's script from
// the connected lists they sit in when the form is sent, which only a real
// browser, under the real CSP, can show working.
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
import { waitForServerResponse } from '../support/response.js';

const MEMBER_NAME = 'Kaa Serpent';
const DEPARTURE_COMMENT = 'Déménage à Namur cet été.';

/**
 * Submits one form of /config/inscriptions/reglages and waits for the page
 * its redirect renders.
 *
 * The barrier is the flash message the save leaves, not the URL: this POST
 * redirects to the address the browser is ALREADY on, so `waitForURL`
 * would resolve instantly and let the next assertion read the page from
 * before the save. Every call starts from a freshly loaded page, which
 * shows no flash, so the message cannot be a leftover.
 *
 * The button is scoped to the form: the page has five « Enregistrer ».
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} action the form's action
 * @param {string} flash the message the save leaves
 */
async function saveSettingsForm(page, action, flash) {
    await page.locator(`form[action="${action}"]`)
        .getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: flash })).toBeVisible();
}

/**
 * Flips « Gérer les listes d'attente », which saves on change, and waits
 * for the server's answer.
 *
 * @param {import('@playwright/test').Page} page
 * @param {boolean} on
 */
async function setWaitlist(page, on) {
    const saved = waitForServerResponse(
        page,
        (response) => response.url().endsWith('/config/inscriptions/listes-attente')
            && response.request().method() === 'POST',
    );
    const toggle = page.getByRole('checkbox', { name: "Gérer les listes d'attente" });
    await (on ? toggle.check() : toggle.uncheck());
    const response = await saved;
    expect(response.status(), 'the waitlist switch saved').toBe(200);
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
    await expect(page.getByRole('heading', { level: 1, name: "Départs de l'unité" })).toBeVisible();

    // An admin staffs every section; walk to the one the harness seeded
    // both members into. The section picker is a select bar, so its
    // options live in a panel that has to be opened first — see
    // ../support/select-bar.js.
    await chooseInSelectBar(page, 'section-picker', 'Meute E2E');
    await page.waitForURL(/\/departs\?section_id=\d+/, { waitUntil: 'domcontentloaded' });

    const leavingBox = page.getByRole('checkbox', { name: `Ne sera plus là l'année prochaine — ${MEMBER_NAME}` });
    await expect(leavingBox).not.toBeChecked();

    const saveOfLeaving = waitForServerResponse(page, (response) => response.url().includes('/departs/') && response.request().method() === 'POST');
    await leavingBox.check();
    expect ((await (await saveOfLeaving).json()).success, 'checking the box must save on its own').toBe(true);

    const commentField = page.getByLabel(`Note interne — jamais vue par la famille — ${MEMBER_NAME}`);
    await expect(commentField).toBeVisible();

    const saveOfComment = waitForServerResponse(page, (response) => response.url().includes('/departs/') && response.request().method() === 'POST');
    await commentField.fill(DEPARTURE_COMMENT);
    await commentField.blur();
    expect((await (await saveOfComment).json()).success).toBe(true);

    // The reload is the point: both single-field saves must have landed —
    // a regression to one whole-form save typically drops one of them.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('checkbox', { name: `Ne sera plus là l'année prochaine — ${MEMBER_NAME}` })).toBeChecked();
    await expect(page.getByLabel(`Note interne — jamais vue par la famille — ${MEMBER_NAME}`)).toHaveValue(DEPARTURE_COMMENT);

    // Put the member back: a standing "leaving" mark would surface in the
    // passage and forecast views every spec after this one reads.
    // Waited for, exactly like the check() above it — unchecking saves on
    // its own too, and reloading straight away races that request: the
    // navigation aborts the POST, the server keeps the member marked as
    // leaving, and the assertion below reads a box that is still checked.
    // Harmless by hand, where nobody reloads inside the same tick; it
    // failed in CI under the security scan, whose added latency widens the
    // window (Playwright reports uncheck() done once the DOM reflects it,
    // not once the save has landed).
    const saveOfReset = waitForServerResponse(page, (response) => response.url().includes('/departs/') && response.request().method() === 'POST');
    await page.getByRole('checkbox', { name: `Ne sera plus là l'année prochaine — ${MEMBER_NAME}` }).uncheck();
    expect((await (await saveOfReset).json()).success, 'unchecking the box must save on its own').toBe(true);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('checkbox', { name: `Ne sera plus là l'année prochaine — ${MEMBER_NAME}` })).not.toBeChecked();

    // ---------------------------------------------------------------
    // Passage. Bring the withdrawn request of registration-flow.spec.js
    // back to ACCEPTED so the grid has a row to save.
    // ---------------------------------------------------------------
    await page.goto('/config/inscriptions/demandes', { waitUntil: 'domcontentloaded' });
    // One request exists on a canonical run — registration-flow's. first()
    // keeps a developer's re-run against a lived-in instance from tripping
    // on its own older copies.
    await page.getByRole('row', { name: /Zoé/ }).first().getByRole('link', { name: 'Ouvrir' }).click();
    await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });

    // Waited for by the STATUS each POST produces, not by the URL.
    //
    // Both actions redirect back to this same address, so
    // waitForURL(/\/config\/inscriptions\/demandes\/\d+$/) matched the page
    // already on screen and returned without waiting for anything at all.
    // The next click then went out against a page whose POST had not
    // landed: the acceptance was lost, the child never reached /passage,
    // and the failure surfaced twenty lines below as a row that does not
    // exist — with the two specs after this one failing in turn, since
    // they inherit its request.
    //
    // request_detail.html.twig renders the state as a badge, so waiting
    // for the badge waits for the fact rather than for an address that
    // never changed.
    await page.getByRole('button', { name: 'Revenir en attente' }).click();
    await expect(page.getByText('En attente', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Accepter', exact: true }).click();
    await expect(page.getByText('Acceptée', { exact: true })).toBeVisible();

    await page.goto('/passage', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Passage' })).toBeVisible();
    await expect(page.getByRole('heading', { name: /Nouvelles inscriptions/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: /Changements de branche/ })).toBeVisible();

    // The accepted child is in the grid, with the section picker
    // the CSP post-mortem is about.
    await expect(page.getByRole('row', { name: /Zoé/ }).first()).toBeVisible();

    // ---------------------------------------------------------------
    // Back to the final state.
    // ---------------------------------------------------------------
    await page.goto('/config/inscriptions/demandes', { waitUntil: 'domcontentloaded' });
    await page.getByRole('row', { name: /Zoé/ }).first().getByRole('link', { name: 'Ouvrir' }).click();
    await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Retirer' }).click();
    await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });
    // `exact` because the contextual help panel on this page explains the
    // status transitions in prose — « En attente → Acceptée ou Retirée » —
    // so a substring match resolves to the badge AND that sentence.
    await expect(page.getByText('Retirée', { exact: true })).toBeVisible();

    // ---------------------------------------------------------------
    // The waitlist switch, inside the capacity box it governs on
    // « Réglages ». Off must remove the availability columns of the
    // dashboard's « Vérification des capacités » and grey out the two
    // occupancy thresholds — still shown, disabled, their stored values
    // kept (chantier #843, D3) — and on again must find them unchanged and
    // editable.
    // ---------------------------------------------------------------
    await page.goto('/config/inscriptions', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('columnheader', { name: 'Niveau public' })).toBeVisible();

    await page.goto('/config/inscriptions/reglages', { waitUntil: 'domcontentloaded' });
    const yesThreshold = () => page.getByLabel("Oui jusqu'à … % de places occupées", { exact: true });
    const maybeThreshold = () => page.getByLabel("Peut-être jusqu'à … % de places occupées", { exact: true });
    await expect(yesThreshold(), 'the capacity box is open on arrival — nothing folds away').toBeEnabled();
    await expect(maybeThreshold()).toBeEnabled();
    const storedYes = await yesThreshold().inputValue();
    const storedMaybe = await maybeThreshold().inputValue();
    expect(storedYes, 'the threshold must have a stored value to lose').not.toBe('');
    expect(storedMaybe, 'the threshold must have a stored value to lose').not.toBe('');

    await setWaitlist(page, false);
    await expect(
        yesThreshold(),
        'a threshold without effect is greyed out on the spot, not hidden',
    ).toBeDisabled();
    await expect(page.getByText('(sans effet tant que les listes d\'attente ne sont pas gérées)')).toBeVisible();

    await page.goto('/config/inscriptions/reglages', { waitUntil: 'domcontentloaded' });
    await expect(
        yesThreshold(),
        'a threshold without effect stays on screen, greyed out rather than editable',
    ).toBeDisabled();
    await expect(maybeThreshold()).toBeDisabled();
    await expect(yesThreshold(), 'a greyed-out threshold still shows the stored value').toHaveValue(storedYes);
    await expect(maybeThreshold()).toHaveValue(storedMaybe);

    await page.goto('/config/inscriptions', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('columnheader', { name: 'Niveau public' })).toHaveCount(0);
    await expect(page.getByRole('columnheader', { name: 'Restant' })).toHaveCount(0);

    await page.goto('/config/inscriptions/reglages', { waitUntil: 'domcontentloaded' });
    await setWaitlist(page, true);
    await expect(yesThreshold(), 'the threshold is editable again once waitlists are managed').toBeEnabled();
    await page.goto('/config/inscriptions/reglages', { waitUntil: 'domcontentloaded' });
    await expect(yesThreshold(), 'and still is after a reload').toBeEnabled();
    await expect(
        yesThreshold(),
        'a threshold greyed out while the waitlist was off must come back unchanged',
    ).toHaveValue(storedYes);
    await expect(maybeThreshold()).toHaveValue(storedMaybe);

    // The thresholds sub-box saves with its own button; the slider follows
    // the field once it is left.
    await yesThreshold().fill('65');
    await yesThreshold().press('Tab');
    await expect(page.getByRole('slider', { name: "Oui jusqu'à ce pourcentage de places occupées" })).toHaveValue('65');
    await saveSettingsForm(page, '/config/inscriptions/seuils', 'Seuils enregistrés.');
    await expect(yesThreshold()).toHaveValue('65');
    await yesThreshold().fill(storedYes);
    await saveSettingsForm(page, '/config/inscriptions/seuils', 'Seuils enregistrés.');
    await expect(yesThreshold()).toHaveValue(storedYes);

    // « Priorités »: the order shown is the order saved, under the zone each
    // criterion sits in.
    const priorities = page.locator('form[action="/config/inscriptions/priorites"]');
    await expect(priorities.getByText("Limite : le nombre de places de l'année de parcours")).toBeVisible();
    await saveSettingsForm(page, '/config/inscriptions/priorites', 'Priorités enregistrées.');
    await expect(
        page.locator('#priority-always .list-editor-item'),
        'the default « Toujours Oui » survives a save of the page as shown',
    ).toHaveText([/Fratrie déjà dans l'unité/]);
    await expect(page.locator('#priority-rank .list-editor-item')).toHaveText([
        /Ancienne demande restée sans place/,
        /Ordre d'arrivée de la demande/,
    ]);

    await page.goto('/config/inscriptions', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('columnheader', { name: 'Niveau public' })).toBeVisible();

    expect(alerts, 'a grid reported a failed save through window.alert()').toEqual([]);
    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
