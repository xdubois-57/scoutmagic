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
//     this booking (§6.23): the lines appear on the stay page, and the
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
// Roles and visible text wherever they identify the element (README.md
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
import { openSectionEditor } from '../support/section-editor.js';
import { pngBuffer } from '../support/png.js';
import { scaled } from '../support/timeouts.js';
import { waitOutHumanCheckDelay } from '../support/human-check.js';

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

        // Both forms below share their action with the « Retirer » button of
        // every row already listed, so each is picked out by the field only
        // the ADD form carries.
        const meterForm = page.locator('form[action="/mes-locations/compteur"]')
            .filter({ has: page.locator('#meter-label') });
        await meterForm.locator('#meter-label').fill(METER_LABEL);
        await meterForm.locator('#meter-kind').selectOption('electricity');
        await meterForm.locator('#meter-unit').fill('kWh');
        await submitAndReload(
            page,
            '/mes-locations/compteur',
            meterForm.getByRole('button', { name: 'Ajouter' }),
        );

        // TWO lines, not one: "every line has been looked at" is the rule
        // the milestone encodes (`MilestoneEvidence::allChecked()`), and a
        // single-line checklist cannot tell it apart from "some line has".
        for (const label of [INVENTORY_KEYS, INVENTORY_KITCHEN]) {
            const template = page.locator('form[action="/mes-locations/inventaire-modele"]')
                .filter({ has: page.locator('#item-label') });
            await template.locator('#item-label').fill(label);
            await submitAndReload(
                page,
                '/mes-locations/inventaire-modele',
                template.getByRole('button', { name: 'Ajouter' }),
            );
        }

        // The request itself, by somebody with no account — in a browser of
        // its own, so the manager's session survives and this spec pays for
        // one login instead of two.
        const reference = await requestTheHall(browser);

        await page.goto(`/mes-locations/${ASSET_SLUG}/reservations`, { waitUntil: 'load' });
        await page.getByRole('link', { name: new RegExp(reference) }).first().click();
        await page.waitForURL(/\/reservations\/\d+$/, { waitUntil: 'load' });

        await page.getByRole('button', { name: 'Confirmée' }).click();
        // The end of the setup, and the only reason it is asserted at all:
        // everything below is about a CONFIRMED booking, and starting the
        // subject before the confirmation has landed would blame the first
        // milestone for the setup's own timing.
        await expect(milestone(page, 'Réservation confirmée')).toContainText(DONE);

        // ── The checklist as a confirmed booking leaves it ───────────────
        // Applicable and unticked: there is a contract to send, and the
        // conditions the renter ticked on the public form are what stands in
        // for the signed copy until one comes back.
        await expect(milestone(page, 'Contrat envoyé')).toContainText(TODO);
        await expect(milestone(page, 'Conditions et contrat acceptés')).toContainText(TODO);
        await expect(milestone(page, 'Conditions et contrat acceptés'))
            .toContainText(/conditions acceptées le \d{2}\/\d{2}\/\d{4}/);

        // The confirmation copied the asset's checklist into this booking,
        // so the two inventory lines have become reachable work.
        await expect(milestone(page, "État des lieux d'entrée")).toContainText(TODO);
        await expect(milestone(page, 'État des lieux de sortie')).toContainText(TODO);
        await expect(milestone(page, 'Relevés de compteurs')).toContainText(TODO);
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

        // ── The contract: generated, then sent ───────────────────────────
        // A marker on the live document. If any of the presses below makes
        // the browser navigate, the document is replaced and the marker goes
        // with it — the only way to tell "the panel was re-rendered" from
        // "the page was reloaded" from the outside.
        await page.evaluate(() => { window.__notReloaded = true; });

        await page.getByRole('button', { name: 'Générer le contrat' }).click();
        // Generating is not sending, and the checklist has to say so: the
        // line reads the document's `sent_at`, never its existence.
        //
        // Headroom over the expect default because this one press renders a
        // PDF: dompdf loads its fonts on the first document of the run, and
        // the work happens inside the request the panel refresh waits on.
        await expect(page.getByRole('button', { name: 'Envoyer', exact: true }))
            .toBeVisible({ timeout: scaled(45_000) });
        await expect(milestone(page, 'Contrat envoyé')).toContainText(TODO);

        await page.getByRole('button', { name: 'Envoyer', exact: true }).click();
        await expect(milestone(page, 'Contrat envoyé')).toContainText(DONE);
        // The date the line carries is the send date. Matched as a shape
        // rather than as today's date written out here: the assertion is
        // that the line became concrete, and a spec that computed the same
        // string a second way would only ever agree with itself.
        await expect(milestone(page, 'Contrat envoyé')).toContainText(/\d{2}\/\d{2}\/\d{4}/);
        // The row now offers « Renvoyer » — the document knows it has gone
        // out, which is the same fact the milestone just read.
        await expect(page.getByRole('button', { name: 'Renvoyer' })).toBeVisible();

        // ── The signed copy coming back ──────────────────────────────────
        // A photograph of a signed contract, which is what a renter actually
        // returns and what the panel's own accept list is sized for. The
        // upload goes out as the `FormData` rental-booking.js builds, so
        // this also pins the one form on this page carrying a file.
        const upload = page.locator('form[action="/mes-locations/document-ajouter"]');
        await page.locator('#document-file').setInputFiles({
            name: 'contrat-signe.png',
            mimeType: 'image/png',
            buffer: pngBuffer(600, 800),
        });
        await page.locator('#document-type').selectOption('signed_contract');
        await upload.getByRole('button', { name: 'Ajouter' }).click();

        await expect(milestone(page, 'Conditions et contrat acceptés')).toContainText(DONE);
        // The detail is the signed copy's own date now — the acknowledgement
        // it replaced is gone from the line.
        await expect(milestone(page, 'Conditions et contrat acceptés'))
            .not.toContainText('conditions acceptées le');

        // Four presses, four panel swaps, and the document was never
        // replaced.
        expect(await page.evaluate(() => window.__notReloaded === true)).toBe(true);

        // ── The stay: meters, then the two inventories ───────────────────
        // A different page, and deliberately a plainer one: stay.html.twig
        // is not wrapped in `[data-rental-booking]`, so every form here
        // posts, redirects and re-renders the way it always did.
        await page.goto(`${bookingUrl(page)}/sejour`, { waitUntil: 'load' });

        await expect(page.getByLabel(`${INVENTORY_KEYS} — Entrée`)).toBeVisible();

        // A photo on both readings, and not as decoration. The form's file
        // input is optional to a manager but never ABSENT from the request:
        // a browser submits an empty file part for it, PHP turns that into a
        // `$_FILES` entry carrying `UPLOAD_ERR_NO_FILE`, and
        // `RentalManagementController::recordReading()` hands that entry
        // straight to `UploadHandler`, which refuses it — so a reading typed
        // with no photo comes back « Erreur lors de l'envoi du fichier (code
        // 4) » and is not saved. (`uploadDocument()` guards exactly that
        // case; this action and `reportIncident()` do not.) No controller
        // test can see it, because PHPUnit never populates `$_FILES` at all.
        // Photographing the dial is also what the page's own advice tells a
        // manager to do, so the scenario does that.
        await recordReading(page, 'entrée', '4210,5');
        await recordReading(page, 'sortie', '4386,25');

        // « Non vérifié » is a real state, never a placeholder: one line
        // looked at is not an inventory, and the milestone must not move
        // until every line has been.
        await setInventoryState(page, INVENTORY_KEYS, 'Entrée', 'ok');
        await page.goto(bookingUrl(page), { waitUntil: 'load' });
        await expect(milestone(page, "État des lieux d'entrée")).toContainText(TODO);
        // The meters, on the other hand, are complete — both ends read.
        await expect(milestone(page, 'Relevés de compteurs')).toContainText(DONE);

        await page.goto(`${bookingUrl(page)}/sejour`, { waitUntil: 'load' });
        await setInventoryState(page, INVENTORY_KITCHEN, 'Entrée', 'ok');
        await setInventoryState(page, INVENTORY_KEYS, 'Sortie', 'ok');
        // A line found broken IS a completed observation — the checklist
        // asks whether somebody looked, not whether everything was fine.
        await setInventoryState(page, INVENTORY_KITCHEN, 'Sortie', 'issue');

        // ── The final settlement ─────────────────────────────────────────
        // Its own lines, and it never touches the agreed price (§6.21).
        await page.locator('#final-persons').fill('28');
        await submitAndReload(
            page,
            '/mes-locations/decompte',
            page.getByRole('button', { name: 'Enregistrer un décompte' }),
        );
        // A version now exists, and it is offered for validation — which is
        // also the proof that pressing « Enregistrer un décompte » created
        // one rather than only redirecting.
        await expect(page.getByRole('button', { name: 'Valider' })).toBeVisible();

        // A version exists but nobody has signed it off, so the line names
        // the version while staying unticked — the shape of a settlement
        // still being argued about.
        await page.goto(bookingUrl(page), { waitUntil: 'load' });
        await expect(milestone(page, "État des lieux d'entrée")).toContainText(DONE);
        await expect(milestone(page, 'État des lieux de sortie')).toContainText(DONE);
        await expect(milestone(page, 'Décompte final réglé')).toContainText(TODO);
        await expect(milestone(page, 'Décompte final réglé')).toContainText('v1');

        await page.goto(`${bookingUrl(page)}/sejour`, { waitUntil: 'load' });
        await submitAndReload(
            page,
            '/mes-locations/decompte-valider',
            page.getByRole('button', { name: 'Valider' }),
        );
        // Validated is final: the control that would change it is gone.
        await expect(page.getByRole('button', { name: 'Valider' })).toHaveCount(0);

        // ── The closure ──────────────────────────────────────────────────
        await page.goto(bookingUrl(page), { waitUntil: 'load' });
        await expect(milestone(page, 'Décompte final réglé')).toContainText(DONE);

        await page.getByRole('button', { name: 'Clôturée' }).click();

        await expect(milestone(page, 'Location clôturée')).toContainText(DONE);
        // And there is nowhere left to go: closed is history, and the way
        // back is a new request (`Booking\BookingTransition`).
        await expect(page.getByText('Cette réservation est dans un état définitif')).toBeVisible();

        expect(serverErrors, 'the application returned a server error').toEqual([]);
        expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
    });
});

