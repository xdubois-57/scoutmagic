// End-to-end: the instance under test really does run with every module
// switched on, and boots that way.
//
// This is the guard that makes the rest of the suite mean what it claims.
// public/index.php wires EVERY enabled module into the front controller
// before it routes anything, so a module left disabled is a block of that
// composition root no browser test ever executes — and a fault there does
// not break one page, it answers HTTP 500 on every route of the site,
// including /login and the 404 page.
//
// That is not hypothetical: a controller constructor whose type hint named
// a class that did not exist (a missing `use`, resolved against the
// declaring namespace) took the live site down exactly that way, while
// this suite stayed green because the module concerned was not one of the
// three the harness used to enable. scripts/e2e-support.php now activates
// all of them; this scenario is what keeps that true as modules are added,
// since a new module ships disabled by default and nothing else here would
// notice.
//
// Deliberately asserted through the real Modules page rather than against
// a hardcoded list: the page reads the modules/ directory through
// Core\Module\ModuleManager::discoverModules(), so a module added to the
// repository tomorrow is covered by this scenario the day it lands,
// without anyone remembering to update a fixture.
import { expect, test } from '@playwright/test';
import { loginAsAdmin } from '../support/admin-login.js';

test('every module the instance ships is active, and the site boots with all of them', async ({ page }) => {
    /** @type {string[]} */
    const serverErrors = [];
    page.on('response', (response) => {
        if (response.status() >= 500) {
            serverErrors.push(`HTTP ${response.status()} on ${response.url()}`);
        }
    });

    await loginAsAdmin(page);

    await page.goto('/config/modules', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Modules' })).toBeVisible();

    // One toggle per module present on disk (the template renders the
    // switch for exactly those), and the "Inactif" badge is the page's own
    // word for a module that is off.
    const toggles = page.locator('input.module-toggle');
    const moduleCount = await toggles.count();

    expect(moduleCount, 'the instance must ship at least the modules this repository contains')
        .toBeGreaterThan(0);

    /** @type {string[]} */
    const disabled = [];
    for (let index = 0; index < moduleCount; index += 1) {
        const toggle = toggles.nth(index);
        if (!(await toggle.isChecked())) {
            disabled.push((await toggle.getAttribute('data-module')) ?? `#${index}`);
        }
    }

    expect(
        disabled,
        'scripts/e2e-support.php must activate every module, so index.php wires every one of them '
        + 'in the run under test — see e2e_activate_all_modules()',
    ).toEqual([]);

    await expect(page.getByText('Inactif')).toHaveCount(0);

    // A module that is enabled but not loaded (an unmet requirement) would
    // silently contribute nothing, which is the same blind spot with a
    // different cause. The page says so in words when it happens.
    await expect(
        page.getByText("Ce module n'est pas chargé"),
    ).toHaveCount(0);

    // And with all of them wired, the public entry point still serves.
    // Anonymous, because that is the path every visitor takes — and the
    // one the outage above broke. Cookies cleared rather than POSTing to
    // /logout: this scenario is about the boot, not about the logout flow.
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('main')).toBeVisible();

    expect(serverErrors, 'no request in this scenario may answer 5xx').toEqual([]);
});
