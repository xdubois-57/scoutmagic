// End-to-end: one booking, from the request to the security deposit coming
// back — through a modification made after the contract went out (#927).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The three other rental specs each stop where their subject ends:
// `rental-request.spec.js` at a change applied before any contract,
// `rental-management.spec.js` at a confirmation, `rental-lifecycle.spec.js`
// at a closure on an asset that takes no payments. What #927 changed lives
// BETWEEN those pieces and only shows when they are chained on one booking:
//
//   - the receivable exists before the first contract, so the PDF prints
//     the very communication the renter later pays with and the refund
//     later leaves with — one communication, from the contract to the bank;
//   - a copy signed by the renter holds the dates firmly before anything
//     is confirmed;
//   - after the first send, the booking changes only through a project and
//     its replacement contract, and only at the countersignature of a copy
//     of THAT contract — a refused copy changes nothing;
//   - the receivable follows the countersignature, the settlement brings it
//     down to what the unit keeps, and the security deposit the renter is
//     owed back is a debit the bank import reconciles on its own, through
//     the same communication — « Caution restituée » ticks without a form.
//
// Each of those is unit-tested on its own. Whether they agree with each
// other on a running install — the communication Finance stores is the one
// the contract printed, the one the tracking page shows, the one the
// statement carries; the copy the renter uploads is attached to the
// replacement contract and not to the one in force — is decidable only
// here.
//
// And, at its start, the race #927 opened with: two visitors asking for the
// same dates at the same moment. Exactly one of them gets the week.
// `php -S` serves one request at a time, so the browser cannot make the two
// writes overlap on the server; what it shows is the end a visitor sees —
// the second is refused, never booked on top of the first. The truly
// simultaneous writes, two connections inside the lock, are
// `Tests\Modules\Rental\Service\ExclusiveDatesOnMysqlTest`'s subject on
// MariaDB.
//
// WHAT IT DELIBERATELY LEAVES ALONE
// ----------------------------------------------------------------------------
// What the other rental specs already pin — the checklist's every line, the
// inventory's every gesture, the request form's every field — is played
// here without assertions of its own. Mail content is not read either: that
// the replacement contract REACHED the renter is asserted the way the renter
// sees it, on their page, which only says so once the send was recorded.
//
// HumanCheck (5 public requests per address per 10 minutes) counts the two
// requests this spec submits together with the three other rental specs':
// five, the ceiling. A sixth public request in this tier needs the limit
// raised for the run, not a retry.
import { expect, test } from '@playwright/test';
import { inflateSync } from 'node:zlib';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
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

const ASSET_NAME = 'Refuge du Val';
const ASSET_SLUG = 'refuge-du-val';

// Its own IBAN, used by no other spec: the import sends a statement to the
// account carrying its IBAN, and when two active accounts share one it is
// ambiguous and every line is set aside (« Lignes ignorées »). The whole
// suite runs on one database, so an IBAN another spec already gave its
// account — this one first borrowed public-home-page.spec.js's
// BE68 5390 0754 7034 — passes alone and fails in the full run. The other
// specs use BE21 0012 3456 7803 and BE71 0961 2345 6769.
const ACCOUNT_NAME = 'Compte des locations E2E';
const ACCOUNT_IBAN = 'BE15 0019 2703 4130';

// Far from the ranges the other rental specs use (60–69, 120, 200–203,
// 300–303), so a failure here can never be one of their bookings.
const ARRIVAL = isoDaysFromNow(240);
const DEPARTURE = isoDaysFromNow(242);
// The modification: a week later and one night longer, so the new price,
// the new dates and the new receivable are all values the booking never had.
const NEW_ARRIVAL = isoDaysFromNow(247);
const NEW_DEPARTURE = isoDaysFromNow(250);

// 100 € a night and 150 € of security deposit: 2 nights → 350 € expected,
// 3 nights → 450 €, of which the unit keeps 300 € once the stay is settled.
const NIGHT_PRICE = '100,00';
const SECURITY_DEPOSIT = '150,00';

