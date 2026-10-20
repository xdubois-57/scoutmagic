// End-to-end support: what a rental contract cannot be generated without.
//
// Annex 8.1 of the Walloon tourism code requires a camp-site rental
// contract to state the renter's address and the address of the asset let,
// and the site refuses to generate one without them (issue #977). Every
// rental spec that presses « Générer le contrat » therefore gives both
// first, the way a unit and a renter give them — never by weakening the
// guard.
import { expect } from '@playwright/test';

import { openSectionEditor } from './section-editor.js';

/**
 * Writes the asset's address in the « Contrat » section of its settings.
 *
 * @param {import('@playwright/test').Page} page a manager of the asset
 * @param {string} slug
 * @param {string} address
 */
export async function giveAssetAddress(page, slug, address) {
    await page.goto(`/mes-locations/${slug}/reglages`, { waitUntil: 'load' });
    const dialog = await openSectionEditor(page, 'contrat-edit');
    await dialog.locator('textarea[name="asset_address"]').fill(address);
    await dialog.getByRole('button', { name: 'Enregistrer le contrat' }).click();
    await expect(page.locator('#contrat')).toContainText(address);
}

/**
 * Gives the renter's address — and the group's responsible person on site —
 * from their own tracking page, in « Vos coordonnées de facturation »,
 * which arrives open while it is empty.
 *
 * @param {import('@playwright/test').Page} renter the renter's tracking page
 * @param {string} address
 */
export async function giveRenterAddress(renter, address) {
    const card = renter.locator('#facturation');
    await card.locator('input[name="billing_address"]').fill(address);
    await card.locator('input[name="on_site_contact_name"]').fill('Paul Lemaire');
    await card.locator('input[name="on_site_contact_phone"]').fill('0470 98 76 54');
    await card.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(renter.locator('#facturation')).toContainText('Enregistrées');
}
