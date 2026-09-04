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
// An optional dependency has a third shape worth its own assertion: the
// provider module ENABLED but not CONFIGURED. llm_connector ships on this
// instance with no AI provider, which is the ordinary state of a fresh
// install, and a consumer gating only on "is the module installed" would
// render a button that fails when pressed. fees' « Chercher les montants »
// asks the connector whether a model is really reachable, so the button is
// absent here — see the note at that block for what that does and does not
// prove.
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
// Roles and visible text throughout, the news field builder's own edit
// panel included — its controls carry real, associated labels (see
// specs/news-form-payment.spec.js's own note). The Modules page's refusal
// arrives as a window.alert() (its own inline script), so it is read from
// the dialog rather than from the DOM — there is nothing else to read.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { moduleToggle, toggleModule } from '../support/modules.js';
import { waitForServerResponse } from '../support/response.js';

const GALLERY = 'Galerie photos et vidéos';
const GROUPS = 'Groupes de discussion';
const FINANCE = 'Finances';

/**
 * Add one field of the given type to the news form builder and return the
 * panel that opens under it.
 *
 * Addressed through `data-key` — the identity news-form-builder.js gives
 * each field in its own state array — so that the assertions below say
 * "this field's price box" rather than "a price box somewhere". What is
 * inside the panel is then found by its accessible name.
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

    // The refusal is a toast raised once the toggle's own fetch()
    // resolves (design.md §7.5 — it used to be a window.alert()), so the
    // response is awaited first: asserting any earlier would read an
    // empty screen every time, whatever the server answered.
    await Promise.all([
        waitForServerResponse(page, (response) => response.url().includes('/config/modules/toggle')),
        moduleToggle(page, GALLERY).uncheck(),
    ]);

    await expect(
        page.locator('.toast-body'),
        'the refusal must name the module that needs this one, and what to do',
    ).toHaveText(`Ce module est requis par le module « ${GROUPS} ». Désactivez-le d'abord.`);

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

    const priceBox = withFinance.getByLabel('Prix unitaire (€)');
    await expect(priceBox).toBeVisible();

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
    // The capacity cap is news' own feature and must survive Finances
    // going away — that difference is the whole point of this half. Its
    // box is present though hidden until the checkbox is ticked, so the
    // checkbox is what "still there" is asserted on.
    await expect(withoutFinance.getByLabel('Limiter la capacité')).toBeVisible();
    await expect(withoutFinance.getByLabel('Capacité maximale')).toHaveCount(1);
    await expect(withoutFinance.getByLabel('Prix unitaire (€)')).toHaveCount(0);
    await expect(page.locator('#news-payment-settings')).toHaveCount(0);

    // ---------------------------------------------------------------
    // And switch it back on: the payment settings return, on the same
    // page, with no other change. Degrading gracefully in one direction
    // only would not be degrading gracefully.
    // ---------------------------------------------------------------
    await toggleModule(page, FINANCE, true);

    await page.goto('/news/create', { waitUntil: 'domcontentloaded' });
    const restored = await addField(page, 'Nombre', 1);
    await expect(restored.getByLabel('Prix unitaire (€)')).toBeVisible();

    // ---------------------------------------------------------------
    // OPTIONAL, third shape: the provider module is ENABLED but not
    // CONFIGURED. llm_connector ships on this instance with no AI
    // provider, and « Chercher les montants » on the cotisations barème
    // is gated on a model really being reachable on the cheap tier — not
    // on the module being installed. So the button must be absent, while
    // the barème itself, which has always been three fields typed by
    // hand, must be untouched.
    //
    // NOTE ON WHAT THIS PROVES, AND WHAT IT DOES NOT. On an instance with
    // no provider this proves the absence and nothing else: the fetch,
    // the prompt, the JSON parsing and the scout-year check are covered
    // by Tests\Modules\Fees\Service\FederalScaleLookupServiceTest, and
    // no end-to-end run can exercise them without a real API key. Do not
    // read a green run here as "the AI lookup works".
    // ---------------------------------------------------------------
    await page.goto('/admin/fees/tarifs', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // The block is collapsed by default; opening it is what makes the
    // absence assertion mean something rather than pass on a hidden DOM.
    await page.getByRole('button', { name: 'Barème des cotisations' }).click();
    await expect(page.getByRole('button', { name: 'Enregistrer le barème' })).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Chercher les montants' }),
        'no AI provider is configured, so the button must not exist at all',
    ).toHaveCount(0);
    await expect(page.getByLabel('Montant par personne (€)').first()).toBeVisible();

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
