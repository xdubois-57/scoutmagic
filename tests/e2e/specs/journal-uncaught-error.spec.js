// End-to-end: an uncaught error is consultable from the site.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Core\Http\ErrorHandler has always logged the real detail — class,
// message, file, line, stack — through error_log(). On shared hosting the
// operator does not choose where that goes: in the incident this scenario
// comes from it went to /var/www/.../log/error.log, invisible from the
// site, and was only found by generating a support package. So the
// handler now ALSO writes the throwable to the site journal, at level
// `error`, and this is the only test that walks the whole chain the way
// production does: a controller throws → ErrorHandler::guard() catches →
// error_log() runs → the journal row is written → the generic 500 page is
// served → an administrator finds the error on /admin/journal and filters
// on it.
//
// PHPUnit cannot walk it. Every unit test of this path calls the handler
// directly with a throwable it built; none of them boots public/index.php,
// which is where the JournalService is handed to the handler in the first
// place — exactly the composition-root gap ARCHITECTURE.md § 15 describes.
//
// WHY ITS OWN SPEC, AND NOT AN EXTRA TEST IN maintenance-backup.spec.js
// ----------------------------------------------------------------------------
// That scenario's closing contract is `expect(serverErrors).toEqual([])` —
// the application must not return a single 5xx while it runs. This one
// requires a 5xx, deliberately provoked. The two cannot share a page, a
// listener or a file without one of them lying about what it saw.
//
// HOW THE ERROR IS PROVOKED
// ----------------------------------------------------------------------------
// /test-tools/uncaught-error, in modules/test_tools — a module declaring
// `visible_when: [reference_installation, local_installation]`, so
// ModuleManager never discovers it on a deploying unit's installation and
// none of its routes exist there (ARCHITECTURE.md §8.49/§8.63). The E2E
// instance is served on a loopback host, which is a local installation,
// which is why the toolbox is reachable here. Nothing else in the
// application may ever offer a route that faults on demand.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';

test('an uncaught error reaches the journal at level error, and the level filter finds it', async ({ page }) => {
    /** @type {number[]} */
    const provokedStatuses = [];
    page.on('response', (response) => {
        if (response.url().includes('/test-tools/uncaught-error')) {
            provokedStatuses.push(response.status());
        }
    });

    await loginAsAdmin(page);
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // Provoke it, from the toolbox page, the way an operator would.
    // ---------------------------------------------------------------
    await page.goto('/test-tools', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Outils de test' })).toBeVisible();

    await page.getByRole('link', { name: 'Provoquer une erreur' }).click();

    // The generic, dependency-free 500 page — never the exception detail,
    // which is the other half of what this handler exists for.
    await expect(page.getByRole('heading', { name: 'Une erreur est survenue' })).toBeVisible();
    await expect(page.getByText('RuntimeException')).toHaveCount(0);
    expect(provokedStatuses, 'the provoked route must answer 500').toEqual([500]);

    // ---------------------------------------------------------------
    // And now the point: find it from the site, without a support
    // package and without shell access to a log file.
    // ---------------------------------------------------------------
    await page.goto('/admin/journal', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Journal' })).toBeVisible();

    await page.getByLabel('Niveau').selectOption('error');
    await page.getByRole('button', { name: 'Filtrer' }).click();
    await expect(page).toHaveURL(/level=error/);

    const table = page.locator('table tbody');
    const entry = table.getByText('Erreur non interceptée', { exact: false }).first();
    await expect(entry, 'the uncaught error must be in the journal').toBeVisible();
    await expect(entry).toContainText('RuntimeException');
    await expect(entry).toContainText('Erreur provoquée volontairement depuis les outils de test.');

    // The filter really filtered: at this level, nothing else is shown.
    await expect(table.getByText('info', { exact: true })).toHaveCount(0);
    await expect(table.getByText('sécurité', { exact: true })).toHaveCount(0);
    await expect(table.getByText('erreur', { exact: true }).first()).toBeVisible();

    // ---------------------------------------------------------------
    // The stack is in the entry's context — with the frames, and
    // without the call arguments.
    // ---------------------------------------------------------------
    await page.locator('tr.journal-row').first().click();
    const context = page.locator('tr.context-row pre').first();
    await expect(context).toBeVisible();
    await expect(context).toContainText('provokeUncaughtError');

    const contextText = (await context.innerText()).trim();
    expect(contextText.length, 'the sanitized stack must not be empty').toBeGreaterThan(20);
    // getTraceAsString() would have carried every frame's arguments here.
    // The signed-in administrator's own address is the personal datum
    // nearest to this request, so it is the one worth pinning.
    expect(contextText).not.toContain(process.env.E2E_ADMIN_EMAIL);

    // ---------------------------------------------------------------
    // The converse: another level does not show it.
    // ---------------------------------------------------------------
    await page.getByLabel('Niveau').selectOption('security');
    await page.getByRole('button', { name: 'Filtrer' }).click();
    await expect(page).toHaveURL(/level=security/);
    await expect(page.locator('table tbody').getByText('Erreur non interceptée', { exact: false })).toHaveCount(0);
});