/**
 * One line of « Où en est cette location », by the label
 * `Booking\BookingMilestones` gives it.
 *
 * Addressed through `[data-booking-panel="milestones"]` because that
 * wrapper is what `public/assets/js/rental-booking.js` swaps after every
 * action — a contract between the script and the template rather than
 * incidental markup — and because the checklist repeats words the rest of
 * the page also uses (« Confirmée » is a button too).
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
    return page.locator('[data-booking-panel="milestones"] li').filter({ hasText: label });
}

/**
 * The booking's own URL, taken from the address bar.
 *
 * The stay page hangs off it and every form there redirects back to it, so
 * the scenario moves between the two by path rather than by hunting for a
 * link — the booking id is never written down in this file.
 *
 * @param {import('@playwright/test').Page} page
 */
function bookingUrl(page) {
    return page.url().replace(/\/sejour$/, '').split('?')[0];
}

/**
 * Submit one of the module's PLAIN forms — the asset's templates page and
 * the stay page, neither of which is inside `[data-rental-booking]` — and
 * wait for the page it redirects to.
 *
 * Needed because `RentalManagementController`'s `assetSetupAction()` and
 * `stayAction()` both redirect back to the SAME url with no new text of
 * their own — several of these actions even set the same flash — so there
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
    const posted = page.waitForResponse(
        (response) => response.url().endsWith(action) && response.request().method() === 'POST',
    );
    await button.click();
    await posted;
    await page.waitForLoadState('load');
}

/**
 * Record one end of a meter reading on the stay page.
 *
 * @param {import('@playwright/test').Page} page
 * @param {'entrée' | 'sortie'} phase as the field's own label spells it
 * @param {string} value typed the way a manager types it, comma included
 */
