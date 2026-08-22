// End-to-end: Config Desk — the page that configures the RBAC itself.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// /config/functions is where a Desk function is bound to a site role,
// which makes it the configuration surface of the entire access model
// (ARCHITECTURE.md §3). Every control on it auto-saves through its own
// hand-built fetch — role select, section name/email on blur, visibility
// switch, colour picker, branch URL — with no submit button anywhere and
// no Vitest file behind any of it: a CSP regression or a renamed endpoint
// silences the page while every other suite stays green.
//
// The scenario's centrepiece chains two promises across two browsers:
// assigning a function a higher role must take effect on the MEMBER'S
// VERY NEXT REQUEST (Core\Security\SessionRevalidator re-resolves the
// role per request — a promotion or demotion never waits out a session),
// and taking it back must slam the door again. RBAC config → live
// session, with no logout in between.
//
// ORDERING
// ----------------------------------------------------------------------------
// Everything is restored — the member's function role, the section's
// name and visibility — because every later spec relies on the seeded
// shape (Kaa resolved to `identified`, a visible "Meute E2E").
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin, loginAsMember } from '../support/admin-login.js';

const SECTION_RENAMED = 'Meute E2E (renommée)';

/**
 * Wait for one of the page's auto-save fetches to answer, by endpoint
 * suffix — every control here saves through its own URL.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} endpoint
 */
function nextSave(page, endpoint) {
    return page.waitForResponse((response) => response.url().includes(endpoint));
}

test('config desk auto-saves roles and sections, and a role change reaches the member\'s live session at once', async ({ page, browser }) => {
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
    await page.goto('/config/functions', { waitUntil: 'load' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // The member's own live session, held open across the role change.
    const memberContext = await browser.newContext();
    try {
        const memberPage = await memberContext.newPage();
        await loginAsMember(memberPage);
        await answerCookieBanner(memberPage);

        // As provisioned: `identified` stops at the intendant boundary.
        await memberPage.goto('/chefs/staffs', { waitUntil: 'domcontentloaded' });
        await expect(memberPage.getByRole('heading', { level: 1, name: 'Staffs' })).toHaveCount(0);

        // ---------------------------------------------------------------
        // Promote the member's function ('Animé', E2E-FCT) to intendant —
        // one change event, one fetch, no button.
        // ---------------------------------------------------------------
        const roleSelect = page.getByLabel('Rôle pour E2E-FCT');
        await expect(roleSelect).toHaveValue('identified');

        const promote = nextSave(page, '/config/functions/update');
        await roleSelect.selectOption('intendant');
        expect((await promote).ok()).toBe(true);

        // The member's NEXT request already carries the new role: the
        // intendant page opens, without any re-login.
        await memberPage.goto('/chefs/staffs', { waitUntil: 'domcontentloaded' });
        await expect(memberPage.getByRole('heading', { level: 1, name: 'Staffs' })).toBeVisible();

        // ---------------------------------------------------------------
        // And back. The demotion lands on the next click just as fast.
        // ---------------------------------------------------------------
        const demote = nextSave(page, '/config/functions/update');
        await page.getByLabel('Rôle pour E2E-FCT').selectOption('identified');
        expect((await demote).ok()).toBe(true);

        await memberPage.goto('/chefs/staffs', { waitUntil: 'domcontentloaded' });
        await expect(
            memberPage.getByRole('heading', { level: 1, name: 'Staffs' }),
            'a demotion must not wait for the session to expire',
        ).toHaveCount(0);
    } finally {
        await memberContext.close();
    }

    // ---------------------------------------------------------------
    // Section name: saved on blur, visible on the public /sections page,
    // then restored the same way.
    // ---------------------------------------------------------------
    const nameField = page.getByLabel('Nom de la section E2E-SEC');
    await expect(nameField).toHaveValue('Meute E2E');

    const rename = nextSave(page, '/config/functions/section-name');
    await nameField.fill(SECTION_RENAMED);
    await nameField.blur();
    expect((await rename).ok()).toBe(true);

    const visitor = await browser.newContext();
    try {
        const visitorPage = await visitor.newPage();
        await visitorPage.goto('/sections', { waitUntil: 'domcontentloaded' });
        await expect(visitorPage.getByText(SECTION_RENAMED)).toBeVisible();

        // ---------------------------------------------------------------
        // Visibility: switched off, the section leaves the public page;
        // switched back on, it returns. The switch is addressed inside
        // the section's own row (.section-row[data-id] — the id the
        // page's script posts, a contract of the template).
        // ---------------------------------------------------------------
        const sectionSwitch = page.locator('.section-row', { has: page.getByLabel('Nom de la section E2E-SEC') })
            .locator('.section-visible-input');

        const hide = nextSave(page, '/config/functions/section-visibility');
        await sectionSwitch.uncheck();
        expect((await hide).ok()).toBe(true);

        await visitorPage.goto('/sections', { waitUntil: 'domcontentloaded' });
        await expect(visitorPage.getByText(SECTION_RENAMED)).toHaveCount(0);

        const show = nextSave(page, '/config/functions/section-visibility');
        await sectionSwitch.check();
        expect((await show).ok()).toBe(true);

        await visitorPage.goto('/sections', { waitUntil: 'domcontentloaded' });
        await expect(visitorPage.getByText(SECTION_RENAMED)).toBeVisible();
    } finally {
        await visitor.close();
    }

    // Restore the name.
    const restore = nextSave(page, '/config/functions/section-name');
    await page.getByLabel('Nom de la section E2E-SEC').fill('Meute E2E');
    await page.getByLabel('Nom de la section E2E-SEC').blur();
    expect((await restore).ok()).toBe(true);

    // ---------------------------------------------------------------
    // Colour: picked, then reset to the default through its own button.
    // ---------------------------------------------------------------
    const colourInput = page.getByLabel('Couleur de la section').first();
    const paint = nextSave(page, '/config/functions/section-color');
    await colourInput.evaluate((node) => {
        /** @type {HTMLInputElement} */ (node).value = '#3a7d44';
        node.dispatchEvent(new Event('change', { bubbles: true }));
    });
    expect((await paint).ok()).toBe(true);

    const unpaint = nextSave(page, '/config/functions/section-color');
    await page.getByLabel('Revenir à la couleur par défaut').first().click();
    expect((await unpaint).ok()).toBe(true);

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
