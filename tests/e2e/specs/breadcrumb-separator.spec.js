// End-to-end: the breadcrumb separator stays on the text's baseline on a
// touch device.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// It is a defect no unit test and no desktop screenshot can see. Bootstrap
// renders the « / » between crumbs as a FLOATED ::before, which is placed
// at the top of its crumb rather than on the text's baseline. That is
// invisible while every crumb is plain text of one height — and wrong the
// moment one is not.
//
// One is not: a `parents` entry that matches a menu renders as a real
// <button> (partials/breadcrumb_bar.html.twig), and app.css's touch
// baseline gives every .btn `min-height: 44px` under `(pointer: coarse)`.
// So on a phone that crumb's <li> is 44px tall while its neighbours are
// 24px, and the separator in front of it sat about ten pixels above the
// words it separates. Reported from a real phone; a mouse-driven browser
// matches `(pointer: fine)` and never shows it, which is exactly why the
// review pass missed it.
//
// The context below therefore emulates a touch device rather than merely
// a narrow window — `hasTouch` is what makes `(pointer: coarse)` match,
// and a viewport size alone would test nothing.
import { test, expect, devices } from '@playwright/test';
import { loginAsAdmin } from '../support/admin-login.js';
import { answerCookieBanner } from '../support/cookie-banner.js';

test('the breadcrumb separator sits on the baseline, not above a 44px crumb', async ({ browser }) => {
    const context = await browser.newContext({ ...devices['Pixel 5'] });
    const page = await context.newPage();

    // Signing in needs the desktop layout: loginAsAdmin() waits for the
    // logout control, which lives inside the drawer on a phone.
    await page.setViewportSize({ width: 1280, height: 900 });
    await loginAsAdmin(page);
    await answerCookieBanner(page, { accept: true });
    await page.setViewportSize({ width: 390, height: 844 });

    // A page whose breadcrumb has a menu parent, so one crumb is a button.
    await page.goto('/chefs/membres', { waitUntil: 'domcontentloaded' });

    const measured = await page.evaluate(() => {
        const items = [...document.querySelectorAll('.breadcrumb-bar .breadcrumb-item')];
        const parent = items.find((li) => li.querySelector('.breadcrumb-parent-btn'));

        return {
            coarse: matchMedia('(pointer: coarse)').matches,
            parentHeight: parent ? parent.getBoundingClientRect().height : null,
            separatorFloat: parent ? getComputedStyle(parent, '::before').float : null,
            separatorContent: parent ? getComputedStyle(parent, '::before').content : null,
        };
    });

    // The conditions that produced the bug are still real…
    expect(measured.coarse, 'the emulated device must have a coarse pointer').toBe(true);
    expect(measured.parentHeight, 'the touch baseline still inflates the crumb button').toBeGreaterThan(40);
    expect(measured.separatorContent).toContain('/');

    // …and the separator no longer floats out of the line box because of them.
    expect(
        measured.separatorFloat,
        'A floated separator is pinned to the top of a 44px crumb instead of its text.',
    ).toBe('none');
});
