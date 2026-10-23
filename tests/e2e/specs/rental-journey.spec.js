// End-to-end: one booking, from the request to the security deposit coming
// back — through a modification made after the contract went out (#927) —
// and on to a damage settled after the stay and its closure (#980).
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
//     the same communication — « Caution restituée » ticks without a form;
//   - both countersignatures go through the core's signature dialog
//     (issue #926): the first with an image imported for that contract
//     alone, the box unticked, and Mon compte still says no signature is
//     kept; the second traced on the pad with « Mémoriser » ticked, and
//     Mon compte keeps it. The signing manager's notification centre holds
//     « Contrat signé », which opens the booking's Documents page;
//   - the stay is walked on the manager's PHONE (#989, lot H): a Pixel 5
//     context, the tile's menu and the forms tapped, the pads traced by
//     touch events. Both
//     inventories are signed on their own screen (lot E) by the renter on
//     the spot — their typed name and their finger — and by the manager
//     with the signature Mon compte now keeps; between them a damage is
//     reported with two photos through the « + Photo » tile's menu, one
//     taken, one chosen (lot B), laid out one thing per line. Each signed
//     inventory reaches the renter's mailbox with its PDF and nobody in
//     copy, is filed as sent in Documents, and the signing manager's
//     notification opens Documents instead (decision Q1). The renter absent
//     or refusing is rental-lifecycle's and the service's subject.
//
// Each of those is unit-tested on its own. Whether they agree with each
// other on a running install — the communication Finance stores is the one
// the contract printed, the one the tracking page shows, the one the
// statement carries; the copy the renter uploads is attached to the
// replacement contract and not to the one in force — is decidable only
// here.
//
// Then the damage of #980, on the same booking, because each of its steps
// moves the money the steps above set up: a damage seen during the stay
// stays « En cours d'évaluation » — the renter reads one fixed sentence and
// nothing of it, the settlement records but does not validate; the renter's
// insurer pays without the communication and the manager reconciles it by
// hand from the booking, in full beyond what is due; billed, the damage
// enters the settlement, and what goes back is still the deposit, through
// the communication; a decision taken after the validation offers a new
// version, and the invoice already sent is completed by a supplementary
// invoice for the difference alone; what the renter still owes is given up
// by « Clôturer avec un solde non recouvré », with a reason and no e-mail.
// Whether those agree with each other — the hand-reconciled credit counted
// by the settlement, the refund still found by the import, the supplement
// measured against the invoice actually sent, the amount given up the one
// the receivable still lacks — is again decidable only here.
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
// here without assertions of its own. Mail content is read for the two
// signed inventories alone (their recipient, subject, attachment and the
// absence of a copy); that the replacement contract REACHED the renter is
// asserted the way the renter sees it, on their page, which only says so
// once the send was recorded.
//
// HumanCheck (5 public requests per address per 10 minutes) counts the two
// requests this spec submits together with the three other rental specs':
// five, the ceiling. A sixth public request in this tier needs the limit
// raised for the run, not a retry.
import { devices, expect, test } from '@playwright/test';
import { inflateSync } from 'node:zlib';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { openModal } from '../support/modal.js';
import { openSectionEditor } from '../support/section-editor.js';
import { pngBuffer } from '../support/png.js';
import { signInventory } from '../support/inventory-signing.js';
import { giveAssetAddress, giveRenterAddress } from '../support/rental-contract.js';
import { scaled } from '../support/timeouts.js';
import { waitOutHumanCheckDelay } from '../support/human-check.js';
import { waitForServerResponse } from '../support/response.js';
import { waitForMail } from '../support/maildrop.js';

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

// The damage of #980: assessed by the renter's insurer, which pays part of
// it without the booking's communication. The texts are the unit's own:
// the renter's page never shows them.
const DAMAGE = 'Vitre de la cuisine brisée';
const DAMAGE_AWAITING = "Expertise de l'assurance du locataire";
const DAMAGE_REFERENCE = 'SIN-2026-4471';
const INSURER = 'Assurances du Lac';
const UNRECOVERED_REASON = 'Assurance refusée pour le surplus, locataire injoignable.';

/** The renter's name, typed under their signature on the inventory (#989, lot E). */
const RENTER_SIGNER = 'Jeanne Martin';

/** The signature dialog's box (partials/signature_dialog.html.twig, issue #926). */
const REMEMBER = 'Mémoriser ma signature dans mon compte pour une utilisation future';

