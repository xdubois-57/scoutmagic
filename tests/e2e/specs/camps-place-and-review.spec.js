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
// THE SECOND SCENARIO, AND WHY IT IS SEPARATE
// ----------------------------------------------------------------------------
// The map on that same list is a different question, not a further step
// of this one: it asks whether a preference SURVIVES a visit, and only
// when cookie consent covers it. Answering it means recording a consent
// decision, changing it, and reloading the page between each — which
// inside the chain above would leave a scenario about two things and a
// reader unable to tell which half a failure came from. It lives here
// rather than in a file of its own because it is the same page, the same
// module and the same login, and a second provisioning would cost more
// than the whole assertion.
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
import { answerCookieBanner } from '../support/cookie-banner.js';
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
        // Exact match: getByText('48') alone substring-matches ANY text
        // node containing "48" — including another fixture's millisecond
        // timestamp-derived name (e.g. an unrelated, hidden calendar event
        // titled "…1787805434855"), which this suite's own SUFFIX pattern
        // can produce. participantCount renders as its own bare text node
        // (modules/camps/views/camp.html.twig), so exact:true still finds
        // it — it just stops finding anything else too.
        await expect(page.getByText('48', { exact: true }).first()).toBeVisible();

        const stayUrl = page.url();
        expect(stayUrl).toMatch(/\/chefs\/camps\/sejours\/\d+/);

        // --- The review, which only opens once the camp is over. ---
        // In a dialog, not a form standing open: the stay page keeps one
        // primary action (design.md §1.9), and Playwright refuses to fill
        // a hidden control — see ../support/section-editor.js for the
        // failure mode that helper rules out.
        const dialog = await openSectionEditor(page, 'review-modal');
        // The note is five radios drawn as stars now, not a dropdown
        // (modules/camps/views/_rating_input.html.twig). The input itself
        // is a 1×1 transparent box; the LABEL is the 44px target a finger
        // actually hits, so that is what this clicks. Asserting the radio
        // afterwards is what proves the label was wired to the right one —
        // clicking a label that points at the wrong input looks identical
        // from the outside, and would silently save a different rating.
        await dialog.locator('label[for="review-rating-4"]').click();
        await expect(dialog.getByRole('radio', { name: '4 étoiles sur 5' })).toBeChecked();
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

    test('the map opens by itself, and remembers being folded only with functional consent', async ({ page }) => {
        // The map is EXPANDED by default and built as the page loads
        // (modules/camps/views/list.html.twig, public/assets/js/camps-map.js).
        // What only a real browser can show is the pair of facts that
        // rests on: Leaflet really takes the container over with no click
        // anywhere, and the reader's fold really survives a visit —
        // through localStorage, which PHPUnit cannot see at all and which
        // Vitest only sees in a jsdom of its own making, with no server,
        // no real consent round trip and no second page load.
        //
        // It is also the browser half of AGENTS.md § Cookie consent for
        // the `camps_map_collapsed` key (declared in this module's
        // module.json, category "functional"): the refusal half first,
        // where the fold must be forgotten, then the grant half, where it
        // must not be.
        await loginAsAdmin(page);

        const panel = page.locator('#camps-map-panel');
        // Leaflet stamps this class on the element it takes over, so it
        // is proof the map was BUILT — not merely that a div is on
        // screen. The module's own JavaScript binds to these two ids;
        // neither the panel nor the map container carries a role or an
        // accessible name a locator could use instead (README.md § Tests
        // de bout en bout).
        const built = page.locator('#camps-map.leaflet-container');
        const toggle = page.getByRole('button', { name: 'Carte', exact: true });
        const stored = () => page.evaluate(() => localStorage.getItem('camps_map_collapsed'));

        /**
         * Click the toggle and wait for Bootstrap to have FINISHED, not
         * merely started.
         *
         * `hidden.bs.collapse` / `shown.bs.collapse` fire at the END of
         * the 0.35s transition, and that is where camps-map.js does its
         * remembering — while Playwright already calls a mid-transition
         * panel hidden the moment its height reaches zero. Navigating in
         * between would destroy the document before the write, a race
         * that passes on a slow machine and fails on a fast one. The
         * settled state is "hidden (or visible) AND no longer
         * `collapsing`", which is exactly when the event has fired.
         *
         * @param {boolean} expectVisible the state being clicked TOWARDS
         */
        async function toggleAndSettle(expectVisible) {
            await toggle.click();
            await (expectVisible ? expect(panel).toBeVisible() : expect(panel).toBeHidden());
            await expect(panel).not.toHaveClass(/collapsing/);
        }

        // --- Without functional consent. ---
        await page.goto('/chefs/camps', { waitUntil: 'domcontentloaded' });
        await expect(panel, 'the map panel must be open with no click anywhere').toBeVisible();
        await expect(built, 'the map must be BUILT on load, not on first open').toHaveCount(1);

        // Refusing IS the "no functional consent" case, recorded rather
        // than left implicit — and it also gets the fixed-bottom banner
        // out of the way of everything below (support/cookie-banner.js).
        await answerCookieBanner(page);

        await toggleAndSettle(false);
        // The fold applies to the page in front of the reader either way;
        // what consent decides is whether anything is written down.
        expect(await stored(), 'nothing may be stored without functional consent').toBeNull();

        await page.goto('/chefs/camps', { waitUntil: 'domcontentloaded' });
        await expect(panel, 'a fold must not be remembered without functional consent').toBeVisible();
        await expect(built).toHaveCount(1);

        // --- With functional consent, granted through the real page. ---
        await page.goto('/cookies', { waitUntil: 'domcontentloaded' });
        await page.locator('#cookie-functional').check();
        await page.getByRole('button', { name: 'Enregistrer mes choix' }).click();
        await expect(page.getByText('Vos préférences cookies ont été enregistrées.')).toBeVisible();

        await page.goto('/chefs/camps', { waitUntil: 'domcontentloaded' });
        await expect(panel).toBeVisible();
        await toggleAndSettle(false);
        await expect.poll(stored, { message: 'the fold must be written once consent covers it' }).toBe('1');

        await page.goto('/chefs/camps', { waitUntil: 'domcontentloaded' });
        await expect(panel, 'the fold must survive the visit once consent covers it').toBeHidden();
        // Folded means the tile provider is not contacted at all — the
        // one thing that made the old always-collapsed default worth its
        // cost. The reader keeps it; they just choose it now.
        await expect(built, 'a folded map must request no tiles at all').toHaveCount(0);
        // The button must announce the state that is on screen: on a
        // fresh load Bootstrap has no Collapse instance yet, so
        // camps-map.js sets this itself alongside the class.
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');

        await toggleAndSettle(true);
        await expect(built, 'opening a folded map builds it then, not never').toHaveCount(1);

        // Expanded is the default, so it is stored as an absence rather
        // than as a second value: coming back finds the map open again
        // with nothing left behind.
        await expect.poll(stored, { message: 'unfolding must REMOVE the key' }).toBeNull();
        await page.goto('/chefs/camps', { waitUntil: 'domcontentloaded' });
        await expect(panel).toBeVisible();
    });
});
