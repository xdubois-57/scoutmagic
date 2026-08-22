// End-to-end: Config > Maintenance — the page that can save, restore or
// erase the whole installation.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// maintenance.js is the largest single script in core after groups.js,
// and its whole job is orchestrating requests the browser hand-builds:
// an auto-save select, a background job started by fetch and then POLLED
// to completion (/api/maintenance/backup-status/{id} on a setInterval),
// a secret revealed by POST, and three destructive forms whose only
// safety is a client-side typed-keyword interlock. None of that runs
// under PHPUnit (which calls MaintenanceController directly) or Vitest
// (which mocks every fetch); and this page is where a wiring mistake
// costs the most — restore and reset live three cards below backup.
//
// WHAT IT DELIBERATELY LEAVES ALONE
// ----------------------------------------------------------------------------
// - The destructive branches themselves (reset, restore, full erase):
//   this scenario proves their INTERLOCKS — the buttons stay dead until
//   the exact keyword is typed — and stops there. Running a restore
//   against the shared instance would pull the state out from under
//   every spec after this one; BackupService's mechanics are
//   Tests\Core\Maintenance territory.
// - "Vérifier maintenant" (update check): it calls GitHub over the real
//   network, which a hermetic suite must not depend on.
// - The admin-vs-superadmin split of this page's routes: RBAC per route
//   is PHPUnit's job; the harness has no admin-but-not-superadmin login.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';

const ARCHIVE_PASSWORD = 'Archive-e2e-2024!';

test('maintenance backups run to completion, the auto-save saves, and the danger zone stays locked behind its keywords', async ({ page }) => {
    // The encrypted backup zips core/, modules/ and public/ in the
    // background — well past the default budget on a loaded run.
    test.setTimeout(300_000);

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

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto('/config/maintenance', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Maintenance' })).toBeVisible();

    // ---------------------------------------------------------------
    // The auto-backup frequency select saves on change — no button.
    // ---------------------------------------------------------------
    const frequency = page.locator('#auto-backup-frequency');
    await frequency.selectOption('weekly');
    await expect(page.locator('#auto-backup-frequency-saved')).toBeVisible();

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('#auto-backup-frequency')).toHaveValue('weekly');

    await page.locator('#auto-backup-frequency').selectOption('none');
    await expect(page.locator('#auto-backup-frequency-saved')).toBeVisible();

    // ---------------------------------------------------------------
    // Database-only backup: a plain synchronous POST whose proof is the
    // new row in the backups table, with its download link.
    // ---------------------------------------------------------------
    // Two buttons on the page say "Générer" (database-only, and the full
    // backup's submit); the database one is the plain form targeting the
    // synchronous endpoint.
    await page.locator('form[action="/config/maintenance/backup/database"]')
        .getByRole('button', { name: 'Générer' }).click();
    await page.waitForURL('**/config/maintenance', { waitUntil: 'domcontentloaded' });

    const backupsCard = page.locator('#maintenance-backups');
    await expect(backupsCard.getByRole('cell', { name: 'Base de données seule' }).first()).toBeVisible();

    // ---------------------------------------------------------------
    // Full encrypted backup (configuration-only scope): started by a
    // hand-built fetch, finished by the status-polling loop — the page
    // reloads itself when the poll reports done, and the row appears.
    // ---------------------------------------------------------------
    await page.locator('#scope-config').check();
    await page.locator('#full-backup-password').fill(ARCHIVE_PASSWORD);
    await page.locator('#full-backup-submit').click();
    await expect(page.locator('#full-backup-progress')).toBeVisible();

    // The polling loop ends in window.location.reload(); waiting for the
    // finished row IS waiting for the poll to have seen 'done'. The
    // ceiling is generous — the job zips core/, modules/ and public/.
    await expect(
        backupsCard.getByRole('cell', { name: 'Configuration seule' }).first(),
        'the background backup must complete and its row appear',
    ).toBeVisible({ timeout: 120_000 });
    await expect(page.locator('#full-backup-error')).toBeHidden();

    // ---------------------------------------------------------------
    // The webhook secret (dev-level auto-updates): revealed only by the
    // superadmin POST, shown exactly once.
    // ---------------------------------------------------------------
    // The backup poll ended in the page's own location.reload() — wait
    // for the fresh document to fully load, or the toggles below race
    // maintenance.js re-attaching its listeners.
    await page.waitForLoadState('load');
    await page.locator('#auto-update-enabled').check();
    await page.locator('#auto-update-level-dev').check();
    await expect(page.locator('#auto-update-webhook-section')).toBeVisible();

    await page.locator('#webhook-generate-secret').click();
    const secret = page.locator('#webhook-secret-value');
    await expect(secret).toBeVisible();
    expect((await secret.innerText()).trim().length, 'a real secret must be revealed').toBeGreaterThan(20);
    await expect(page.locator('#webhook-status-badge')).toHaveText(/Configuré/i);
    // Deliberately NOT saved: the auto-update toggles above were never
    // submitted, so the stored configuration stays as provisioned.

    // ---------------------------------------------------------------
    // The danger zone's interlocks: every destructive button is dead
    // until its exact keyword is typed, and arms the moment it is.
    // Nothing is clicked while armed.
    // ---------------------------------------------------------------
    for (const [keywordField, submit, keyword] of [
        ['#reset-settings-keyword', '#reset-settings-submit', 'REINITIALISER'],
        ['#restore-backup-keyword', '#restore-backup-submit', 'RESTAURER'],
    ]) {
        await expect(page.locator(submit)).toBeDisabled();
        await page.locator(keywordField).fill('pas-le-bon-mot');
        await expect(page.locator(submit), `${submit} must stay locked on a wrong keyword`).toBeDisabled();
        await page.locator(keywordField).fill(keyword);
        await expect(page.locator(submit)).toBeEnabled();
        await page.locator(keywordField).fill('');
        await expect(page.locator(submit), `${submit} must re-lock when the keyword goes`).toBeDisabled();
    }

    // The full erase adds a checkbox to its keyword — both are required.
    await expect(page.locator('#full-reset-submit')).toBeDisabled();
    await page.locator('#full-reset-keyword').fill('EFFACER');
    await expect(page.locator('#full-reset-submit'), 'the keyword alone must not arm a full erase').toBeDisabled();
    await page.locator('#full-reset-checkbox').check();
    await expect(page.locator('#full-reset-submit')).toBeEnabled();
    await page.locator('#full-reset-checkbox').uncheck();
    await expect(page.locator('#full-reset-submit')).toBeDisabled();
    await page.locator('#full-reset-keyword').fill('');

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
