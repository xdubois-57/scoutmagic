// End-to-end: the complete scout-year transition, driven through the real
// "Année scoute" page in a real browser.
//
// ============================================================================
// SOURCE OF TRUTH
// ----------------------------------------------------------------------------
// This scenario is a transcription of the workflow the "Année scoute" page
// itself describes — core/View/templates/admin/scout_year.html.twig, whose
// steps come from Core\Http\Controller\ScoutYearController::
// buildTransitionSteps(). That page is the specification; this file only
// follows it.
//
// **When that page changes, this test must change with it.** The page
// carries a matching reminder comment pointing back here, so whoever edits
// one is told about the other.
//
// The four steps, as the page words them:
//   1. "Prévisualiser le site de l'année prochaine (<cible>)" — session-only,
//      "n'affecte aucun autre utilisateur".
//   2. "Importer les données Desk" — done once the target year has members.
//   3. "Activer l'année <cible> pour les staffs" — "les chefs et intendants
//      verront <cible> […] tandis que les animés et les visiteurs restent sur
//      l'année courante".
//   4. "Activer pour tout le monde" — "bascule l'ensemble du site (visiteurs
//      inclus) […] et désactive l'année du staff".
// ============================================================================
//
// Why this is worth an end-to-end test rather than more unit tests: the
// year in effect for a given request is decided by
// Core\ScoutYear\ScoutYearResolver from three sources at once — a
// session-only preview, a global staff-year setting, and the global public
// year — and the whole point of the workflow is that those three disagree
// with each other, on purpose, for part of it. That disagreement is only
// real across genuine HTTP requests carrying a genuine session; a unit
// test asserting each source separately cannot see it. This is also the
// one scenario in the suite that exercises an authenticated admin session,
// a file upload, and a multi-request stateful workflow.
//
// This scenario deliberately mutates global instance state (it moves the
// public year, and imports members): that is the feature under test. Every
// `npm run e2e` provisions a fresh database, and the suite runs on a single
// worker, so nothing leaks between runs — but a future scenario that
// depends on the public year must not assume it still is what the harness
// provisioned.
//
// No year label is ever hardcoded: ScoutYearService derives them from the
// calendar, so a hardcoded "2025-2026" would start failing on its own in
// September. Every label is read from the page and then asserted
// *relationally* (the public year becomes the year step 1 targeted, and so
// on), which is what the workflow actually promises.
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { expect, test } from '@playwright/test';

import { loginAsAdmin } from '../support/admin-login.js';

const DESK_FIXTURE = path.join(
    path.dirname(fileURLToPath(import.meta.url)),
    // The same Desk export tests/Core/Import/ already uses — one fixture
    // for both suites, never a second copy that could drift from the
    // format Core\Import actually parses.
    '../../fixtures/desk_export_sample.csv',
);

/**
 * The card carrying a given year heading ("Année publique", "Année du
 * staff"). Anchored on the heading — a real semantic landmark — because
 * the value sits as a sibling inside the same card, which is precisely
 * what the page means by it; only the "same card" part is structural.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} heading
 */
function yearCard(page, heading) {
    return page.locator('.card').filter({
        has: page.getByRole('heading', { level: 2, name: heading }),
    });
}

/**
 * A step's own form, identified by the route it posts to. The three year
 * selects on this page all share the accessible name "Année" (each
 * belongs to its own step), so the route is what disambiguates them — and
 * a route is a real contract from public/index.php's routing table, not
 * incidental markup.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} action
 */
function stepForm(page, action) {
    return page.locator(`form[action="${action}"]`);
}

/**
 * Pick a year by its label in one step's own "Année" select.
 *
 * @param {import('@playwright/test').Locator} form
 * @param {string} label
 */
async function chooseYear(form, label) {
    await form.getByLabel('Année').selectOption({ label });
}

