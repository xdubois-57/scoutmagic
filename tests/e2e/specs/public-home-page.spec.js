// End-to-end: the public home page boots and renders in a real browser.
//
// This is the foundational E2E scenario (see README.md § Tests de bout en
// bout for the full current list) — the first test in this repository
// that answers "can the application actually start and serve a page to a
// browser?", a question neither PHPUnit nor Vitest can answer:
//
//   - PHPUnit constructs controllers and services directly, one at a
//     time, with hand-written arguments. It never executes public/index.php,
//     so it cannot see a broken composition root. That exact failure has
//     already shipped once: a controller's constructor lost a parameter,
//     every test was updated and green, and the one call site nobody
//     updated was index.php's wiring — a TypeError on literally every
//     request (AGENTS.md § Static analysis).
//   - Vitest runs first-party browser JavaScript against a jsdom DOM,
//     with no PHP, no database, no HTTP.
//
// So this scenario exercises the real path end to end: `php -S` over the
// real public/index.php, the real configuration/secrets loading, the real
// MySQL connection, the real schema-migration check, the real module
// registry, the real router and RBAC guard on a `role_min: public` route,
// the real Twig rendering, and a real Chromium parsing the result.
//
// Assertions are semantic (roles, accessible names, the document title) —
// never CSS/structural selectors, which would break on any Bootstrap
// markup reshuffle without a single user-visible thing having changed.
import { expect, test } from '@playwright/test';

test('the public home page boots through public/index.php and renders in a browser', async ({ page }) => {
    // Anything the application itself got wrong in the browser: an
    // uncaught exception in first-party JavaScript, or a same-origin
    // response the server should never produce. Third-party console noise
    // is deliberately not collected — this instance loads nothing the
    // application does not control, and failing on generic console
    // messages is how an E2E gate becomes a flaky one.
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

    const response = await page.goto('/', { waitUntil: 'domcontentloaded' });

    // The navigation itself: a 5xx here means the bootstrap threw before
    // producing a page at all, which is precisely the class of failure
    // this test exists for.
    expect(response, 'the browser received no response for GET /').not.toBeNull();
    expect(response.status(), 'GET / must return HTTP 200').toBe(200);

    // Rendered by pages/home.html.twig's {% block title %}, interpolating
    // the site_name Twig global — which the composition root only has
    // once the database connection, the settings table, and SettingService
    // are all genuinely working. A hardcoded string could not prove that.
    await expect(page).toHaveTitle('Bienvenue — Unité de test E2E');

    // The page's own content, from Core\View\EditableContentService's
    // default for the 'home.intro' key.
    await expect(page.getByRole('heading', { level: 1, name: 'Bienvenue' })).toBeVisible();

    // base.html.twig's landmarks. <main> only exists if template
    // inheritance resolved at all. The primary navigation landmark is
    // matched by the menu it contains rather than by position: the layout
    // renders a second one (the breadcrumb bar), and "the first <nav> on
    // the page" is exactly the kind of incidental-structure assertion
    // that breaks on an unrelated layout change. Its "Notre unité" entry
    // is also the proof that MenuBuilder actually built the menu tree for
    // a public, logged-out visitor instead of throwing.
    await expect(page.getByRole('main')).toBeVisible();
    await expect(
        page.getByRole('navigation').filter({ hasText: 'Notre unité' }),
    ).toBeVisible();

    // The footer's RGPD link — a real, routable public URL rendered by the
    // shared layout, so a smoke test of the layout as a whole rather than
    // of the home page's own block alone.
    await expect(
        page.getByRole('contentinfo').getByRole('link', { name: 'Protection des données' }),
    ).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