const INVENTORY_KEYS = 'Trousseau de clés';

/** The three states one line of the checklist can be rendered in. */
const DONE = 'Fait :';
const TODO = 'À faire :';

test.describe('Rentals — one booking from the request to the deposit back', () => {
    const title = 'a request, a contract, a replacement contract, a stay and the deposit refunded '
        + 'through one communication';
    test(title, async ({ page, browser }) => {
        // Long by nature: an asset, a Finance account and a manager to build,
        // three PDFs rendered, two statements imported, two renters. Nothing
        // waits on a timer but the anti-robot delay of the public form.
        test.setTimeout(scaled(300_000));

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

        // Every confirmation is answered « oui »; their wording is the
        // subject of the specs and controller tests of each step.
        await autoConfirm(page);

        // ── SETUP — played without assertions of its own ──────────────────
        await loginAsAdmin(page);
        await answerCookieBanner(page);

        // The account the rentals are paid on. Listing the categories seeds
        // the default ones (FinanceService::ensureDefaultCategories()), among
        // them « Locaux », which the asset's payments are booked under.
        await page.goto('/config/finance/accounts', { waitUntil: 'domcontentloaded' });
        await page.getByRole('button', { name: 'Nouveau compte' }).click();
        await page.getByLabel(/^Nom \*$/).fill(ACCOUNT_NAME);
        await page.getByLabel('IBAN').fill(ACCOUNT_IBAN);
        await page.getByLabel('Nom du titulaire').fill('Unité de test E2E');
        await page.getByRole('button', { name: 'Enregistrer' }).click();
        // Active, or Finance would offer it to nothing: an IBAN and a holder.
        await expect(page.getByRole('row', { name: new RegExp(ACCOUNT_NAME) }).getByText('Actif')).toBeVisible();
        await page.goto('/config/finance/categories', { waitUntil: 'load' });

        // The asset, its manager, and the account it is paid on.
        await page.goto('/admin/locations/nouveau', { waitUntil: 'load' });
        const creation = page.locator('form[action="/admin/locations/create"]');
        await creation.locator('input[name="name"]').fill(ASSET_NAME);
        await creation.locator('select[name="asset_type"]').selectOption('Local');
        await creation.locator('input[name="capacity"]').fill('40');
        await creation.locator('input[name="is_public"]').check();
        await creation.getByRole('button', { name: 'Créer le bien' }).click();
        await expect(page).toHaveURL(/\/admin\/locations\/\d+$/);

        await grantManagerBySearch(page);

        const accountDialog = await openSectionEditor(page, 'compte-edit');
        const accountSelect = accountDialog.locator('select[name="finance_account_id"]');
        await accountSelect.selectOption(
            await accountSelect.locator('option', { hasText: ACCOUNT_NAME }).getAttribute('value'),
        );
        await accountDialog.locator('select[name="finance_category_id"]').selectOption({ label: 'Locaux' });
        await accountDialog.getByRole('button', { name: 'Enregistrer le compte' }).click();
        await expect(page.locator('#compte')).toContainText(ACCOUNT_NAME);

        // The tariff and the payments, from the asset's own settings.
        await page.goto(`/mes-locations/${ASSET_SLUG}/reglages`, { waitUntil: 'load' });
        const pricing = await openSectionEditor(page, 'tarification-edit');
        await pricing.locator('select[name="billing_unit"]').selectOption('per_night');
        await pricing.locator('input[name="default_unit_price"]').fill(NIGHT_PRICE);
        await pricing.getByRole('button', { name: 'Enregistrer la tarification' }).click();
        await expect(page.locator('#tarification')).toContainText(NIGHT_PRICE);

        const payments = await openSectionEditor(page, 'paiements-edit');
        await payments.locator('input[name="payments_enabled"]').check();
        await payments.locator('input[name="security_deposit_enabled"]').check();
        await payments.locator('input[name="security_deposit_amount"]').fill(SECURITY_DEPOSIT);
        await payments.getByRole('button', { name: 'Enregistrer les paiements' }).click();
        await expect(page.locator('#paiements')).toContainText(SECURITY_DEPOSIT);

        // One inventory line, so the stay has something to walk through. It
        // has to exist before the confirmation, which copies it (§6.23).
        await page.goto(`/mes-locations/${ASSET_SLUG}/gabarits`, { waitUntil: 'load' });
        const inventory = page.locator('#inventory-list');
        await inventory.locator('#item-label').fill(INVENTORY_KEYS);
        await inventory.locator('#item-kind').selectOption('quantity');
        await inventory.getByRole('button', { name: 'Ajouter', exact: true }).click();
        await expect(inventory.locator('.list-editor-item', { hasText: INVENTORY_KEYS })).toBeVisible();

        // The manager's signature, which the countersignatures put on the
        // renter's copy. Imported as an image rather than traced.
        await page.goto('/mes-locations/ma-signature', { waitUntil: 'load' });
        await page.locator('#signature-file').setInputFiles({
            name: 'signature.png',
            mimeType: 'image/png',
            buffer: pngBuffer(600, 200),
        });
        await page.getByRole('button', { name: 'Enregistrer ma signature' }).click();
        await expect(page.getByRole('button', { name: 'Supprimer ma signature' })).toBeVisible();

        // ── 1. Two visitors, the same dates, the same moment ──────────────
        const visitors = [
            await openRequestForm(browser, 'Claire Dumont', 'claire.dumont@example.be'),
            await openRequestForm(browser, 'Marc Lambert', 'marc.lambert@example.be'),
        ];
        await Promise.all(visitors.map((visitor) => waitOutHumanCheckDelay(visitor)));
        await Promise.all(visitors.map(
            (visitor) => visitor.getByRole('button', { name: 'Envoyer ma demande' }).click(),
        ));

        // Each lands somewhere definite: its tracking page, or the form back
        // with the reason. Waited on as a state, never as a time.
        const outcomes = await Promise.all(visitors.map(async (visitor) => {
            const tracked = visitor.getByRole('heading', { name: /Votre demande LOC-[2-9A-HJKMNP-Z]{6}/ });
            const refused = visitor.getByRole('alert');
            await expect(tracked.or(refused).first()).toBeVisible();

            return (await tracked.count()) > 0 ? 'booked' : 'refused';
        }));
        expect(outcomes.slice().sort(), 'exactly one of the two requests takes the week')
            .toEqual(['booked', 'refused']);

        const renter = visitors[outcomes.indexOf('booked')];
        const turnedAway = visitors[outcomes.indexOf('refused')];
        // Refused for the dates, the form kept as it was typed.
        await expect(turnedAway.getByRole('alert').first()).toContainText(/disponible|occup|réserv/i);
        await expect(turnedAway.locator('input[name="arrival"]')).toHaveValue(ARRIVAL);
        await turnedAway.context().close();

        const trackingUrl = renter.url();
        const reference = (await renter.getByRole('heading', { name: /Votre demande LOC-/ }).textContent())
            .match(/LOC-[2-9A-HJKMNP-Z]{6}/)[0];

        // The renter's page asks nothing of them and offers no change: they
        // contact the unit (#927, chantier §2.2).
        await expectNoRenterChangeAction(renter);

        // ── 2. The contract, which prints the communication ───────────────
        await page.goto(`/mes-locations/${ASSET_SLUG}/reservations`, { waitUntil: 'load' });
        await page.getByRole('link', { name: new RegExp(reference) }).first().click();
        await page.waitForURL(/\/reservations\/\d+$/, { waitUntil: 'load' });
        const dashboard = page.url();

        await page.getByRole('button', { name: 'Générer le contrat' }).first().click();
        const send = page.getByRole('button', { name: 'Envoyer le contrat' }).first();
        // A PDF is rendered inside this request; dompdf loads its fonts on
        // the first document of the run.
        await expect(send).toBeVisible({ timeout: scaled(45_000) });
        await send.click();
        await expect(milestone(page, 'Contrat envoyé')).toContainText(DONE);

        // The communication exists from the first generation (§2.5): the
        // renter is shown it, and it is the one written in the PDF.
        await renter.goto(trackingUrl, { waitUntil: 'load' });
        const communication = (await renter.locator('[data-renter-payment-communication] .font-monospace')
            .textContent()).trim();
        expect(communication).toMatch(/^\+\+\+\d{3}\/\d{4}\/\d{5}\+\+\+$/);

        await page.goto(`${dashboard}/documents`, { waitUntil: 'load' });
        const firstContract = await pdfText(page,
            page.locator('[data-contract-in-force]').getByRole('link', { name: 'Ouvrir' }));
        expect(firstContract, 'the contract prints the booking\'s communication').toContain(communication);

        // ── 3. The renter's signed copy holds the dates firmly ────────────
        await uploadSignedCopy(renter, 'contrat-signe.png');
        await expect(renter.locator('#contrat')).toContainText('Votre copie signée est bien reçue');

        await page.goto(dashboard, { waitUntil: 'load' });
        await expect(milestone(page, 'Contrat signé reçu')).toContainText(DONE);
        await expect(milestone(page, 'Dates bloquées fermement')).toContainText(DONE);
        // Firm is not confirmed: the confirmation stays the manager's.
        await expect(milestone(page, 'Réservation confirmée')).toContainText(TODO);

        // ── 4. Countersigned, then confirmed ─────────────────────────────
        await page.getByRole('button', { name: 'Valider et contresigner' }).click();
        await expect(milestone(page, 'Contrat contresigné')).toContainText(DONE);
        await page.getByRole('button', { name: 'Confirmer la réservation' }).first().click();
        await expect(milestone(page, 'Réservation confirmée')).toContainText(DONE);

        // ── 5. A modification after the contract: a project ───────────────
        await page.goto(`${dashboard}/modifications`, { waitUntil: 'load' });
        await page.getByRole('link', { name: 'Créer une modification' }).click();
        await page.waitForURL(/\/modifications\/nouvelle$/, { waitUntil: 'load' });
        const form = page.locator('#modification-form');
        await form.locator('input[name="arrival"]').fill(NEW_ARRIVAL);
        await form.locator('input[name="departure"]').fill(NEW_DEPARTURE);
        await page.locator('[data-modification-actions]')
            .getByRole('button', { name: 'Enregistrer la modification' }).click();
        await page.waitForURL(/\/modifications\/\d+$/, { waitUntil: 'load' });

        // ── 6. The replacement contract ───────────────────────────────────
        await page.getByRole('button', { name: 'Générer et envoyer le contrat de remplacement' }).click();
        await expect(page.getByText('Le contrat de remplacement est parti')).toBeVisible({ timeout: scaled(45_000) });

        // Nothing has changed on the booking yet: the dashboard still states
        // the dates in force.
        await page.goto(dashboard, { waitUntil: 'load' });
        await expect(page.getByText(`du ${frenchDate(ARRIVAL)} au ${frenchDate(DEPARTURE)}`)
            .filter({ visible: true }).first()).toBeVisible();

        // Same communication on the replacement contract (chantier §4).
        await page.goto(`${dashboard}/documents`, { waitUntil: 'load' });
        await expect(page.locator('[data-modification-banner]')).toContainText('Une modification est en cours');
        const replacement = page.locator('[data-replacement-contract]');
        await expect(replacement).toContainText('copie attendue');
        const replacementText = await pdfText(page, replacement.getByRole('link', { name: 'Ouvrir' }));
        expect(replacementText, 'the replacement contract keeps the communication').toContain(communication);

        // ── 7. A copy refused, then a copy accepted ───────────────────────
        await renter.goto(trackingUrl, { waitUntil: 'load' });
        await expect(renter.locator('[data-replacement-sent]')).toBeVisible();
        await uploadSignedCopy(renter, 'nouveau-contrat-page-1.png');
        await expect(renter.locator('[data-replacement-copy-received]')).toBeVisible();

        await page.goto(`${dashboard}/documents`, { waitUntil: 'load' });
        await expect(replacement).toContainText('à vérifier');
        await page.locator('details', { has: page.locator('form[action="/mes-locations/copie-refuser"]') })
            .locator('summary').click();
        const refusal = page.locator('form[action="/mes-locations/copie-refuser"]');
        await refusal.locator('textarea[name="reason"]').fill("La deuxième page n'est pas signée.");
        await refusal.getByRole('button', { name: 'Refuser la copie' }).click();
        await expect(replacement).toContainText("La deuxième page n'est pas signée.");

        // Refused, the project is still the one running and the booking has
        // not moved.
        await renter.goto(trackingUrl, { waitUntil: 'load' });
        await expect(renter.locator('#contrat')).toContainText("La deuxième page n'est pas signée.");
        await expect(stayDates(renter)).toContainText(frenchDate(DEPARTURE));
        await expectNoRenterChangeAction(renter);
        await uploadSignedCopy(renter, 'nouveau-contrat-complet.png');
        await expect(renter.locator('[data-replacement-copy-received]')).toBeVisible();

        // ── 8. The countersignature applies the modification ──────────────
        await page.goto(`${dashboard}/documents`, { waitUntil: 'load' });
        await expect(replacement).toContainText('à vérifier');
        await replacement.getByRole('button', { name: 'Contresigner' }).click();
        await expect(page.locator('[data-modification-banner]')).toHaveCount(0);
        await expect(page.locator('[data-contract-in-force]')).toContainText(frenchDate(NEW_DEPARTURE));

        await page.goto(`${dashboard}/modifications`, { waitUntil: 'load' });
        await expect(page.getByText('Appliquée', { exact: true }).first()).toBeVisible();

        // The renter's page shows the new dates and the new amount to pay.
        await renter.goto(trackingUrl, { waitUntil: 'load' });
        await expect(stayDates(renter)).toContainText(frenchDate(NEW_DEPARTURE));
        await expect(renter.locator('#paiement')).toContainText('450,00');
        await expect(renter.locator('[data-renter-payment-communication]')).toContainText(communication);

        // ── 9. The renter pays, with the communication ────────────────────
        await importStatement(page, [
            statementLine('2026000101', 0, '450,00', 'Claire ou Marc', communication),
        ], '450,00');

        // Reconciled on its own, by the communication alone.
        await page.goto(`${dashboard}/finances`, { waitUntil: 'load' });
        await expect(page.locator('[data-finance-movements]')).toContainText('450,00');
        await expect(page.locator('[data-finance-nothing-due]')).toBeVisible();
        await page.goto(dashboard, { waitUntil: 'load' });
        await expect(milestone(page, 'Solde reçu')).toContainText(DONE);
        await expect(milestone(page, 'Caution reçue')).toContainText(DONE);

        // ── 10. The stay ─────────────────────────────────────────────────
        await page.goto(`${dashboard}/etat-des-lieux`, { waitUntil: 'load' });
        await takeReference(page, INVENTORY_KEYS);
        await validateInventory(page, "Valider l'état des lieux d'entrée");
        await takeReference(page, INVENTORY_KEYS);
        await validateInventory(page, "Valider l'état des lieux de sortie");
        await expect(page.locator('[data-booking-panel="inventory"]'))
            .toContainText('Les deux états des lieux sont validés');

        // ── 11. The settlement: the unit keeps the rent ──────────────────
        await page.goto(`${dashboard}/facture`, { waitUntil: 'load' });
        await page.locator('#final-persons').fill('28');
        await submitAndRefresh(page, '/mes-locations/decompte',
            () => page.getByRole('button', { name: 'Enregistrer un décompte' }).click());
        await submitAndRefresh(page, '/mes-locations/decompte-valider',
            () => page.getByRole('button', { name: 'Valider', exact: true }).click());
        await expect(page.getByRole('button', { name: 'Valider', exact: true })).toHaveCount(0);

        await page.goto(`${dashboard}/finances`, { waitUntil: 'load' });
        const refund = page.locator('[data-finance-refund]');
        await expect(refund).toContainText('À rembourser au locataire : 150,00');
        await expect(refund).toContainText(communication);

        // ── 12. The refund leaves the bank with the communication ─────────
        await importStatement(page, [
            statementLine('2026000102', 0, '-150,00', 'Claire ou Marc', communication),
        ]);

        await page.goto(`${dashboard}/finances`, { waitUntil: 'load' });
        await expect(page.locator('[data-finance-refund]')).toHaveCount(0);
        await expect(page.locator('[data-booking-panel="payment"]')).toContainText('Remboursé');
        await expect(page.locator('[data-finance-movements]')).toContainText('150,00');
        await page.goto(dashboard, { waitUntil: 'load' });
        await expect(milestone(page, 'Caution restituée')).toContainText(DONE);

        // And the renter, owed nothing, is asked for nothing.
        await renter.goto(trackingUrl, { waitUntil: 'load' });
        await expect(renter.locator('[data-renter-payment-nothing-due]')).toBeVisible();
        await expectNoRenterChangeAction(renter);

        await renter.context().close();
        expect(serverErrors, 'the application returned a server error').toEqual([]);
        expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
    });
});

