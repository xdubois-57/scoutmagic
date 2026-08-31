// End-to-end: "Mon compte" — the page where a person edits themself.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Three behaviours of this page live only in a browser against a real
// server:
//
//   1. The photo. The avatar's edit overlay walks to /upload, where
//      public/assets/js/upload.js REWRITES THE BYTES before anything is
//      posted: an image past 2400px is decoded, downscaled on a canvas
//      and re-encoded as WebP, so what the server stores is a file the
//      test never supplied. That is the purest case of "the browser
//      decides what the server receives" (ARCHITECTURE.md §15) — and
//      upload.js has no Vitest suite at all, so until here NOTHING
//      executed it.
//   2. Changing the password revokes every OTHER session
//      (UserAccountRepository::updatePasswordHash() + AuthSession::
//      refreshIssuedAt(): the tab that changed it stays signed in,
//      everywhere else is signed out). Two real browser contexts are the
//      only way to watch both halves of that promise at once.
//   3. The profile form and the photo delete are ordinary POSTs, but they
//      run through the same page, whose flash/redirect loop and
//      data-confirm dialog (delegated once in base.html.twig) are
//      browser-level wiring.
//
// WHAT IT DELIBERATELY LEAVES ALONE
// ----------------------------------------------------------------------------
// - Passkey registration/login: pinned end-to-end by auth-methods.spec.js
//   with a virtual authenticator.
// - The push toggle's subscribe round trip: playwright.config.js blocks
//   service workers for the whole suite (a deliberate, documented choice),
//   and PushManager.subscribe() cannot run without one. The section
//   rendering with its VAPID key is asserted; the subscription itself is
//   covered by tests/js/push-notifications.test.js.
//
// ORDERING
// ----------------------------------------------------------------------------
// Everything this scenario changes it restores — name, password, photo —
// because the member account is shared fixture for every later spec.
import { expect, test } from '@playwright/test';

import { answerConfirmation } from '../support/confirm-dialog.js';
import { loginAsMember, logoutControl } from '../support/admin-login.js';
import { pngBuffer } from '../support/png.js';

const TEMP_PASSWORD = 'Compte-E2E-mdp-7!';

/**
 * Change the password through the real form. Which flash comes back is
 * the caller's business — a wrong current password is part of the story.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} currentPassword
 * @param {string} newPassword
 */
async function submitPasswordChange(page, currentPassword, newPassword) {
    await page.goto('/account', { waitUntil: 'domcontentloaded' });
    const form = page.locator('#account-password-form');
    await form.getByLabel('Mot de passe actuel').fill(currentPassword);
    await form.getByLabel('Nouveau mot de passe', { exact: true }).fill(newPassword);
    await form.getByLabel('Confirmer le nouveau mot de passe', { exact: true }).fill(newPassword);
    await form.getByRole('button', { name: 'Changer le mot de passe' }).click();
    await page.waitForURL('**/account', { waitUntil: 'domcontentloaded' });
}

