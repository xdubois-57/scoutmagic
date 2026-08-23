// End-to-end: un publipostage Excel, du fichier refusé aux boîtes personnalisées.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The mail-merge mode (ARCHITECTURE.md §8.61) is the compose dialog's most
// client-built path yet: selecting "Publipostage" swaps the scout-year
// checkboxes for an upload zone, the audience is a real multipart POST of
// an .xlsx the browser picks, the columns come back as chips feeding a
// "Variable" toolbar dropdown that inserts {{tokens}} into a
// contenteditable via execCommand, and test mode swaps in a per-recipient
// preview that pages through the file's rows over fetch. None of that
// exists for PHPUnit (which posts arrays it wrote itself) and none of it
// has a Vitest file (the dialog script is inline Twig). The blast radius
// of a regression is worse than the classic list's: every recipient gets
// a WRONG email — somebody else's first name, somebody else's amount.
//
// So the scenario runs the whole machine on real files: a bad .xlsx is
// refused with its line-by-line report (all-or-nothing, module spec), a
// good one resolves one row by Desk "Tiers" and one by free address, both
// insertion paths put variables in the body, the preview shows each row's
// OWN values, and the real batch send — through the instance's actual
// scheduler entry point — lands two DIFFERENT personalized messages in
// two different mailboxes, unsubscribe footer included for the external
// stranger.
//
// THE FIXTURES
// ----------------------------------------------------------------------------
// tests/e2e/fixtures/publipostage-audience.xlsx (sheet "Camp"):
//   Tiers      | Email                                | Prenom | Montant
//   E2E-MEMBER |                                      | Kaa    | 145
//              | publipostage-externe@example.invalid | Emma   | 80
// "E2E-MEMBER" is the Desk id scripts/e2e-support.php seeds for the
// ordinary member (whose mailbox is E2E_MEMBER_EMAIL) — the Tiers row
// must reach THAT mailbox, resolved through members.desk_id, never
// through anything in the file itself.
//
// tests/e2e/fixtures/publipostage-invalide.xlsx (sheet "Feuille1"):
//   line 2 an invalid address, line 3 an unknown Tiers, line 4 neither —
//   three distinct refusal reasons the error report must name per line.
//
// Both were generated with PhpSpreadsheet (the exact writer the site's
// own exports use), so what the importer reads here is what it will read
// from a unit's real re-imported export.
import { expect, test } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { readMailbox, waitForMail } from '../support/maildrop.js';
import { runScheduler } from '../support/scheduler.js';

const FIXTURES = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../fixtures');
const EXTERNAL_RECIPIENT = 'publipostage-externe@example.invalid';
const TEST_RECIPIENT = 'relecture-publipostage@example.invalid';
// The subject carries a variable too — each delivered message must arrive
// under its own row's rendered subject.
const RUN_TAG = `${Date.now()}`;
const SUBJECT_TEMPLATE = `Camp {{Prenom}} — infos ${RUN_TAG}`;