/**
 * One line of « Cycle de vie », by its label — the same contract with
 * `rental-booking.js` as `rental-lifecycle.spec.js` reads it through.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
function milestone(page, label) {
    return page.locator('[data-booking-panel="milestones"] li')
        .filter({ has: page.getByText(label, { exact: true }) });
}

/**
 * The renter's tracking page offers no change and no cancellation: the
 * controls that used to (« Modifier votre demande », answering a proposal,
 * « Annuler ma demande ») are gone, and nothing took their place.
 *
 * @param {import('@playwright/test').Page} renter
 */
async function expectNoRenterChangeAction(renter) {
    const main = renter.locator('#main-content');
    await expect(main.getByRole('button', { name: /modifier|annuler|accepter|refuser/i })).toHaveCount(0);
    await expect(main.getByRole('link', { name: /modifier|annuler/i })).toHaveCount(0);
    await expect(main).not.toContainText('Modifier votre demande');
    await expect(main).not.toContainText('Annuler ma demande');
}

/**
 * Open the public request form in a browser of its own and fill it in for
 * ARRIVAL → DEPARTURE, without sending it.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string} name
 * @param {string} email
 * @returns {Promise<import('@playwright/test').Page>}
 */
async function openRequestForm(browser, name, email) {
    const page = await (await browser.newContext()).newPage();
    await page.goto(`/locations/${ASSET_SLUG}/demande`, { waitUntil: 'load' });
    await answerCookieBanner(page);

    await page.locator('input[name="arrival"]').fill(ARRIVAL);
    await page.locator('input[name="departure"]').fill(DEPARTURE);
    await page.locator('input[name="persons"]').fill('28');
    await page.locator('input[name="name"]').fill(name);
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="phone"]').fill('0470 12 34 56');
    await page.locator('input[name="purpose"]').fill('Week-end de section');
    await page.locator('input[name="accept_conditions"]').check();
    await page.locator('input[name="accept_privacy"]').check();

    return page;
}

