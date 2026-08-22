// Shared end-to-end helper: answer the cookie consent banner, the way a
// visitor has to before it stops standing in front of the page.
//
// Not housekeeping. The banner is `fixed-bottom`, so on any page long
// enough to scroll it sits over the last controls — a submit button at
// the foot of a moderator's members page, for instance — and Playwright's
// actionability checks correctly refuse to click through it. A scenario
// that skips this does not fail with "the banner is in the way"; it fails
// with a timeout on whatever the click was supposed to cause, several
// assertions later.
//
// Which decision to record is the caller's: `reject` (the default) keeps
// the session to the strictly necessary cookie, and is what a scenario
// should use unless it is specifically about something consent unlocks —
// see specs/auth-methods.spec.js, which accepts precisely because
// remembering the login method is a functional cookie.
import { expect } from '@playwright/test';

/**
 * @param {import('@playwright/test').Page} page
 * @param {{ accept?: boolean }} [options]
 */
export async function answerCookieBanner(page, options = {}) {
    const banner = page.locator('#cookie-banner');

    // Already answered in this browser context — the decision is a cookie,
    // so it survives every later navigation and the banner never returns.
    if (await banner.count() === 0) {
        return;
    }

    await expect(banner).toBeVisible();
    await page.getByRole('button', { name: options.accept ? 'Tout accepter' : 'Tout refuser' }).click();

    // cookie-consent.js hides the banner only once its POST has resolved,
    // so waiting for it to go is waiting for the decision to be recorded —
    // never a fixed delay.
    await expect(banner).toBeHidden();
}
