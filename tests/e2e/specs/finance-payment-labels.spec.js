// End-to-end: finance — a payment campaign, and the sheet of labels a
// treasurer cuts up and hands out at the door of a unit meeting.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The label sheet is a page-less route whose whole output is a BINARY the
// server generates, and three of the things that can go wrong with it are
// invisible to PHPUnit:
//
//   1. The route is reached through the real composition root. The
//      service it needs (Modules\Finance\Service\PaymentLabelService) is
//      wired into CampaignController by hand in public/index.php, in the
//      long argument list AGENTS.md § Static analysis describes as the
//      exact place a constructor change reaches production unnoticed. No
//      PHPUnit test executes that file.
//   2. dompdf runs for real, over the real Twig template, with real QR
//      PNGs embedded as data: URIs — a render that PHPUnit exercises with
//      the same code but that only the booted application proves is
//      reachable at all.
//   3. The campaign that feeds it is built the way a treasurer builds
//      one: a real .xlsx uploaded through the real import, resolved to
//      real members through their identifier and nothing else.
//
// WHAT IS ASSERTED, AND WHAT DELIBERATELY IS NOT
// ----------------------------------------------------------------------------
// That the PDF is produced, that it really is a PDF, and that it carries
// exactly as many labels as there are receivables still owing something —
// including after one of them stops owing anything. **The layout is not
// asserted from here**: millimetres, font sizes and page breaks are
// pinned in Tests\Modules\Finance\Service\PaymentLabelServiceTest, where
// a failure names the rule it broke instead of a byte offset.
//
// Counting labels: dompdf embeds one image XObject per DISTINCT image,
// and every receivable of a campaign carries its own structured
// communication, so no two QR codes of a sheet are ever the same bytes —
// one `/Subtype /Image` per label. Those dictionaries are written
// uncompressed, unlike the content streams, which is what makes them
// readable from the raw response.
//
// tests/e2e/fixtures/campagne-etiquettes.xlsx (sheet "Cotisations"):
//   Identifiant Desk | Nom          | Montant
//   E2E-ADMIN        | Powell Baden | 45,00
//   E2E-MEMBER       | Serpent Kaa  | 38,25
// The two desk identifiers are the ones scripts/e2e-support.php seeds for
// the super-admin and the ordinary member — stable strings rather than
// auto-increment ids, which is what lets a committed file address them.
// Written with PhpSpreadsheet, the same writer the site's own member
// export uses, so what the importer reads here is what it reads from a
// unit's real re-imported export.
import { expect, test } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { scaled } from '../support/timeouts.js';

const FIXTURES = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../fixtures');

const ACCOUNT_NAME = `Compte étiquettes E2E ${Date.now()}`;
const ACCOUNT_IBAN = 'BE71 0961 2345 6769';

/**
 * How many labels a generated sheet carries — one embedded image each.
 *
 * @param {Buffer} pdf
 */
function labelCount(pdf) {
    return (pdf.toString('latin1').match(/\/Subtype\s*\/Image/g) || []).length;
}

/**
 * Put the instance back the way this scenario found it, pass or fail.
 *
 * **This is not tidiness, it is a defect this spec already caused.** The
 * movements page with no `account_id` shows the first ACTIVE account BY
 * NAME (`FinanceService::resolveSelectedAccount()` falls through to
 * `$accounts[0]`, and `AccountRepository::findAllOrdered()` sorts on
 * `name`). MySQL's collation is accent-insensitive, so « Compte
 * étiquettes E2E » sorts ahead of « Compte reçus E2E » — and the account
 * created here silently became what finance-receipts.spec.js, the very
 * next spec in alphabetical order, saw on its own bare
 * `/finance/movements`. That spec was green until this one landed beside
 * it, and then failed on a movement it had itself imported.
 *
 * Deactivating is what actually removes it from that list (the ACTIVE
 * filter is what `getAccountsForUser()` applies), and it destroys
 * nothing: the campaign, its receivables and the account itself stay for
 * anyone reading the instance afterwards. It runs in an afterEach rather
 * than at the end of the test so a failure half-way through does not
 * leave the landmine armed for every spec that follows.
 *
 * It must never throw: a cleanup that fails on top of a real failure
 * replaces the error somebody needs to read with one nobody does.
 */
test.afterEach(async ({ page }) => {
    try {
        await page.goto('/config/finance/accounts', { waitUntil: 'domcontentloaded' });
        const row = page.getByRole('row', { name: new RegExp(ACCOUNT_NAME) });
        if (await row.count() === 0) {
            return; // the test failed before creating it
        }

        // Two buttons in the row — « Modifier » and this one — so the
        // substring matching of `name` has nothing to collide with.
        const toggle = row.getByRole('button', { name: 'Actif — cliquer pour désactiver' });
        if (await toggle.count() === 0) {
            return; // never activated, or already deactivated
        }

        await toggle.click();
        // finance-accounts.js reloads the page once the POST resolves, so
        // the toggle coming back under its OTHER label is the barrier —
        // never a delay.
        await expect(row.getByRole('button', { name: 'Inactif — cliquer pour activer' }))
            .toBeVisible({ timeout: scaled(15_000) });
    } catch {
        // Deliberately swallowed — see the docblock.
    }
});

