// Shared end-to-end helper: sign in as the throwaway instance's
// super-admin through the real login form.
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
// down by scripts/e2e.sh through the environment — the password is
// generated fresh for every run, so nothing password-shaped lives in the
// repository.
import { expect } from '@playwright/test';

/**
 * @param {import('@playwright/test').Page} page
 */
export async function loginAsAdmin(page) {
    const email = process.env.E2E_ADMIN_EMAIL;
    const password = process.env.E2E_ADMIN_PASSWORD;

    if (!email || !password) {
        throw new Error(
            'E2E_ADMIN_EMAIL / E2E_ADMIN_PASSWORD are not set. Run the end-to-end '
            + 'tests via `npm run e2e` (scripts/e2e.sh), which provisions the '
            + 'super-admin account and exports its credentials.',
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

    // Core\View\MenuBuilder only emits the "Espace chefs d'U" menu for a
    // visitor whose resolved role reaches `admin` — proof the session was
    // really established *and* really carries the admin role, rather than
    // merely that the browser navigated somewhere. Failing here, in the
    // helper, beats letting a silently-anonymous session surface three
    // assertions later as a puzzling redirect back to /login.
    await expect(page.getByRole('button', { name: "Espace chefs d'U" })).toBeVisible();
}
