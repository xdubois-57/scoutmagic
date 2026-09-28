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
    // Database-only backup: a plain synchronous POST that comes back to
    // the page it was sent from, and whose proof is the new row in
    // « Sauvegardes récentes », with its download link.
    // ---------------------------------------------------------------
    // Two buttons on the page say "Générer" (database-only, and the full
    // backup's submit); the database one is the plain form targeting the
    // synchronous endpoint.
    await page.goto('/config/maintenance/sauvegarde-manuelle', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#maintenance-backups-body')).toBeVisible();
    await page.locator('form[action="/config/maintenance/backup/database"]')
        .getByRole('button', { name: 'Générer' }).click();
    await page.waitForURL('**/config/maintenance/sauvegarde-manuelle', { waitUntil: 'domcontentloaded' });

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

    // ---------------------------------------------------------------
    // Full encrypted backup (configuration-only scope): started by a
    // hand-built fetch, finished by the status-polling loop — the page
    // reloads itself when the poll reports done.
    // ---------------------------------------------------------------
    await page.goto('/config/maintenance/sauvegarde-manuelle', { waitUntil: 'domcontentloaded' });
    await page.locator('#scope-config').check();
    // Nothing to type (issue #619, IT-03): the site generates the password.
    await expect(page.locator('#full-backup-form input[type="password"]')).toHaveCount(0);
    await page.locator('#full-backup-submit').click();

    // **Waiting on the pair, not on the progress bar alone — because a
    // refusal hides the progress bar too.** `maintenance.js` removes the
    // bar's `d-none` synchronously on submit, then, if the launch comes
    // back refused, puts the server's sentence in `#full-backup-error`
    // and puts `d-none` BACK. So the state « progress hidden » is the
    // state of a refusal, and a spec that asserts only `toBeVisible()`
    // reports « Expected: visible / Received: hidden » while the page is
    // displaying the reason two lines below. That is what happened on
    // pull request #607, where this line failed and the run said nothing
    // about why (issue #623).
    //
    // `#full-backup-error` is read here rather than at the end of the
    // scenario — where it is also asserted hidden — because that later
    // assertion never runs: this one fails first and the test stops.
    await expect
        .poll(
            async () => {
                if (await page.locator('#full-backup-progress').isVisible()) {
                    return 'launched';
                }

                const refusal = ((await page.locator('#full-backup-error').textContent()) ?? '').trim();

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
    // the form back with its progress bar hidden and no error: that pair
    // is the poll having seen 'done'. A failed job would show its error
    // instead of reloading, and fail here saying so.
    await expect(
        page.locator('#full-backup-progress'),
        'the background backup must complete and the page reload itself',
    ).toBeHidden({ timeout: scaled(120_000) });
    await page.waitForLoadState('load');
    await expect(page.locator('#full-backup-error')).toBeHidden();

    // And the finished row is on the list, downloadable — the download
    // link is only drawn for a completed backup, so a row still pending
    // would not satisfy this.
    await page.goto('/config/maintenance/sauvegardes-recentes', { waitUntil: 'domcontentloaded' });
    await expect(
        backupsList.getByLabel(/^Télécharger la sauvegarde « Configuration seule »/).first(),
    ).toBeVisible();

    // Its generated password is revealed on demand beside the download,
    // with the sentence that says to note it.
    await backupsList.getByLabel(/^Afficher le mot de passe de la sauvegarde « Configuration seule »/).first().click();
    const revealed = backupsList.locator('output[id^="backup-password-"]').first();
    await expect(revealed.locator('code')).toHaveText(/^[A-HJKMNP-Z2-9]{5}(-[A-HJKMNP-Z2-9]{5}){5}$/);
    await expect(revealed).toContainText('Notez-le');

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
    await page.goto('/config/maintenance/reinitialisation', { waitUntil: 'load' });
    await expect(page.locator('#maintenance-reset-body')).toBeVisible();
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