/**
 * Deposit a signed copy from the renter's tracking page — a photograph of
 * the printed pages, which the form accepts.
 *
 * @param {import('@playwright/test').Page} renter
 * @param {string} fileName
 */
async function uploadSignedCopy(renter, fileName) {
    await renter.locator('#signed-copy').setInputFiles({
        name: fileName,
        mimeType: 'image/png',
        buffer: pngBuffer(600, 800),
    });
    await renter.getByRole('button', { name: 'Envoyer ma copie signée' }).click();
    await renter.waitForLoadState('load');
}

/**
 * The text of the PDF a link opens, as far as a contract needs reading.
 *
 * dompdf deflates each content stream and writes a Unicode font's text as
 * UTF-16BE literal strings: inflating every stream and dropping the NUL
 * high bytes gives back the characters a contract is written in (ASCII and
 * Latin-1), which is all a communication is made of.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} link
 */
async function pdfText(page, link) {
    const response = await page.request.get(await link.getAttribute('href'));
    expect(response.ok()).toBe(true);
    const pdf = await response.body();

    let text = '';
    let cursor = 0;
    for (;;) {
        const start = pdf.indexOf('stream', cursor);
        if (start < 0) {
            break;
        }
        const end = pdf.indexOf('endstream', start);
        if (end < 0) {
            break;
        }
        let from = start + 'stream'.length;
        if (pdf[from] === 0x0d) {
            from++;
        }
        if (pdf[from] === 0x0a) {
            from++;
        }
        try {
            text += inflateSync(pdf.subarray(from, end)).toString('latin1');
        } catch {
            // Not deflated (an image, a font program): not text.
        }
        cursor = end + 'endstream'.length;
    }

    return text.replace(/\u0000/g, '').replace(/\\([()\\])/g, '$1');
}

