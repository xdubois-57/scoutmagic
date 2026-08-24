// End-to-end: letting a hall, from the admin who creates it to the
// stranger who asks to rent it — in a real browser, through the real
// application.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The rentals module's public request path crosses every layer the unit
// tests each cover in isolation and none of them covers together: a
// superadmin creating an asset, the module's own menu hook putting it in
// front of a visitor, the availability calculator deciding which dates a
// stranger may pick, the pricing engine quoting them, and the booking
// service turning that into a request that holds the dates. A break
// anywhere in that chain is invisible to PHPUnit — which sees a $_POST
// array a test wrote itself — and to Vitest, which sees a form with no
// server behind it.
//
// It stays ONE scenario rather than the eight the roadmap enumerates
// (AGENTS.md § Tests: the E2E suite is a release gate, not a coverage
// tool). The seven it does not drive through a browser are covered where
// they can be exercised honestly and exhaustively instead:
//
//   - the stay, meters, settlement and deposit → Tests\Modules\Rental\
//     Service\RentalStayServiceTest;
//   - the email attachment and its filing → Tests\Modules\Rental\Mail\
//     RentalMessageConsumerTest, which drives the real MIME parser and
//     the real sync service against a scripted mailbox;
//   - "an animateur sees only « — loué »" and "a manager sees the detail"
//     → Tests\Modules\Rental\Calendar\RentalVirtualEventProviderTest,
//     which asserts on what is never BUILT rather than on what a template
//     rendered — a stronger claim than a browser can make;
//   - a manager of another asset being refused → the same file plus
//     Tests\Security\RentalHardeningAuditTest;
//   - the purge and the surviving aggregate → Tests\Modules\Rental\
//     Retention\RentalRetentionServiceTest.
//
// WHAT IT ASSERTS THAT NOTHING ELSE CAN
// ----------------------------------------------------------------------------
// That the pieces are actually wired to each other on a running install:
// the asset an admin creates is the one a visitor can reach, the form
// that visitor submits is accepted by the real CSRF guard and the real
// controller, and the request that comes back carries a reference the
// unit can quote.
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text wherever they identify the element (README.md
// § Tests de bout en bout), field names only where a control has no
// accessible name of its own.
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from '../support/admin-login.js';
import { expectRendersAsACalendar } from '../support/calendar.js';
import { openSectionEditor } from '../support/section-editor.js';

/** A date far enough out to clear any notice period the asset declares. */
function isoDaysFromNow(days) {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return date.toISOString().slice(0, 10);
}

const ASSET_NAME = 'Local Saint-Georges';
const ASSET_SLUG = 'local-saint-georges';
const INTERNAL_NOTE = "Le groupe précédent avait laissé la cuisine dans un état déplorable.";
const ARRIVAL = isoDaysFromNow(60);
const DEPARTURE = isoDaysFromNow(62);

test.describe('Rentals', () => {
    test('an admin creates a hall and a visitor asks to rent it', async ({ page }) => {
        // --- The unit puts a hall online. ---
        await loginAsAdmin(page);
        await page.goto('/admin/locations');

        await expect(page.getByRole('heading', { name: 'Ajouter un bien' })).toBeVisible();

        const creation = page.locator('form[action="/admin/locations/create"]');
        await creation.locator('input[name="name"]').fill(ASSET_NAME);
        // A closed list now, not a free field: the type is what the
        // public index groups by and what a contract prints.
        await creation.locator('select[name="asset_type"]').selectOption('Local');
        await creation.locator('input[name="capacity"]').fill('60');
        // Without this the asset exists but no stranger can reach it —
        // which is the default, and deliberately so (§6.1).
        await creation.locator('input[name="is_public"]').check();
        await creation.getByRole('button', { name: 'Créer le bien' }).click();

        await expect(page.getByText(ASSET_NAME).first()).toBeVisible();

        // --- And designates somebody to look after it. ---
        // Creating a hall does not give anybody the managed space: asset
        // management is a per-asset grant (§6.3), and even the superadmin
        // who just created it manages nothing until they are named. That is
        // the point of the grant, so the scenario has to go through it.
        //
        // Through the search box, which is the only place this flow is
        // observable at all: the server renders a plain <select> and
        // rental-managers.js hides it and rebuilds the same field from a
        // fetch — so neither PHPUnit (which posts an array it wrote itself)
        // nor Vitest (a jsdom form with no server behind it) sees whether
        // the two halves still agree on the field name.
        await grantManagerBySearch(page);


        // --- A stranger, with no account at all. ---
        await page.context().clearCookies();
        await page.goto('/locations');

        await expect(page.getByRole('heading', { name: 'Locations' })).toBeVisible();
        const assetLink = page.getByRole('link', { name: ASSET_NAME }).first();
        await expect(assetLink).toBeVisible();
        await assetLink.click();

        // The public page says what the hall is and never who is in it
        // (§6.6) — the calendar shows occupancy, never a renter.
        await expect(page.getByRole('heading', { name: ASSET_NAME })).toBeVisible();

        // The availability calendar has to be READABLE, not merely
        // present: see ../support/calendar.js for the failure mode this
        // rules out, which every markup assertion in the PHP suite is
        // blind to.
        await expectRendersAsACalendar(page.locator('#rental-calendar .daygrid'));

        // Paging forward is a plain link — no account, no JavaScript
        // required — and the grid on the next month must render just as
        // well as the first one did.
        await page.getByRole('link', { name: 'Mois suivant' }).click();
        await expectRendersAsACalendar(page.locator('#rental-calendar .daygrid'));

        // And a visitor can never page into the past (§22.2): the control
        // is disabled rather than hidden, so they are told rather than
        // left wondering where it went.
        await page.goto(`/locations/${ASSET_SLUG}`);
        await expect(
            page.getByRole('button', { name: /Mois précédent/ }),
        ).toBeDisabled();

        await page.getByRole('link', { name: /demande/i }).first().click();
        await expect(page.getByRole('heading', { name: new RegExp(ASSET_NAME) })).toBeVisible();

        // --- The request itself. ---
        await page.locator('input[name="arrival"]').fill(ARRIVAL);
        await page.locator('input[name="departure"]').fill(DEPARTURE);
        await page.locator('input[name="persons"]').fill('35');
        await page.locator('input[name="name"]').fill('Jeanne Martin');
        await page.locator('input[name="email"]').fill('jeanne.martin@example.be');
        await page.locator('input[name="organisation"]').fill('Les Scouts de Nulle Part');

        // Both are required, and both are an acknowledgement rather than a
        // consent: the unit cannot handle the request without the data,
        // and the box attests that the visitor was told (§6.13).
        await page.locator('input[name="accept_conditions"]').check();
        await page.locator('input[name="accept_privacy"]').check();

        // Core\Security\HumanCheck refuses a form submitted faster than a
        // human could have filled it — a real barrier, and one this
        // scenario has to clear the way a visitor does rather than
        // configure away. Waiting here is therefore part of what is being
        // tested: the public form is protected, and a genuine request still
        // gets through.
        await page.waitForTimeout(4000);

        await page.getByRole('button', { name: 'Envoyer ma demande' }).click();

        // --- What the visitor gets back. ---
        // A reference they can quote, which is also what a reply's subject
        // line carries back (§7.6, level 1) — and the tracking page itself,
        // reached with no account and no session at all: the link in their
        // acknowledgement IS the authorisation (§6.26).
        const heading = page.getByRole('heading', { name: /Votre demande LOC-\d{4}-\d+/ });
        await expect(heading).toBeVisible();
        const reference = (await heading.textContent()).match(/LOC-\d{4}-\d+/)[0];

        // The dates are held while the unit answers, and the page says
        // until when rather than leaving the visitor guessing (§6.14).
        await expect(page.getByText(/Dates bloquées/)).toBeVisible();

        // The link IS the authorisation (§6.26): no account, no session,
        // and it still opens on a cold browser. Keeping the URL and
        // clearing everything is the only way to say that honestly.
        const trackingUrl = page.url();
        await page.context().clearCookies();
        await page.goto(trackingUrl);
        await expect(page.getByRole('heading', { name: new RegExp(reference) })).toBeVisible();

        // A neighbouring id with the same token is refused — the id is
        // right there in the URL, so only the token may decide.
        await page.goto(trackingUrl.replace(/\/(\d+)\//, (_, id) => `/${Number(id) + 1}/`));
        await expect(page.getByRole('heading', { name: new RegExp(reference) })).toHaveCount(0);

        // --- And what the unit sees. ---
        await loginAsAdmin(page);
        await page.goto('/mes-locations');

        await expect(page.getByText(ASSET_NAME).first()).toBeVisible();
        await expect(page.getByText(/LOC-\d{4}-\d+/).first()).toBeVisible();

        // --- What the renter never sees of it. ---
        // A manager's internal comment is the one thing §6.6 is most
        // explicit about, and the tracking page renders from the same
        // booking. Writing one and then re-opening the renter's page with
        // no session is the only way to prove the separation holds on a
        // running install rather than in a template review.
        await page.goto(`/mes-locations/${ASSET_SLUG}/reservations`);
        await page.getByRole('link', { name: new RegExp(reference) }).first().click();

        const comment = page.locator('form[action="/mes-locations/commentaire"]');
        await comment.locator('textarea[name="body"]').fill(INTERNAL_NOTE);
        await comment.getByRole('button', { name: 'Enregistrer' }).click();
        await expect(page.getByText(INTERNAL_NOTE)).toBeVisible();

        await page.context().clearCookies();
        await page.goto(trackingUrl);

        await expect(page.getByRole('heading', { name: new RegExp(reference) })).toBeVisible();
        await expect(page.locator('body')).not.toContainText(INTERNAL_NOTE);
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
    // The section is a read card; its form lives in the dialog behind
    // « Modifier » (design.md §1.9).
    const dialog = await openSectionEditor(page, 'gestionnaires-edit');

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

    // The submit sits in the dialog's footer and reaches the form through
    // `form="rental-managers-form"` — outside the <form> element itself.
    await dialog.getByRole('button', { name: 'Enregistrer les gestionnaires' }).click();
    await expect(page.getByText('Les gestionnaires ont été enregistrés.')).toBeVisible();
}
