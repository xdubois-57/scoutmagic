// End-to-end: « Ma signature » records a signature through a dialog
// (issue #1356), in a real browser through the real application.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The form is now present in the page but hidden inside a dialog, so a
// search of the HTML proves nothing about what a visitor sees: only a
// browser tells a hidden form from a visible one, draws on a pad that was
// hidden at load, and shows that « Annuler » wrote nothing. No other spec
// covers /account/signature.
//
// The ordinary member is used, not the administrator: rental-journey
// relies on the manager's account keeping no signature.
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text (docs/developpement.md § Tests de bout en bout);
// the dialog through support/modal.js, which waits for Bootstrap.
import { test, expect } from '@playwright/test';
import { loginAsMember } from '../support/admin-login.js';
import { closeModal, openModal } from '../support/modal.js';

/**
 * Traces a stroke across the pad, as a finger or a mouse would.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} pad
 */
async function trace(page, pad) {
    const box = await pad.boundingBox();
    if (box === null) {
        throw new Error('the pad is not on screen');
    }
    await page.mouse.move(box.x + box.width * 0.2, box.y + box.height * 0.5);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.5, box.y + box.height * 0.3, { steps: 5 });
    await page.mouse.move(box.x + box.width * 0.8, box.y + box.height * 0.6, { steps: 5 });
    await page.mouse.up();
}

test('the signature is recorded through a dialog, and cancelling it writes nothing', async ({ page, context }) => {
    /** @type {string[]} */
    const failures = [];
    context.on('weberror', (error) => failures.push(`uncaught: ${error.error().message}`));
    context.on('response', (response) => {
        if (response.status() >= 500) {
            failures.push(`HTTP ${response.status()} on ${response.url()}`);
        }
    });

    await loginAsMember(page);
    await page.goto('/account/signature');
    await page.waitForLoadState('load');

    // Before anything: the button, and no form on screen.
    const open = page.getByRole('button', { name: 'Enregistrer ma signature' });
    await expect(open).toBeVisible();
    await expect(page.getByRole('button', { name: 'Enregistrer la signature' })).toBeHidden();
    await expect(page.getByLabel('Ou importez une image de votre signature')).toBeHidden();

    // Drawn, then cancelled: nothing recorded.
    let dialog = await openModal(page, 'account-signature', () => open.click());
    await expect(dialog.getByRole('heading', { name: 'Enregistrer ma signature' })).toBeVisible();
    await trace(page, dialog.locator('canvas[data-signature-pad]'));
    await closeModal(page, 'account-signature', () => dialog.getByRole('button', { name: 'Annuler' }).click());
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Votre signature enregistrée' })).toHaveCount(0);

    // Drawn and saved: the page shows it, and the button now replaces it.
    dialog = await openModal(page, 'account-signature', () => page.getByRole('button', { name: 'Enregistrer ma signature' }).click());
    await trace(page, dialog.locator('canvas[data-signature-pad]'));
    await dialog.getByRole('button', { name: 'Enregistrer la signature' }).click();
    await expect(page.getByText('Votre signature est enregistrée.')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Votre signature enregistrée' })).toBeVisible();
    await expect(page.getByRole('img', { name: 'Votre signature enregistrée' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Remplacer ma signature' })).toBeVisible();

    // Reopened after a cancel, the dialog starts empty: sending it as it
    // stands is refused in place, and nothing is posted.
    dialog = await openModal(page, 'account-signature', () => page.getByRole('button', { name: 'Remplacer ma signature' }).click());
    await trace(page, dialog.locator('canvas[data-signature-pad]'));
    await closeModal(page, 'account-signature', () => dialog.getByRole('button', { name: 'Annuler' }).click());
    dialog = await openModal(page, 'account-signature', () => page.getByRole('button', { name: 'Remplacer ma signature' }).click());
    await dialog.getByRole('button', { name: 'Enregistrer la signature' }).click();
    await expect(dialog.getByText("Tracez votre signature ou importez-en l'image.")).toBeVisible();
    await closeModal(page, 'account-signature', () => dialog.getByRole('button', { name: 'Annuler' }).click());

    expect(failures).toEqual([]);
});

test('arriving from a document to sign opens the dialog by itself', async ({ page }) => {
    await loginAsMember(page);

    // The gesture that opens it is the arrival itself.
    const dialog = await openModal(page, 'account-signature', () =>
        page.goto('/account/signature?retour=' + encodeURIComponent('/account')));
    // « Enregistrer » or « Remplacer »: the scenario above leaves the
    // member a signature, and this one does not depend on running after it.
    await expect(dialog.getByRole('heading', { name: /^(Enregistrer|Remplacer) ma signature$/ })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Enregistrer la signature' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Continuer sans modifier ma signature' })).toHaveAttribute('href', '/account');
});