test('the account page updates the profile, replaces the photo through the client-side downscale, and a password change signs the other devices out', async ({ page, browser }) => {
    const memberEmail = process.env.E2E_MEMBER_EMAIL;
    const memberPassword = process.env.E2E_MEMBER_PASSWORD;
    if (!memberEmail || !memberPassword) {
        throw new Error('E2E_MEMBER_EMAIL / E2E_MEMBER_PASSWORD are not set — run via `npm run e2e`.');
    }

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

    await loginAsMember(page);
    await page.goto('/account', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Mon compte' })).toBeVisible();
    await expect(page.getByText(memberEmail)).toBeVisible();

    // ---------------------------------------------------------------
    // Profile: change both names, prove the round trip, put them back.
    // The provisioned name is load-bearing fixture for the groups
    // scenarios, so restoring it is part of the scenario's contract.
    // ---------------------------------------------------------------
    const firstNameField = page.getByLabel('Prénom');
    const lastNameField = page.getByLabel('Nom', { exact: true });
    const originalFirstName = await firstNameField.inputValue();
    const originalLastName = await lastNameField.inputValue();

    await firstNameField.fill('Prénom-E2E');
    await lastNameField.fill('Nom-E2E');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await page.waitForURL('**/account', { waitUntil: 'domcontentloaded' });

    // Re-rendered from the database, not from browser state.
    await expect(page.getByLabel('Prénom')).toHaveValue('Prénom-E2E');
    await expect(page.getByLabel('Nom', { exact: true })).toHaveValue('Nom-E2E');

    await page.getByLabel('Prénom').fill(originalFirstName);
    await page.getByLabel('Nom', { exact: true }).fill(originalLastName);
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await page.waitForURL('**/account', { waitUntil: 'domcontentloaded' });
    await expect(page.getByLabel('Prénom')).toHaveValue(originalFirstName);

    // ---------------------------------------------------------------
    // The photo. The avatar's overlay walks to the shared upload page;
    // a 3000×2000 PNG is over upload.js's 2400px budget, so the preview
    // must announce a .webp — the browser has re-encoded the file, and
    // THAT file is what the form will post.
    // ---------------------------------------------------------------
    await page.locator('.editable-image .editable-edit-btn').click();
    await page.waitForURL(/\/upload\?context=account_photo/, { waitUntil: 'domcontentloaded' });

    await page.locator('#file-input').setInputFiles({
        name: 'portrait.png',
        mimeType: 'image/png',
        buffer: pngBuffer(3000, 2000),
    });

    const previewName = page.locator('#preview-name');
    await expect(previewName).toContainText('portrait.webp');
    await expect(page.locator('#upload-btn')).toBeEnabled();
    await page.getByRole('button', { name: 'Envoyer' }).click();

    // The upload redirects back to where the overlay was clicked, and the
    // avatar is now a real <img> served by the authorised /files route.
    await page.waitForURL('**/account', { waitUntil: 'domcontentloaded' });
    const avatarImage = page.locator('.editable-image img.person-avatar');
    await expect(avatarImage).toBeVisible();
    // Polled rather than sampled once. The redirect above is awaited at
    // DOMContentLoaded, which says nothing about subresources, and
    // Core\View\PersonAvatar renders this <img> with width/height
    // attributes plus loading="lazy" decoding="async" — so the element
    // already owns its reserved 96px box, and satisfies toBeVisible(),
    // while naturalWidth is still 0. The claim is unchanged; it is just
    // given the time the decode always needed. Same shape as the
    // lightbox decode check in gallery-media.spec.js.
    await expect
        .poll(
            () => avatarImage.evaluate((img) => /** @type {HTMLImageElement} */ (img).naturalWidth),
            { message: 'the stored WebP must decode when served back through /files' },
        )
        .toBeGreaterThan(0);

    // ---------------------------------------------------------------
    // And taken away again — through the data-confirm dialog that
    // base.html.twig wires once for every form that declares one.
    // ---------------------------------------------------------------
    // Answered explicitly rather than through autoConfirm(): this is the
    // one confirmation of the scenario, and the point of the step is that
    // the visitor is asked at all before their photo goes.
    await page.getByRole('button', { name: 'Retirer la photo' }).click();
    await answerConfirmation(page);
    await page.waitForURL('**/account', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.editable-image img.person-avatar')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Retirer la photo' })).toHaveCount(0);

    // ---------------------------------------------------------------
    // The password. A second signed-in context stands for "another
    // device"; the change must kill that one and spare this one.
    // ---------------------------------------------------------------
    const otherDevice = await browser.newContext();
    try {
        const otherPage = await otherDevice.newPage();
        await loginAsMember(otherPage);

        // Wrong current password: refused, nothing revoked.
        await submitPasswordChange(page, 'pas-le-bon-mot-de-passe', TEMP_PASSWORD);
        await expect(page.getByText('Le mot de passe actuel est incorrect.')).toBeVisible();

        // Right current password: accepted.
        await submitPasswordChange(page, memberPassword, TEMP_PASSWORD);
        await expect(page.getByText('Mot de passe mis à jour.')).toBeVisible();

        // This tab survives its own change...
        await page.goto('/account', { waitUntil: 'domcontentloaded' });
        await expect(logoutControl(page)).toBeVisible();

        // ...the other device does not: its next navigation finds the
        // session revoked and the visitor anonymous again.
        await otherPage.goto('/account', { waitUntil: 'domcontentloaded' });
        await expect(otherPage.getByRole('link', { name: 'Se connecter' })).toBeVisible();
        await expect(logoutControl(otherPage)).toHaveCount(0);

        // Restore the provisioned password for every scenario after this
        // one — same form, same rules.
        await submitPasswordChange(page, TEMP_PASSWORD, memberPassword);
        await expect(page.getByText('Mot de passe mis à jour.')).toBeVisible();
    } finally {
        await otherDevice.close();
    }

    // ---------------------------------------------------------------
    // The push section renders armed (real VAPID key on the toggle) —
    // the subscribe round trip itself needs the service worker the
    // suite's config deliberately blocks, and stays with Vitest.
    // ---------------------------------------------------------------
    const pushToggle = page.locator('#push-toggle');
    await expect(pushToggle).toBeVisible();
    expect(await pushToggle.getAttribute('data-vapid-public-key')).not.toBe('');

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
