// End-to-end: the "Mot de passe oublié" flow, from the mini-form on the
// login page to signing back in with the new password.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// This flow is finished on the other side of an email, and its security
// posture lives in three places no other suite can watch together:
//
//   1. The reset token travels in the URL FRAGMENT, which browsers never
//      transmit. The server renders a neutral "checking" page and the
//      page's own script posts the fragment to /password-reset/{id}/check
//      before revealing the form (PasswordResetController::show()). That
//      handshake — fragment read, address bar scrubbed, check round trip,
//      panel switch — happens only in a browser talking to a real server:
//      PHPUnit never sees a fragment (it is not part of the request), and
//      Vitest has no /check endpoint to answer.
//   2. Whether an address has an account must be unobservable from the
//      outside (ARCHITECTURE.md §5). The mini-form must show the SAME
//      message for a known and an unknown address, and the unknown one
//      must produce no email — an absence only the harness's maildrop can
//      certify.
//   3. A used link must be dead. checkToken() deliberately does NOT
//      consume the token (a prefetch must not burn it), so "single use"
//      is enforced one layer further down — revisiting the link after a
//      successful reset has to land on the "no longer valid" panel.
//
// The password complexity checklist itself (password-field.js) is pinned
// by auth-methods.spec.js and its own Vitest suite; this scenario only
// relies on it accepting a compliant password.
//
// ORDERING
// ----------------------------------------------------------------------------
// The member's password is the harness-provisioned one other scenarios
// log in with, so this scenario RESTORES it before ending — through a
// second full reset, which conveniently is also what proves the flow
// works twice in a row (the per-IP rate limit allows 5 requests per
// window; this file makes 3).
import { expect, test } from '@playwright/test';

import { logoutControl } from '../support/admin-login.js';
import { linkFromMail, readMailbox, waitForMail } from '../support/maildrop.js';
import { waitOutHumanCheckDelay } from '../support/human-check.js';

const UNKNOWN_EMAIL = 'personne@example.invalid';
const TEMP_PASSWORD = 'Nouveau-mdp-E2E-42!';

/**
 * Ask for a reset link from the login page's mini-form and return the
 * generic acknowledgement the visitor reads. The mini-form is a fetch the
 * page hand-builds (auth.js), anti-bot fields included — driving it for
 * real is the point.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} email
 * @returns {Promise<string>} the message shown under the mini-form
 */
async function requestResetLink(page, email) {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByRole('tab', { name: 'Mot de passe' }).click();
    await page.getByRole('link', { name: 'Mot de passe oublié ?' }).click();

    const miniForm = page.locator('#forgot-password-form');
    await miniForm.getByLabel('Adresse email du compte').fill(email);

    // The mini-form carries its own anti-bot challenge (form key
    // password_reset_request), distinct from the magic-link form's on the
    // same page — scoping the wait to the form is what picks the right one.
    await waitOutHumanCheckDelay(page, miniForm);
    await miniForm.getByRole('button', { name: 'Recevoir un lien de réinitialisation' }).click();

    const message = page.locator('#forgot-password-message');
    await expect(message).toBeVisible();

    return (await message.innerText()).trim();
}

/**
 * Open a mailed reset link and set a new password through the real form.
 * Returns after the redirect back to /login confirms the reset.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} link
 * @param {string} newPassword
 */
