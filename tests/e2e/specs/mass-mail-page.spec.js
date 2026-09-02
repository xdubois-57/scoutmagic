// End-to-end: la page d'un publipostage — composition, destinataires, suivi.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// `/mass-mail/{id}` answered JSON, and `Service\MergeDraftService` promised
// « the draft's edit URL » and returned it — so « Écrire aux répondants »
// sent a chief to a raw payload. The composer was a dialog opened from the
// list, and a dialog has no address anybody can be sent to. The whole
// feature had been written against a page that did not exist.
//
// A unit test can assert that the controller renders HTML. What it cannot
// assert is that the address a chief is actually redirected to renders a
// usable screen in a browser: the redirect, the route, the template, the
// nav rail, the breadcrumb and the form's own POST all have to line up,
// and each of them lives in a different file. That is exactly the shape of
// bug that shipped here.
//
// So this scenario opens the page the way a chief reaches it (the list's
// subject link), edits the draft there and saves it through the page's own
// form, then walks the nav rail to « Destinataires ». No mail is sent —
// the sending machine is covered by mass-mail.spec.js and
// mass-mail-merge.spec.js, at their own cost.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';

const SUBJECT = `Réunion de parents ${Date.now()}`;
const EDITED_SUBJECT = `${SUBJECT} — reporté`;
const BODY_LINE = 'La réunion est déplacée au samedi suivant, même heure.';

test("un brouillon a sa propre page, et c'est là qu'on l'écrit", async ({ page }) => {
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
    await page.goto('/mass-mail', { waitUntil: 'load' });

    // ---------------------------------------------------------------
    // A draft to open. Created through the list's dialog, which is what
    // still creates one at this point.
    // ---------------------------------------------------------------
    await page.locator('#mm-new-btn').click();
    const dialog = page.locator('#mm-modal');
    await expect(dialog).toBeVisible();
    await dialog.locator('#mm-list').selectOption({ label: 'Section - Meute E2E' });
    await dialog.locator('#mm-subject').fill(SUBJECT);
    await dialog.locator('#mm-body-content').fill('Message à relire.');
    await dialog.locator('#mm-save-btn').click();

    const row = page.getByRole('row', { name: new RegExp(SUBJECT.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) });
    await expect(row).toBeVisible();
    await page.waitForLoadState('load');

    // ---------------------------------------------------------------
    // The subject is a link to the email's own page — a detail is a page
    // everywhere else on this site, and this one now is too.
    // ---------------------------------------------------------------
    await row.getByRole('link', { name: SUBJECT }).click();
    await page.waitForURL(/\/mass-mail\/\d+$/, { waitUntil: 'load' });

    // Rendered HTML, not the JSON payload this address used to answer.
    await expect(page.getByRole('heading', { level: 1, name: SUBJECT })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Composition' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Destinataires' })).toBeVisible();

    // The breadcrumb names the list it hangs under — « Envoi de mails » —
    // which is the only way back, by convention (design.md §7.3).
    await expect(
        page.locator('.breadcrumb-bar').getByRole('link', { name: 'Envoi de mails' }),
    ).toBeVisible();

    // The payload moved rather than disappearing: the list's dialog still
    // reads it, one segment down.
    const payload = await page.request.get(page.url() + '/data');
    expect(payload.status()).toBe(200);
    expect((await payload.json()).email.subject).toBe(SUBJECT);

    // ---------------------------------------------------------------
    // Writing, on the page, through its own form — no dialog, no fetch.
    // ---------------------------------------------------------------
    await page.locator('#mm-subject').fill(EDITED_SUBJECT);
    const body = page.locator('#mm-body-content');
    await body.click();
    await page.keyboard.type(BODY_LINE);
    await page.getByRole('button', { name: 'Enregistrer' }).click();

    await page.waitForURL(/\/mass-mail\/\d+$/, { waitUntil: 'load' });
    await expect(page.getByRole('alert').filter({ hasText: 'Brouillon enregistré.' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 1, name: EDITED_SUBJECT })).toBeVisible();
    // Saved through the hidden field the shared rich-text component keeps
    // in step with the editing surface — the body survives a round trip.
    await expect(page.locator('#mm-body-content')).toContainText(BODY_LINE);

    // ---------------------------------------------------------------
    // « Destinataires » — the question asked last, and the one the
    // composition screen cannot answer while you are writing in it.
    // ---------------------------------------------------------------
    await page.getByRole('link', { name: 'Destinataires' }).click();
    await page.waitForURL(/\/mass-mail\/\d+\/recipients$/, { waitUntil: 'load' });
    await expect(page.getByRole('heading', { level: 1, name: 'Destinataires' })).toBeVisible();
    await expect(page.getByText('Section - Meute E2E')).toBeVisible();
    // The breadcrumb's dynamic ancestor: the email itself.
    await expect(
        page.locator('.breadcrumb-bar').getByRole('link', { name: EDITED_SUBJECT }),
    ).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
