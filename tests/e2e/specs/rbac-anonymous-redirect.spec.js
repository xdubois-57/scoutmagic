// End-to-end: the RBAC guard redirects an anonymous visitor away from a
// protected route, in a real browser.
//
// core/Security/RbacGuard::enforce() is the single chokepoint every
// `role_min`-gated route goes through — AGENTS.md requires it be called
// by the Router, never a Controller, specifically so this behavior cannot
// be forgotten per-route. PHPUnit already covers RbacGuard's own decision
// table in isolation (tests/Core/Security/RbacGuardTest.php); what only a
// real browser proves is that a genuine 302 response actually carries the
// visitor to a real, rendering /login page — not just that the right
// status/header pair was returned by a unit under test.
//
// /account (role_min: identified, Core\Http\Controller\AccountController)
// is used here only as a stand-in gated route: this scenario is about the
// guard's redirect behavior, not about the account page itself.
import { expect, test } from '@playwright/test';

test('an anonymous visitor requesting a role-gated page lands on the real login page', async ({ page }) => {
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

    await page.goto('/account', { waitUntil: 'domcontentloaded' });

    // The redirect itself: RbacGuard::enforce() returns a 302 to /login
    // for an unauthenticated visitor, never a 403 (403 is reserved for an
    // authenticated visitor with an insufficient role) and never a silent
    // fall-through to the gated page's own content.
    await expect(page).toHaveURL(/\/login$/);
    await expect(page).toHaveTitle('Se connecter — Unité de test E2E');
    await expect(page.getByRole('heading', { level: 1, name: 'Se connecter' })).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
