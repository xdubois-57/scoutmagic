// Shared end-to-end helpers: sign in as one of the throwaway instance's
// two accounts — the super-admin, or the ordinary member — through the
// real login form.
//
// Not a shortcut around authentication — there is deliberately no
// "inject a session cookie" path here. The login goes through
// Core\Http\Controller\AuthController::loginWithPassword(), the real
// CSRF check, the real Core\Security\AuthService/PasswordAuthMethod, the
// real Core\Security\RoleResolver (which is what turns the super-admin
// row into the `superadmin` role that satisfies `role_min: admin`), and
// the real session. A scenario that needs an admin page therefore also
// proves the whole authentication path still works.
//
// The credentials are provisioned by scripts/e2e-support.php and handed
// down by scripts/e2e.sh through the environment — both passwords are
// generated fresh for every run, so nothing password-shaped lives in the
// repository.
import { expect } from '@playwright/test';

/**
 * The logout control the visitor can actually reach.
 *
 * partials/nav.html.twig renders it twice — once in the mobile drawer,
 * once on the desktop bar — and the drawer's copy comes FIRST in document
 * order while being hidden by CSS on a desktop viewport. A plain
 * `.first()` therefore points at a button nobody can click, and only
 * appears to work while the stylesheet that hides it has not applied yet:
 * a race that stays quiet on a fast run and fails on a slow one (it did,
 * under E2E_COVERAGE=1, which is how CI runs the suite). Filtering on
 * visibility asks for the one the viewport actually offers, whichever
 * that is, so the same call is right on a phone and on a desktop.
 *
 * @param {import('@playwright/test').Page} page
 */
export function logoutControl(page) {
    return page.getByRole('button', { name: 'Se déconnecter' }).filter({ visible: true });
}

/**
 * Sign in as whoever the two environment variables name — the shared half
 * of loginAsAdmin() and loginAsMember() below, which differ only in whose
 * credentials they read and in what they check afterwards.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} emailVariable
 * @param {string} passwordVariable
 */
async function loginWithPassword(page, emailVariable, passwordVariable) {
    const email = process.env[emailVariable];
    const password = process.env[passwordVariable];

    if (!email || !password) {
        throw new Error(
            `${emailVariable} / ${passwordVariable} are not set. Run the end-to-end `
            + 'tests via `npm run e2e` (scripts/e2e.sh), which provisions both '
            + 'accounts and exports their credentials.',
        );
    }

    await page.goto('/login', { waitUntil: 'domcontentloaded' });

    // Three tabs (magic link, password, passkey) each carry their own
    // "Adresse email" field, so every locator below is scoped to the
    // password tab's panel rather than matched page-wide. The panel id is
    // the same hook public/assets/js/auth.js itself uses to show and hide
    // the tab, not incidental markup.
    const passwordTab = page.locator('#tab-password');

    await page.getByRole('tab', { name: 'Mot de passe' }).click();

    // exact: the "forgot password" sub-form in this same panel is labelled
    // "Adresse email du compte", which a substring match would also hit.
    await passwordTab.getByLabel('Adresse email', { exact: true }).fill(email);
    await passwordTab.getByLabel('Mot de passe', { exact: true }).fill(password);
    // Mandatory RGPD consent — the server refuses the login without it
    // (AuthController::hasRgpdConsent()), so this is part of the flow, not
    // an optional nicety.
    await passwordTab.getByRole('checkbox').check();

    await passwordTab.getByRole('button', { name: 'Se connecter' }).click();

    // auth.js redirects to / on success and reveals an inline error
    // otherwise. Waiting on the redirect (rather than on the fetch) is
    // what makes a failed login fail here, loudly, instead of surfacing
    // three assertions later as a puzzling 302 to /login.
    await page.waitForURL('**/', { waitUntil: 'domcontentloaded' });

    // Proof that a session was really established, rather than merely
    // that the browser navigated somewhere: partials/nav.html.twig offers
    // the logout control to an authenticated visitor and to nobody else.
    // Failing here, in the helper, beats letting a silently-anonymous
    // session surface three assertions later as a puzzling redirect back
    // to /login.
    await expect(logoutControl(page)).toBeVisible();
}

/**
 * @param {import('@playwright/test').Page} page
 */
export async function loginAsAdmin(page) {
    await loginWithPassword(page, 'E2E_ADMIN_EMAIL', 'E2E_ADMIN_PASSWORD');

    // Core\View\MenuBuilder only emits the "Espace chefs d'U" menu for a
    // visitor whose resolved role reaches `admin` — so this checks not
    // just that the session exists but that it really carries the admin
    // role, which is what lets a scenario reach a `role_min: admin` page.
    await expect(page.getByRole('button', { name: "Espace chefs d'U" })).toBeVisible();
}

/**
 * Sign in as the ordinary member — no super-admin flag, and a function
 * whose own role is `identified` (see e2e_seed_section_with_both_members()
 * in scripts/e2e-support.php), so Core\Security\RoleResolver resolves
 * them to `identified`: the role most of a real unit has. Asserting the
 * ABSENCE of the admin menu is
 * what keeps this helper honest: a scenario that means "an ordinary
 * member sees this" must not quietly be running as an administrator.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function loginAsMember(page) {
    await loginWithPassword(page, 'E2E_MEMBER_EMAIL', 'E2E_MEMBER_PASSWORD');

    await expect(page.getByRole('button', { name: "Espace chefs d'U" })).toHaveCount(0);
}
