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
// costs the most — restore and reset are one tab away from backup.
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
// - The role of this page's routes (every one `superadmin` since issue
//   #619): RBAC per route is PHPUnit's job (Tests\Core\Http\
//   MaintenanceRbacTest); the harness has no admin-but-not-superadmin
//   login.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { runScheduler } from '../support/scheduler.js';
import { scaled } from '../support/timeouts.js';

test('maintenance backups run to completion, the auto-save saves, and the danger zone stays locked behind its keywords', async ({ page }) => {
    // The encrypted backup zips core/, modules/ and public/ in the
    // background — well past the default budget on a loaded run.
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

    await loginAsAdmin(page);
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // Six sub-pages since issue #619, one rail between them, Santé de
    // l'hébergement at /config/maintenance as the landing page.
    // ---------------------------------------------------------------
    await page.goto('/config/maintenance', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: "Santé de l'hébergement" })).toBeVisible();
    const rail = page.getByRole('navigation', { name: 'Pages Maintenance' });
    await expect(rail.getByRole('link')).toHaveText([
        "Santé de l'hébergement",
        'Mise à jour',
        'Sauvegarde manuelle',
        'Sauvegarde automatique',
        'Sauvegardes récentes',
        'Réinitialisation',
    ]);
    // Every box arrives open on its own sub-page: asserted rather than
    // trusted, because every interaction below reaches into one, and a
    // folded box would make a control « not visible » for a reason that
    // has nothing to do with it.
    await expect(page.locator('#maintenance-health-body')).toBeVisible();

    // ---------------------------------------------------------------
    // The auto-backup frequency select saves on change — no button.
    // ---------------------------------------------------------------
    // Reached through the rail, which is a plain link.
    await rail.getByRole('link', { name: 'Sauvegarde automatique' }).click();
    await page.waitForURL('**/config/maintenance/sauvegarde-automatique', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#maintenance-backups-automatic-body')).toBeVisible();
    await expect(page.locator('#remote-backup-body')).toBeVisible();
    // The acknowledgement is a toast now (public/assets/js/maintenance.js
    // → window.ScoutMagicToast, design.md §7.5), not the inline
    // « Enregistré » span this used to reveal. Same promise, said in the
    // site's one notification surface: the select answers for itself.
    const savedToast = page.locator('.toast-body', { hasText: 'Enregistré.' });

    const frequency = page.locator('#auto-backup-frequency');
    await frequency.selectOption('weekly');
    await expect(savedToast).toBeVisible();

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('#auto-backup-frequency')).toHaveValue('weekly');

    await page.locator('#auto-backup-frequency').selectOption('none');
    await expect(savedToast).toBeVisible();

    // ---------------------------------------------------------------
    // The manual backup is one form since IT-04 of issue #619: four
    // scopes, one button, and every scope goes the same way — a
    // background task, then a line in « Sauvegardes récentes ». Each
    // launch is started by the form's fetch and finished by the
    // status-polling loop, which reloads the page when the poll reports
    // done.
    // ---------------------------------------------------------------
    /** @param {string} scopeId the radio to check before launching */
    async function launchManualBackup(scopeId) {
        await page.goto('/config/maintenance/sauvegarde-manuelle', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('#maintenance-backups-body')).toBeVisible();
        await page.locator(scopeId).check();
        // Nothing to type (issue #619, IT-03): the site generates the password.
        await expect(page.locator('#manual-backup-form input[type="password"]')).toHaveCount(0);
        await page.locator('#manual-backup-submit').click();

        // **Waiting on the pair, not on the progress bar alone — because a
        // refusal hides the progress bar too.** `maintenance.js` removes
        // the bar's `d-none` synchronously on submit, then, if the launch
        // comes back refused, puts the server's sentence in
        // `#manual-backup-error` and puts `d-none` BACK. So the state
        // « progress hidden » is the state of a refusal, and a spec that
        // asserts only `toBeVisible()` reports « Expected: visible /
        // Received: hidden » while the page is displaying the reason two
        // lines below. That is what happened on pull request #607, where
        // this line failed and the run said nothing about why (issue #623).
        await expect
            .poll(
                async () => {
                    if (await page.locator('#manual-backup-progress').isVisible()) {
                        return 'launched';
                    }

                    const refusal = ((await page.locator('#manual-backup-error').textContent()) ?? '').trim();

                    return refusal === '' ? 'nothing happened yet' : `refused: ${refusal}`;
                },
                {
                    message: 'the backup launch must be accepted, and say why if it is not',
                    timeout: scaled(10_000),
                },
            )
            .toBe('launched');

        // The backup itself is a scheduled task, and public/cron.php is the
        // only thing that runs one — the application does not turn its own
        // queue on the tail of a request any more, and this instance has no
        // crontab. The progress bar being visible means the fetch came back
        // with a backup id, so the row is committed and a pass will claim it.
        await runScheduler();

        // The polling loop ends in window.location.reload(), which brings
        // the form back with its progress bar hidden and no error: that
        // pair is the poll having seen 'done'. A failed job would show its
        // error instead of reloading, and fail here saying so.
        await expect(
            page.locator('#manual-backup-progress'),
            'the background backup must complete and the page reload itself',
        ).toBeHidden({ timeout: scaled(120_000) });
        await page.waitForLoadState('load');
        await expect(page.locator('#manual-backup-error')).toBeHidden();
    }

    // The database alone — a synchronous download until IT-04.
    await launchManualBackup('#scope-database');

    // The list's rows carry the family badge with the type label, so the
    // assertion is on the badge's text, not on a cell.
    await page.goto('/config/maintenance/sauvegardes-recentes', { waitUntil: 'domcontentloaded' });
    const backupsList = page.locator('#maintenance-backups-list');
    await expect(backupsList.getByText('Base de données', { exact: true }).first()).toBeVisible();

    // Each row's two actions are icons since the boxes started folding —
    // the accessible name is what names the backup, and it is all a
    // screen reader has to tell fifteen identical trash icons apart.
    await expect(backupsList.getByLabel(/^Télécharger la sauvegarde/).first()).toBeVisible();
    await expect(backupsList.getByLabel(/^Supprimer la sauvegarde/).first()).toBeVisible();

    // The configuration alone.
    await launchManualBackup('#scope-config');

    // And the finished row is on the list, downloadable — the download
    // link is only drawn for a completed backup, so a row still pending
    // would not satisfy this.
    await page.goto('/config/maintenance/sauvegardes-recentes', { waitUntil: 'domcontentloaded' });
    await expect(
        backupsList.getByLabel(/^Télécharger la sauvegarde « Configuration seule »/).first(),
    ).toBeVisible();

    // Downloading it reveals its generated password first, with the
    // sentence that says to note it, then serves the file (IT-06).
    const revealed = backupsList.locator('output[id^="backup-password-"]').first();
    const downloaded = page.waitForEvent('download');
    await backupsList.getByLabel(/^Télécharger la sauvegarde « Configuration seule »/).first().click();
    expect((await downloaded).suggestedFilename()).toMatch(/\.zip$/);
    await expect(revealed.locator('code')).toHaveText(/^[A-HJKMNP-Z2-9]{5}(-[A-HJKMNP-Z2-9]{5}){5}$/);
    await expect(revealed).toContainText('Notez-le');

    // The key beside it still hides and shows it on demand (IT-03).
    const key = backupsList.getByLabel(/^Afficher le mot de passe de la sauvegarde « Configuration seule »/).first();
    await key.click();
    await expect(revealed).toBeHidden();
    await key.click();
    await expect(revealed.locator('code')).toHaveText(/^[A-HJKMNP-Z2-9]{5}(-[A-HJKMNP-Z2-9]{5}){5}$/);

    // ---------------------------------------------------------------
    // The webhook secret (dev-level auto-updates): revealed only by the
    // superadmin POST, shown exactly once.
    // ---------------------------------------------------------------
    await page.goto('/config/maintenance/mise-a-jour', { waitUntil: 'load' });
    await expect(page.locator('#maintenance-auto-update-body')).toBeVisible();
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
    // Restoring lives under « Sauvegardes récentes » since IT-06 (issue
    // #619). An archive of this server needs no password, so the field only
    // appears once « Depuis un fichier téléversé » is chosen.
    await page.goto('/config/maintenance/sauvegardes-recentes', { waitUntil: 'load' });
    await expect(page.locator('#restore-backup-password')).toBeHidden();
    await page.locator('#restore-source-upload').check();
    await expect(page.locator('#restore-backup-password')).toBeVisible();
    await page.locator('#restore-source-server').check();
    await expect(page.locator('#restore-backup-password')).toBeHidden();
    await expectKeywordGate(page, '#restore-backup-keyword', '#restore-backup-submit', 'RESTAURER');

    await page.goto('/config/maintenance/reinitialisation', { waitUntil: 'load' });
    await expect(page.locator('#maintenance-reset-body')).toBeVisible();
    await expect(page.locator('#restore-backup-form')).toHaveCount(0);
    await expectKeywordGate(page, '#reset-settings-keyword', '#reset-settings-submit', 'REINITIALISER');

    // The full erase adds a checkbox to its keyword — both are required —
    // and, since IT-03b (issue #619), the safety copy's password: shown,
    // then confirmed noted, because the reset erases it.
    await expect(page.locator('#full-reset-submit')).toBeDisabled();
    await page.locator('#full-reset-keyword').fill('EFFACER');
    await expect(page.locator('#full-reset-submit'), 'the keyword alone must not arm a full erase').toBeDisabled();
    await page.locator('#full-reset-checkbox').check();
    await expect(
        page.locator('#full-reset-submit'),
        'a full erase must not arm before its safety copy password was noted',
    ).toBeDisabled();
    await expect(page.locator('#full-reset-password-noted')).toBeDisabled();
    await page.locator('#full-reset-password-reveal').click();
    await expect(page.locator('#full-reset-password code')).toHaveText(/^[A-HJKMNP-Z2-9]{5}(-[A-HJKMNP-Z2-9]{5}){5}$/);
    await page.locator('#full-reset-password-noted').check();
    await expect(page.locator('#full-reset-submit')).toBeEnabled();
    await page.locator('#full-reset-checkbox').uncheck();
    await expect(page.locator('#full-reset-submit')).toBeDisabled();
    await page.locator('#full-reset-keyword').fill('');

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});

/**
 * A destructive button is dead until its exact keyword is typed, arms the
 * moment it is, and re-locks when the keyword goes. Nothing is clicked.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} keywordField
 * @param {string} submit
 * @param {string} keyword
 */
async function expectKeywordGate(page, keywordField, submit, keyword) {
    await expect(page.locator(submit)).toBeDisabled();
    await page.locator(keywordField).fill('pas-le-bon-mot');
    await expect(page.locator(submit), `${submit} must stay locked on a wrong keyword`).toBeDisabled();
    await page.locator(keywordField).fill(keyword);
    await expect(page.locator(submit)).toBeEnabled();
    await page.locator(keywordField).fill('');
    await expect(page.locator(submit), `${submit} must re-lock when the keyword goes`).toBeDisabled();
}