/** The three states one line of the checklist can be rendered in. */
const DONE = 'Fait :';
const TODO = 'À faire :';

test.describe('Rentals — one booking from the request to the deposit back', () => {
    const title = 'a request, a contract, a replacement contract, a stay and the deposit refunded '
        + 'through one communication, then a damage assessed, billed and partly written off';
    test(title, async ({ page, browser }) => {
        // Long by nature: an asset, a Finance account and a manager to build,
        // contracts, inventories and invoices rendered, three statements
        // imported, two renters. Nothing waits on a timer but the anti-robot
        // delay of the public form.
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

        // What no contract is generated without (issue #977): the asset's
        // address here, the renter's from their own page further down.
        await giveAssetAddress(page, ASSET_SLUG, 'Chemin du Camp 4, 1300 Wavre');

        // One inventory line, so the stay has something to walk through. It
        // has to exist before the confirmation, which copies it (§6.23).
        await page.goto(`/mes-locations/${ASSET_SLUG}/gabarits`, { waitUntil: 'load' });
        const inventory = page.locator('#inventory-list');
        await inventory.locator('#item-label').fill(INVENTORY_KEYS);
        await inventory.locator('#item-kind').selectOption('quantity');
        await inventory.getByRole('button', { name: 'Ajouter', exact: true }).click();
        await expect(inventory.locator('.list-editor-item', { hasText: INVENTORY_KEYS })).toBeVisible();

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
        const renterEmail = ['claire.dumont@example.be', 'marc.lambert@example.be'][outcomes.indexOf('booked')];
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
        await giveRenterAddress(renter, 'Rue Haute 12, 1000 Bruxelles');

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
        // The manager's account keeps no signature: the dialog asks for one
        // for this contract alone (issue #926) — imported here, the
        // « Mémoriser » box left as it comes, unticked.
        const countersign = await openSignatureDialog(page,
            page.getByRole('button', { name: 'Valider et contresigner' }));
        await expect(countersign.getByLabel(REMEMBER)).not.toBeChecked();
        await countersign.locator('input[name="signature_file"]').setInputFiles({
            name: 'signature.png',
            mimeType: 'image/png',
            buffer: pngBuffer(600, 200),
        });
        await countersign.getByRole('button', { name: 'Signer le document' }).click();
        await expect(milestone(page, 'Contrat contresigné')).toContainText(DONE);
        await page.getByRole('button', { name: 'Confirmer la réservation' }).first().click();
        await expect(milestone(page, 'Réservation confirmée')).toContainText(DONE);

        // Signed for that contract alone: nothing was kept in Mon compte.
        await page.goto('/account', { waitUntil: 'load' });
        await expect(page.getByText('Aucune signature enregistrée.')).toBeVisible();

        // The signing account is told, and its notification opens the
        // booking's Documents page (issue #926, decision Q1).
        await page.goto('/notifications', { waitUntil: 'load' });
        // The bell's dropdown carries hidden copies of the same rows.
        const signedNotice = page.locator('form[action$="/read"]',
            { hasText: `Contrat signé — ${ASSET_NAME}` }).filter({ visible: true });
        await expect(signedNotice).toHaveCount(1);
        await expect(signedNotice).toContainText(`Vous avez contresigné le contrat de ${reference} avec votre signature`);
        await signedNotice.getByRole('button').click();
        await page.waitForURL(`${dashboard}/documents`, { waitUntil: 'load' });

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
        // Traced on the pad this time, and remembered: the signature the
        // manager's account keeps from now on.
        const replacementSign = await openSignatureDialog(page,
            replacement.getByRole('button', { name: 'Contresigner' }));
        await trace(page, replacementSign.locator('canvas[data-signature-pad]'));
        await replacementSign.getByLabel(REMEMBER).check();
        await replacementSign.getByRole('button', { name: 'Signer le document' }).click();
        await expect(page.locator('[data-modification-banner]')).toHaveCount(0);
        await expect(page.locator('[data-contract-in-force]')).toContainText(frenchDate(NEW_DEPARTURE));
        await expect(page.getByText('Votre signature est enregistrée dans Mon compte.').first()).toBeVisible();

        await page.goto('/account', { waitUntil: 'load' });
        await expect(page.getByText(/Signature enregistrée le/)).toBeVisible();

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

        // ── 10. The stay, on the manager's phone ─────────────────────────
        // Where an inventory is really walked (#989, lot H): a phone, its
        // screen tapped and its signature pads traced with a finger. The
        // manager's session is carried over rather than signed in again;
        // the desktop page waits until the phone is put away.
        const phoneContext = await browser.newContext({
            ...devices['Pixel 5'],
            storageState: await page.context().storageState(),
            // What the config gives every context it creates, and a context
            // made here does not inherit: without it Bootstrap scrolls
            // smoothly, and a tap that lands while the page still glides to
            // its target only stops the scroll — the button never clicks.
            reducedMotion: 'reduce',
            serviceWorkers: 'block',
        });
        const phone = await phoneContext.newPage();
        phone.on('pageerror', (error) => pageErrors.push(`phone: ${error.message}`));
        phone.on('response', (response) => {
            if (response.status() >= 500) {
                serverErrors.push(`phone: HTTP ${response.status()} on ${response.url()}`);
            }
        });
        await autoConfirm(phone);

        await phone.goto(`${dashboard}/etat-des-lieux`, { waitUntil: 'load' });
        expect(await phone.evaluate(() => matchMedia('(pointer: coarse)').matches), 'a touch screen').toBe(true);
        await takeReference(phone, INVENTORY_KEYS);
        // Signed on its own screen, by the renter on the spot — their name
        // and their finger — and by the manager with the signature Mon
        // compte now keeps (#989, lot E).
        await signInventory(phone, "Signer l'état des lieux d'entrée", { renter: RENTER_SIGNER }, { touch: true });
        await expect(phone.locator('[data-booking-panel="inventory"]')).toContainText("État des lieux d'entrée, validé le");
        // It leaves at once, to the renter alone, the PDF attached; the
        // signing manager is told in the site instead of in copy (Q1).
        await expectInventoryMailed(renterEmail, reference, "État des lieux d'entrée");
        await openSignedNotice(phone, `État des lieux d'entrée signé — ${ASSET_NAME}`,
            `Vous avez signé l'état des lieux d'entrée de ${reference}`);
        await phone.waitForURL(`${dashboard}/documents`, { waitUntil: 'load' });
        await expectFiledAndSent(phone, `etat-des-lieux-entree-${reference}.pdf`);
        await phone.goto(`${dashboard}/etat-des-lieux`, { waitUntil: 'load' });

        // Seen during the stay, its price not known yet: the renter's
        // insurer sends an expert. It stays « En cours d'évaluation », with
        // what it waits for and the insurer's file number (#980, §2.1).
        const reportForm = phone.locator('form[action="/mes-locations/incident"]');
        await reportForm.locator('#incident-description').fill(DAMAGE);
        await reportForm.locator('#incident-amount').fill('250,00');
        // On a phone, one thing per line (#989 §5): the description, the
        // amount under it, then the row of photos ending with the tile.
        await expectStackedBelow(reportForm.locator('#incident-description'), reportForm.locator('#incident-amount'));
        await expectStackedBelow(reportForm.locator('#incident-amount'), reportForm.locator('[data-photo-add]'));
        await expect(phone.getByText("Une vue d'ensemble, puis un gros plan.")).toBeVisible();
        // Two photos through the « + Photo » tile's own menu: the overall
        // view taken with the camera, the close-up chosen from the gallery.
        // Each is reduced in the browser and waits as a thumbnail; the
        // server re-encodes them — no EXIF, no GPS — and shows them on the
        // incident as their `thumb` derivative, through FileAccessGuard.
        await pickPhoto(phone, reportForm, 'Prendre une photo', { name: 'vue.png', mimeType: 'image/png', buffer: pngBuffer(800, 600) });
        await pickPhoto(phone, reportForm, 'Choisir dans la galerie', { name: 'gros-plan.png', mimeType: 'image/png', buffer: pngBuffer(600, 800) });
        await expect(reportForm.locator('[data-photo-pending]')).toHaveCount(2);
        await submitAndRefresh(phone, '/mes-locations/incident',
            () => reportForm.getByRole('button', { name: 'Ajouter', exact: true }).tap());
        const phoneIncident = phone.locator('li[id^="incident-"]', { hasText: DAMAGE });
        const incidentAnchor = await phoneIncident.getAttribute('id');
        await expect(phoneIncident.locator('figure img[src$="/thumb"]')).toHaveCount(2);
        await expect(phoneIncident.getByRole('link', { name: `Vue 1 — ${DAMAGE}` })).toHaveAttribute('href', /\/files\/\d+$/);
        await expect(phoneIncident.getByRole('link', { name: `Vue 2 — ${DAMAGE}` })).toHaveAttribute('href', /\/files\/\d+$/);
        await phoneIncident.locator('input[name="awaiting"]').fill(DAMAGE_AWAITING);
        await phoneIncident.locator('input[name="reference"]').fill(DAMAGE_REFERENCE);
        await submitAndRefresh(phone, '/mes-locations/incident-decision',
            () => phoneIncident.getByRole('button', { name: 'Trancher' }).tap());
        await expect(phoneIncident).toContainText(`En attente de : ${DAMAGE_AWAITING}`);
        await expect(phoneIncident.getByLabel("En cours d'évaluation")).toBeChecked();

        await takeReference(phone, INVENTORY_KEYS);
        // The departure, signed by both again, on the same phone. The
        // screen shows the incident with its date and both thumbnails —
        // what the renter is about to sign for.
        await phone.getByRole('link', { name: "Signer l'état des lieux de sortie" }).tap();
        await phone.waitForURL(/\/etat-des-lieux\/signer\?phase=departure$/, { waitUntil: 'load' });
        const signingSummary = phone.locator('[data-inventory-summary]');
        await expect(signingSummary).toContainText(DAMAGE);
        await expect(signingSummary.locator('li', { hasText: DAMAGE })).toContainText(/\d{2}\/\d{2}\/\d{4} à \d{2}:\d{2}/);
        await expect(signingSummary.locator('img[src$="/thumb"]')).toHaveCount(2);
        for (const internal of [DAMAGE_AWAITING, DAMAGE_REFERENCE, '250,00']) {
            await expect(signingSummary).not.toContainText(internal);
        }
        await phone.goBack({ waitUntil: 'load' });
        await signInventory(phone, "Signer l'état des lieux de sortie", { renter: RENTER_SIGNER }, { touch: true });
        await expect(phone.locator('[data-booking-panel="inventory"]'))
            .toContainText('Les deux états des lieux sont validés');
        await expectInventoryMailed(renterEmail, reference, 'État des lieux de sortie');
        await openSignedNotice(phone, `État des lieux de sortie signé — ${ASSET_NAME}`,
            `Vous avez signé l'état des lieux de sortie de ${reference}`);
        await phone.waitForURL(`${dashboard}/documents`, { waitUntil: 'load' });
        await expectFiledAndSent(phone, `etat-des-lieux-sortie-${reference}.pdf`);
        await phoneContext.close();

        // Back at the desk for the rest of the booking.
        const incident = page.locator('li[id^="incident-"]', { hasText: DAMAGE });

        // The renter is told one fixed sentence about it, and nothing else:
        // neither what was seen, nor an amount, nor what it waits for — the
        // page never builds them (§2.7).
        await renter.goto(trackingUrl, { waitUntil: 'load' });
        const renterIncidents = renter.locator('#incidents');
        await expect(renterIncidents.locator('[data-renter-incident-assessment]'))
            .toHaveText("Un dégât est en cours d'évaluation ; la caution reste retenue jusqu'à son règlement.");
        await expect(renterIncidents.locator('[data-renter-incident]')).toHaveCount(0);
        const renterMain = renter.locator('#main-content');
        for (const internal of [DAMAGE, DAMAGE_AWAITING, DAMAGE_REFERENCE, '250,00']) {
            await expect(renterMain).not.toContainText(internal);
        }

        // ── 11. The invoice goes out before the damage is settled ────────
        await page.goto(`${dashboard}/facture`, { waitUntil: 'load' });
        await submitAndRefresh(page, '/mes-locations/document-generer',
            () => page.getByRole('button', { name: 'Générer la facture' }).click());
        await submitAndRefresh(page, '/mes-locations/document-envoyer',
            () => page.getByRole('button', { name: 'Envoyer la facture' }).click());
        await expect(page.locator('[data-invoice]')).toContainText('Envoyée le');

        // ── 12. A settlement recorded, which does not validate yet ───────
        await page.locator('#final-persons').fill('28');
        await submitAndRefresh(page, '/mes-locations/decompte',
            () => page.getByRole('button', { name: 'Enregistrer un décompte' }).click());
        const waitsForAssessment = page.locator('[data-settlement-waits="assessment"]');
        await expect(waitsForAssessment).toContainText("Un dégât est en cours d'évaluation");
        await expect(waitsForAssessment.getByRole('link', { name: "Voir l'incident" }))
            .toHaveAttribute('href', new RegExp(`#${incidentAnchor}$`));
        await expect(page.getByRole('button', { name: 'Valider', exact: true })).toHaveCount(0);

        // ── 13. The insurer pays, without the communication ──────────────
        // Nothing reconciles it on its own: it is found from the booking,
        // by its exact amount and part of the payer's name (§2.6), and
        // counts in full even beyond what is due (Q1).
        await importStatement(page, [
            statementLine('2026000103', 0, '250,00', INSURER, `Sinistre ${DAMAGE_REFERENCE}`),
        ]);

        await page.goto(`${dashboard}/finances`, { waitUntil: 'load' });
        const movements = page.locator('[data-finance-movements]');
        await expect(movements).not.toContainText(INSURER);
        const transfer = await openModal(page, 'rapprocher-virement',
            () => page.getByRole('button', { name: 'Rapprocher un virement' }).click());
        await expect(transfer.locator('[data-transfer-statement]')).toBeVisible();
        await transfer.locator('#transfer-amount').fill('250,00');
        await transfer.locator('#transfer-payer').fill('assurances du lac');
        await transfer.getByRole('button', { name: 'Chercher' }).click();
        const candidate = transfer.locator('[data-transfer-results] [data-transfer-candidate]');
        await expect(candidate).toHaveCount(1);
        await expect(candidate).toContainText(INSURER);
        // Its form asks first, then posts the whole page: it comes back with
        // the dialog closed and the credit among the booking's movements.
        await candidate.getByRole('button', { name: 'Rapprocher' }).click();
        await expect(movements.locator('[data-movement-reconciled-by]')).toContainText('Rapproché à la main par');
        await expect(movements).toContainText(INSURER);
        await expect(movements.locator('[data-finance-movement-beyond]')).toContainText('250,00');
        await expect(movements.locator('[data-movement-detach]')).toHaveCount(1);

        // ── 14. Billed, then the settlement: the unit keeps rent and damage
        await page.goto(`${dashboard}/etat-des-lieux`, { waitUntil: 'load' });
        await incident.getByLabel('Facturer', { exact: true }).check();
        await submitAndRefresh(page, '/mes-locations/incident-decision',
            () => incident.getByRole('button', { name: 'Trancher' }).click());
        await expect(incident.locator('[data-incident-amount]')).toHaveText('250,00 €');

        // The version recorded before the decision no longer validates; one
        // recorded after it does.
        await page.goto(`${dashboard}/facture`, { waitUntil: 'load' });
        await expect(page.locator('[data-settlement-waits="outdated"]')).toBeVisible();
        await submitAndRefresh(page, '/mes-locations/decompte',
            () => page.getByRole('button', { name: 'Enregistrer un décompte' }).click());
        await submitAndRefresh(page, '/mes-locations/decompte-valider',
            () => page.getByRole('button', { name: 'Valider', exact: true }).click());
        await expect(page.getByRole('button', { name: 'Valider', exact: true })).toHaveCount(0);

        await renter.goto(trackingUrl, { waitUntil: 'load' });
        await expect(renterIncidents.locator('[data-renter-incident-assessment]')).toHaveCount(0);
        await expect(renterIncidents.locator('[data-renter-incident]')).toContainText(DAMAGE);
        await expect(renterIncidents.locator('[data-renter-incident]')).toContainText('Facturé : 250,00 €');
        await expect(renterMain).not.toContainText(DAMAGE_AWAITING);

        // 300 € of rent and 250 € of damage kept, 700 € received with the
        // insurer's: the security deposit is what goes back.
        await page.goto(`${dashboard}/finances`, { waitUntil: 'load' });
        const refund = page.locator('[data-finance-refund]');
        await expect(refund).toContainText('À rembourser au locataire : 150,00');
        await expect(refund).toContainText(communication);
        // A settlement validated after the reconciliation has counted it.
        await expect(movements.locator('[data-movement-detach]')).toHaveCount(0);

        // ── 15. The refund leaves the bank with the communication ─────────
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

        // ── 16. The repair costs more: a decision after the validation ────
        await page.goto(`${dashboard}/etat-des-lieux`, { waitUntil: 'load' });
        await incident.locator('input[name="amount"]').fill('400,00');
        await submitAndRefresh(page, '/mes-locations/incident-decision',
            () => incident.getByRole('button', { name: 'Trancher' }).click());
        await expect(incident.locator('[data-incident-amount]')).toHaveText('400,00 €');

        // The validated version stays as it was; the page offers the next.
        await page.goto(`${dashboard}/facture`, { waitUntil: 'load' });
        const revision = page.locator('[data-settlement-revision]');
        await expect(revision).toContainText('Le décompte validé (v2) ne tient pas compte de la décision du');
        await submitAndRefresh(page, '/mes-locations/decompte-actualiser',
            () => revision.getByRole('button', { name: 'Enregistrer un nouveau décompte' }).click());
        await expect(revision).toHaveCount(0);
        await submitAndRefresh(page, '/mes-locations/decompte-valider',
            () => page.getByRole('button', { name: 'Valider', exact: true }).click());
        await expect(page.getByRole('button', { name: 'Valider', exact: true })).toHaveCount(0);

        // ── 17. The supplementary invoice bills the difference only ───────
        // 700 € kept against the 300 € the invoice billed; it refers to that
        // invoice, which is never touched.
        const offer = page.locator('[data-supplement-offer]');
        await expect(offer).toContainText('400,00 € de plus');
        await submitAndRefresh(page, '/mes-locations/facture-complementaire',
            () => offer.getByRole('button', { name: 'Générer la facture complémentaire' }).click());
        await expect(offer).toHaveCount(0);
        const supplement = page.locator('[data-supplementary-invoice]');
        await expect(supplement).toContainText('Pas encore envoyée');
        const supplementText = await pdfText(page, supplement.getByRole('link').first());
        expect(supplementText, 'the supplement refers to the original invoice').toContain(`${reference}-v1`);
        await submitAndRefresh(page, '/mes-locations/document-envoyer',
            () => supplement.getByRole('button', { name: 'Envoyer la facture complémentaire' }).click());
        await expect(supplement).toContainText('Envoyée le');
        await expect(page.locator('[data-invoice]')).toContainText('Envoyée le');

        // What is still due falls due thirty days after the validation —
        // not on the settings' date, counted before an arrival long past in
        // a real stay (Q13).
        await renter.goto(trackingUrl, { waitUntil: 'load' });
        await expect(renter.locator('#paiement')).toContainText('150,00');
        await expect(renter.locator('#paiement')).toContainText(`avant le ${brusselsDateIn(30)}`);

        // ── 18. Closing with what will not be recovered ───────────────────
        // 150 € remain due, which the ordinary closure refuses (its sentence
        // waits for the departure date, months away in this spec): the unit
        // can give the balance up, with a reason and no e-mail.
        await page.goto(dashboard, { waitUntil: 'load' });
        const closure = await openModal(page, 'close-unrecovered',
            () => page.getByRole('button', { name: 'Clôturer avec un solde non recouvré' }).click());
        await expect(closure.locator('[data-unrecovered-amount]')).toHaveText('150,00 €');
        await expect(closure).toContainText("Aucun e-mail n'est envoyé au locataire.");
        await closure.locator('#close-unrecovered-reason').fill(UNRECOVERED_REASON);
        await closure.getByRole('button', { name: 'Clôturer et renoncer au solde' }).click();

        await expect(milestone(page, 'Location clôturée')).toContainText(DONE);
        await expect(page.locator('[data-booking-panel="next-step"]'))
            .toContainText('Cette location est clôturée : il ne reste rien à faire.');
        await expect(page.getByRole('alert').filter({ hasText: 'solde non recouvré de 150,00' }))
            .toContainText("Aucun e-mail n'a été envoyé au locataire.");

        // The reason is on the version it wrote, for the unit's managers.
        await page.goto(`${dashboard}/facture`, { waitUntil: 'load' });
        const unrecovered = page.locator('[data-settlement-unrecovered]');
        await expect(unrecovered).toContainText('Montant non recouvré : 150,00 €');
        await expect(unrecovered).toContainText(UNRECOVERED_REASON);

        // The renter sees the damage billed in full, never the reason.
        await renter.goto(trackingUrl, { waitUntil: 'load' });
        await expect(renterIncidents.locator('[data-renter-incident]')).toContainText('Facturé : 400,00 €');
        await expect(renterMain).not.toContainText(UNRECOVERED_REASON);

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
 * Opens the core's signature dialog from the button that targets it, and
 * returns it once Bootstrap says it is open.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} trigger
 * @returns {Promise<import('@playwright/test').Locator>}
 */
async function openSignatureDialog(page, trigger) {
    const target = await trigger.getAttribute('data-bs-target');
    expect(target, 'the button opens a dialog').toMatch(/^#countersign-\d+$/);

    return openModal(page, String(target).slice(1), () => trigger.click());
}

/**
 * A stroke across the pad, the way a finger or a mouse draws one.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} canvas
 */
async function trace(page, canvas) {
    await canvas.scrollIntoViewIfNeeded();
    const box = await canvas.boundingBox();
    expect(box, 'the pad is on screen').not.toBeNull();
    const at = (/** @type {number} */ x, /** @type {number} */ y) => [box.x + box.width * x, box.y + box.height * y];
    await page.mouse.move(...at(0.2, 0.6));
    await page.mouse.down();
    await page.mouse.move(...at(0.5, 0.3), { steps: 8 });
    await page.mouse.move(...at(0.8, 0.6), { steps: 8 });
    await page.mouse.up();
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
 * The day `days` after today in Brussels, as `|date_fr` prints it — the
 * clock the server stamps a validation with.
 *
 * @param {number} days
 */
function brusselsDateIn(days) {
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Brussels', year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(new Date());
    const date = new Date(`${today}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return frenchDate(date.toISOString().slice(0, 10));
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
 * The signing account's notification, found in its centre and opened.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 * @param {string} body the start of its text
 */
async function openSignedNotice(page, title, body) {
    await page.goto('/notifications', { waitUntil: 'load' });
    // The bell's dropdown carries hidden copies of the same rows.
    const notice = page.locator('form[action$="/read"]', { hasText: title }).filter({ visible: true });
    await expect(notice).toHaveCount(1);
    await expect(notice).toContainText(body);
    await notice.getByRole('button').click();
}

/**
 * The signed inventory in the renter's mailbox: theirs alone, under its
 * label and the booking's reference, the PDF attached — and nobody in
 * copy, since the signing manager is told in the site (#989, Q1).
 *
 * @param {string} email the renter's address
 * @param {string} reference the booking's LOC-XXXXXX
 * @param {string} label « État des lieux d'entrée » or « … de sortie »
 */
async function expectInventoryMailed(email, reference, label) {
    const message = await waitForMail(
        (candidate) => candidate.to.includes(email) && candidate.subject.includes(reference)
            && candidate.subject.includes(label),
        { description: `« ${label} » sent to ${email}` },
    );
    expect(message.raw, 'the signed PDF is attached').toMatch(/Content-Type: application\/pdf/i);
    expect(message.raw, 'nobody is in copy').not.toMatch(/^(Cc|Bcc):/im);
}

/**
 * On Documents, the inventory is filed and its send recorded.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} fileName the PDF's name, which tells the two phases apart
 */
async function expectFiledAndSent(page, fileName) {
    const row = page.getByRole('row').filter({ hasText: fileName });
    await expect(row).toHaveCount(1);
    await expect(row).toContainText(/Envoyé le \d{2}\/\d{2}\/\d{4}/);
}

/**
 * `lower` sits entirely below `upper`: on a phone they share no line.
 *
 * @param {import('@playwright/test').Locator} upper
 * @param {import('@playwright/test').Locator} lower
 */
async function expectStackedBelow(upper, lower) {
    const top = await upper.boundingBox();
    const bottom = await lower.boundingBox();
    expect(top, 'the upper control is laid out').not.toBeNull();
    expect(bottom, 'the lower control is laid out').not.toBeNull();
    expect(bottom.y, 'one control per line on a phone').toBeGreaterThanOrEqual(top.y + top.height - 1);
}

/**
 * One photo through the « + Photo » tile, by tapping it and then one entry
 * of its menu — the way a phone opens the camera or the gallery. The file
 * chooser that entry opens is answered with `file`.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} scope the form holding the tile
 * @param {'Prendre une photo' | 'Choisir dans la galerie'} entry
 * @param {{ name: string, mimeType: string, buffer: Buffer }} file
 */
async function pickPhoto(page, scope, entry, file) {
    const pending = scope.locator('[data-photo-pending]');
    const before = await pending.count();
    await scope.getByRole('button', { name: '+ Photo' }).tap();
    const chooser = page.waitForEvent('filechooser');
    await scope.getByRole('button', { name: entry }).tap();
    await (await chooser).setFiles(file);
    await expect(pending).toHaveCount(before + 1);
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
