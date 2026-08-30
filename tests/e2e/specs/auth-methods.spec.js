// End-to-end: the three ways somebody signs in to ScoutMagic, each driven
// the whole way through in a real browser.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Authentication is the one feature of this application whose halves live
// in three different processes. The magic link is finished in the mail
// client, not the browser that asked for it, and the tab that asked is
// logged in by a poll that never sees the click. A passkey is a ceremony
// between the browser and an authenticator, and the bytes the server
// verifies (clientDataJSON, the attestation object, the signature over
// the RP ID hash) are produced by neither the application nor its
// JavaScript. A password login is an AJAX POST whose body public/assets/
// js/auth.js builds itself, and whose refusals are rendered inline.
//
// None of that is reachable below the browser. PHPUnit covers
// Core\Security\AuthService, WebAuthnService and PasswordAuthMethod one
// call at a time, against values a test wrote itself; Vitest runs auth.js
// in a jsdom with no server, no mail and no authenticator. What no other
// suite can state is that a person who never touches a terminal actually
// gets in — and that the barriers on the way (the RGPD consent the server
// requires, the anti-bot delay, a wrong password, the cookie consent that
// gates remembering the method) behave as promised while they do.
//
// WHAT MAKES IT POSSIBLE, AND WHAT IS NOT BYPASSED
// ----------------------------------------------------------------------------
// Two harness facts, both of them arrangements of the real thing rather
// than stand-ins for it:
//
//   - Mail. The instance runs in local mail mode and scripts/e2e.sh
//     points PHP's sendmail_path at scripts/e2e-maildrop.php, so the
//     message this scenario reads is the one Core\Mail\MailService really
//     produced, through the real templates. Only the transport's last hop
//     is a file instead of a socket.
//   - A domain name. WebAuthn Relying Party IDs are domains, and Chrome
//     rejects an IP-literal one outright — on the 127.0.0.1 this harness
//     used to serve, no passkey could be registered or used at all, in
//     any browser. The instance now calls itself http://localhost:<port>
//     (see e2e_base_url() in scripts/e2e-support.php).
//
// Nothing else is arranged. Every login below goes through the real
// CsrfGuard, the real HumanCheck barriers (waited out, never disabled —
// see support/human-check.js), the real LoginThrottler, the real
// RoleResolver and the real session. The virtual authenticator is
// Chrome's own (the CDP WebAuthn domain): the credential it creates is a
// genuine one, and Core\Security\WebAuthnService verifies its signature
// with no knowledge that it is virtual.
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text everywhere they identify the element (README.md
// § Tests de bout en bout). The login page's three tabs each carry their
// own "Adresse email" field and their own consent checkbox, so locators
// are scoped to the tab panel the visitor is on — the ids auth.js itself
// shows and hides, a contract rather than incidental structure.
import { expect, test } from '@playwright/test';

import { linkFromMail, readMailbox, waitForMail } from '../support/maildrop.js';
import { logoutControl } from '../support/admin-login.js';
import { waitOutHumanCheckDelay } from '../support/human-check.js';
import { scaled } from '../support/timeouts.js';

/**
 * An address with no account and no member behind it. Used to check that
 * asking for a link for it looks exactly like asking for a real one —
 * ScoutMagic deliberately answers the same either way so the login page
 * cannot be used to find out who is a member (Core\Security\AuthService::
 * requestMagicLink()'s "no enumeration" branch).
 */
const UNKNOWN_EMAIL = 'personne-inconnue@example.invalid';

/**
 * Sign out through the real control in the navigation, and wait for the
 * session to be gone rather than for the click.
 *
 * @param {import('@playwright/test').Page} page
 */
async function logout(page) {
    await logoutControl(page).click();
    await page.waitForURL('**/', { waitUntil: 'domcontentloaded' });
    // Every copy of it, not just the visible one: an anonymous visitor is
    // offered none at all.
    await expect(page.getByRole('button', { name: 'Se déconnecter' })).toHaveCount(0);
}

test('a magic link signs in the device that asked for it, and says nothing about who has an account', async ({ page, browser }) => {
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

    const adminEmail = process.env.E2E_ADMIN_EMAIL;
    expect(adminEmail, 'E2E_ADMIN_EMAIL must be set — run through `npm run e2e`').toBeTruthy();

    await page.goto('/login', { waitUntil: 'domcontentloaded' });

    const magicLinkTab = page.locator('#tab-magic-link');

    // ---------------------------------------------------------------
    // First, an address nobody here has. The page must answer exactly
    // what it answers a real member, and no mail may be produced.
    // ---------------------------------------------------------------
    await magicLinkTab.getByLabel('Adresse email').fill(UNKNOWN_EMAIL);
    await magicLinkTab.getByRole('checkbox').check();
    await waitOutHumanCheckDelay(page, magicLinkTab);
    await magicLinkTab.getByRole('button', { name: 'Envoyer le lien de connexion' }).click();

    await expect(page.getByRole('heading', { name: 'Vérifiez vos emails' })).toBeVisible();
    await expect(page.getByText(UNKNOWN_EMAIL)).toBeVisible();

    // ---------------------------------------------------------------
    // Then the real one. Back to the address form through the page's own
    // "use another address" control — the state machine auth.js drives,
    // not a reload.
    // ---------------------------------------------------------------
    await page.getByRole('button', { name: 'Utiliser une autre adresse' }).click();
    await expect(magicLinkTab.getByLabel('Adresse email')).toBeVisible();

    await magicLinkTab.getByLabel('Adresse email').fill(adminEmail);
    await waitOutHumanCheckDelay(page, magicLinkTab);
    await magicLinkTab.getByRole('button', { name: 'Envoyer le lien de connexion' }).click();

    await expect(page.getByRole('heading', { name: 'Vérifiez vos emails' })).toBeVisible();

    // Subject matched loosely on purpose: Core\Mail\MailService prefixes
    // every subject with the site's short name ("[E2E] Votre lien de
    // connexion"), which is its own behaviour and not this scenario's
    // subject.
    const mail = await waitForMail(
        (message) => message.to.includes(adminEmail) && message.subject.includes('Votre lien de connexion'),
        { description: `a magic link addressed to ${adminEmail}` },
    );

    // The unknown address gets no mail — and this is the moment that can
    // be asserted, not a moment earlier: the mail above proves the whole
    // sending path works and that a message this run produced has already
    // landed, so an absent one is absent because nothing sent it.
    expect(
        readMailbox().filter((message) => message.to.includes(UNKNOWN_EMAIL)),
        'a magic link was sent to an address with no account — the login page must not reveal who is a member',
    ).toEqual([]);

    // ---------------------------------------------------------------
    // The other device: the mail is opened somewhere else entirely. A
    // separate browser context is a separate cookie jar, which is what
    // makes it a second device rather than a second tab.
    // ---------------------------------------------------------------
    const otherDevice = await browser.newContext();
    try {
        const otherPage = await otherDevice.newPage();
        await otherPage.goto(linkFromMail(mail, '/auth/verify'), { waitUntil: 'domcontentloaded' });

        await expect(otherPage.getByRole('heading', { name: 'Connexion confirmée' })).toBeVisible();
        // …and THIS device is not signed in by the click. A link travels
        // by email and an email is read wherever it is convenient — a
        // shared tablet, a work laptop, a corporate scanner that follows
        // every URL — so confirming a link is not logging in: the session
        // belongs to the window that asked for it, which collects it by
        // polling below (Tests\Integration\MagicLinkWindowIdentityTest).
        await otherPage.goto('/account', { waitUntil: 'domcontentloaded' });
        await expect(otherPage).toHaveURL(/\/login/);
    } finally {
        await otherDevice.close();
    }

    // ---------------------------------------------------------------
    // And back on the device that asked: it was never told anything, it
    // polls. auth.js shows "Connecté" and then sends the browser home,
    // where the navigation offers the logout control to a session that
    // really exists.
    // ---------------------------------------------------------------
    await expect(page.getByRole('heading', { name: 'Connecté' })).toBeVisible({ timeout: scaled(20_000) });
    await page.waitForURL('**/', { waitUntil: 'domcontentloaded' });
    await expect(logoutControl(page)).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});

test('a passkey registered from the account page then signs in on its own, and is remembered only with cookie consent', async ({ page, context }) => {
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

    // Both the account page and the login page report a failure through
    // window.alert(), which Playwright dismisses silently by default —
    // capturing them turns "the button did nothing" into a message.
    /** @type {string[]} */
    const alerts = [];
    page.on('dialog', async (dialog) => {
        alerts.push(dialog.message());
        await dialog.dismiss();
    });

    const adminEmail = process.env.E2E_ADMIN_EMAIL;
    const adminPassword = process.env.E2E_ADMIN_PASSWORD;
    expect(adminEmail && adminPassword, 'E2E_ADMIN_* must be set — run through `npm run e2e`').toBeTruthy();

    // ---------------------------------------------------------------
    // A platform authenticator, exactly as a phone or a laptop presents
    // one: it stores discoverable credentials (the login page asks for
    // one without naming an account) and it reports the user as verified
    // (WebAuthnService asks for userVerification: 'required', and checks
    // the UV flag server-side too).
    // ---------------------------------------------------------------
    const cdp = await context.newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
        },
    });

    // ---------------------------------------------------------------
    // Register one, signed in with the password — which is how a real
    // member gets their first passkey.
    // ---------------------------------------------------------------
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    const passwordTab = page.locator('#tab-password');
    await page.getByRole('tab', { name: 'Mot de passe' }).click();
    await passwordTab.getByLabel(/^Adresse email \*$/).fill(adminEmail);
    await passwordTab.getByLabel('Mot de passe', { exact: true }).fill(adminPassword);
    await passwordTab.getByRole('checkbox').check();
    await passwordTab.getByRole('button', { name: 'Se connecter' }).click();
    await page.waitForURL('**/', { waitUntil: 'domcontentloaded' });

    await page.goto('/account', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Clés numériques' })).toBeVisible();
    await expect(page.getByText('Aucune clé enregistrée')).toBeVisible();

    // Two clicks by design: the first reveals the name field, the second
    // starts the ceremony (public/assets/js/account-passkeys.js).
    const addKey = page.getByRole('button', { name: 'Ajouter une clé' });
    await addKey.click();
    await page.getByLabel('Nom de cette clé').fill('Téléphone E2E');
    await addKey.click();

    // The page reloads itself once the server has stored the credential,
    // so the key appearing in the list IS the round trip completing.
    await expect(page.getByText('Téléphone E2E')).toBeVisible();
    // Hidden, not absent: the empty state is rendered on every visit and
    // revealed by account-passkeys.js once the LAST key is revoked (it
    // used to live in the loop's {% else %}, so on a page that had keys it
    // did not exist and deleting the last one emptied the list into
    // nothing at all).
    await expect(page.getByText('Aucune clé enregistrée')).toBeHidden();
    expect(alerts, 'registering the passkey failed').toEqual([]);

    await logout(page);

    // ---------------------------------------------------------------
    // Accept cookies before signing in again: remembering which method
    // was used last is a FUNCTIONAL cookie, and AGENTS.md § Cookie
    // consent forbids setting one without consent. Accepting here is what
    // makes the assertion at the end meaningful — its mirror image, the
    // cookie staying absent after a refusal, is
    // specs/cookie-consent-reject.spec.js.
    // ---------------------------------------------------------------
    await page.getByRole('button', { name: 'Tout accepter' }).click();
    await expect(page.locator('#cookie-banner')).toBeHidden();

    // ---------------------------------------------------------------
    // And now the passkey on its own: no address typed, no password. The
    // authenticator answers with a discoverable credential and the server
    // recognises whose it is.
    // ---------------------------------------------------------------
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByRole('tab', { name: 'Clé numérique' }).click();

    const passkeyTab = page.locator('#tab-passkey');
    await passkeyTab.getByRole('checkbox').check();
    await passkeyTab.getByRole('button', { name: 'Utiliser ma clé' }).click();

    await page.waitForURL('**/', { waitUntil: 'domcontentloaded' });
    await expect(logoutControl(page)).toBeVisible();
    // A session that carries the admin role, not merely a session:
    // Core\View\MenuBuilder offers this menu to `admin` and to nobody
    // else, so the passkey login resolved the same role the password one
    // does.
    await expect(page.getByRole('button', { name: "Espace chefs d'U" })).toBeVisible();

    const lastMethod = (await context.cookies()).find((cookie) => cookie.name === 'last_login_method');
    expect(lastMethod?.value, 'the functional cookie must record the method actually used').toBe('passkey');

    // Which is what the login page reads to pre-select a tab: the visitor
    // who comes back finds their own method open, not the default. Signed
    // out first — /login sends an authenticated visitor home rather than
    // showing them a login form, which is itself worth having seen once.
    await logout(page);
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#tab-passkey')).toBeVisible();
    await expect(page.locator('#tab-magic-link')).toBeHidden();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});

