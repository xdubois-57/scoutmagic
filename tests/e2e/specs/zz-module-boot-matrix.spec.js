// End-to-end, `full` tier only: the twenty-boot matrix. One test per
// module the repository ships: switch THAT module off, prove the site
// still boots and every remaining module's pages still answer, switch it
// back on.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// specs/all-modules-enabled.spec.js proves the composition root boots
// with everything ON, and specs/optional-module-dependencies.spec.js
// proves two hand-picked dependencies degrade honestly. What neither
// covers is the general case this codebase's whole §7.5 discipline
// promises: EVERY module must be individually removable without taking
// the site — or any other module — down with it. public/index.php wires
// each enabled module's block conditionally, seeding null handles for the
// others; a wiring mistake there (a handle read before its guard, a
// controller registered with a service that no longer exists) does not
// break one feature, it answers HTTP 500 on every route. That is exactly
// the class of failure the composition-root refactoring iterations
// (chantier « dépendances entre modules », IT-10 to IT-12) can introduce,
// which is why this matrix lands BEFORE them and freezes the reference
// behaviour. If it fails during a refactoring, the refactoring is at
// fault — never this test.
//
// WHAT EACH BOOT ASSERTS
// ----------------------------------------------------------------------------
// With module M off (and, first, any enabled module that hard-requires M,
// per module.json's `requires`):
//   - M's own first menu page answers 404 — a disabled module's routes
//     are gone, not broken;
//   - the public home page answers 200 and renders its <h1>;
//   - every OTHER still-enabled module's labelled, parameterless GET
//     pages answer 200 as the superadmin — a page that 500s here is a
//     wiring bug in the composition root, not a feature bug;
//   - no uncaught page error surfaced anywhere along the way.
// Then everything is switched back on and asserted back on: a dependency
// that degrades gracefully has to come BACK gracefully too (the same
// contract optional-module-dependencies.spec.js states).
//
// The module list and each module's pages are read from modules/*/
// module.json at collection time — never a hardcoded list, so a module
// added tomorrow is covered the day it lands (the same principle
// all-modules-enabled.spec.js applies to the Modules page).
//
// FILE NAME
// ----------------------------------------------------------------------------
// The `zz-` prefix is deliberate: Playwright runs spec files
// alphabetically and several earlier specs depend on the full module set
// being active (rental-*, scout-year-transition — see
// optional-module-dependencies.spec.js's own STATE note). This file
// toggles every module in turn, so it runs LAST, after every scenario
// that could be disturbed by a half-restored registry if a boot test
// fails midway.
//
// TIER
// ----------------------------------------------------------------------------
// Tagged @full: twenty boots visiting every module page each are a
// matrix, costly by nature, exactly the case AGENTS.md § Tests assigns to
// the `full` tier. `npm run e2e` (the confidence tier, CI's default)
// skips this file; `npm run e2e:full` (the release gate) runs it.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { moduleToggle, toggleModule } from '../support/modules.js';
import { scaled } from '../support/timeouts.js';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const modulesDir = path.join(repoRoot, 'modules');

/**
 * @typedef {{ id: string, name: string, requires: string[], pages: string[] }} ModuleUnderTest
 */

/** @type {ModuleUnderTest[]} */
const allModules = fs.readdirSync(modulesDir)
    .filter((dir) => fs.existsSync(path.join(modulesDir, dir, 'module.json')))
    .sort()
    .map((dir) => {
        const manifest = JSON.parse(fs.readFileSync(path.join(modulesDir, dir, 'module.json'), 'utf8'));

        return {
            id: manifest.id,
            name: manifest.name,
            requires: manifest.requires ?? [],
            // The pages a superadmin can visit without inventing an id: the
            // labelled (menu-bearing), parameterless GET routes. Visiting a
            // page whose route needs a row id would test a fixture, not the
            // wiring.
            pages: (manifest.routes ?? [])
                .filter((route) => (route.method ?? 'GET') === 'GET'
                    && typeof route.label === 'string' && route.label !== ''
                    && !route.path.includes('{'))
                .map((route) => route.path),
        };
    });

/**
 * Every enabled module that would block disabling `moduleId` — the ones
 * that hard-require it, transitively. They must be switched off first
 * (ModuleManager::deactivate() refuses otherwise) and back on last,
 * dependency before dependent.
 *
 * @param {string} moduleId
 * @returns {ModuleUnderTest[]}
 */
