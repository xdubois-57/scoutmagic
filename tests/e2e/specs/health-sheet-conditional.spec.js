// End-to-end: the health sheet asks OUI / NON before it asks for details, and
// remembers the answer (issue #1346).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// No scenario covered the health sheet. The unit tests run the script against
// a hand-written fixture and the PHP tests read the template as a string; only
// a real page proves the things that depend on both being there together:
//
//   - the boxes that depend on an answer are hidden when it is not OUI, shown
//     when it is, and follow it without any reload (a marker set on `window`
//     would not survive one);
//   - hiding erases nothing: OUI → NON → OUI finds the text again, and a text
//     kept behind a NON is still posted, so the SERVER decides what reaches the
//     paper from the saved choices;
//   - a saved answer is selected again when the page reopens. The select
//     partial used to ignore `value` and reopen every choice on « Non
//     précisé », which looked like lost data and erased it on the next save.
//
// A parent's account is needed — « Fiche santé » is for the member's own
// household, never for an administrator — so a household is seeded the way
// mass-mail-shared-address.spec.js does it.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginWithCredentials } from '../support/admin-login.js';
import { seedSharedAddressSiblings } from '../support/shared-address.js';
import { scaled } from '../support/timeouts.js';

/** @returns {string} */
function newStamp() {
    return `${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`;
}

test('the health sheet shows the details of an OUI only, keeps what was typed, and reopens on the saved answer', async ({ page }) => {
    test.setTimeout(scaled(120_000));

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

    const household = await seedSharedAddressSiblings(newStamp());
    const child = household.siblings[0];
    await loginWithCredentials(page, household.family_email, household.family_password);
    await answerCookieBanner(page);
    await page.goto(`/members/${child.member_year_id}/fiche-sante`, { waitUntil: 'domcontentloaded' });

    // The sections are collapsed accordions: open the one with the questions.
    await page.getByRole('button', { name: 'Informations confidentielles concernant la santé de la personne' }).click();
    await page.evaluate(() => { window.__healthSheetMarker = 'still-here'; });
    const stillHere = () => page.evaluate(() => window.__healthSheetMarker);

    const allergic = page.locator('#allergic');
    const allergies = page.locator('#allergies');
    const consequences = page.locator('#allergy-consequences');
    const diet = page.locator('#diet');
    const treatment = page.locator('#treatment');
    const autonomy = page.locator('#treatment-autonomy');

    // --- nothing answered: nothing asked ----------------------------------
    await expect(allergic, 'every choice starts on « Non précisé »').toHaveValue('');
    for (const hidden of [allergies, consequences, diet, treatment, autonomy]) {
        await expect(hidden).toBeHidden();
    }

    // --- OUI shows the details, and only those of its own question --------
    await allergic.selectOption('yes');
    await expect(allergies).toBeVisible();
    await expect(consequences).toBeVisible();
    await expect(diet, 'the diet is another question').toBeHidden();
    await allergies.fill('Arachides');
    await consequences.fill('Œdème : EpiPen dans son sac');

    // --- NON hides them without erasing them -------------------------------
    await allergic.selectOption('no');
    await expect(allergies).toBeHidden();
    await expect(consequences).toBeHidden();
    await allergic.selectOption('yes');
    await expect(allergies, 'OUI → NON → OUI finds the text again').toHaveValue('Arachides');
    await expect(consequences).toHaveValue('Œdème : EpiPen dans son sac');

    // --- the autonomy is asked only when a treatment is declared ----------
    await page.locator('#takes-treatment').selectOption('yes');
    await expect(treatment).toBeVisible();
    await expect(autonomy).toBeVisible();
    await treatment.fill('Ventoline 100 µg');
    await autonomy.selectOption('yes');
    await page.locator('#takes-treatment').selectOption('no');
    await expect(treatment).toBeHidden();
    await expect(autonomy).toBeHidden();

    // --- a diet, answered NON after typing ---------------------------------
    await page.locator('#follows-diet').selectOption('yes');
    await diet.fill('Sans porc');
    await page.locator('#follows-diet').selectOption('no');
    await expect(diet).toBeHidden();

    expect(await stillHere(), 'answering never reloaded the page').toBe('still-here');

    // The restrictions note and the booster date depend on no answer.
    await page.locator('#participation').selectOption('no');
    await expect(page.locator('#participation-details')).toBeVisible();
    await page.locator('#tetanus').selectOption('no');
    await expect(page.locator('#tetanus-booster')).toBeVisible();

    // --- save, then reopen: the saved answers are selected again ----------
    await allergic.selectOption('no');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await page.waitForURL(new RegExp(`/members/${child.member_year_id}/fiche-sante$`));
    await page.getByRole('button', { name: 'Informations confidentielles concernant la santé de la personne' }).click();

    await expect(page.locator('#allergic'), 'a saved NON reopens as NON, not as « Non précisé »').toHaveValue('no');
    await expect(page.locator('#follows-diet')).toHaveValue('no');
    await expect(page.locator('#takes-treatment')).toHaveValue('no');
    await expect(page.locator('#participation')).toHaveValue('no');
    await expect(page.locator('#tetanus')).toHaveValue('no');
    await expect(page.locator('#allergies')).toBeHidden();

    // What was typed before the NON was saved with it, and comes back with OUI.
    await page.locator('#allergic').selectOption('yes');
    await expect(page.locator('#allergies')).toHaveValue('Arachides');
    await page.locator('#follows-diet').selectOption('yes');
    await expect(page.locator('#diet')).toHaveValue('Sans porc');
    await page.locator('#takes-treatment').selectOption('yes');
    await expect(page.locator('#treatment')).toHaveValue('Ventoline 100 µg');
    await expect(page.locator('#treatment-autonomy'), 'the saved autonomy reopens on its own answer').toHaveValue('yes');

    // --- the identity the paper will carry is on the page -----------------
    await page.getByRole('button', { name: 'Identité de la personne' }).click();
    await expect(page.getByRole('term').filter({ hasText: 'Tél. / GSM :' })).toBeVisible();

    expect(pageErrors, 'no uncaught script error').toEqual([]);
    expect(serverErrors, 'no server error').toEqual([]);
});
