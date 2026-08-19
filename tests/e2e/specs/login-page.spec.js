// End-to-end: the public login page boots and renders in a real browser.
//
// A second public, unauthenticated route (`GET /login`, role_min: public,
// Core\Http\Controller\AuthController::login()) — deliberately not the same
// controller/service wiring as the home page scenario, so this catches a
// boot defect specific to AuthController's own composition (CsrfGuard,
// LastLoginMethodCookie, HumanCheck) rather than re-proving the same
// bootstrap path public-home-page.spec.js already covers.
//
// Assertions are semantic (labelled form controls, button names, the
// document title) — never CSS/structural selectors.
import { expect, test } from '@playwright/test';

test('the public login page boots through public/index.php and renders its form in a browser', async ({ page }) => {
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

    const response = await page.goto('/login', { waitUntil: 'domcontentloaded' });

    expect(response, 'the browser received no response for GET /login').not.toBeNull();
    expect(response.status(), 'GET /login must return HTTP 200').toBe(200);

    await expect(page).toHaveTitle('Se connecter — Unité de test E2E');
    await expect(page.getByRole('heading', { level: 1, name: 'Se connecter' })).toBeVisible();

    // The magic-link tab is the server-rendered default (AuthController::
    // login()'s default_login_method falls back to 'magic-link' whenever
    // no last_login_method cookie exists — always true for this freshly
    // provisioned, never-visited instance). Its email input is the only
    // one of the three tabs' email fields with a real <label for>
    // association (the password tab's label lacks a `for` attribute), so
    // this also incidentally proves accessible-name wiring rather than
    // relying on visibility alone.
    await expect(page.getByLabel('Adresse email')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Envoyer le lien de connexion' })).toBeVisible();

    // The mandatory RGPD consent link — real content from a real route
    // (core/View/RgpdContentService), not a static string in this template.
    await expect(page.getByRole('link', { name: 'politique de protection des données' })).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