function transitiveDependents(moduleId) {
    /** @type {ModuleUnderTest[]} */
    const dependents = [];
    const covered = new Set([moduleId]);

    let grew = true;
    while (grew) {
        grew = false;
        for (const candidate of allModules) {
            if (covered.has(candidate.id)) {
                continue;
            }
            if (candidate.requires.some((required) => covered.has(required))) {
                covered.add(candidate.id);
                dependents.push(candidate);
                grew = true;
            }
        }
    }

    return dependents;
}

/**
 * Best-effort restoration for a test that failed midway: put every module
 * whose switch reads off back on, requirements before their dependents so
 * activation is never refused. A test that PASSED has already restored
 * (and asserted) everything itself — this then finds nothing to do.
 *
 * @param {import('@playwright/test').Page} page
 */
async function ensureAllModulesEnabled(page) {
    await page.goto('/config/modules', { waitUntil: 'domcontentloaded' });

    // Requirements first: `requires` declarations only ever point at
    // modules with none of their own today, so one dependency-ordered pass
    // is enough — and toggleModule() would fail loudly if that stopped
    // being true.
    const ordered = [...allModules].sort((a, b) => a.requires.length - b.requires.length);
    for (const moduleUnderTest of ordered) {
        if (!await moduleToggle(page, moduleUnderTest.name).isChecked()) {
            await toggleModule(page, moduleUnderTest.name, true);
        }
    }
}

// Twenty boots, each visiting every remaining module's pages, cannot fit
// the suite's ordinary 60 s ceiling and should not stretch it for
// everyone else.
test.describe.configure({ timeout: scaled(240_000) });

test.afterEach(async ({ page }) => {
    await ensureAllModulesEnabled(page);
});

for (const moduleUnderTest of allModules) {
    test(
        `the site boots and every other module still answers with ${moduleUnderTest.id} disabled`,
        { tag: '@full' },
        async ({ page }) => {
            /** @type {string[]} */
            const pageErrors = [];
            page.on('pageerror', (error) => pageErrors.push(`${page.url()}: ${error.message}`));

            await loginAsAdmin(page);
            await answerCookieBanner(page);

            const dependents = transitiveDependents(moduleUnderTest.id);
            const offIds = new Set([moduleUnderTest.id, ...dependents.map((dependent) => dependent.id)]);

            // Dependents first, or deactivation is (rightly) refused.
            for (const dependent of dependents) {
                await toggleModule(page, dependent.name, false);
            }
            await toggleModule(page, moduleUnderTest.name, false);

            // A disabled module's routes are GONE — 404, never an error page.
            if (moduleUnderTest.pages.length > 0) {
                const ownPage = await page.goto(moduleUnderTest.pages[0], { waitUntil: 'domcontentloaded' });
                expect(
                    ownPage?.status(),
                    `${moduleUnderTest.pages[0]} must 404 while ${moduleUnderTest.id} is disabled`,
                ).toBe(404);
            }

            // The site itself boots: the composition root ran every other
            // module's block around this one's absence.
            const home = await page.goto('/', { waitUntil: 'domcontentloaded' });
            expect(home?.status(), `the home page with ${moduleUnderTest.id} disabled`).toBe(200);
            await expect(page.locator('h1').first()).toBeVisible();

            // Every remaining module's own pages still answer. Soft, so one
            // broken page reports every other broken one alongside it
            // instead of hiding them behind the first failure.
            for (const other of allModules) {
                if (offIds.has(other.id)) {
                    continue;
                }
                for (const pagePath of other.pages) {
                    const response = await page.goto(pagePath, { waitUntil: 'domcontentloaded' });
                    expect.soft(
                        response?.status(),
                        `${pagePath} (module ${other.id}) with ${moduleUnderTest.id} disabled`,
                    ).toBe(200);
                }
            }

            expect(pageErrors, `uncaught page errors with ${moduleUnderTest.id} disabled`).toEqual([]);

            // Back on — the module under test before its dependents, so
            // every activation finds its requirements already satisfied.
            await toggleModule(page, moduleUnderTest.name, true);
            for (const dependent of [...dependents].reverse()) {
                await toggleModule(page, dependent.name, true);
            }
        },
    );
}
