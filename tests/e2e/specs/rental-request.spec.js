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
// And, in its second half, the one negotiation this module has: a manager
// proposes other dates, the renter accepts them from a page they reach
// with no account at all, and the booking MOVES. Every step of that
// crosses the token boundary in a direction unit tests cannot: the
// proposal is written by an authenticated manager and answered by an
// anonymous browser holding nothing but a link. It is added here rather
// than as a second spec because it needs the very asset, manager, booking
// and tracking link this scenario has already built — a second file would
// pay for all of it again to reach the same starting point.
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text wherever they identify the element (README.md
// § Tests de bout en bout), field names only where a control has no
// accessible name of its own.
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from '../support/admin-login.js';
import { expectRendersAsACalendar } from '../support/calendar.js';
import { answerCookieBanner } from '../support/cookie-banner.js';
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
// Deliberately a week later, so the dates the renter ends up with are
// dates this booking never had — the only way to assert the move on text
// that cannot have been on the page before.
const PROPOSED_ARRIVAL = isoDaysFromNow(67);
const PROPOSED_DEPARTURE = isoDaysFromNow(69);
const PROPOSAL_MESSAGE = 'Ces dates-là nous arrangeraient mieux, le local est pris la semaine avant.';

test.describe('Rentals', () => {
    test('an admin creates a hall and a visitor asks to rent it', async ({ page, browser }) => {
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
        // Their own browser context, not this one with its cookies
        // cleared. The two identities used to share a context and take
        // turns in it — clearCookies() to become the stranger,
        // loginAsAdmin() to become the unit again — and a session is not
        // something a page can put down and pick back up: a form renders
        // with the CSRF token of the session that drew it, and by the
        // time it is submitted the OTHER identity has replaced that
        // session. The POST is then refused, the page comes back
        // unchanged, and the failure surfaces several assertions later as
        // whatever the click was supposed to cause never happening.
        //
        // That is a real DAST failure, not a hypothesis: the accept below
        // answered 302 into « Votre session a expirée » while
        // POST /cookies/reject-all answered 403, and the trace showed five
        // renders of the tracking page carrying five different CSRF
        // tokens — five sessions, for one visitor who never logged in.
        // Two contexts cannot do that to each other.
        const renterContext = await browser.newContext();
        const renter = await renterContext.newPage();
        await renter.goto('/locations');

        await expect(renter.getByRole('heading', { name: 'Locations' })).toBeVisible();
        const assetLink = renter.getByRole('link', { name: ASSET_NAME }).first();
        await expect(assetLink).toBeVisible();
        await assetLink.click();

        // The public page says what the hall is and never who is in it
        // (§6.6) — the calendar shows occupancy, never a renter.
        await expect(renter.getByRole('heading', { name: ASSET_NAME })).toBeVisible();

        // The availability calendar has to be READABLE, not merely
        // present: see ../support/calendar.js for the failure mode this
        // rules out, which every markup assertion in the PHP suite is
        // blind to.
        await expectRendersAsACalendar(renter.locator('#rental-calendar .daygrid'));

        // Paging forward is a plain link — no account, no JavaScript
        // required — and the grid on the next month must render just as
        // well as the first one did.
        await renter.getByRole('link', { name: 'Mois suivant' }).click();
        await expectRendersAsACalendar(renter.locator('#rental-calendar .daygrid'));

        // And a visitor can never page into the past (§22.2): the control
        // is disabled rather than hidden, so they are told rather than
        // left wondering where it went.
        await renter.goto(`/locations/${ASSET_SLUG}`);
        await expect(
            renter.getByRole('button', { name: /Mois précédent/ }),
        ).toBeDisabled();

        await renter.getByRole('link', { name: /demande/i }).first().click();
        await expect(renter.getByRole('heading', { name: new RegExp(ASSET_NAME) })).toBeVisible();

        // --- The request itself. ---
        await renter.locator('input[name="arrival"]').fill(ARRIVAL);
        await renter.locator('input[name="departure"]').fill(DEPARTURE);
        await renter.locator('input[name="persons"]').fill('35');
        await renter.locator('input[name="name"]').fill('Jeanne Martin');
        await renter.locator('input[name="email"]').fill('jeanne.martin@example.be');
        await renter.locator('input[name="organisation"]').fill('Les Scouts de Nulle Part');

        // Both are required, and both are an acknowledgement rather than a
        // consent: the unit cannot handle the request without the data,
        // and the box attests that the visitor was told (§6.13).
        await renter.locator('input[name="accept_conditions"]').check();
        await renter.locator('input[name="accept_privacy"]').check();

        // Core\Security\HumanCheck refuses a form submitted faster than a
        // human could have filled it — a real barrier, and one this
        // scenario has to clear the way a visitor does rather than
        // configure away. Waiting here is therefore part of what is being
        // tested: the public form is protected, and a genuine request still
        // gets through.
        await renter.waitForTimeout(4000);

        await renter.getByRole('button', { name: 'Envoyer ma demande' }).click();

        // --- What the visitor gets back. ---
        // A reference they can quote, which is also what a reply's subject
        // line carries back (§7.6, level 1) — and the tracking page itself,
        // reached with no account and no session at all: the link in their
        // acknowledgement IS the authorisation (§6.26).
        const heading = renter.getByRole('heading', { name: /Votre demande LOC-\d{4}-\d+/ });
        await expect(heading).toBeVisible();
        const reference = (await heading.textContent()).match(/LOC-\d{4}-\d+/)[0];

        // The dates are held while the unit answers, and the page says
        // until when rather than leaving the visitor guessing (§6.14).
        await expect(renter.getByText(/Dates bloquées/)).toBeVisible();

        // The link IS the authorisation (§6.26): no account, no session,
        // and it still opens on a cold browser. Keeping the URL and
        // clearing everything is the only way to say that honestly.
        const trackingUrl = renter.url();
        await renter.context().clearCookies();
        await renter.goto(trackingUrl);
        await expect(renter.getByRole('heading', { name: new RegExp(reference) })).toBeVisible();

        // A neighbouring id with the same token is refused — the id is
        // right there in the URL, so only the token may decide.
        await renter.goto(trackingUrl.replace(/\/(\d+)\//, (_, id) => `/${Number(id) + 1}/`));
        await expect(renter.getByRole('heading', { name: new RegExp(reference) })).toHaveCount(0);

        // --- And what the unit sees. ---
        // Still signed in: nothing dropped this session in the meantime.
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

        await renter.context().clearCookies();
        await renter.goto(trackingUrl);

        await expect(renter.getByRole('heading', { name: new RegExp(reference) })).toBeVisible();
        await expect(renter.locator('body')).not.toContainText(INTERNAL_NOTE);

        // --- The negotiation: the unit proposes, the renter decides. ---
        // Nothing a manager proposes changes the booking on its own —
        // that is the whole rule (§6.16), and it is only observable by
        // crossing the boundary twice: written by an authenticated
        // manager, answered by an anonymous browser holding a link.
        await page.goto(`/mes-locations/${ASSET_SLUG}/reservations`);
        await page.getByRole('link', { name: new RegExp(reference) }).first().click();

        const proposal = page.locator('form[action="/mes-locations/proposition"]');
        await proposal.locator('input[name="arrival"]').fill(PROPOSED_ARRIVAL);
        await proposal.locator('input[name="departure"]').fill(PROPOSED_DEPARTURE);
        await proposal.locator('input[name="message"]').fill(PROPOSAL_MESSAGE);
        await proposal.getByRole('button', { name: 'Proposer' }).click();

        // The manager's own screen still shows the ORIGINAL dates: a
        // proposal is a question, and a question that had already moved
        // the booking would be a decision.
        await expect(page.getByText(/En attente/).first()).toBeVisible();

        // --- The renter, with no account and no session. ---
        await renter.context().clearCookies();
        await renter.goto(trackingUrl);

        // The proposal is put to them in words, with what it costs to
        // ignore it spelled out.
        await expect(renter.getByText(/L'unité vous propose ces dates/)).toBeVisible();
        await expect(renter.getByText(/votre réservation reste inchangée/i)).toBeVisible();

        // --- And the booking has NOT moved. ---
        // Asserted on the « Votre séjour » block rather than on the page
        // as a whole, precisely because the proposed dates ARE already on
        // the page — inside the proposal being put to them. What must not
        // have changed is the booking, and the booking is that <dd>.
        await expect(stayDates(renter)).toContainText(frenchDate(DEPARTURE));
        await expect(stayDates(renter)).not.toContainText(frenchDate(PROPOSED_DEPARTURE));

        // `exact` because the cookie banner's « Tout accepter » is a
        // substring match away, and it stands in front of the page anyway
        // — answering it first is not housekeeping (see
        // ../support/cookie-banner.js).
        await answerCookieBanner(renter);
        await renter.getByRole('button', { name: 'Accepter', exact: true }).click();

        // --- And now it has. ---
        // The dates in « Votre séjour » are ones this booking never had,
        // the proposal is closed, and the button that closed it is gone —
        // three things that could not have been true a moment ago.
        await expect(stayDates(renter)).toContainText(frenchDate(PROPOSED_DEPARTURE));
        await expect(stayDates(renter)).not.toContainText(frenchDate(DEPARTURE));
        await expect(renter.getByText('Acceptée').first()).toBeVisible();
        await expect(renter.getByRole('button', { name: 'Accepter', exact: true })).toHaveCount(0);

        // --- The unit sees the same booking, moved. ---
        // Which is what says the DECISION reached the database rather
        // than only the page that rendered it: a different session, a
        // different template, the same dates.
        await page.goto(`/mes-locations/${ASSET_SLUG}/reservations`);
        await page.getByRole('link', { name: new RegExp(reference) }).first().click();

        await expect(
            page.getByText(`${ASSET_NAME} · du ${frenchDate(PROPOSED_ARRIVAL)} au ${frenchDate(PROPOSED_DEPARTURE)}`),
        ).toBeVisible();
        // And the booking's own history recorded it, through Core\Audit
        // like every other per-entity timeline on the site (§8.66).
        await expect(page.locator('.audit-timeline')).toBeVisible();
        await expect(page.getByText(/Décision sur la modification/).first()).toBeVisible();

        await renterContext.close();
    });
});

/**
 * The « Votre séjour » block of the renter's page — the booking's OWN
 * dates, as distinct from any dates a pending proposal happens to be
 * showing alongside them.
 *
 * @param {import('@playwright/test').Page} page
 */
function stayDates(page) {
    return page.locator('.card', { has: page.getByRole('heading', { name: 'Votre séjour' }) });
}

/**
 * `2027-07-24` as the site renders it — `|date_fr`'s output, which is what
 * a renter actually reads and therefore what a scenario must look for.
 *
 * Written here rather than imported because the point is to reach the same
 * string by a DIFFERENT route than the template does: a shared formatter
 * would agree with itself whatever it produced.
 */
function frenchDate(iso) {
    const [year, month, day] = iso.split('-');

    return `${day}/${month}/${year}`;
}

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