test('a password login refuses what it must, then works — and signing out really ends the session', async ({ page }) => {
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    const memberEmail = process.env.E2E_MEMBER_EMAIL;
    const memberPassword = process.env.E2E_MEMBER_PASSWORD;
    expect(memberEmail && memberPassword, 'E2E_MEMBER_* must be set — run through `npm run e2e`').toBeTruthy();

    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByRole('tab', { name: 'Mot de passe' }).click();

    const passwordTab = page.locator('#tab-password');
    // exact: the "forgot password" sub-form in this same panel is labelled
    // "Adresse email du compte", which a substring match would also hit.
    const emailField = passwordTab.getByLabel(/^Adresse email \*$/);
    const passwordField = passwordTab.getByLabel('Mot de passe', { exact: true });
    const submit = passwordTab.getByRole('button', { name: 'Se connecter' });

    // ---------------------------------------------------------------
    // Without the RGPD consent, nothing is even attempted. The server
    // refuses it too (AuthController::hasRgpdConsent()) — this is the
    // browser half of a rule enforced on both sides.
    // ---------------------------------------------------------------
    await emailField.fill(memberEmail);
    await passwordField.fill(memberPassword);
    await submit.click();

    await expect(
        passwordTab.getByText('Vous devez accepter la politique de protection des données pour vous connecter.'),
    ).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);

    // ---------------------------------------------------------------
    // With consent but the wrong password: refused, in the page, with a
    // message that says nothing about which half was wrong.
    // ---------------------------------------------------------------
    await passwordTab.getByRole('checkbox').check();
    await passwordField.fill(`${memberPassword}-faux`);
    await submit.click();

    await expect(passwordTab.getByText('Identifiants invalides.')).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);

    // No session was created by the refusal — a role-gated page still
    // sends this browser back to the login page.
    await page.goto('/account', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login$/);

    // ---------------------------------------------------------------
    // And with the right one. An ordinary member: identified, and
    // deliberately not more than that.
    // ---------------------------------------------------------------
    await page.getByRole('tab', { name: 'Mot de passe' }).click();
    await emailField.fill(memberEmail);
    await passwordField.fill(memberPassword);
    await passwordTab.getByRole('checkbox').check();
    await submit.click();

    await page.waitForURL('**/', { waitUntil: 'domcontentloaded' });
    await expect(logoutControl(page)).toBeVisible();
    await expect(page.getByRole('button', { name: "Espace chefs d'U" })).toHaveCount(0);

    await page.goto('/account', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/account$/);

    // ---------------------------------------------------------------
    // Signing out ends it for real: the site says so, and the page that
    // was reachable a moment ago is not any more.
    // ---------------------------------------------------------------
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await logout(page);
    await expect(page.getByText('Vous avez été déconnecté.')).toBeVisible();

    await page.goto('/account', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login$/);

    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