test('a campaign prints a sheet of payment labels, and a receivable that stops owing stops being printed', async ({ page }) => {
    // Roomier than the default: a login, an account, a spreadsheet import
    // and TWO dompdf renders (each embedding freshly generated QR PNGs)
    // in one scenario. Nothing here should come close to it — the budget
    // exists so a slow runner fails on an assertion rather than on the
    // clock.
    test.setTimeout(scaled(90_000));

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

    await autoConfirm(page);
    await loginAsAdmin(page);
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // The account the families will pay into. Without an IBAN there is
    // nothing to print, and the site says so rather than producing a
    // sheet nobody can act on.
    // ---------------------------------------------------------------
    await page.goto('/config/finance/accounts', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Nouveau compte' }).click();
    await page.getByLabel(/^Nom \*$/).fill(ACCOUNT_NAME);
    await page.getByLabel('IBAN').fill(ACCOUNT_IBAN);
    await page.getByLabel('Nom du titulaire').fill('Unité de test E2E');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByRole('row', { name: new RegExp(ACCOUNT_NAME) })).toBeVisible();

    // ---------------------------------------------------------------
    // The campaign, built the way a treasurer builds one: the real
    // .xlsx, the real import, and the identifier column doing the
    // matching (there is no fall-back on a name, on purpose).
    // ---------------------------------------------------------------
    await page.goto('/finance/campaigns/new', { waitUntil: 'load' });
    await page.getByLabel(/^Nom de la campagne \*$/).fill('Cotisations étiquettes E2E');
    await page.getByLabel(/^Compte destinataire \*$/).selectOption({ label: ACCOUNT_NAME });
    await page.getByLabel(/^Fichier Excel \*$/).setInputFiles(path.join(FIXTURES, 'campagne-etiquettes.xlsx'));
    await page.getByRole('button', { name: 'Créer la campagne' }).click();

    await page.waitForURL(/\/finance\/campaigns\/\d+$/, { waitUntil: 'load', timeout: scaled(20_000) });
    const campaignUrl = new URL(page.url()).pathname;

    // Two unpaid receivables, so two labels — and the button says so
    // before anybody prints anything.
    // `exact`: the default is a case-insensitive SUBSTRING match, and
    // « Étiquettes (1) » below would happily match « Étiquettes (12) ».
    await expect(page.getByRole('link', { name: 'Étiquettes (2)', exact: true })).toBeVisible();

    // ---------------------------------------------------------------
    // The sheet itself. Fetched through the page's own session rather
    // than saved as a download: what is being read is the bytes, and a
    // file on disk would only add a step between the route and them.
    // ---------------------------------------------------------------
    const sheet = await page.request.get(`${campaignUrl}/labels`);
    expect(sheet.status()).toBe(200);
    expect(sheet.headers()['content-type']).toContain('application/pdf');
    expect(sheet.headers()['content-disposition']).toContain('etiquettes-campagne-');

    const pdf = await sheet.body();
    expect(pdf.subarray(0, 5).toString('latin1'), 'a real PDF, not an HTML error page').toBe('%PDF-');
    expect(labelCount(pdf), 'one label per receivable that still owes something').toBe(2);

    // ---------------------------------------------------------------
    // A receivable that stops owing anything stops being printed. Waived
    // rather than paid, because abandoning a receivable is a gesture the
    // browser can make on its own — settling one needs a bank statement,
    // and that path is already covered by finance-receipts.spec.js.
    // ---------------------------------------------------------------
    await page.getByRole('button', { name: 'Détail de la créance' }).filter({ visible: true }).first().click();
    await page.getByRole('button', { name: 'Abandonner la créance' }).filter({ visible: true }).first().click();

    // Scoped to <main>: the same string unscoped would be a strict-mode
    // violation the day a toast or a nav badge repeats it.
    await expect(page.getByRole('main').getByText('La créance a été abandonnée.'))
        .toBeVisible({ timeout: scaled(15_000) });
    await expect(page.getByRole('link', { name: 'Étiquettes (1)', exact: true })).toBeVisible();

    const afterWaiver = await page.request.get(`${campaignUrl}/labels`);
    expect(afterWaiver.status()).toBe(200);
    expect(
        labelCount(await afterWaiver.body()),
        'an abandoned receivable asks for nothing, so it prints nothing',
    ).toBe(1);

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
