// End-to-end: the other half of an article's life — the half
// news-form-payment.spec.js deliberately stops before. An existing
// article is re-opened in the editor, its form is changed, a member edits
// their own response, the responses leave as XLSX, the poster leaves as
// PDF, and the article dies.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
//   1. The EDIT round trip is the fragile half of the builder:
//      editor.html.twig seeds window.NEWS_EDITOR_DATA from the stored
//      fields (hand-mirrored key names: field_type, capacity_max,
//      price_per_unit…), news-form-builder.js re-draws its state from it,
//      and saving re-serialises everything back through the one hidden
//      `fields_json` input. A renamed key breaks the cycle silently —
//      the create path (covered elsewhere) still works while every
//      re-opened article loses data. Only a browser can walk the full
//      circle: store → seed → redraw → re-serialise → store.
//   2. A member editing THEIR OWN response exercises
//      ResponseService::canEditResponse() (owner-only, form still open)
//      and the capacity recomputation on update — a public route guarded
//      only in the controller.
//   3. The XLSX export and the A4 poster are the module's two
//      file-producing routes; a real download is the only place their
//      headers and bytes exist.
//   4. Deletion is a JS-only verb (fetch DELETE from a Bootstrap modal).
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin, loginAsMember } from '../support/admin-login.js';
import { pngBuffer } from '../support/png.js';

const ARTICLE_TITLE = `Souper spaghetti ${Date.now()}`;
const PLACES_LABEL = 'Nombre de convives';
const PLACES_LABEL_EDITED = 'Nombre de personnes à table';

/**
 * The builder's row for one field — same [data-key] contract as
 * news-form-payment.spec.js (the identity news-form-builder.js gives each
 * field in its own state array).
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} index
 */
function fieldRow(page, index) {
    return page.locator('#news-fields-list > [data-key]').nth(index);
}

