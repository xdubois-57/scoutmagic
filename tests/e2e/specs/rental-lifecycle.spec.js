// End-to-end: what happens to a rental AFTER it is confirmed — the
// milestone checklist a manager works through, ticked by their own hands.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The two rental specs that already exist stop at the confirmation.
// `rental-request.spec.js` covers a stranger asking for a hall and the one
// negotiation the module has; `rental-management.spec.js` covers the unit
// configuring the asset and answering that request. Everything after
// « Confirmée » — the contract, the inventories, the meters, the final
// settlement, the closure — is today reached only by PHPUnit
// (`Tests\Modules\Rental\Booking\MilestoneEvidenceTest`,
// `RentalStayServiceTest`), which sees a `$extras` map a test wrote itself
// and never the page that map is supposed to move.
//
// A THIRD FILE rather than one cradle-to-grave scenario, deliberately.
// "the tariff a chief typed reaches the visitor" and "pressing « Envoyer »
// ticks « Contrat envoyé »" are different bugs, and one long test would
// report them as the same red line. The price of that choice is the setup
// below — an asset, a manager, a request, a confirmation — which is
// therefore played FAST AND WITHOUT ASSERTIONS: it is the other two specs'
// subject, not this one's. Everything asserted here bears on the part
// nothing else covers.
//
// WHAT IT ASSERTS THAT NOTHING ELSE CAN
// ----------------------------------------------------------------------------
//   - That the checklist is really DERIVED (§6.15). Ten of its fourteen
//     lines were permanently greyed for the whole time
//     `BookingMilestones::for()` was called with no extras at all: sending
//     the contract, finishing an inventory, validating a settlement moved
//     nothing. Nothing in PHPUnit noticed, because nothing in PHPUnit
//     renders the page. Here every tick is read off the page a manager
//     reads, after the action a manager performs.
//   - That the booking page's sixteen forms still act WITHOUT A PAGE LOAD.
//     `public/assets/js/rental-booking.js` intercepts each submit, posts a
//     `FormData` of the form with `X-Requested-With`, and then re-fetches
//     this same page to swap every `[data-booking-panel]`. Whether the
//     body that reaches the server still carries what the form declares —
//     and whether the checklist really comes back changed — is decidable
//     only in a browser: PHPUnit posts an array it wrote itself, Vitest
//     has no server behind the form. A marker planted on the live document
//     proves the document was never replaced.
//   - That « sans objet » and « à faire » stay apart. This asset takes no
//     payments, so « Acompte reçu » and « Caution reçue » must render
//     GREYED rather than unticked — an unreachable box reads as work
//     outstanding, which is the whole reason `BookingMilestone::
//     $isApplicable` exists.
//   - That confirming really froze the asset's inventory checklist into
//     this booking (§6.23): the lines appear on « État des lieux », and the
//     matching milestone stops being « sans objet » the moment they do.
//
// WHAT IT DELIBERATELY LEAVES ALONE
// ----------------------------------------------------------------------------
// The FOUR MONEY milestones — « Acompte reçu », « Solde reçu », « Caution
// reçue », « Caution restituée ». They are not manager actions at all: the
// first two are a live comparison against what Finance reconciled off a
// bank statement (`RentalPaymentService::statusFor()` — "a threshold
// compared live, never a stored flag"), so there is no button anywhere on
// this page to press, and the security deposit's two lines only exist once
// a Finance account has been pinned by unit staff and a second receivable
// raised. Driving that chain through a browser would add a bank account, a
// statement import and a reconciliation to a spec whose subject is the
// checklist — for milestones `Tests\Modules\Rental\Booking\
// MilestoneEvidenceTest` already derives exhaustively, in every
// combination, without a browser. What IS asserted here is the half only
// the page can show: that with payments off those lines render as « sans
// objet » and not as unfinished work.
//
// Scheduler-driven reminders (`Modules\Rental\Reminder\ReminderPlanner`)
// are out of scope too: nothing here turns `public/cron.php`. They are a
// scenario of their own the day one is needed, rather than extra weight
// and extra fragility on this one.
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text wherever they identify the element (docs/developpement.md
// § Tests de bout en bout), field names only where a control has no
// accessible name of its own. The milestone lines carry neither a role nor
// an id, so they are addressed through the panel wrapper
// `rental-booking.js` itself swaps — a contract between that script and
// `booking.html.twig`, not incidental structure — and matched on the label
// `BookingMilestones` gives them.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { openCard } from '../support/collapsible-card.js';
import { openSectionEditor } from '../support/section-editor.js';
import { pngBuffer } from '../support/png.js';
import { scaled } from '../support/timeouts.js';
import { waitOutHumanCheckDelay } from '../support/human-check.js';
import { waitForServerResponse } from '../support/response.js';

/** A date far enough out to clear any notice period the asset declares. */
function isoDaysFromNow(days) {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return date.toISOString().slice(0, 10);
}

const ASSET_NAME = 'Chalet des Fagnes';
const ASSET_SLUG = 'chalet-des-fagnes';

// Deliberately far from the ranges the other two rental specs use, so a
// failure here can never be one of their bookings holding the dates.
const ARRIVAL = isoDaysFromNow(300);
const DEPARTURE = isoDaysFromNow(303);

const METER_LABEL = 'Électricité';
const INVENTORY_KEYS = 'Trousseau de clés';
const INVENTORY_KITCHEN = 'Cuisine';

/** The three states one line of the checklist can be rendered in. */
const DONE = 'Fait :';
const TODO = 'À faire :';
const NOT_APPLICABLE = 'Sans objet :';

test.describe('Rentals — the milestones after a confirmation', () => {
    test('a manager works a confirmed booking through to its closure and the checklist follows', async ({ page, browser }) => {
        // A long scenario by nature: it builds a whole asset before it can
        // start, generates a PDF, and walks eleven form round trips after
        // that. Nothing here waits on a timer except the anti-robot delay
        // the public form legitimately imposes.
        test.setTimeout(scaled(240_000));

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

        // Every confirmation on this run is answered « oui »: the wording of
        // the ones this scenario meets — confirming a booking, validating a
        // settlement — is asserted by the specs those steps belong to, and
        // installing the answer once keeps the setup silent. It rides in on
        // an init script, so it has to be installed before the first
        // navigation.
        await autoConfirm(page);

        // ── SETUP — the other two specs' subject, played without a single
        //    assertion of its own. ─────────────────────────────────────────
        await loginAsAdmin(page);
        // The banner is `fixed-bottom` and this scenario clicks near the
        // foot of a very long page; see ../support/cookie-banner.js.
        await answerCookieBanner(page);

        await page.goto('/admin/locations', { waitUntil: 'load' });
        const creation = page.locator('form[action="/admin/locations/create"]');
        await creation.locator('input[name="name"]').fill(ASSET_NAME);
        await creation.locator('select[name="asset_type"]').selectOption('Local');
        await creation.locator('input[name="capacity"]').fill('40');
        await creation.locator('input[name="is_public"]').check();
        await creation.getByRole('button', { name: 'Créer le bien' }).click();
        await expect(page).toHaveURL(/\/admin\/locations\?asset_id=\d+/);

        await grantManagerBySearch(page);

        // The meter and the inventory template have to exist BEFORE the
        // confirmation: `RentalOperationsService::confirm()` copies the
        // asset's checklist into the booking there and never again, exactly
        // so that editing the template later cannot rewrite an inventory
        // somebody already signed off (§6.23).
        await page.goto(`/mes-locations/${ASSET_SLUG}/gabarits`, { waitUntil: 'load' });

        // Both lists add in place (#708, IT-10): the add form posts JSON and
        // the new row appears without a reload — which is what is awaited.
        const meters = page.locator('#meter-list');
        await meters.locator('#meter-label').fill(METER_LABEL);
        await meters.locator('#meter-kind').selectOption('electricity');
        await meters.locator('#meter-unit').fill('kWh');
        await meters.getByRole('button', { name: 'Ajouter', exact: true }).click();
        await expect(meters.locator('.list-editor-item', { hasText: METER_LABEL })).toBeVisible();

        // TWO lines, not one: "every line has been looked at" is the rule
        // the milestone encodes (`MilestoneEvidence::allChecked()`), and a
        // single-line checklist cannot tell it apart from "some line has".
        // The kitchen is observed, not counted: a « Oui / Non » item.
        const inventory = page.locator('#inventory-list');
        for (const [label, kind] of [[INVENTORY_KEYS, 'quantity'], [INVENTORY_KITCHEN, 'yes_no']]) {
            await inventory.locator('#item-label').fill(label);
            await inventory.locator('#item-kind').selectOption(kind);
            await inventory.getByRole('button', { name: 'Ajouter', exact: true }).click();
            await expect(inventory.locator('.list-editor-item', { hasText: label })).toBeVisible();
        }
        // Added in place, and really stored: a reload shows both, in order.
        await page.reload({ waitUntil: 'load' });
        await expect(page.locator('#inventory-list .list-editor-item')).toHaveCount(2);
        await expect(page.locator('#inventory-list .list-editor-item').first()).toContainText(INVENTORY_KEYS);

        // The request itself, by somebody with no account — in a browser of
        // its own, so the manager's session survives and this spec pays for
        // one login instead of two.
        const reference = await requestTheHall(browser);

        await page.goto(`/mes-locations/${ASSET_SLUG}/reservations`, { waitUntil: 'load' });
        await page.getByRole('link', { name: new RegExp(reference) }).first().click();
        await page.waitForURL(/\/reservations\/\d+$/, { waitUntil: 'load' });

        // The unit's answer to a request is its contract (#708, IT-13): the
        // journey puts forward generating it — from the dashboard itself
        // (IT-16) — and confirming is not on offer until the agreement is
        // complete.
        await expect(page.getByRole('button', { name: 'Générer le contrat' }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Confirmer la réservation' })).toHaveCount(0);
        await expect(milestone(page, 'Contrat généré')).toContainText(TODO);
        await expect(milestone(page, 'Contrat envoyé')).toContainText(TODO);
        await expect(milestone(page, 'Contrat signé reçu')).toContainText(TODO);
        // The conditions were accepted with the request, and that is where
        // the journey says so — never as a step of the contract (IT-16).
        await expect(milestone(page, 'Demande reçue')).toContainText(/conditions acceptées/);

        // ── The contract: generated, read, then sent ─────────────────────
        // A marker on the live document. If any of the presses below makes
        // the browser navigate, the document is replaced and the marker goes
        // with it — the only way to tell "the panel was re-rendered" from
        // "the page was reloaded" from the outside.
        const dashboard = page.url();
        await page.evaluate(() => { window.__notReloaded = true; });

        await page.getByRole('button', { name: 'Générer le contrat' }).first().click();
        // Generating is not sending: the journey now puts forward the send,
        // a second gesture so the PDF can be read before it leaves.
        //
        // Headroom over the expect default because this one press renders a
        // PDF: dompdf loads its fonts on the first document of the run, and
        // the work happens inside the request the panel refresh waits on.
        const send = page.getByRole('button', { name: 'Envoyer le contrat' }).first();
        await expect(send).toBeVisible({ timeout: scaled(45_000) });
        await expect(milestone(page, 'Contrat généré')).toContainText(DONE);
        await expect(milestone(page, 'Contrat envoyé')).toContainText(TODO);

        // The dialog this raises — to whom it goes, the text then locked,
        // the dates held — is answered by autoConfirm() at the top of the
        // scenario.
        await send.click();
        await expect(milestone(page, 'Contrat envoyé')).toContainText(DONE);
        // The date the line carries is the send date. Matched as a shape
        // rather than as today's date written out here: the assertion is
        // that the line became concrete, and a spec that computed the same
        // string a second way would only ever agree with itself.
        await expect(milestone(page, 'Contrat envoyé')).toContainText(/\d{2}\/\d{2}\/\d{4}/);
        expect(await page.evaluate(() => window.__notReloaded === true)).toBe(true);

        // ── The signed copy coming back ──────────────────────────────────
        // « Documents » is a page of the booking's own (issue #462), reached
        // by its chip in the booking's rail. Its box arrives open; `openCard`
        // asserts that rather than assuming it.
        await page.locator('#rental-booking-picker').getByRole('link', { name: 'Documents' }).click();
        await page.waitForURL(/\/reservations\/\d+\/documents$/, { waitUntil: 'load' });
        await openCard(page, 'dossier-documents');
        await page.evaluate(() => { window.__notReloaded = true; });

        // The contract is listed with every other document, and resent
        // from here — never generated here any more (IT-16).
        await expect(page.getByRole('button', { name: /^Renvoyer «/ })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Générer le contrat' })).toHaveCount(0);

        // A photograph of a contract signed by both parties — countersigned
        // on paper — which is what the panel's own accept list is sized
        // for. The upload goes out as the `FormData` rental-booking.js
        // builds, so this also pins the one form on this page carrying a
        // file.
        const upload = page.locator('form[action="/mes-locations/document-ajouter"]');
        await page.locator('#document-file').setInputFiles({
            name: 'contrat-signe.png',
            mimeType: 'image/png',
            buffer: pngBuffer(600, 800),
        });
        await page.locator('#document-type').selectOption('signed_contract');
        // `exact`, and not decoration: an `<input type="file">` is a BUTTON
        // in the accessibility tree, and partials/drop_zone.html.twig gives
        // this one the accessible name « Document à ajouter ». getByRole's
        // name matching is a case-insensitive SUBSTRING by default, so a
        // plain « Ajouter » names the drop zone as well as the submit and
        // Playwright refuses the click. Scoping to the form does not help —
        // both live inside it.
        await upload.getByRole('button', { name: 'Ajouter', exact: true }).click();
        await expect(page.locator('[data-booking-panel="documents-figure"]')).toContainText('2 documents');
        expect(await page.evaluate(() => window.__notReloaded === true)).toBe(true);

        // ── The checklist those presses moved ────────────────────────────
        // On the dashboard, which a fresh load renders from the same
        // records the Documents page just wrote. Signed by both parties is
        // the renter's signature too.
        await page.goto(dashboard, { waitUntil: 'load' });
        await expect(milestone(page, 'Contrat signé reçu')).toContainText(DONE);
        await expect(milestone(page, 'Contrat contresigné')).toContainText(DONE);

        // ── The agreement complete: now it can be confirmed ──────────────
        // The action the journey puts forward once the contract is out and
        // signed — the last line of the agreement (#708, IT-13).
        await page.getByRole('button', { name: 'Confirmer la réservation' }).first().click();
        // The end of the setup, and the only reason it is asserted at all:
        // everything below is about a CONFIRMED booking, and starting the
        // subject before the confirmation has landed would blame the first
        // milestone for the setup's own timing.
        await expect(milestone(page, 'Réservation confirmée')).toContainText(DONE);

        // The confirmation copied the asset's checklist into this booking,
        // so the two inventory lines have become reachable work.
        await expect(milestone(page, "État des lieux d'entrée")).toContainText(TODO);
        await expect(milestone(page, 'État des lieux de sortie')).toContainText(TODO);
        await expect(milestone(page, 'Décompte final réglé')).toContainText(TODO);
        await expect(milestone(page, 'Location clôturée')).toContainText(TODO);

        // And the money, which this asset does not handle, is GREYED rather
        // than unticked — the distinction `BookingMilestone::$isApplicable`
        // exists for, and the one thing about those four lines that only a
        // rendered page can state.
        await expect(milestone(page, 'Acompte reçu')).toContainText(NOT_APPLICABLE);
        await expect(milestone(page, 'Solde reçu')).toContainText(NOT_APPLICABLE);
        await expect(milestone(page, 'Caution reçue')).toContainText(NOT_APPLICABLE);
        await expect(milestone(page, 'Caution restituée')).toContainText(NOT_APPLICABLE);

        // ── The two inventories, with their meters (#708, IT-17) ─────────
        // A page of the booking now, inside `[data-rental-booking]`: the
        // meter readings post without a page load and the panel is
        // re-rendered; each inventory line saves on its own as it is typed
        // (rental-inventory.js), and « = » takes the line's reference.
        await page.goto(`${bookingUrl(page)}/etat-des-lieux`, { waitUntil: 'load' });

        // Nothing is pre-filled: an empty field is « pas encore regardé ».
        await expect(page.getByLabel(INVENTORY_KEYS, { exact: true })).toHaveValue('');

        // A photo on the reading, and not as decoration: the form's file
        // input is optional to a manager but never ABSENT from the request
        // — a browser submits an empty file part for it — so this is the
        // body a real manager's reading carries. Photographing the dial is
        // also what the page's own advice tells a manager to do.
        await recordReading(page, 'entrée', '4210,5');

        // The keys as expected, in one gesture; the kitchen left unchecked,
        // which the validation's question then counts.
        await takeReference(page, INVENTORY_KEYS);
        await expect(page.getByLabel(INVENTORY_KEYS, { exact: true })).toHaveValue('1');
        await expect(page.locator('form[data-inventory-validate]'))
            .toHaveAttribute('data-confirm', /1 élément sans valeur/);

        await validateInventory(page, "Valider l'état des lieux d'entrée");
        // Frozen and filed: the page moves on to the departure, read
        // against what the arrival found.
        await expect(page.locator('[data-booking-panel="inventory"]')).toContainText('État des lieux de sortie');
        await expect(page.locator('[data-booking-panel="inventory"]')).toContainText('ouvrir le PDF');

        await page.goto(bookingUrl(page), { waitUntil: 'load' });
        await expect(milestone(page, "État des lieux d'entrée")).toContainText(DONE);
        await expect(milestone(page, 'État des lieux de sortie')).toContainText(TODO);

        await page.goto(`${bookingUrl(page)}/etat-des-lieux`, { waitUntil: 'load' });
        await recordReading(page, 'sortie', '4386,25');
        await takeReference(page, INVENTORY_KEYS);
        // A « non » IS a completed observation — the inventory asks what
        // was found, not whether everything was fine.
        await saveLine(page, page.getByLabel(INVENTORY_KITCHEN, { exact: true }), (field) => field.selectOption('no'));
        await validateInventory(page, "Valider l'état des lieux de sortie");
        await expect(page.locator('[data-booking-panel="inventory"]'))
            .toContainText('Les deux états des lieux sont validés');

        // ── The final settlement, then the invoice (#708, IT-18) ─────────
        // On « Facture », inside `[data-rental-booking]`: the settlement's
        // forms post without a page load and the panel is re-rendered.
        // Its own lines, and it never touches the agreed price (§6.21).
        await page.goto(`${bookingUrl(page)}/facture`, { waitUntil: 'load' });
        await page.locator('#final-persons').fill('28');
        await submitAndRefresh(
            page,
            '/mes-locations/decompte',
            () => page.getByRole('button', { name: 'Enregistrer un décompte' }).click(),
        );
        // A version now exists, and it is offered for validation — which is
        // also the proof that pressing « Enregistrer un décompte » created
        // one rather than only answering.
        await expect(page.getByRole('button', { name: 'Valider', exact: true })).toBeVisible();

        // A version exists but nobody has signed it off, so the line names
        // the version while staying unticked — the shape of a settlement
        // still being argued about.
        await page.goto(bookingUrl(page), { waitUntil: 'load' });
        await expect(milestone(page, 'État des lieux de sortie')).toContainText(DONE);
        await expect(milestone(page, 'Décompte final réglé')).toContainText(TODO);
        await expect(milestone(page, 'Décompte final réglé')).toContainText('v1');

        await page.goto(`${bookingUrl(page)}/facture`, { waitUntil: 'load' });
        await submitAndRefresh(
            page,
            '/mes-locations/decompte-valider',
            () => page.getByRole('button', { name: 'Valider', exact: true }).click(),
        );
        // Validated is final: the control that would change it is gone.
        await expect(page.getByRole('button', { name: 'Valider', exact: true })).toHaveCount(0);

        // The departure inventory is validated, so the invoice can be made.
        await submitAndRefresh(
            page,
            '/mes-locations/document-generer',
            () => page.getByRole('button', { name: 'Générer la facture' }).click(),
        );
        await expect(page.getByRole('button', { name: 'Envoyer la facture' })).toBeVisible();

        // ── The closure ──────────────────────────────────────────────────
        await page.goto(bookingUrl(page), { waitUntil: 'load' });
        await expect(milestone(page, 'Décompte final réglé')).toContainText(DONE);

        // With everything settled, closing is the action put forward.
        await page.getByRole('button', { name: 'Clôturer la location' }).first().click();

        await expect(milestone(page, 'Location clôturée')).toContainText(DONE);
        // And there is nowhere left to go: the journey's heading says so.
        // Scoped to the panel that owns the sentence rather than asked of
        // the whole document: a page-wide getByText is how a header, a
        // drawer and the body all answer to one visible string.
        await expect(
            page.locator('[data-booking-panel="next-step"]'),
        ).toContainText('Cette location est clôturée : il ne reste rien à faire.');

        expect(serverErrors, 'the application returned a server error').toEqual([]);
        expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
    });
});

/**
 * One line of « Cycle de vie », by the label
 * `Booking\BookingMilestones` gives it.
 *
 * Addressed through `[data-booking-panel="milestones"]` because that
 * wrapper is what `public/assets/js/rental-booking.js` swaps after every
 * action — a contract between the script and the template rather than
 * incidental markup — and because the checklist repeats words the rest of
 * the page also uses (« Réservation confirmée » is also the flash a confirmation raises).
 *
 * The state is read off the visually-hidden prefix each line carries
 * (« Fait : », « À faire : », « Sans objet : »), which is the only textual
 * form the tick has: the box itself is a `bi-*` icon with
 * `aria-hidden="true"`, so a screen reader and this spec read the same
 * words, and a line whose icon changed without its prefix would be a bug
 * either way.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} label the milestone's label
 */
function milestone(page, label) {
    // By the step's own label, exactly: another line may quote it — « Il
    // manque : « Contrat envoyé » » under « Réservation confirmée ».
    return page.locator('[data-booking-panel="milestones"] li')
        .filter({ has: page.getByText(label, { exact: true }) });
}


/**
 * The booking's own URL, taken from the address bar.
 *
 * Every page of the file hangs off it, so the scenario moves between them
 * by path rather than by hunting for a link — the booking id is never
 * written down in this file.
 *
 * @param {import('@playwright/test').Page} page
 */
function bookingUrl(page) {
    return page.url().split(/[?#]/)[0].replace(/\/(modifications|finances|documents|etat-des-lieux|facture|courrier)$/, '');
}

/**
 * Submit one of the module's PLAIN forms — the asset's templates page,
 * which is not inside `[data-rental-booking]` — and wait for the page it
 * redirects to.
 *
 * Needed because `RentalManagementController`'s `assetSetupAction()`
 * redirects back to the SAME url with no new text of its own — several
 * of these actions even set the same flash — so there
 * is nothing for a following assertion to wait on, and a bare click would
 * let the next action race the navigation it just started. Waiting for the
 * POST's own response and then for the document that follows it is the one
 * synchronisation that cannot be satisfied by the stale page.
 *
 * It also covers the forms behind a `data-confirm`, where the POST only
 * leaves once the dialog has been answered: the wait is armed before the
 * click, so the question being asked in between changes nothing.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} action the form's action path
 * @param {import('@playwright/test').Locator} button the submit to press
 */
async function submitAndReload(page, action, button) {
    const posted = waitForServerResponse(page, 
        (response) => response.url().endsWith(action) && response.request().method() === 'POST',
    );
    await button.click();
    await posted;
    await page.waitForLoadState('load');
}

/**
 * Wait for one of the booking page's ASYNC forms: its POST, then the GET
 * of this same page that rental-booking.js re-renders the panels from.
 * Nothing navigates, so a following assertion could otherwise read the
 * panel the refresh is about to replace.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} action the form's action path
 * @param {() => Promise<void>} submit what presses it
 */
async function submitAndRefresh(page, action, submit) {
    const here = page.url().split(/[?#]/)[0];
    const posted = waitForServerResponse(page,
        (response) => response.url().endsWith(action) && response.request().method() === 'POST',
    );
    const refreshed = waitForServerResponse(page,
        (response) => response.url().split(/[?#]/)[0] === here && response.request().method() === 'GET',
    );
    await submit();
    await posted;
    await refreshed;
    // The answer is in, but the panels are swapped only once it is parsed;
    // rental-booking.js says busy until then. An inventory line saved in
    // between is replaced by its older render, value and « Enregistré »
    // gone (#809).
    await expect(page.locator('[data-rental-booking]')).not.toHaveAttribute('aria-busy', 'true');
}

/**
 * Record one end of a meter reading on « État des lieux ».
 *
 * @param {import('@playwright/test').Page} page
 * @param {'entrée' | 'sortie'} phase as the field's own label spells it
 * @param {string} value typed the way a manager types it, comma included
 */
async function recordReading(page, phase, value) {
    const form = page.locator('form[action="/mes-locations/releve"]')
        .filter({ has: page.getByLabel(`Relevé ${phase}`) });

    await form.locator('input[name="value"]').fill(value);
    await form.locator('input[name="photo"]').setInputFiles({
        name: `compteur-${phase}.png`,
        mimeType: 'image/png',
        buffer: pngBuffer(320, 240),
    });
    await submitAndRefresh(
        page,
        '/mes-locations/releve',
        () => form.getByRole('button', { name: 'Enregistrer', exact: true }).click(),
    );
}

/**
 * Save one inventory line the way the page does — on its own, as it is
 * typed — and wait for the line to say so.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} field the line's value field
 * @param {(field: import('@playwright/test').Locator) => Promise<unknown>} act
 */
async function saveLine(page, field, act) {
    const saved = waitForServerResponse(page,
        (response) => response.url().endsWith('/mes-locations/etat-des-lieux/ligne')
            && response.request().method() === 'POST',
    );
    await act(field);
    await saved;
    await expect(page.locator('form[data-inventory-line]').filter({ has: field })
        .locator('[data-inventory-status]')).toHaveText('Enregistré');
}

/**
 * « = » on one line: the reference taken, and saved.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} item the inventory line's label
 */
async function takeReference(page, item) {
    await saveLine(
        page,
        page.getByLabel(item, { exact: true }),
        () => page.getByRole('button', { name: `Reprendre la référence pour ${item}` }).click(),
    );
}

/**
 * Validate the phase on screen; the confirmation is answered « oui » by
 * the run's autoConfirm.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} label the button's label
 */
async function validateInventory(page, label) {
    await submitAndRefresh(
        page,
        '/mes-locations/etat-des-lieux/valider',
        () => page.getByRole('button', { name: label }).click(),
    );
}

/**
 * Ask for the hall as a stranger would, and hand back the reference.
 *
 * In a browser context of its own rather than by clearing the manager's
 * cookies: this spec needs the manager signed in immediately afterwards,
 * and logging in twice would cost more than a second context does. No
 * assertion beyond reading the reference back — the public request path is
 * `rental-request.spec.js`'s subject, not this file's.
 *
 * @param {import('@playwright/test').Browser} browser
 * @returns {Promise<string>} the booking's LOC-YYYY-N reference
 */
async function requestTheHall(browser) {
    const visitor = await browser.newContext();

    try {
        const page = await visitor.newPage();
        await page.goto(`/locations/${ASSET_SLUG}/demande`, { waitUntil: 'load' });
        await answerCookieBanner(page);

        await page.locator('input[name="arrival"]').fill(ARRIVAL);
        await page.locator('input[name="departure"]').fill(DEPARTURE);
        await page.locator('input[name="persons"]').fill('28');
        await page.locator('input[name="name"]').fill('Sophie Delvaux');
        await page.locator('input[name="email"]').fill('sophie.delvaux@example.be');
        // Required since §22.5, in the browser as well as on the server:
        // without them the form simply does not submit.
        await page.locator('input[name="phone"]').fill('0470 12 34 56');
        await page.locator('input[name="purpose"]').fill('Week-end de section');
        await page.locator('input[name="accept_conditions"]').check();
        await page.locator('input[name="accept_privacy"]').check();

        // Core\Security\HumanCheck refuses a submission that arrives faster
        // than a human could have filled the form. Cleared the way a
        // visitor clears it, never configured away — and waited out exactly,
        // from the challenge's own timestamp.
        await waitOutHumanCheckDelay(page);
        await page.getByRole('button', { name: 'Envoyer ma demande' }).click();

        const heading = page.getByRole('heading', { name: /Votre demande LOC-\d{4}-\d+/ });
        await expect(heading).toBeVisible();

        return (await heading.textContent()).match(/LOC-\d{4}-\d+/)[0];
    } finally {
        await visitor.close();
    }
}

/**
 * Name the seeded member (Baden Powell) manager of the currently selected
 * asset, through the real search box.
 *
 * The same helper `rental-request.spec.js` and `rental-management.spec.js`
 * each carry: creating a hall grants nobody the managed space, not even
 * the superadmin who created it (§6.3), so every rental spec has to pass
 * through this grant before it can reach `/mes-locations`. Copied rather
 * than hoisted into `../support/` so that a change to this module's
 * managers screen breaks the specs that assert on it and not this one,
 * which only needs to get past it.
 *
 * @param {import('@playwright/test').Page} page
 */
async function grantManagerBySearch(page) {
    // The section is a read card; its form lives in the dialog behind
    // « Modifier » (design.md §1.9).
    const dialog = await openSectionEditor(page, 'gestionnaires-edit');

    const managers = page.locator('form[action="/admin/locations/managers"]');
    await expect(managers).toBeVisible();

    await page.locator('#rental-manager-search').fill('Powell');
    const result = page.locator('#rental-manager-results button').first();
    await expect(result).toBeVisible();
    await result.click();

    // The submit sits in the dialog's footer and reaches the form through
    // `form="rental-managers-form"` — outside the <form> element itself,
    // which is why it is located on the dialog rather than on the form.
    await dialog.getByRole('button', { name: 'Enregistrer les gestionnaires' }).click();
    await expect(page.getByText('Les gestionnaires ont été enregistrés.')).toBeVisible();
}
