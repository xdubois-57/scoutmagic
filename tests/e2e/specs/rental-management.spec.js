// End-to-end: a unit runs one of its halls, from configuring it to
// answering a real request — in a real browser, through the real
// application.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// rental-request.spec.js drives the OTHER half of the module: a stranger
// finding a hall and asking for it. This one is the unit's side, and the
// two are deliberately separate rather than one long test — a failure in
// "the tariff a chief typed reaches the visitor's estimate" and a failure
// in "the request form is accepted" are different bugs, and a single
// scenario would report them as the same red line.
//
// What only a browser on a running install can show here:
//
//   - The configuration page's four independent forms really are
//     independent (§6.4): saving the tariff does not blank the
//     constraints, and saving the constraints does not blank the tariff.
//     PHPUnit sees one $_POST array per controller call and can never
//     catch a form that silently posts a neighbour's fields.
//   - A tariff typed with a French decimal comma survives the round trip
//     to cents and back.
//   - The managed space's availability calendar renders as a calendar.
//     `.daygrid` is styled solely by components.css, which
//     base.html.twig deliberately does NOT load — a page that forgets to
//     link it still passes every PHPUnit assertion about its HTML and is
//     unusable in front of a human. Only a real browser computing real
//     styles can tell the difference, which is exactly why the check is
//     here and not in a controller test.
//   - A manual block entered on that calendar reaches the public
//     calendar as an indistinguishable "occupé" (§6.7).
//   - Confirming a request moves it through the real transition table and
//     the real availability re-check.
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text wherever they identify the element (README.md
// § Tests de bout en bout), field names only where a control has no
// accessible name of its own.
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from '../support/admin-login.js';
import { expectRendersAsACalendar } from '../support/calendar.js';

/** A date far enough out to clear any notice period the asset declares. */
function isoDaysFromNow(days) {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return date.toISOString().slice(0, 10);
}

const ASSET_NAME = 'Ferme de la Hulpe';
const ASSET_SLUG = 'ferme-de-la-hulpe';

const BLOCK_START = isoDaysFromNow(120);
const BLOCK_END = isoDaysFromNow(122);

const ARRIVAL = isoDaysFromNow(200);
const DEPARTURE = isoDaysFromNow(203);

test.describe('Rentals — running an asset', () => {
    test('a chief configures a hall, blocks a week and answers a request', async ({ page }) => {
        // ── The hall, and a tariff ──────────────────────────────────────
        await loginAsAdmin(page);
        await page.goto('/admin/locations');

        const creation = page.locator('form[action="/admin/locations/create"]');
        await creation.locator('input[name="name"]').fill(ASSET_NAME);
        // A closed list now, not a free field: the type is what the
        // public index groups by and what a contract prints.
        await creation.locator('select[name="asset_type"]').selectOption('Terrain');
        await creation.locator('input[name="capacity"]').fill('80');
        await creation.locator('input[name="is_public"]').check();
        await creation.getByRole('button', { name: 'Créer le bien' }).click();

        // The controller redirects to the new asset already selected, so
        // every form below is the new hall's own.
        await expect(page).toHaveURL(/\/admin\/locations\?asset_id=\d+/);
        await expect(page.locator('input[name="name"]').first()).toHaveValue(ASSET_NAME);

        // The manager grant, which creating the hall does NOT confer —
        // not even on the superadmin who created it (§6.3).
        await grantManagerBySearch(page);

        // ── The asset's own settings, in the managed space ──────────────
        // The tariff and the booking rules used to sit on the admin page
        // above, at `role_min: admin` — which made a chief d'unité the only
        // person able to price a hall somebody else runs. They belong with
        // the bookings now, reachable by the asset's managers.
        await page.goto('/mes-locations');
        await page.getByRole('link', { name: ASSET_NAME }).first().click();
        await page.locator('main').getByRole('link', { name: 'Réglages' }).first().click();

        await expect(page.getByRole('heading', { name: 'Tarification' })).toBeVisible();

        // A French decimal comma, the way a chief types it. It has to
        // survive the round trip through integer cents.
        const pricing = page.locator('form[action$="/reglages/tarif"]').first();
        await pricing.locator('select[name="billing_unit"]').selectOption('per_night');
        await pricing.locator('input[name="default_unit_price"]').fill('125,50');
        await pricing.getByRole('button', { name: 'Enregistrer la tarification' }).click();

        await expect(page.locator('input[name="default_unit_price"]').first()).toHaveValue('125,50');

        // ── And its own booking rules ───────────────────────────────────
        const constraints = page.locator('form[action$="/reglages/regles"]');
        await constraints.locator('input[name="min_nights"]').fill('2');
        await constraints.locator('input[name="min_notice_days"]').fill('7');
        await constraints.getByRole('button', { name: 'Enregistrer les règles' }).click();

        await expect(page.locator('input[name="min_nights"]')).toHaveValue('2');
        // §6.4: each block is its own form, so saving one must not blank
        // its neighbour. A single shared form would fail exactly here.
        await expect(page.locator('input[name="default_unit_price"]').first()).toHaveValue('125,50');

        // ── The calendar ────────────────────────────────────────────────
        // Scoped to the page's own content, per the fix main made to this
        // click: the Calendrier MODULE puts a link of the same name in the
        // navigation drawer and in the footer, and page-wide .first()
        // picks the drawer's — which is hidden on a desktop viewport, so
        // the click waits for an element that will never become visible.
        // It only surfaced under E2E_COVERAGE=1, where every request is
        // slow enough for the stylesheet that hides the drawer to have
        // applied before the click; without it the same locator sometimes
        // caught the drawer link while it was still unstyled and visible,
        // and navigated to /calendar instead of to this asset's calendar.
        await page.locator('main').getByRole('link', { name: 'Calendrier' }).first().click();

        await expect(page.getByRole('heading', { name: `Calendrier — ${ASSET_NAME}` })).toBeVisible();
        await expectRendersAsACalendar(page.locator('.daygrid').first());

        // ── A week taken off the market ─────────────────────────────────
        const blocking = page.locator('form[action="/mes-locations/blocage"]');
        await blocking.locator('input[name="start"]').fill(BLOCK_START);
        await blocking.locator('input[name="end"]').fill(BLOCK_END);
        await blocking.locator('input[name="reason"]').fill('Chantier toiture');
        await blocking.getByRole('button', { name: 'Réserver' }).click();

        await expect(page.getByText('Chantier toiture')).toBeVisible();

        // ── What the public sees of it: that the days are taken, and not
        //    one word about why (§6.7). ───────────────────────────────────
        await page.context().clearCookies();
        await page.goto(`/locations/${ASSET_SLUG}?month=${BLOCK_START.slice(0, 7)}`);

        const publicGrid = page.locator('#rental-calendar .daygrid');
        await expectRendersAsACalendar(publicGrid);

        const blockedDay = publicGrid.locator(`[data-date="${BLOCK_START}"]`);
        await expect(blockedDay).toHaveClass(/daygrid-day--occupied/);
        await expect(page.locator('#rental-calendar')).not.toContainText('Chantier');
        await expect(page.locator('#rental-calendar')).not.toContainText('toiture');

        // ── A visitor asks for other dates, and is quoted the tariff the
        //    chief typed. ──────────────────────────────────────────────────
        await page.goto(`/locations/${ASSET_SLUG}?arrival=${ARRIVAL}&departure=${DEPARTURE}&persons=40`);

        // 3 nights × 125,50 €. The estimate comes from the same engine
        // that prices the contract, so this is the tariff arriving at the
        // visitor, not a template repeating it. The total row rather than
        // the page: the same figure is also the base line's amount, and
        // the total is the one the visitor actually reads.
        await expect(
            page.getByRole('row', { name: /Total estimé/ }).getByRole('cell', { name: /376,50/ }),
        ).toBeVisible();

        // --- And « Estimer » recomputes it WITHOUT reloading the page. ---
        // A marker planted on the live document: if the browser navigates,
        // the document is replaced and the marker goes with it. That is the
        // only way to tell "the page was re-rendered" from "the page was
        // reloaded" from the outside.
        await page.evaluate(() => { window.__notReloaded = true; });

        await page.locator('input[name="persons"]').fill('12');
        await page.getByRole('button', { name: 'Estimer' }).click();

        // The address bar followed, so a reload or a shared link lands on
        // exactly what is on screen…
        await expect(page).toHaveURL(/persons=12/);
        // …the block really was re-rendered by the server (the form comes
        // back holding what was asked for, not what the page first had)…
        await expect(page.locator('#rental-estimate-fragment input[name="persons"]')).toHaveValue('12');
        await expect(
            page.getByRole('row', { name: /Total estimé/ }).getByRole('cell', { name: /376,50/ }),
        ).toBeVisible();
        // …and the document was never replaced.
        expect(await page.evaluate(() => window.__notReloaded === true)).toBe(true);

        // A calendar tap is the same story: it repaints, it does not reload.
        // A SELECTABLE day — the grid's first cells are padding days from
        // the previous month, which are deliberately inert.
        await page.locator('#rental-calendar .daygrid-day--selectable').first().click();
        await expect(page).toHaveURL(/arrival=/);
        expect(await page.evaluate(() => window.__notReloaded === true)).toBe(true);

        await page.getByRole('link', { name: /demande/i }).first().click();
        await page.locator('input[name="arrival"]').fill(ARRIVAL);
        await page.locator('input[name="departure"]').fill(DEPARTURE);
        await page.locator('input[name="persons"]').fill('40');
        await page.locator('input[name="name"]').fill('Pierre Lambert');
        await page.locator('input[name="email"]').fill('pierre.lambert@example.be');
        await page.locator('input[name="accept_conditions"]').check();
        await page.locator('input[name="accept_privacy"]').check();

        // Core\Security\HumanCheck refuses a form submitted faster than a
        // human could have filled it. Cleared the way a visitor does, not
        // configured away.
        await page.waitForTimeout(4000);
        await page.getByRole('button', { name: 'Envoyer ma demande' }).click();

        const heading = page.getByRole('heading', { name: /Votre demande LOC-\d{4}-\d+/ });
        await expect(heading).toBeVisible();
        const reference = (await heading.textContent()).match(/LOC-\d{4}-\d+/)[0];

        // ── And the unit answers it ─────────────────────────────────────
        await loginAsAdmin(page);
        await page.goto(`/mes-locations/${ASSET_SLUG}/reservations`);

        await page.getByRole('link', { name: new RegExp(reference) }).first().click();
        await expect(page.getByText('Pierre Lambert').first()).toBeVisible();

        await page.getByRole('button', { name: 'Confirmée' }).click();

        // Confirming goes through the real transition table and the real
        // availability re-check, both of which can refuse.
        await expect(page.getByText(/Confirmée/).first()).toBeVisible();

        // ── The confirmed stay now holds its dates against everybody
        //    else, with no more reason given than the block was. ──────────
        await page.context().clearCookies();
        await page.goto(`/locations/${ASSET_SLUG}?month=${ARRIVAL.slice(0, 7)}`);

        await expect(
            page.locator(`#rental-calendar [data-date="${ARRIVAL}"]`),
        ).toHaveClass(/daygrid-day--occupied/);
        await expect(page.locator('#rental-calendar')).not.toContainText('Lambert');
    });
});

/**
 * Name the seeded member (Baden Powell) manager of the currently selected
 * asset, through the real search box.
 *
 * Exercises the whole contract in one go: the debounce, the fetch against
 * `/admin/locations/gestionnaire-recherche`, the row the script builds, and
 * the field name that row has to carry for
 * RentalConfigController::saveManagers() to honour it.
 *
 * @param {import('@playwright/test').Page} page
 */
async function grantManagerBySearch(page) {
    const managers = page.locator('form[action="/admin/locations/managers"]');
    await expect(managers).toBeVisible();

    // The plain <select> is still rendered — it is the no-JavaScript
    // control — and the script hides it. Seeing it hidden is how we know
    // the enhancement actually ran rather than silently doing nothing.
    await expect(page.locator('#rental-manager-select-wrapper')).toBeHidden();

    await page.locator('#rental-manager-search').fill('Powell');
    const result = page.locator('#rental-manager-results button').first();
    await expect(result).toBeVisible();
    await result.click();

    // The row the script built posts the same field the select would have.
    await expect(
        managers.locator('input[name="manager_member_ids[]"][value]').first(),
    ).toBeChecked();

    await managers.getByRole('button', { name: 'Enregistrer les gestionnaires' }).click();
    await expect(page.getByText('Les gestionnaires ont été enregistrés.')).toBeVisible();
}
