// End-to-end: « Courrier entrant », the configuration a unit does once
// (#1333, lot B).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Adding an address is now one form and one button: « Vérifier et
// enregistrer » opens a real IMAP connection before anything is written,
// and an address that does not connect is not saved. No PHPUnit test sees
// that path whole — the browser posting the form, the composition root's
// client factory, the library's TLS handshake against a certificate the
// application really verifies, and the redirect back to the list. And the
// page it replaced (« Portée ») is gone: a unit sets its general address
// and a reserved one without ever being sent to a second screen.
//
// The IMAP server is scripts/e2e-imap.php, started by scripts/e2e.sh on
// E2E_IMAP_PORT with a throwaway certificate the application server trusts.
// It refuses the password « refuse » and accepts any other.
//
// ORDERING
// ----------------------------------------------------------------------------
// The two addresses are deleted at the end, so no later spec meets a mailbox
// it did not expect.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { autoConfirm } from '../support/confirm-dialog.js';

const GENERAL = 'contact@unite-e2e.test';
const RESERVED = 'locations@unite-e2e.test';

/**
 * Fill the connection half of the form: the provider of these test
 * addresses is unknown, so the three settings are typed.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} address
 * @param {string} password
 */
async function fillConnection(page, address, password) {
    const port = process.env.E2E_IMAP_PORT;
    if (!port) {
        throw new Error('E2E_IMAP_PORT is not set: run this through scripts/e2e.sh, which starts the IMAP server.');
    }

    await page.getByLabel('Adresse e-mail').fill(address);
    await page.getByLabel('Adresse e-mail').blur();
    // An address the site does not recognise leaves the settings empty, and says so.
    await expect(page.locator('#mailbox-provider-unknown')).toBeVisible();
    await expect(page.getByLabel('Serveur IMAP')).toHaveValue('');

    await page.getByLabel("Mot de passe d'application").fill(password);
    await page.getByLabel('Serveur IMAP').fill('localhost');
    await page.locator('#mailbox-port').fill(port);
    await page.getByLabel('Chiffrement').selectOption('ssl');
}

test('a first general address, then one reserved to the Locations, verified before they are saved', async ({ page }) => {
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await autoConfirm(page);
    await loginAsAdmin(page);
    await answerCookieBanner(page);

    await page.goto('/config/courrier-entrant');
    await expect(page.getByRole('heading', { level: 1, name: 'Courrier entrant' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Comment ça marche' })).toHaveAttribute('href', '/aide/courrier-entrant');

    // ── The first address: proposed as the unit's general mail (D6) ─────
    await page.getByRole('link', { name: 'Ajouter une adresse' }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'Ajouter une adresse' })).toBeVisible();
    await expect(page.locator('#mailbox-purpose-shared')).toBeChecked();
    await expect(page.locator('#mailbox-module-field')).toBeHidden();

    // A password the server refuses: nothing is saved, and the form comes
    // back filled as typed — all but the password (D10).
    await fillConnection(page, GENERAL, 'refuse');
    await page.getByRole('button', { name: 'Vérifier et enregistrer' }).click();
    await expect(page.locator('#mailbox-failure')).toContainText("La connexion a échoué. Rien n'est enregistré.");
    await expect(page.getByLabel('Adresse e-mail')).toHaveValue(GENERAL);
    await expect(page.getByLabel('Serveur IMAP')).toHaveValue('localhost');
    await expect(page.getByLabel("Mot de passe d'application")).toHaveValue('');

    // « Dossiers lus » lists what the server has, without its junk folder,
    // and names the sent folder it marks.
    await page.getByLabel("Mot de passe d'application").fill('bon-mot-de-passe');
    await page.getByText('Dossiers lus (facultatif)').click();
    await expect(page.locator('#mailbox-folders-list').getByLabel('Factures')).toBeVisible();
    await expect(page.locator('#mailbox-folders-list')).not.toContainText('Indesirables');
    await expect(page.locator('#mailbox-sent-found')).toContainText('Trouvé automatiquement : Envoyes');

    await page.getByRole('button', { name: 'Vérifier et enregistrer' }).click();
    await expect(page).toHaveURL(/\/config\/courrier-entrant$/);
    await expect(page.getByText('Adresse vérifiée et enregistrée.')).toBeVisible();
    const general = page.locator('.list-group-item').filter({ hasText: GENERAL });
    await expect(general).toContainText("Courrier général de l'unité");
    await expect(general).toContainText('Pas encore relevée');

    // ── The second: proposed as reserved, to a module that has none (D5, D6)
    await page.getByRole('link', { name: 'Ajouter une adresse' }).first().click();
    await expect(page.locator('#mailbox-purpose-dedicated')).toBeChecked();
    await expect(page.locator('#mailbox-module-field')).toBeVisible();
    await fillConnection(page, RESERVED, 'un-autre-mot-de-passe');
    await page.getByLabel('Module', { exact: true }).selectOption({ label: 'Locations' });
    await page.getByRole('button', { name: 'Vérifier et enregistrer' }).click();

    await expect(page).toHaveURL(/\/config\/courrier-entrant$/);
    await expect(page.locator('.list-group-item').filter({ hasText: RESERVED })).toContainText('Réservée au module « Locations »');

    // The Locations now have their address: a third form does not offer them.
    await page.getByRole('link', { name: 'Ajouter une adresse' }).first().click();
    await expect(page.getByLabel('Module', { exact: true }).locator('option', { hasText: 'Locations' })).toHaveCount(0);
    await page.goto('/config/courrier-entrant');

    // No « Portée » anywhere: the usage chosen in the form is all there is.
    await expect(page.locator('body')).not.toContainText('Portée');
    await expect(page.locator('a[href*="/portee"]')).toHaveCount(0);

    // ── Clean up for the specs after this one ───────────────────────────
    for (const address of [RESERVED, GENERAL]) {
        await page.locator('.list-group-item').filter({ hasText: address }).getByRole('button', { name: 'Supprimer' }).click();
        await expect(page.locator('.list-group-item').filter({ hasText: address })).toHaveCount(0);
    }

    expect(pageErrors).toEqual([]);
});
