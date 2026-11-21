// End-to-end: on a touch device, the phone icon of a Staffs card is centred
// on the number (#1352).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Under `(pointer: coarse)` app.css gives the number's link (.phone-link) a
// 44px minimum height and centres the number in it, while the card's contact
// rows aligned their icon to the TOP of the row. The icon then sat well above
// the number. No unit test and no mouse-driven browser can see it — `pointer:
// fine` never inflates the link — so the context below emulates a touch
// device (`hasTouch` is what makes `(pointer: coarse)` match; a narrow
// window alone would test nothing) and measures the real geometry rather than
// the presence of a class.
//
// The unit chief's own member carries a mobile number in the E2E instance
// (scripts/e2e-support.php) and belongs to Staff d'U, whose card is the first
// one the page offers.
import { test, expect, devices } from '@playwright/test';
import { loginAsAdmin } from '../support/admin-login.js';
import { answerCookieBanner } from '../support/cookie-banner.js';

test('the phone icon is centred on the number on a touch device', async ({ browser }) => {
    const context = await browser.newContext({ ...devices['Pixel 5'] });
    const page = await context.newPage();

    // Signing in needs the desktop layout: loginAsAdmin() waits for the
    // logout control, which lives inside the drawer on a phone.
    await page.setViewportSize({ width: 1280, height: 900 });
    await loginAsAdmin(page);
    await answerCookieBanner(page, { accept: true });
    await page.setViewportSize({ width: 390, height: 844 });

    await page.goto('/chefs/staffs', { waitUntil: 'domcontentloaded' });

    // Found by what it holds, not by the class that fixes it: the test measures geometry.
    const row = page.locator('.staff-contact-row').filter({ has: page.locator('.phone-link') }).first();
    await expect(row, 'a staff card with a phone row must be on the page').toBeVisible();

    const measured = await row.evaluate((element) => {
        const icon = element.querySelector('i').getBoundingClientRect();
        const link = element.querySelector('.phone-link').getBoundingClientRect();

        return {
            coarse: matchMedia('(pointer: coarse)').matches,
            linkHeight: link.height,
            iconCentre: icon.top + icon.height / 2,
            linkCentre: link.top + link.height / 2,
        };
    });

    // The conditions that produced the defect are still real…
    expect(measured.coarse, 'the emulated device must have a coarse pointer').toBe(true);
    expect(measured.linkHeight, 'the number keeps its 44px touch target').toBeGreaterThanOrEqual(44);

    // …and the icon is nonetheless on the number's centre line.
    expect(
        Math.abs(measured.iconCentre - measured.linkCentre),
        'the icon must be centred on the number, not pinned to the top of a 44px target',
    ).toBeLessThanOrEqual(2);
});
