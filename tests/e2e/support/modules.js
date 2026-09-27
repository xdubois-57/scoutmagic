// Shared end-to-end helper: switch a module on or off through the real
// Modules page (/config/modules), and wait for the STORED state rather
// than for the click.
//
// The toggle is a fetch() to /config/modules/toggle followed by
// window.location.reload(), landing back on the page we are already on —
// so waiting on the URL would resolve instantly, against the pre-submit
// DOM. Waiting on the request itself, then on the reload it causes, is
// what makes "the module is now off" a fact rather than a hope.
//
// Extracted here because two scenarios need it for opposite reasons:
// specs/scout-year-transition.spec.js takes a module away to watch the
// steps it owns disappear, and specs/optional-module-dependencies.spec.js
// takes one away to watch another module degrade around its absence.
import { expect } from '@playwright/test';
import { waitForServerResponse } from './response.js';

/**
 * The switch for one module, addressed the way the page labels it for
 * assistive technology.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} moduleName the module's name as the page shows it
 */
export function moduleToggle(page, moduleName) {
    return page.getByRole('checkbox', { name: `Activer ou désactiver le module ${moduleName}` });
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} moduleName the module's name as the page labels it
 * @param {boolean} enabled
 */
export async function toggleModule(page, moduleName, enabled) {
    await page.goto('/config/modules', { waitUntil: 'domcontentloaded' });

    const toggle = moduleToggle(page, moduleName);
    // The reload itself is awaited, not only the response (issue #617):
    // config-modules.js calls window.location.reload() in the fetch's
    // .then(), so when the response arrives the reload may not have
    // started — and waitForLoadState() below was then satisfied by the
    // page from BEFORE it. The helper returned with the reload still in
    // flight, and the caller's next page.goto() was aborted by it
    // (net::ERR_ABORTED). Listening for the main frame's navigation,
    // registered before the click, makes the wait below the reload's.
    await Promise.all([
        page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame()),
        waitForServerResponse(page, (response) => response.url().includes('/config/modules/toggle')),
        enabled ? toggle.check() : toggle.uncheck(),
    ]);
    await page.waitForLoadState('domcontentloaded');

    // The page has reloaded from the database by now, so this reads the
    // stored state and not the checkbox the click just moved.
    await expect(moduleToggle(page, moduleName)).toBeChecked({ checked: enabled });
}
