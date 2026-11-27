// End-to-end: in the installed application, the privacy notice opens over
// the sign-in form instead of replacing it (issue #1431).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The installed window keeps this site's own links inside it whatever
// `target="_blank"` says, and has no back button: tapping « politique de
// protection des données » left the sign-in tab, and the address typed in
// it, behind for good. public/assets/js/document-reader.js opens the
// notice in the shared dialog of base.html.twig instead, from the real
// /rgpd page. Vitest proves the script against a stand-in page; only a
// browser proves the whole chain — the real page fetched, its marked
// block shown, Bootstrap's dialog, the focus, and the form untouched.
//
// THE INSTALLED APPLICATION, SIMULATED
// ----------------------------------------------------------------------------
// As in installed-app-document-viewer.spec.js: Chromium cannot be put in
// `display: standalone`, so `matchMedia('(display-mode: standalone)')`
// answers true before any script runs. What this does NOT reproduce is
// Safari's own handling of `target="_blank"` in the installed window,
// which is why the ticket also asks for a check on a real iPhone.
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text (docs/developpement.md § Tests de bout en bout).
import { test, expect } from '@playwright/test';
import { closeModal, openModal } from '../support/modal.js';

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

test.describe('Document reader', () => {
    test('in the installed app, the privacy notice opens over the sign-in form and closes back onto it', async ({ page }) => {
        /** @type {string[]} */
        const pageErrors = [];
        page.on('pageerror', (error) => pageErrors.push(error.message));
        await pretendInstalled(page);

        await page.goto('/login');
        await page.waitForLoadState('load');
        await page.getByRole('tab', { name: 'Mot de passe' }).click();
        const passwordTab = page.locator('#tab-password');
        await passwordTab.getByLabel(/^Adresse email(\s*\*)?$/).fill('famille.dupont@example.test');

        const dialog = await openModal(page, 'document-reader', () =>
            passwordTab.getByRole('link', { name: 'politique de protection des données' }).click());
        await expect(dialog.getByRole('heading', { name: 'Protection des données' })).toBeVisible();
        await expect(dialog.getByRole('heading', { name: /Qui sommes-nous/ })).toBeVisible();
        // The notice and nothing else of the /rgpd page: no second sign-in
        // form, navigation or footer inside the dialog.
        await expect(dialog.getByRole('navigation')).toHaveCount(0);
        await expect(dialog.getByRole('textbox')).toHaveCount(0);
        await expect(page).toHaveURL(/\/login$/);

        await closeModal(page, 'document-reader', () =>
            dialog.getByRole('button', { name: 'Fermer et revenir' }).click());

        // Still on the « Mot de passe » tab. The login page marks its active
        // tab with Bootstrap's class (and leaves aria-selected as rendered),
        // so that is what tells the tab was not reset.
        await expect(page.getByRole('tab', { name: 'Mot de passe' })).toHaveClass(/\bactive\b/);
        await expect(passwordTab).toBeVisible();
        await expect(passwordTab.getByLabel(/^Adresse email(\s*\*)?$/)).toHaveValue('famille.dupont@example.test');
        await expect(passwordTab.getByRole('checkbox', { name: /J'accepte la politique/ })).not.toBeChecked();
        await expect(passwordTab.getByRole('link', { name: 'politique de protection des données' })).toBeFocused();
        expect(pageErrors).toEqual([]);
    });

    test('in a browser tab, the same link opens a new tab and the form stays where it was', async ({ page, context }) => {
        await page.goto('/login');
        await page.waitForLoadState('load');
        const magicLinkTab = page.locator('#tab-magic-link');
        await magicLinkTab.getByLabel('Adresse email').fill('famille.dupont@example.test');

        const [notice] = await Promise.all([
            context.waitForEvent('page'),
            magicLinkTab.getByRole('link', { name: 'politique de protection des données' }).click(),
        ]);
        await notice.waitForLoadState();

        await expect(notice).toHaveURL(/\/rgpd$/);
        await expect(page).toHaveURL(/\/login$/);
        await expect(magicLinkTab.getByLabel('Adresse email')).toHaveValue('famille.dupont@example.test');
        await notice.close();
    });
});
