// End-to-end: in the installed application, a navigation never ends on a
// file — in a real browser, through the real application (issue #502).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The installed window has no address bar and no back button. A
// navigation that ended on a file used to leave it there, and on iOS the
// only way out was to kill the app. The fix is a chain no unit test
// crosses end to end: public/assets/js/display-mode.js writes a cookie,
// the browser sends it with a NAVIGATION (Sec-Fetch-Dest: document), a
// controller answers a file — through a plain link, a page-header button
// and a form POST — and Core\File\Held\InstalledAppFileInterceptor, at the
// tail of public/index.php, puts it aside and answers the viewer page.
// PHPUnit sees each link of that chain; only a browser sees them joined.
//
// THE INSTALLED APPLICATION, SIMULATED
// ----------------------------------------------------------------------------
// Chromium cannot be put in `display: standalone`, so the page is made to
// believe it is: `matchMedia('(display-mode: standalone)')` answers true
// before any script runs. Everything downstream — the cookie, the
// navigation headers, the server's decision — is real. What this does NOT
// reproduce is Safari's own behaviour in the installed window, which is
// why the ticket also asks for a check on a real iPhone.
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text (docs/developpement.md § Tests de bout en bout).
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from '../support/admin-login.js';

function isoDaysFromNow(days) {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return date.toISOString().slice(0, 10);
}

/** @param {import('@playwright/test').Page} page */
async function pretendInstalled(page) {
    await page.addInitScript(() => {
        const original = window.matchMedia.bind(window);
        window.matchMedia = (query) => (query === '(display-mode: standalone)'
            ? /** @type {MediaQueryList} */ ({
                matches: true,
                media: query,
                onchange: null,
                addEventListener() {},
                removeEventListener() {},
                addListener() {},
                removeListener() {},
                dispatchEvent() { return false; },
            })
            : original(query));
    });
}

/**
 * The viewer, and not a file: a page, with its three ways on.
 *
 * @param {import('@playwright/test').Page} page
 * @param {RegExp} name
 */
async function expectViewer(page, name) {
    await expect(page.getByRole('heading', { name: 'Document prêt' })).toBeVisible();
    await expect(page.getByText(name)).toBeVisible();
    await expect(page.getByRole('link', { name: 'Ouvrir dans le navigateur' })).toHaveAttribute('href', /^\/document\/[a-f0-9]{64}$/);
    await expect(page.getByRole('link', { name: 'Télécharger' })).toHaveAttribute('href', /^\/document\/telecharger\/[a-f0-9]{64}$/);
}

test.describe('Installed application', () => {
    test('a PDF, an export and a form-generated document all reach the viewer, and Retour comes back', async ({ page }) => {
        await pretendInstalled(page);
        await loginAsAdmin(page);

        // --- A page-header button: the trombinoscope PDF. ---
        await page.goto('/trombinoscope');
        await expect.poll(async () => (await page.context().cookies()).find((c) => c.name === 'sm_display')?.value)
            .toBe('standalone');
        await page.getByRole('link', { name: 'Télécharger le PDF' }).click();
        await expectViewer(page, /\.pdf$/);

        // « Ouvrir dans le navigateur » serves the file once, without the
        // app's session — the phone's browser has none — and never again.
        const browserHref = await page.getByRole('link', { name: 'Ouvrir dans le navigateur' }).getAttribute('href');
        const outsider = await page.context().browser().newContext();
        const first = await outsider.request.get(new URL(browserHref, page.url()).href);
        expect(first.status()).toBe(200);
        expect(first.headers()['content-type']).toBe('application/pdf');
        expect((await outsider.request.get(new URL(browserHref, page.url()).href)).status()).toBe(404);
        await outsider.close();

        await page.getByRole('link', { name: 'Retour' }).click();
        await expect(page).toHaveURL(/\/trombinoscope$/);

        // --- A plain link to a route that generates a file: an export. ---
        await page.goto('/admin/members?q=Powell');
        await page.getByRole('link', { name: 'Exporter les résultats' }).click();
        await expectViewer(page, /\.xlsx$/);
        await page.getByRole('link', { name: 'Retour' }).click();
        await expect(page).toHaveURL(/\/admin\/members\?q=Powell/);

        // --- A form POST: the parental authorisation, which cannot be
        //     asked for again — the reason the file is kept aside. ---
        await page.goto('/');
        const memberHref = await page.locator('a[href^="/members/"]').evaluateAll((links) => links
            .map((link) => link.getAttribute('href'))
            .find((href) => /^\/members\/\d+$/.test(href || '')));
        expect(memberHref, 'The signed-in member\'s own page is linked from the home page.').toBeTruthy();
        await page.goto(`${memberHref}/autorisation-parentale`);
        await page.getByRole('textbox', { name: 'Du', exact: true }).fill(isoDaysFromNow(30));
        await page.getByRole('textbox', { name: 'Au', exact: true }).fill(isoDaysFromNow(33));
        await page.getByRole('textbox', { name: 'Fait à', exact: true }).fill('Namur');
        await page.getByRole('button', { name: 'Télécharger le PDF' }).click();
        await expectViewer(page, /autorisation-parentale-\d+\.pdf/);
        await page.getByRole('link', { name: 'Retour' }).click();
        await expect(page).toHaveURL(/\/autorisation-parentale$/);
    });

    test('a browser tab still gets the file itself', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/trombinoscope');

        const download = page.waitForEvent('download');
        await page.getByRole('link', { name: 'Télécharger le PDF' }).click();

        expect((await download).suggestedFilename()).toMatch(/\.pdf$/);
    });
});