test('the whole site transitions to the next scout year through the four documented steps', async ({ page }) => {
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

    // Step 4 guards itself with a window.confirm() ("Activer cette année
    // pour tout le monde ?"). Playwright dismisses dialogs by default,
    // which would silently cancel the submit, so accepting it is part of
    // driving the page as a human would.
    page.on('dialog', (dialog) => dialog.accept());

    await loginAsAdmin(page);

    await page.goto('/admin/scout-year', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Année scoute' })).toBeVisible();

    const publicYearCard = yearCard(page, 'Année publique');
    const previewForm = stepForm(page, '/admin/scout-year/preview');
    const staffForm = stepForm(page, '/admin/scout-year/activate-staff');
    const publicForm = stepForm(page, '/admin/scout-year/activate-public');

    // The year the workflow is about to move away from, and the one it
    // targets — both read from the page, never assumed.
    // textContent() rather than innerText() for the option: Chromium
    // reports no rendered text for an <option> inside a closed <select>.
    const outgoingYear = (await publicYearCard.locator('p.fs-5').innerText()).trim();
    const targetYear = (await previewForm.getByLabel('Année').locator('option:checked').textContent() ?? '').trim();

    expect(targetYear, 'the page must target a different year than the current public one')
        .not.toBe(outgoingYear);

    // ---------------------------------------------------------------
    // Before anything: the page enforces its own ordering through the
    // buttons. Only the current step's button is enabled, so this is the
    // workflow's gating as a visitor actually experiences it — not a CSS
    // class read out of the markup.
    // ---------------------------------------------------------------
    await expect(previewForm.getByRole('button', { name: 'Prévisualiser' })).toBeEnabled();
    await expect(staffForm.getByRole('button', { name: 'Activer pour le staff' })).toBeDisabled();
    await expect(publicForm.getByRole('button', { name: 'Activer pour tout le monde' })).toBeDisabled();
    await expect(page.getByText("Activez d'abord une année pour le staff (étape 3).")).toBeVisible();
    await expect(yearCard(page, 'Année du staff')).toHaveCount(0);

    // ---------------------------------------------------------------
    // Step 1 — "Prévisualiser le site de l'année prochaine".
    // The page promises this "ne concerne que votre session et n'affecte
    // aucun autre utilisateur", so both halves are asserted: the visitor
    // does move, and the year everyone else sees does not.
    // ---------------------------------------------------------------
    await chooseYear(previewForm, targetYear);
    await previewForm.getByRole('button', { name: 'Prévisualiser' }).click();

    await expect(page.getByRole('alert').filter({ hasText: 'Prévisualisation activée pour cette session.' }))
        .toBeVisible();
    // base.html.twig's site-wide banner — the preview is visible on every
    // page, and says in so many words that it is session-scoped.
    await expect(page.getByRole('alert').filter({ hasText: `Vous visualisez le site en ${targetYear} (session uniquement).` }))
        .toBeVisible();
    await expect(publicYearCard).toContainText(outgoingYear);

    await expect(previewForm.getByRole('button', { name: 'Prévisualiser' })).toBeDisabled();
    await expect(page.getByRole('link', { name: "Aller à l'import Desk" })).toBeVisible();

    // ---------------------------------------------------------------
    // Step 2 — "Importer les données Desk". Followed through the page's
    // own link, and done with a real multipart upload of a real Desk
    // export: the step is complete only once the target year genuinely
    // has members (ScoutYearResolver::countMembers() > 0), which no
    // amount of clicking can fake.
    // ---------------------------------------------------------------
    await page.getByRole('link', { name: "Aller à l'import Desk" }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Import Desk' })).toBeVisible();

    const importForm = page.locator('form[action="/admin/import"]');
    await importForm.getByLabel('Année scoute cible').selectOption({ label: targetYear });
    await importForm.getByLabel('Fichier CSV à importer').setInputFiles(DESK_FIXTURE);
    // exact: Chromium exposes <input type="file"> as a button too, and its
    // accessible name ("Fichier CSV à importer") contains "importer".
    await importForm.getByRole('button', { name: 'Importer', exact: true }).click();

    await expect(page.getByText(/membres importés/)).toBeVisible();

    await page.goto('/admin/scout-year', { waitUntil: 'domcontentloaded' });
    await expect(staffForm.getByRole('button', { name: 'Activer pour le staff' })).toBeEnabled();

    // ---------------------------------------------------------------
    // Step 3 — "Activer l'année <cible> pour les staffs". The page's own
    // wording is the assertion: chiefs move, "les animés et les visiteurs
    // restent sur l'année courante".
    // ---------------------------------------------------------------
    await chooseYear(staffForm, targetYear);
    await staffForm.getByRole('button', { name: 'Activer pour le staff' }).click();

    await expect(page.getByRole('alert').filter({ hasText: `Année ${targetYear} activée pour le staff.` }))
        .toBeVisible();

    const staffYearCard = yearCard(page, 'Année du staff');
    await expect(staffYearCard).toContainText(targetYear);
    await expect(staffYearCard).toContainText('Vue par les chefs et intendants uniquement.');
    // The half that matters most, and the one a unit test cannot see: the
    // year everyone else is served has still not moved.
    await expect(publicYearCard).toContainText(outgoingYear);

    // ---------------------------------------------------------------
    // The staff year, seen without a preview shadowing it.
    //
    // ScoutYearResolver resolves preview → staff → public in that order,
    // so this session's own step-1 preview is still what it sees. Dropping
    // it through the page's own "Revenir à l'année courante" control is
    // what reveals the staff year underneath — and it also demonstrates,
    // deliberately, that step 1 tracks live session state: with the
    // preview gone the page walks its own ordering back to step 1, and
    // step 4 locks again until it is set anew.
    // ---------------------------------------------------------------
    // Two controls carry this name — the site-wide banner's close button
    // and step 1's own — so this is scoped to the banner, which is the
    // one a visitor reaches from any page.
    await page
        .getByRole('alert')
        .filter({ hasText: `Vous visualisez le site en ${targetYear} (session uniquement).` })
        .getByRole('button', { name: "Revenir à l'année courante" })
        .click();

    await expect(page.getByRole('alert').filter({ hasText: `Le staff voit actuellement l'année ${targetYear}.` }))
        .toBeVisible();
    await expect(publicYearCard).toContainText(outgoingYear);
    await expect(publicForm.getByRole('button', { name: 'Activer pour tout le monde' })).toBeDisabled();

    await chooseYear(previewForm, targetYear);
    await previewForm.getByRole('button', { name: 'Prévisualiser' }).click();
    await expect(publicForm.getByRole('button', { name: 'Activer pour tout le monde' })).toBeEnabled();

    // ---------------------------------------------------------------
    // Step 4 — "Activer pour tout le monde": the whole site moves, "de
    // façon permanente", and the staff year is cleared automatically.
    // ---------------------------------------------------------------
    await chooseYear(publicForm, targetYear);
    await publicForm.getByRole('button', { name: 'Activer pour tout le monde' }).click();

    await expect(page.getByRole('alert').filter({ hasText: `Année ${targetYear} activée pour tout le monde.` }))
        .toBeVisible();
    await expect(publicYearCard).toContainText(targetYear);
    await expect(publicYearCard).toContainText('Année vue par tout le monde.');
    // "…et désactive l'année du staff": staff and public are aligned
    // again, so the staff card is gone entirely.
    await expect(yearCard(page, 'Année du staff')).toHaveCount(0);

    // And the workflow has rolled forward: with the site now on what used
    // to be the target, the page describes the transition to the year
    // after it, from step 1. That is the clearest single proof the
    // transition actually committed rather than merely flashing a message.
    await expect(page.getByText(`Prévisualiser le site de l'année prochaine (${targetYear})`)).toHaveCount(0);
    await expect(previewForm.getByRole('button', { name: 'Prévisualiser' })).toBeEnabled();
    await expect(staffForm.getByRole('button', { name: 'Activer pour le staff' })).toBeDisabled();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