test('an article is re-edited without losing its form, a member edits their response, exports run, and the article is deleted', async ({ page, browser }) => {
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
    // A public article with a capacity-capped number field.
    // ---------------------------------------------------------------
    await page.goto('/news/manage', { waitUntil: 'domcontentloaded' });
    await page.locator('main').getByRole('link', { name: 'Nouvel article' }).first().click();
    await page.getByLabel("Titre de l'article").fill(ARTICLE_TITLE);
    await page.getByLabel('Résumé en une phrase').fill('Le repas qui finance le camp.');
    await page.locator('#image').setInputFiles({
        name: 'affiche.png',
        mimeType: 'image/png',
        buffer: pngBuffer(2, 2),
    });
    await page.getByRole('radio', { name: 'Public', exact: true }).check();

    const bodyEditor = fieldRow(page, 0).getByRole('textbox', { name: 'Contenu' });
    await bodyEditor.fill('Rendez-vous au réfectoire, apportez votre bonne humeur.');

    await page.getByRole('button', { name: 'Ajouter un champ' }).click();
    await page.getByRole('button', { name: 'Nombre' }).click();
    const placesPanel = fieldRow(page, 1);
    await placesPanel.getByLabel('Libellé du champ').fill(PLACES_LABEL);
    await placesPanel.getByLabel('Limiter la capacité').check();
    await placesPanel.getByLabel('Capacité maximale').fill('5');

    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await page.waitForURL(/\/news\/\d+\/gerer$/, { waitUntil: 'domcontentloaded' });
    const articleUrl = new URL(page.url()).pathname.replace(/\/gerer$/, '');

    // ---------------------------------------------------------------
    // A signed-in member responds — an IDENTIFIED response is the only
    // kind its author may edit afterwards (canEditResponse: owner-only).
    // ---------------------------------------------------------------
    const member = await browser.newContext();
    try {
        const memberPage = await member.newPage();
        await loginAsMember(memberPage);
        await answerCookieBanner(memberPage);
        await memberPage.goto(articleUrl, { waitUntil: 'domcontentloaded' });
        await expect(memberPage.getByRole('heading', { level: 1, name: ARTICLE_TITLE })).toBeVisible();
        await expect(memberPage.getByText('Il reste 5 places')).toBeVisible();

        await memberPage.getByLabel(new RegExp(PLACES_LABEL)).fill('2');
        await memberPage.getByRole('button', { name: 'Envoyer' }).click();
        await expect(memberPage.getByRole('heading', { name: 'Votre réponse a été enregistrée' })).toBeVisible();

        // The member changes their mind — through the edit link the
        // confirmation offers to a response's own author.
        await memberPage.getByRole('link', { name: 'Modifier mes réponses' }).click();
        await memberPage.waitForURL(/\/form\/responses\/\d+\/edit$/, { waitUntil: 'domcontentloaded' });
        const placesField = memberPage.getByLabel(new RegExp(PLACES_LABEL));
        await expect(placesField).toHaveValue('2');
        await placesField.fill('3');
        // Saving an EDIT redirects straight back to the article
        // (FormController::updateResponse()), where the capacity has been
        // recomputed against the edited answer: 5 − 3.
        await memberPage.getByRole('button', { name: 'Enregistrer les modifications' }).click();
        await memberPage.waitForURL(`**${articleUrl}`, { waitUntil: 'domcontentloaded' });
        await expect(memberPage.getByText('Il reste 2 places')).toBeVisible();
    } finally {
        await member.close();
    }

    // ---------------------------------------------------------------
    // The editor re-opened: NEWS_EDITOR_DATA seeded the builder with the
    // STORED field — label, capacity, checkbox state — and a change
    // re-serialises through fields_json without dropping anything.
    // ---------------------------------------------------------------
    await page.goto(`${articleUrl}/gerer`, { waitUntil: 'load' });
    await expect(page.getByLabel("Titre de l'article")).toHaveValue(ARTICLE_TITLE);

    // A re-opened editor lists its fields folded; the row opens its edit
    // panel on click (the builder's own affordance).
    const reopenedPanel = fieldRow(page, 1);
    await reopenedPanel.getByText(PLACES_LABEL).first().click();
    await expect(reopenedPanel.getByLabel('Libellé du champ')).toHaveValue(PLACES_LABEL);
    await expect(reopenedPanel.getByLabel('Limiter la capacité')).toBeChecked();
    await expect(reopenedPanel.getByLabel('Capacité maximale')).toHaveValue('5');

    await reopenedPanel.getByLabel('Libellé du champ').fill(PLACES_LABEL_EDITED);
    await reopenedPanel.getByLabel('Capacité maximale').fill('6');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await page.waitForURL(/\/news\/\d+\/gerer$/, { waitUntil: 'load' });

    // Round trip complete: the re-opened editor shows the edit, and the
    // public page re-derives the remaining places (6 − 3) — the recorded
    // response survived its field being edited.
    await fieldRow(page, 1).getByText(PLACES_LABEL_EDITED).first().click();
    await expect(fieldRow(page, 1).getByLabel('Libellé du champ')).toHaveValue(PLACES_LABEL_EDITED);
    await expect(fieldRow(page, 1).getByLabel('Capacité maximale')).toHaveValue('6');

    const visitor = await browser.newContext();
    try {
        const visitorPage = await visitor.newPage();
        await visitorPage.goto(articleUrl, { waitUntil: 'domcontentloaded' });
        await expect(visitorPage.getByText('Il reste 3 places')).toBeVisible();
    } finally {
        await visitor.close();
    }

    // ---------------------------------------------------------------
    // The two file-producing routes: XLSX responses, PDF poster.
    // ---------------------------------------------------------------
    await page.goto(`${articleUrl}/form/responses`, { waitUntil: 'domcontentloaded' });
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: /Exporter/ }).click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/\.xlsx$/);

    const poster = await page.request.get(`${articleUrl}/poster`);
    expect(poster.status()).toBe(200);
    expect(poster.headers()['content-type']).toContain('application/pdf');
    expect((await poster.body()).subarray(0, 5).toString('latin1')).toBe('%PDF-');

    // ---------------------------------------------------------------
    // Deletion: a fetch DELETE behind a Bootstrap confirm modal — the
    // only way this verb exists.
    // ---------------------------------------------------------------
    await page.goto(`${articleUrl}/gerer`, { waitUntil: 'load' });
    await page.getByRole('button', { name: 'Supprimer', exact: true }).first().click();
    const deleteModal = page.locator('#news-delete-modal');
    await expect(deleteModal).toBeVisible();
    await deleteModal.locator('#news-delete-confirm-btn').click();

    await page.waitForURL('**/news/manage', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('link', { name: ARTICLE_TITLE })).toHaveCount(0);

    const gone = await page.request.get(articleUrl);
    expect(gone.status(), 'a deleted article must be gone, not empty').toBe(404);

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