test('a mail merge refuses a bad file line by line, previews each row, and delivers personalized mail', async ({ page }) => {
    // Two real sends (test + batch) plus a scheduler pass — roomier than
    // the default budget, far under the classic spec's cron-waiting 180s.
    test.setTimeout(120_000);

    const memberEmail = process.env.E2E_MEMBER_EMAIL;
    if (!memberEmail) {
        throw new Error('E2E_MEMBER_EMAIL is not set — run via `npm run e2e`.');
    }

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
    // "Lancer l'envoi" asks first — through the site's own modal now
    // (public/assets/js/mass-mail-list.js → window.ScoutMagicConfirm),
    // which no Playwright dialog handler can see. Installed before the
    // first navigation because the observer is an init script.
    await autoConfirm(page);

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto('/mass-mail', { waitUntil: 'load' });

    // ---------------------------------------------------------------
    // Picking "Publipostage" reshapes the dialog: upload zone in, scout
    // years out (the file, not a year, defines the audience).
    // ---------------------------------------------------------------
    await page.locator('#mm-new-btn').click();
    const dialog = page.locator('#mm-modal');
    await expect(dialog).toBeVisible();

    await dialog.locator('#mm-list').selectOption({ label: 'Publipostage — fichier Excel' });
    await expect(dialog.locator('#mm-merge-zone')).toBeVisible();
    await expect(dialog.locator('#mm-scout-year-zone')).toBeHidden();

    // ---------------------------------------------------------------
    // The bad file: refused whole, every offending line named at once
    // (module spec: all-or-nothing, never a partial import).
    // ---------------------------------------------------------------
    await dialog.locator('#mm-merge-file').setInputFiles(path.join(FIXTURES, 'publipostage-invalide.xlsx'));
    await dialog.locator('#mm-merge-import-btn').click();

    const errorBox = dialog.locator('#mm-error');
    await expect(errorBox).toBeVisible();
    await expect(errorBox).toContainText('n\'a pas été accepté');
    await expect(errorBox).toContainText('Ligne 2');
    await expect(errorBox).toContainText('jean.dupont@');
    await expect(errorBox).toContainText('Ligne 3');
    await expect(errorBox).toContainText('TIERS-INCONNU');
    await expect(errorBox).toContainText('Ligne 4');

    // ---------------------------------------------------------------
    // The good file: imported, columns surfaced as chips, and the
    // "Variable" dropdown appears on the toolbar.
    // ---------------------------------------------------------------
    await dialog.locator('#mm-merge-file').setInputFiles(path.join(FIXTURES, 'publipostage-audience.xlsx'));
    await dialog.locator('#mm-merge-import-btn').click();

    const mergeInfo = dialog.locator('#mm-merge-info-state');
    await expect(mergeInfo).toBeVisible();
    await expect(mergeInfo).toContainText('publipostage-audience.xlsx');
    await expect(mergeInfo).toContainText('2 lignes');
    await expect(mergeInfo).toContainText('Prenom');
    await expect(mergeInfo).toContainText('Montant');

    // ---------------------------------------------------------------
    // Compose with variables — both insertion paths: the toolbar
    // dropdown (execCommand into the contenteditable, first row's value
    // as the sample hint) and a token simply typed by hand.
    // ---------------------------------------------------------------
    await dialog.locator('#mm-subject').fill(SUBJECT_TEMPLATE);

    const body = dialog.locator('#mm-body-content');
    await body.click();
    await page.keyboard.type('Cher ');

    const variableDropdown = dialog.locator('#mm-variable-dropdown');
    await expect(variableDropdown).toBeVisible();
    await variableDropdown.locator('button.dropdown-toggle').click();
    const prenomItem = dialog.locator('#mm-variable-menu .mm-variable-item', { hasText: 'Prenom' });
    // The dropdown offers the first row's value as a sample hint.
    await expect(prenomItem).toContainText('Kaa');
    await prenomItem.click();
    await expect(body).toContainText('Cher {{Prenom}}');

    await body.click();
    await page.keyboard.press('End');
    await page.keyboard.type(', tu devras payer {{Montant}} € pour le camp.');

    await dialog.locator('#mm-save-btn').click();

    // Saving reloads the page; the list row shows the raw template subject.
    // The row can be visible before the reload's own copy of the page's
    // bottom <script> (which wires every .mm-open-btn's click listener)
    // has actually run — parsing reaches the row well before it reaches
    // that script — so the assertions above alone are not enough to
    // prove the new page has finished loading.
    const row = page.getByRole('row', { name: new RegExp(RUN_TAG) });
    await expect(row).toBeVisible();
    await expect(row.getByText('Publipostage')).toBeVisible();
    await expect(row.getByText('Brouillon')).toBeVisible();
    await page.waitForLoadState('domcontentloaded');

    // ---------------------------------------------------------------
    // Reopening rebuilds the dialog from the server: the stored audience
    // must come back (GET /mass-mail/audiences/{id}), not just the draft.
    // ---------------------------------------------------------------
    // Saving reloaded the page, and mass-mail-list.js is deferred: the row
    // is server-rendered and therefore visible BEFORE the script that
    // makes its buttons do anything has run.
    await page.waitForLoadState('load');
    await row.locator('.mm-open-btn').click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#mm-merge-info-state')).toContainText('publipostage-audience.xlsx');

    // ---------------------------------------------------------------
    // Test mode: the per-recipient preview pages through the file's rows
    // with each row's OWN values — the feature's whole point.
    // ---------------------------------------------------------------
    await dialog.locator('#mm-to-test-btn').click();
    await expect(dialog.locator('#mm-status-badge')).toHaveText('Test');

    const preview = dialog.locator('#mm-merge-preview-zone');
    await expect(preview).toBeVisible();
    await expect(preview.locator('#mm-merge-preview-position')).toHaveText('Ligne 1 / 2');
    // Row 1 resolves the Tiers to the seeded member — every known address.
    await expect(preview.locator('#mm-merge-preview-recipient')).toContainText('toutes ses adresses connues');
    await expect(preview.locator('#mm-merge-preview-subject')).toContainText(`Camp Kaa — infos ${RUN_TAG}`);
    await expect(preview.locator('#mm-merge-preview-body')).toContainText('Cher Kaa, tu devras payer 145 € pour le camp.');

    await preview.locator('#mm-merge-next-btn').click();
    await expect(preview.locator('#mm-merge-preview-position')).toHaveText('Ligne 2 / 2');
    await expect(preview.locator('#mm-merge-preview-recipient')).toContainText(EXTERNAL_RECIPIENT);
    await expect(preview.locator('#mm-merge-preview-body')).toContainText('Cher Emma, tu devras payer 80 € pour le camp.');

    // The test send carries the PREVIEWED row's values (row 2, Emma).
    await dialog.locator('#mm-test-send-email').fill(TEST_RECIPIENT);
    await dialog.locator('#mm-test-send-btn').click();

    const testMail = await waitForMail(
        (message) => message.to.includes(TEST_RECIPIENT) && message.subject.includes(`Camp Emma — infos ${RUN_TAG}`),
        { description: `a test send rendered with row 2's values, addressed to ${TEST_RECIPIENT}`, timeout: 20_000 },
    );
    expect(testMail.text).toContain('Cher Emma, tu devras payer 80 €');

    // ---------------------------------------------------------------
    // The real send: freeze, then the batch task through the instance's
    // own scheduler entry point — two rows, two DIFFERENT messages.
    // ---------------------------------------------------------------
    await dialog.locator('#mm-start-sending-btn').click();
    await expect(dialog.locator('#mm-status-badge')).toHaveText(/Envoi en cours|Envoyé/);

    await runScheduler();

    const memberMail = await waitForMail(
        (message) => message.to.includes(memberEmail) && message.subject.includes(`Camp Kaa — infos ${RUN_TAG}`),
        { description: `the Tiers row's mail, personalized for Kaa, delivered to ${memberEmail}` },
    );
    expect(memberMail.text).toContain('Cher Kaa, tu devras payer 145 €');

    const externalMail = await waitForMail(
        (message) => message.to.includes(EXTERNAL_RECIPIENT) && message.subject.includes(`Camp Emma — infos ${RUN_TAG}`),
        { description: `the free-address row's mail, personalized for Emma, delivered to ${EXTERNAL_RECIPIENT}` },
    );
    expect(externalMail.text).toContain('Cher Emma, tu devras payer 80 €');
    // The external stranger gets the one-click unsubscribe like anyone.
    expect(externalMail.text).toContain('/mass-mail/unsubscribe/');

    // Two rows, two messages — never a third from some cross-join.
    const mergeDeliveries = readMailbox().filter((message) => message.subject.includes(RUN_TAG));
    expect(mergeDeliveries.filter((m) => !m.to.includes(TEST_RECIPIENT))).toHaveLength(2);

    // ---------------------------------------------------------------
    // Tracking: the member by name, the external by their address.
    // ---------------------------------------------------------------
    await dialog.locator('#mm-tracking-link').click();
    await page.waitForURL(/\/mass-mail\/\d+\/tracking/, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(EXTERNAL_RECIPIENT).first()).toBeVisible();
    await expect(page.getByText('Envoyé', { exact: false }).first()).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
