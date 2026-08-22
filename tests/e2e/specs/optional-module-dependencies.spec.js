// End-to-end: what happens to a module when the module it leans on is
// switched off — both kinds of leaning, and both directions.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// ARCHITECTURE.md §7.5 gives modules two ways to depend on each other,
// and the difference between them is the whole point:
//
//   - A HARD dependency, declared as `requires` in module.json. groups
//     requires gallery. The application must refuse to take gallery away
//     while groups is on, and must refuse to switch groups on while
//     gallery is off — with a sentence saying which module and why, not a
//     silent failure and not a broken site.
//   - An OPTIONAL dependency, which is a nullable interface and nothing
//     else. news accepts Modules\Finance\Api\FinanceAccountInterface and
//     three siblings, or null. With Finances off, those are null and the
//     news editor must simply stop offering payment — not error, not
//     render a picker with nothing in it, and not stop working.
//
// A module's absence is exactly what PHPUnit cannot stage: it constructs
// each controller itself and passes whatever it likes, including null.
// What it cannot construct is public/index.php, which decides at BOOT,
// per request, from the module registry, which interfaces exist to pass
// at all. Getting that wiring wrong does not break one page — it answers
// HTTP 500 on every route of the site, which is why
// specs/all-modules-enabled.spec.js exists. This scenario is its
// complement: that one proves the site boots with everything ON, this one
// proves it boots, and degrades honestly, with something OFF.
//
// STATE
// ----------------------------------------------------------------------------
// Every module the harness activated is switched back on before this
// scenario ends, and the scenario asserts that it was — which is not
// bookkeeping but the second half of the claim: a dependency that
// degrades gracefully has to come BACK gracefully too. Playwright runs
// spec files alphabetically, so the ones that need these modules
// (rental-*, scout-year-transition) run after this file and depend on
// that restoration.
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text throughout. The Modules page's refusal arrives
// as a window.alert() (its own inline script), so it is read from the
// dialog rather than from the DOM — there is nothing else to read.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { moduleToggle, toggleModule } from '../support/modules.js';

const GALLERY = 'Galerie photos et vidéos';
const GROUPS = 'Groupes de discussion';
const FINANCE = 'Finances';

/**
 * Add one field of the given type to the news form builder and return the
 * panel that opens under it.
 *
 * The builder's own edit panel has no accessible names to query by (see
 * specs/news-form-payment.spec.js's LOCATORS note for why that is a real
 * defect and not something a test should paper over), so the panel is
 * addressed through `data-key`, the identity news-form-builder.js gives
 * each field in its own state array.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} typeLabel the field type as the picker labels it
 * @param {number} index the field's position (0 is the pinned text block)
 */
async function addField(page, typeLabel, index) {
    await page.getByRole('button', { name: 'Ajouter un champ' }).click();
    await page.getByRole('button', { name: typeLabel }).click();

    return page.locator('#news-fields-list > [data-key]').nth(index);
}

