// End-to-end: a unit records where it camped, and what it thought of it —
// in a real browser, through the real application.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The camps module's whole point is a chain no unit test crosses end to
// end: one form creates a PLACE and a STAY together, the duplicate
// detector gets a say before either is written, the stay's page is built
// from four repositories and a Core\Audit timeline, and the review is only
// offered once the camp is over. A break anywhere in that chain is
// invisible to PHPUnit — which sees a $_POST array a test wrote itself —
// and to Vitest, which sees a form with no server behind it.
//
// It stays ONE scenario rather than one per screen (AGENTS.md § Tests: the
// E2E suite is a release gate, not a coverage tool). Everything it does
// not drive through a browser is covered where it can be exercised
// exhaustively instead: the duplicate detector's own matching in
// Tests\Modules\Camps\Service\DuplicatePlaceDetectorTest, the merges in
// MergeServiceTest, the mail screen in CampsMailControllerTest, and every
// route's RBAC boundary in CampsRbacTest.
//
// WHAT IT ASSERTS THAT NOTHING ELSE CAN
// ----------------------------------------------------------------------------
// That the pieces are wired to each other on a running install: the place
// a chief describes in the creation form is the place the stay is attached
// to, the stay's page renders from a real database rather than from a
// fixture, the review dialog's form reaches the real controller through
// the real CSRF guard, and what comes back is on both the stay AND the
// place — which is the entire reason the module exists.
//
// DETERMINISM
// ----------------------------------------------------------------------------
// Every date is computed relative to today and every name carries a
// per-run suffix, so the scenario neither ages nor collides with itself.
// The stay is placed firmly in the past, because a review only opens once
// the camp is over (Service\ReviewService::isOpen()).
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text wherever they identify the element (README.md
// § Tests de bout en bout), field names only where a control has no
// accessible name of its own.
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from '../support/admin-login.js';
import { openSectionEditor } from '../support/section-editor.js';

function isoDaysAgo(days) {
    const date = new Date();
    date.setDate(date.getDate() - days);

    return date.toISOString().slice(0, 10);
}

// Unique per run: two runs against the same throwaway database must not
// make the second one match the first as a duplicate place.
const SUFFIX = Date.now().toString(36).slice(-5);
const PLACE_NAME = `Domaine de Mozet ${SUFFIX}`;
const CITY = 'Mozet';
const START = isoDaysAgo(40);
const END = isoDaysAgo(33);
const REVIEW_COMMENT = `Terrain en pente mais l'eau est au camp — ${SUFFIX}`;

test.describe('Camps', () => {
    test('a chief records a past camp and leaves a review on it', async ({ page }) => {
        await loginAsAdmin(page);

        // --- The list, before there is anything in it. ---
        await page.goto('/chefs/camps');
        await expect(page.getByRole('heading', { name: 'Camps' })).toBeVisible();

        // --- One form, a place and a stay. ---
        // Two screens would force a chief to think of the place before the
        // camp, which is the opposite of the order they learn about
        // either — so the creation form does both, and this is where that
        // actually gets exercised.
        await page.getByRole('link', { name: /Nouveau camp/i }).first().click();
        await expect(page.getByRole('heading', { name: 'Nouveau camp' })).toBeVisible();

        await page.locator('input[name="place_name"]').fill(PLACE_NAME);
        await page.locator('input[name="postal_code"]').fill('5340');
        await page.locator('input[name="city"]').fill(CITY);
        await page.locator('input[name="start_date"]').fill(START);
        await page.locator('input[name="end_date"]').fill(END);
        await page.locator('input[name="participant_count"]').fill('48');
        await page.locator('input[name="booked_by_name"]').fill('Thomas Dupont');

        await page.getByRole('button', { name: 'Créer le séjour' }).click();

        // --- The stay's own page. ---
        // The place was created with it and the stay is attached to that
        // place, not to a placeholder: the breadcrumb back to the place is
        // the proof, since it is built from the stay's own place id.
        await expect(page.getByRole('link', { name: PLACE_NAME }).first()).toBeVisible();
        await expect(page.getByText('48').first()).toBeVisible();

        const stayUrl = page.url();
        expect(stayUrl).toMatch(/\/chefs\/camps\/sejours\/\d+/);

        // --- The review, which only opens once the camp is over. ---
        // In a dialog, not a form standing open: the stay page keeps one
        // primary action (design.md §1.9), and Playwright refuses to fill
        // a hidden control — see ../support/section-editor.js for the
        // failure mode that helper rules out.
        const dialog = await openSectionEditor(page, 'review-modal');
        await dialog.locator('select[name="rating"]').selectOption('4');
        await dialog.locator('textarea[name="comment"]').fill(REVIEW_COMMENT);
        await dialog.getByRole('button', { name: /Enregistrer l'avis/ }).click();

        // `.first()` because the dialog's own textarea still holds the
        // text it was submitted with: the visible paragraph comes first in
        // the DOM, and it is the one a reader sees.
        await expect(page.getByText(REVIEW_COMMENT).first()).toBeVisible();

        // --- And on the place, which is the whole point. ---
        // "Où est-on déjà allés, et est-ce que c'était bien ?" is answered
        // on the PLACE's page, from a review left on one of its stays. A
        // stay that shows its own review and a place that does not would
        // look right on both screens and answer nothing.
        await page.getByRole('link', { name: PLACE_NAME }).first().click();
        await expect(page.getByRole('heading', { name: PLACE_NAME })).toBeVisible();
        await expect(page.getByText(REVIEW_COMMENT).first()).toBeVisible();

        // --- The history, from Core\Audit rather than from a local table. ---
        await page.goto(stayUrl);
        await expect(page.getByRole('heading', { name: 'Historique' })).toBeVisible();
        await expect(page.locator('.audit-timeline')).toBeVisible();

        // --- The place is now offered as a known one. ---
        // The second camp on the same field is the ordinary case, and it
        // must not mean describing the place again: the creation form's
        // place picker is what makes the module accumulate knowledge
        // instead of rows.
        await page.goto('/chefs/camps/nouveau');
        await expect(
            page.locator('select[name="place_id"]').getByRole('option', { name: PLACE_NAME }),
        ).toHaveCount(1);
    });
});