/**
 * A statement date, `dd/mm/yyyy`, `daysAgo` before today in Brussels and
 * never before the current scout year's first day — the import files a
 * movement under the scout year of its date (see finance-receipts.spec.js,
 * where the same rule is explained at length).
 *
 * @param {number} daysAgo
 */
function statementDate(daysAgo) {
    const inBrussels = (date) => new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Brussels', year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(date);

    const today = inBrussels(new Date());
    const [year, month] = today.split('-').map(Number);
    const yearStart = `${month >= 9 ? year : year - 1}-09-01`;
    const wanted = inBrussels(new Date(Date.now() - daysAgo * 24 * 60 * 60 * 1000));
    const [y, m, d] = (wanted < yearStart ? yearStart : wanted).split('-');

    return `${d}/${m}/${y}`;
}

/**
 * One line of a BNP Fortis export on ACCOUNT_IBAN, carrying `communication`
 * where a bank puts a structured one.
 *
 * @param {string} bankReference the « REFERENCE BANQUE » the import
 *        deduplicates on: one per movement
 * @param {number} daysAgo
 * @param {string} amount French decimal, negative for a debit
 * @param {string} counterpart
 * @param {string} communication
 */
function statementLine(bankReference, daysAgo, amount, counterpart, communication) {
    const date = statementDate(daysAgo);
    const iban = ACCOUNT_IBAN.replace(/ /g, '');

    return `2026-;${date};${date};${amount};EUR;${iban};Virement en euros;BE00000000000004;${counterpart};`
        + `${communication};VIREMENT EN EUROS ${counterpart.toUpperCase()} COMMUNICATION : ${communication} `
        + `REFERENCE BANQUE : ${bankReference} ;Accepté;`;
}