async function completeReset(page, link, newPassword) {
    await page.goto(link, { waitUntil: 'domcontentloaded' });

    // The neutral page checked the fragment against /check and revealed
    // the form — the one visible round trip of the token's journey.
    await expect(page.getByLabel('Nouveau mot de passe', { exact: true })).toBeVisible();

    // The script scrubs the fragment from the address bar the moment it
    // has read it, so the token never lingers in history.
    expect(new URL(page.url()).hash, 'the token must be stripped from the address bar').toBe('');

    await page.getByLabel('Nouveau mot de passe', { exact: true }).fill(newPassword);
    await page.getByLabel('Confirmer le nouveau mot de passe', { exact: true }).fill(newPassword);
    await page.getByRole('button', { name: 'Réinitialiser le mot de passe' }).click();

    await page.waitForURL('**/login', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Votre mot de passe a été réinitialisé.', { exact: false })).toBeVisible();
}

/**
 * Sign in on the password tab and report what came of it, without
 * insisting: this scenario needs to see a wrong password refused and a
 * right one accepted, with the same steps either way.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} email
 * @param {string} password
 */
async function tryPasswordLogin(page, email, password) {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    const passwordTab = page.locator('#tab-password');
    await page.getByRole('tab', { name: 'Mot de passe' }).click();
    await passwordTab.getByLabel(/^Adresse email \*$/).fill(email);
    await passwordTab.getByLabel('Mot de passe', { exact: true }).fill(password);
    await passwordTab.getByRole('checkbox').check();
    await passwordTab.getByRole('button', { name: 'Se connecter' }).click();
}

test('a forgotten password is reset through the emailed link, which then stops working', async ({ page }) => {
    // Three reset requests, each sitting out the anti-bot minimum delay,
    // plus two mailed round trips — headroom for CI's slower,
    // coverage-instrumented runs.
    test.setTimeout(120_000);

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

    // The visitor is anonymous throughout — a password reset is precisely
    // for somebody who cannot log in. Refusing the consent banner also
    // states that the whole flow needs no non-essential cookie.
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Tout refuser' }).click();
    await expect(page.locator('#cookie-banner')).toBeHidden();

    // ---------------------------------------------------------------
    // 1. An address with no account gets the exact same acknowledgement
    // as a real one — and, checked further down, no email at all.
    // ---------------------------------------------------------------
    const unknownMessage = await requestResetLink(page, UNKNOWN_EMAIL);

    // ---------------------------------------------------------------
    // 2. The member asks for a link; the acknowledgement is identical.
    // ---------------------------------------------------------------
    const knownMessage = await requestResetLink(page, memberEmail);
    expect(knownMessage, 'known and unknown addresses must be indistinguishable').toBe(unknownMessage);

    const mail = await waitForMail(
        (message) => message.to.includes(memberEmail) && /r[ée]initialis/i.test(message.subject),
        { description: `a password-reset email addressed to ${memberEmail}` },
    );
    const resetLink = linkFromMail(mail, '/password-reset/');
    expect(resetLink, 'the reset link must carry the token as a fragment').toContain('#');

    // The member's mail has arrived, so a mail for the unknown address —
    // requested BEFORE it — would already be here too. It must not be.
    expect(
        readMailbox().filter((message) => message.to.includes(UNKNOWN_EMAIL)),
        'an address with no account must never receive a reset email',
    ).toEqual([]);

    // ---------------------------------------------------------------
    // 3. The emailed link works: check round trip, form, new password.
    // ---------------------------------------------------------------
    await completeReset(page, resetLink, TEMP_PASSWORD);

    // ---------------------------------------------------------------
    // 4. The old password is dead, the new one signs in.
    // ---------------------------------------------------------------
    await tryPasswordLogin(page, memberEmail, memberPassword);
    await expect(page.locator('#password-error')).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);

    await tryPasswordLogin(page, memberEmail, TEMP_PASSWORD);
    await page.waitForURL('**/', { waitUntil: 'domcontentloaded' });
    await expect(logoutControl(page)).toBeVisible();
    await logoutControl(page).click();
    await expect(page.getByText('Vous avez été déconnecté.')).toBeVisible();

    // ---------------------------------------------------------------
    // 5. The link that just served is dead: revisiting it must land on
    // the "no longer valid" panel, never on the form again.
    // ---------------------------------------------------------------
    await page.goto(resetLink, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText("n'est plus valide", { exact: false })).toBeVisible();
    await expect(page.getByLabel('Nouveau mot de passe', { exact: true })).toBeHidden();

    // ---------------------------------------------------------------
    // 6. Restore the provisioned password through a second reset, so the
    // scenarios after this one find the member exactly as the harness
    // left them.
    // ---------------------------------------------------------------
    const restoreMessage = await requestResetLink(page, memberEmail);
    expect(restoreMessage).toBe(unknownMessage);

    const restoreMail = await waitForMail(
        (message) => message.to.includes(memberEmail)
            && /r[ée]initialis/i.test(message.subject)
            && linkFromMail(message, '/password-reset/') !== resetLink,
        { description: `a second password-reset email addressed to ${memberEmail}` },
    );
    await completeReset(page, linkFromMail(restoreMail, '/password-reset/'), memberPassword);

    await tryPasswordLogin(page, memberEmail, memberPassword);
    await page.waitForURL('**/', { waitUntil: 'domcontentloaded' });
    await expect(logoutControl(page)).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
