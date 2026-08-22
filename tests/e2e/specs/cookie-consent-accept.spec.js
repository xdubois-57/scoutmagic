// End-to-end: the other two answers to the consent banner — accepting
// everything, and choosing category by category on the preferences page.
//
// cookie-consent-reject.spec.js pins the refusal half of AGENTS.md
// § Cookie consent. What it deliberately leaves open, and what nothing
// below a browser can prove, is the GRANT side of the same rule: a
// functional cookie must appear once (and only once) consent covers it,
// and must be TAKEN BACK — expired out of the browser's jar, not merely
// stopped being read — the moment that consent is withdrawn on /cookies
// (CookieController::save() calls LastLoginMethodCookie::forget()).
// PHPUnit sees CookieConsentService against a test jar; only a real
// browser sees the Set-Cookie round trips land in a real one.
//
// The observable non-essential cookie is the one core declares:
// `last_login_method` (category "functional", written on a successful
// login) — the same real name cookie-consent-reject.spec.js relies on.
import { expect, test } from '@playwright/test';

import { loginAsMember, logoutControl } from '../support/admin-login.js';

/**
 * The consent decision as the browser's jar records it — the JSON payload
 * of the `cookie_consent` cookie CookieConsentService writes.
 *
 * @param {import('@playwright/test').BrowserContext} context
 * @returns {Promise<{functional: boolean, analytics: boolean} | null>}
 */
async function consentCookie(context) {
    const cookie = (await context.cookies()).find((candidate) => candidate.name === 'cookie_consent');

    return cookie ? JSON.parse(decodeURIComponent(cookie.value)) : null;
}

/**
 * @param {import('@playwright/test').BrowserContext} context
 * @param {string} name
 */
async function hasCookie(context, name) {
    return (await context.cookies()).some((cookie) => cookie.name === name);
}

test('accepting all cookies is remembered, and lets the functional login cookie exist', async ({ page, context }) => {
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

    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const banner = page.locator('#cookie-banner');
    await expect(banner).toBeVisible();
    await page.getByRole('button', { name: 'Tout accepter' }).click();

    // cookie-consent.js removes the banner only after its fetch to
    // /cookies/accept-all resolves — the removal IS the round trip.
    await expect(banner).toBeHidden();

    expect(await consentCookie(context), 'accepting all must record every category').toEqual({
        functional: true,
        analytics: true,
    });

    // The choice is stored, so a next page never asks again.
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(banner).toBeHidden();

    // With functional consent in place, a login writes the one functional
    // cookie core declares. Consent is what stands between the two — the
    // reject spec proves the same login leaves it unset.
    await loginAsMember(page);
    expect(await hasCookie(context, 'last_login_method'), 'functional cookie allowed by consent').toBe(true);

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});

test('the preferences page grants a category and later takes its cookies back', async ({ page, context }) => {
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

    // "Personnaliser" on the banner is the documented path to /cookies.
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Personnaliser' }).click();
    await page.waitForURL('**/cookies', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Préférences cookies' })).toBeVisible();

    // Strictly necessary is not a choice: no switch, a "Toujours actif"
    // badge — the ePrivacy rule the page exists to embody.
    await expect(page.getByText('Toujours actif')).toBeVisible();
    await expect(page.locator('#cookie-necessary')).toHaveCount(0);

    // Grant functional only, through the real form.
    const functionalSwitch = page.locator('#cookie-functional');
    await expect(functionalSwitch).not.toBeChecked();
    await functionalSwitch.check();
    await page.getByRole('button', { name: 'Enregistrer mes choix' }).click();

    await expect(page.getByText('Vos préférences cookies ont été enregistrées.')).toBeVisible();
    // The saved state survives the redirect: the switch renders back
    // checked from the stored consent, not from browser state.
    await expect(page.locator('#cookie-functional')).toBeChecked();
    expect(await consentCookie(context)).toEqual({ functional: true, analytics: false });

    // A granted category really flows through: logging in writes the
    // functional cookie.
    await loginAsMember(page);
    expect(await hasCookie(context, 'last_login_method')).toBe(true);
    await logoutControl(page).click();
    await expect(page.getByText('Vous avez été déconnecté.')).toBeVisible();

    // Withdrawing the category must EXPIRE the cookie out of the jar in
    // the same response, not merely stop reading it.
    await page.goto('/cookies', { waitUntil: 'domcontentloaded' });
    await page.locator('#cookie-functional').uncheck();
    await page.getByRole('button', { name: 'Enregistrer mes choix' }).click();
    await expect(page.getByText('Vos préférences cookies ont été enregistrées.')).toBeVisible();

    expect(await consentCookie(context)).toEqual({ functional: false, analytics: false });
    expect(
        await hasCookie(context, 'last_login_method'),
        'withdrawing functional consent must remove the cookie it covered',
    ).toBe(false);

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
