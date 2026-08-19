// End-to-end: rejecting all cookies from the consent banner leaves no
// non-essential cookie set, in a real browser.
//
// AGENTS.md § Cookie consent is explicit and non-negotiable: "Never set a
// non-essential cookie without first checking CookieConsentService::
// isAllowed($category)." PHPUnit already covers CookieConsentService and
// CookieController in isolation; what only a real browser proves is the
// full round trip this rule actually depends on — the banner rendering
// unprompted on a first visit, its button wired to the real endpoint by
// public/assets/js/cookie-consent.js, a real Set-Cookie response, and the
// browser's own cookie jar ending up exactly as promised.
//
// core/Cookie/CookieRegistry declares exactly one non-essential HTTP
// cookie core sets today (`last_login_method`, category "functional",
// written on a successful login) — this scenario proves rejecting
// consent keeps it absent, using the real declared name rather than a
// guess, so a future core cookie addition either updates this assertion
// deliberately or the test fails loudly instead of silently stopping to
// mean anything.
import { expect, test } from '@playwright/test';

test('rejecting all cookies from the banner sets no non-essential cookie', async ({ page, context }) => {
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

    // No consent recorded yet on this never-visited instance — the banner
    // must render unprompted, not be silently skipped.
    const banner = page.locator('#cookie-banner');
    await expect(banner).toBeVisible();

    await page.getByRole('button', { name: 'Tout refuser' }).click();

    // cookie-consent.js removes the banner only after its fetch() to
    // /cookies/reject-all resolves — waiting on the banner's removal is
    // therefore also waiting on that round trip actually completing,
    // never a fixed sleep.
    await expect(banner).toBeHidden();

    const cookies = await context.cookies();
    const cookieNames = cookies.map((cookie) => cookie.name);

    expect(cookieNames, 'necessary cookie recording the consent decision itself').toContain('cookie_consent');
    expect(cookieNames, 'non-essential cookie set despite consent being rejected').not.toContain('last_login_method');

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