test('a hard dependency is refused both ways, and an optional one degrades and comes back', async ({ page }) => {
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
    // HARD: gallery cannot be taken away while groups needs it.
    // ---------------------------------------------------------------
    await page.goto('/config/modules', { waitUntil: 'domcontentloaded' });

    // The refusal is a window.alert() raised once the toggle's own fetch()
    // resolves, so it is awaited as the event it is — asserting on it any
    // earlier would read an empty list every time, whatever the server
    // answered. Handling the dialog explicitly also stops Playwright's
    // default (dismiss silently) from swallowing the one sentence this
    // half of the scenario is about.
    const refusal = page.waitForEvent('dialog');
    await Promise.all([
        page.waitForResponse((response) => response.url().includes('/config/modules/toggle')),
        moduleToggle(page, GALLERY).uncheck(),
    ]);

    const refusalDialog = await refusal;
    expect(refusalDialog.message(), 'the refusal must name the module that needs this one, and what to do')
        .toBe(`Ce module est requis par le module « ${GROUPS} ». Désactivez-le d'abord.`);
    await refusalDialog.dismiss();

    // The switch springs back rather than lying about a state the server
    // refused — and reloading proves the refusal really was a refusal.
    await expect(moduleToggle(page, GALLERY)).toBeChecked();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(moduleToggle(page, GALLERY)).toBeChecked();

    // ---------------------------------------------------------------
    // Take groups away first, and gallery may go. Which leaves groups
    // un-switchable-on, with the page saying why rather than offering a
    // control that would fail.
    // ---------------------------------------------------------------
    // toggleModule() re-reads the stored state after the reload, so these
    // two succeeding is itself the assertion that neither was refused.
    await toggleModule(page, GROUPS, false);
    await toggleModule(page, GALLERY, false);

    await expect(page.getByText('Activation impossible : activez d\'abord le ou les modules requis.')).toBeVisible();
    await expect(moduleToggle(page, GROUPS)).toBeDisabled();

    // The site still boots and serves with two modules gone — the failure
    // mode this whole suite exists for is the one where it does not.
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('main')).toBeVisible();

    // ---------------------------------------------------------------
    // And back, in the only order that works: the requirement first.
    // ---------------------------------------------------------------
    await toggleModule(page, GALLERY, true);
    await toggleModule(page, GROUPS, true);
    await expect(page.getByText("Ce module n'est pas chargé")).toHaveCount(0);

    // ---------------------------------------------------------------
    // OPTIONAL: with Finances on, the news editor offers a price on a
    // number field. That price is the entire trigger for the payment
    // settings — the module being installed is not enough.
    // ---------------------------------------------------------------
    await page.goto('/news/create', { waitUntil: 'domcontentloaded' });
    const withFinance = await addField(page, 'Nombre', 1);

    // step="0.50" is the price box's; the capacity box beside it is
    // step="1" (see specs/news-form-payment.spec.js).
    const priceBox = withFinance.locator('input[type="number"][step="0.50"]');
    await expect(priceBox).toBeVisible();
    await expect(page.getByText('Prix unitaire (€)')).toBeVisible();

    await priceBox.fill('7.50');

    // The box itself, not what is inside it: whether it offers an account
    // picker or says there is no account to pick yet depends on what
    // Finances has been configured with, which is another scenario's
    // business — this one is about the box existing at all.
    const paymentSettings = page.locator('#news-payment-settings');
    await expect(paymentSettings).toBeVisible();
    await expect(paymentSettings.getByRole('heading', { name: 'Paiement' })).toBeVisible();

    // ---------------------------------------------------------------
    // Switch Finances off. Nothing about news is declared to depend on
    // it, so it must simply go — the editor keeps working, and the price
    // and the account picker are not disabled or empty, they are absent.
    // ---------------------------------------------------------------
    await toggleModule(page, FINANCE, false);

    await page.goto('/news/create', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Nouvel article' })).toBeVisible();

    const withoutFinance = await addField(page, 'Nombre', 1);
    // Present, though hidden until "Limiter la capacité" is ticked — the
    // capacity cap is news' own feature and must survive Finances going
    // away, which is the difference this half of the scenario is drawing.
    await expect(page.getByText('Limiter la capacité')).toBeVisible();
    await expect(withoutFinance.locator('input[type="number"][step="1"]')).toHaveCount(1);
    await expect(withoutFinance.locator('input[type="number"][step="0.50"]')).toHaveCount(0);
    await expect(page.getByText('Prix unitaire (€)')).toHaveCount(0);
    await expect(page.locator('#news-payment-settings')).toHaveCount(0);

    // ---------------------------------------------------------------
    // And switch it back on: the payment settings return, on the same
    // page, with no other change. Degrading gracefully in one direction
    // only would not be degrading gracefully.
    // ---------------------------------------------------------------
    await toggleModule(page, FINANCE, true);

    await page.goto('/news/create', { waitUntil: 'domcontentloaded' });
    const restored = await addField(page, 'Nombre', 1);
    await expect(restored.locator('input[type="number"][step="0.50"]')).toBeVisible();

    // ---------------------------------------------------------------
    // Every module this instance ships is on again, which the specs that
    // run after this one rely upon.
    // ---------------------------------------------------------------
    await page.goto('/config/modules', { waitUntil: 'domcontentloaded' });
    const toggles = page.locator('input.module-toggle');
    const count = await toggles.count();

    /** @type {string[]} */
    const stillOff = [];
    for (let index = 0; index < count; index += 1) {
        if (!(await toggles.nth(index).isChecked())) {
            stillOff.push((await toggles.nth(index).getAttribute('data-module')) ?? `#${index}`);
        }
    }
    expect(stillOff, 'this scenario must leave the instance exactly as it found it').toEqual([]);

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
