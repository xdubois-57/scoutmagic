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
import { pngBuffer } from './png.js';

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

    await signAsManager(page, form);

    await form.getByRole('button', { name: 'Valider, signer et envoyer' }).click();
    await page.waitForURL(inventory, { waitUntil: 'load' });
    await expect(page.getByRole('alert').filter({ hasText: 'signé et rangé dans les documents' })).toHaveCount(1);
}

/**
 * The manager's frame: the account's signature, previewed from the owner's
 * route and never written into the page — or a stroke of their own when
 * the account keeps none. Which one depends on what an earlier spec left
 * on the shared account, so a spec never assumes either.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} form the signing form
 */
export async function signAsManager(page, form) {
    const managerFrame = form.locator('[data-signature-field]').filter({ hasNot: page.locator('[name^="renter_"]') });
    if (await managerFrame.getAttribute('data-signature-mode') === 'stored') {
        await expect(managerFrame.locator('[data-signature-preview] img'))
            .toHaveAttribute('src', '/account/signature/image');
    } else {
        await traceSignature(page, managerFrame.locator('canvas[data-signature-pad]'));
    }
}

/**
 * « Signé sur papier » (lot F): both parties signed the printed sheet, so
 * nobody signs on screen — the photo of the sheet goes through the « + Photo »
 * tile, held back by the form until it is there.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} label the link on « État des lieux »
 */
export async function signInventoryOnPaper(page, label) {
    const inventory = page.url().split(/[?#]/)[0];
    await page.getByRole('link', { name: label }).click();
    await page.waitForURL(/\/etat-des-lieux\/signer\?phase=(arrival|departure)$/, { waitUntil: 'load' });

    const form = page.locator('form[data-inventory-signing-form]');
    await form.getByLabel('Signé sur papier').check();
    await expect(form.locator('fieldset[data-renter-signature]')).toBeHidden();
    await expect(form.locator('fieldset[data-manager-signature]')).toBeHidden();
    const sheet = form.locator('fieldset[data-paper-sheet]');
    await expect(sheet).toBeVisible();

    // Sent without the sheet: the form holds it and says why.
    await form.getByRole('button', { name: 'Valider, signer et envoyer' }).click();
    await expect(sheet.locator('[data-photo-status]')).toHaveText('Ajoutez la photo de la feuille signée.');
    expect(page.url()).toContain('/etat-des-lieux/signer');

    await sheet.locator('[data-photo-source="camera"]').setInputFiles({
        name: 'feuille.png', mimeType: 'image/png', buffer: pngBuffer(1200, 1600),
    });
    await expect(sheet.locator('[data-photo-pending]')).toHaveCount(1);

    await form.getByRole('button', { name: 'Valider, signer et envoyer' }).click();
    await page.waitForURL(inventory, { waitUntil: 'load' });
    await expect(page.getByRole('alert').filter({ hasText: 'signé sur papier et rangé dans les documents avec la photo de la feuille' }))
        .toHaveCount(1);
}