/**
 * Import a statement through the real BNP parser and the real
 * reconciliation, and wait for its report.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string[]} lines
 * @param {string} [closingBalance] asked on the account's first import only
 */
async function importStatement(page, lines, closingBalance) {
    const header = "﻿Nº de séquence;Date d'exécution;Date valeur;Montant;Devise du compte;Numéro de compte;"
        + 'Type de transaction;Contrepartie;Nom de la contrepartie;Communication;Détails;Statut;Motif du refus';

    await page.goto('/finance/import', { waitUntil: 'load' });
    await page.getByLabel('Fichier du relevé').setInputFiles({
        name: 'releve-bnp.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from([header, ...lines].join('\n'), 'utf8'),
    });
    if (closingBalance !== undefined) {
        await page.getByLabel('Solde après ce relevé').fill(closingBalance);
    }
    await page.getByRole('button', { name: 'Importer' }).click();

    // The report names the account each part of the file went to: ours, and
    // only ours, with every line new — nothing set aside as ambiguous or
    // unknown, nothing read as already imported.
    await expect(page.getByRole('heading', { name: "Résultat de l'import" }))
        .toBeVisible({ timeout: scaled(15_000) });
    await expect(page.getByRole('heading', { name: 'Lignes ignorées' })).toHaveCount(0);
    const report = page.locator('section', {
        has: page.getByRole('heading', { name: ACCOUNT_NAME, exact: true }),
    });
    await expect(report)
        .toContainText(`${lines.length} ligne(s) lue(s), ${lines.length} nouvelle(s), 0 déjà présente(s)`);
}

