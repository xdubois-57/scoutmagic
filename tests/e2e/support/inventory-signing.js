// Shared end-to-end helper: sign an inventory on its own screen (#989,
// lot E; modules/rental/views/management/booking_inventory_sign.html.twig).
//
// « Signer l'état des lieux… » on « État des lieux » leads to a screen the
// renter and the manager sign on, in one form: the renter as a witness —
// their typed name and a stroke in their frame — unless they are absent or
// refuse, which sets their frame aside and shows a warning; the manager
// with the signature their account keeps, or one traced there. Sent, it
// comes back to « État des lieux » with the flash.
import { expect } from '@playwright/test';

/**
 * A stroke across a pad, the way a finger or a mouse draws one.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} canvas
 */
export async function traceSignature(page, canvas) {
    await canvas.scrollIntoViewIfNeeded();
    const box = await canvas.boundingBox();
    expect(box, 'the pad is on screen').not.toBeNull();
    const at = (/** @type {number} */ x, /** @type {number} */ y) => [box.x + box.width * x, box.y + box.height * y];
    await page.mouse.move(...at(0.2, 0.6));
    await page.mouse.down();
    await page.mouse.move(...at(0.5, 0.3), { steps: 8 });
    await page.mouse.move(...at(0.8, 0.6), { steps: 8 });
    await page.mouse.up();
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label the link on « État des lieux »
 * @param {{ renter: string } | { renterChoice: 'Le locataire est absent' | 'Le locataire refuse de signer' }} who
 *        the renter's typed name when they sign here, or the choice that sets them aside
 */
export async function signInventory(page, label, who) {
    const inventory = page.url().split(/[?#]/)[0];
    await page.getByRole('link', { name: label }).click();
    await page.waitForURL(/\/etat-des-lieux\/signer\?phase=(arrival|departure)$/, { waitUntil: 'load' });

    const form = page.locator('form[data-inventory-signing-form]');
    const renterFrame = form.locator('fieldset[data-renter-signature]');
    const warning = form.locator('[data-renter-unsigned-warning]');
    if ('renter' in who) {
        await expect(warning).toBeHidden();
        await renterFrame.getByLabel('Nom de la personne qui signe').fill(who.renter);
        await traceSignature(page, renterFrame.locator('canvas[data-signature-pad]'));
    } else {
        await form.getByLabel(who.renterChoice).check();
        await expect(warning).toBeVisible();
        // Set aside by its disabled fieldset: the required name with it.
        await expect(renterFrame.getByLabel('Nom de la personne qui signe')).toBeDisabled();
    }

    // The manager: the account's signature, previewed from the owner's
    // route and never written into the page — or a stroke of their own.
    const managerFrame = form.locator('[data-signature-field]').filter({ hasNot: page.locator('[name^="renter_"]') });
    if (await managerFrame.getAttribute('data-signature-mode') === 'stored') {
        await expect(managerFrame.locator('[data-signature-preview] img'))
            .toHaveAttribute('src', '/account/signature/image');
    } else {
        await traceSignature(page, managerFrame.locator('canvas[data-signature-pad]'));
    }

    await form.getByRole('button', { name: 'Valider, signer et envoyer' }).click();
    await page.waitForURL(inventory, { waitUntil: 'load' });
    await expect(page.getByRole('alert').filter({ hasText: 'signé et rangé dans les documents' })).toHaveCount(1);
}