async function recordReading(page, phase, value) {
    const form = page.locator('form[action="/mes-locations/releve"]')
        .filter({ has: page.getByLabel(`Relevé ${phase}`) });

    await form.locator('input[name="value"]').fill(value);
    // See the call site for why the photo is not optional here.
    await form.locator('input[name="photo"]').setInputFiles({
        name: `compteur-${phase}.png`,
        mimeType: 'image/png',
        buffer: pngBuffer(320, 240),
    });
    await submitAndReload(page, '/mes-locations/releve', form.getByRole('button', { name: 'Enregistrer' }));
}

/**
 * Set one inventory line, one phase, on the stay page.
 *
 * The select is found by the accessible name the template gives it — a
 * visually-hidden « {élément} — {phase} » — because the grid renders one
 * identical form per cell and nothing else tells them apart. The state is
 * chosen by value rather than by label: `Stay\InventoryState`'s values are
 * the contract the form posts, and the labels are prose that may be
 * reworded.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} item the inventory line's label
 * @param {'Entrée' | 'Sortie'} phase
 * @param {'ok' | 'issue' | 'missing'} state
 */
async function setInventoryState(page, item, phase, state) {
    const select = page.getByLabel(`${item} — ${phase}`);
    await select.selectOption(state);

    const form = page.locator('form[action="/mes-locations/inventaire"]').filter({ has: select });
    await submitAndReload(page, '/mes-locations/inventaire', form.getByRole('button', { name: 'OK' }));
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