/**
 * Wait for one of the booking page's ASYNC forms: its POST, then the GET of
 * this same page that rental-booking.js re-renders the panels from.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} action
 * @param {() => Promise<void>} submit
 */
async function submitAndRefresh(page, action, submit) {
    const here = page.url().split(/[?#]/)[0];
    const posted = waitForServerResponse(page,
        (response) => response.url().endsWith(action) && response.request().method() === 'POST');
    const refreshed = waitForServerResponse(page,
        (response) => response.url().split(/[?#]/)[0] === here && response.request().method() === 'GET');
    await submit();
    await posted;
    await refreshed;
    await expect(page.locator('[data-rental-booking]')).not.toHaveAttribute('aria-busy', 'true');
}

/**
 * « = » on one inventory line: the reference taken, and saved.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} item
 */
async function takeReference(page, item) {
    const field = page.getByLabel(item, { exact: true });
    const saved = waitForServerResponse(page,
        (response) => response.url().endsWith('/mes-locations/etat-des-lieux/ligne')
            && response.request().method() === 'POST');
    await page.getByRole('button', { name: `Reprendre la référence pour ${item}` }).click();
    await saved;
    await expect(page.locator('form[data-inventory-line]').filter({ has: field })
        .locator('[data-inventory-status]')).toHaveText('Enregistré');
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label the validation button's label
 */
async function validateInventory(page, label) {
    await submitAndRefresh(page, '/mes-locations/etat-des-lieux/valider',
        () => page.getByRole('button', { name: label }).click());
}

/**
 * The « Votre séjour » block of the renter's page.
 *
 * @param {import('@playwright/test').Page} page
 */
function stayDates(page) {
    return page.locator('.card', { has: page.getByRole('heading', { name: 'Votre séjour' }) });
}

/**
 * `2027-07-24` as `|date_fr` prints it, reached by another route than the
 * template's.
 *
 * @param {string} iso
 */
function frenchDate(iso) {
    const [year, month, day] = iso.split('-');

    return `${day}/${month}/${year}`;
}

/**
 * Name the seeded member (Baden Powell) manager of the asset on screen —
 * the same helper every rental spec carries, copied rather than shared so a
 * change to the managers screen breaks the spec that asserts on it.
 *
 * @param {import('@playwright/test').Page} page
 */
async function grantManagerBySearch(page) {
    const dialog = await openSectionEditor(page, 'gestionnaires-edit');
    await page.locator('#rental-manager-search').fill('Powell');
    const result = page.locator('#rental-manager-results button').first();
    await expect(result).toBeVisible();
    await result.click();
    await dialog.getByRole('button', { name: 'Enregistrer les gestionnaires' }).click();
    await expect(page.getByText('Les gestionnaires ont été enregistrés.')).toBeVisible();
}
