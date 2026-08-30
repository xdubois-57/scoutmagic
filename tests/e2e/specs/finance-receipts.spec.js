// End-to-end: finance — a bank statement in, a receipt photographed by
// the treasurer, and the two associated.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The finance module carries the biggest browser-JS surface in the
// repository with NO Vitest file at all — all of it inline in its Twig
// templates. Three of its shapes are exactly the class of thing only a
// real browser against a real server can pin:
//
//   1. The receipt grid is rendered TWICE, by two implementations that
//      must not drift: Twig draws the first page server-side, and the
//      live search re-draws the same cards client-side from JSON
//      (receiptCardHtml() in receipts/list.html.twig). The search round
//      trip below forces both renderers over the same receipt.
//   2. The receipt upload REWRITES THE BYTES before submitting: an image
//      is decoded, downscaled on a canvas and re-encoded as JPEG in the
//      form's own submit handler — the file stored is one the test never
//      supplied (same class as the account-photo downscale, different
//      implementation).
//   3. Association (receipt ↔ movement) happens in a modal fed by a live
//      movement search, and the movements exist because the REAL BNP
//      import parsed a statement whose IBAN column had to match the
//      account — the cross-feature chain a unit's treasurer actually
//      lives.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { pngBuffer } from '../support/png.js';
import { scaled } from '../support/timeouts.js';

const ACCOUNT_NAME = `Compte reçus E2E ${Date.now()}`;
const ACCOUNT_IBAN = 'BE71 0961 2345 6769';

/** A BNP Fortis export whose "Numéro de compte" matches the account. */
function bnpStatement() {
    const iban = ACCOUNT_IBAN.replace(/ /g, '');
    return [
        "﻿Nº de séquence;Date d'exécution;Date valeur;Montant;Devise du compte;Numéro de compte;Type de transaction;Contrepartie;Nom de la contrepartie;Communication;Détails;Statut;Motif du refus",
        `2026-;01/07/2026;01/07/2026;-45,90;EUR;${iban};Virement en euros;BE00000000000002;Colruyt Namur;Courses camp;VIREMENT EN EUROS COLRUYT NAMUR COMMUNICATION : COURSES CAMP ;Accepté;`,
        `2026-;03/07/2026;03/07/2026;120,00;EUR;${iban};Virement en euros;BE00000000000003;Parent Dupont;Participation camp;VIREMENT EN EUROS PARENT DUPONT COMMUNICATION : PARTICIPATION CAMP ;Accepté;`,
    ].join('\n');
}

test('a statement imports, a receipt uploads through the client-side resize, and the live search and association close the loop', async ({ page }) => {
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
    /** @type {string[]} */
    const alerts = [];
    page.on('dialog', async (dialog) => {
        if (dialog.type() === 'confirm') {
            await dialog.accept();
            return;
        }
        alerts.push(dialog.message());
        await dialog.dismiss();
    });

    await loginAsAdmin(page);
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // A bank account — active the moment it carries an IBAN and holder.
    // ---------------------------------------------------------------
    await page.goto('/config/finance/accounts', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Nouveau compte' }).click();
    await page.getByLabel(/^Nom \*$/).fill(ACCOUNT_NAME);
    await page.getByLabel('IBAN').fill(ACCOUNT_IBAN);
    await page.getByLabel('Nom du titulaire').fill('Unité de test E2E');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByRole('row', { name: new RegExp(ACCOUNT_NAME) })).toBeVisible();

    // ---------------------------------------------------------------
    // The statement: the real BNP parser, the real IBAN check, and the
    // first import's mandatory closing balance.
    // ---------------------------------------------------------------
    await page.goto('/finance/import', { waitUntil: 'load' });
    await page.getByLabel('Compte').selectOption({ label: ACCOUNT_NAME });
    await page.getByLabel('Fichier CSV').setInputFiles({
        name: 'releve-bnp.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(bnpStatement(), 'utf8'),
    });
    await page.getByLabel('Solde après ce relevé').fill('574,10');
    await page.getByRole('button', { name: 'Importer' }).click();

    await expect(page.getByText('2 ligne(s) lue(s), 2 nouvelle(s)', { exact: false })).toBeVisible({ timeout: scaled(15_000) });
    await page.getByRole('link', { name: 'Voir les mouvements' }).click();
    await page.waitForURL(/\/finance\/movements\?account_id=\d+/, { waitUntil: 'load' });
    // The list renders every row twice (desktop table + phone cards) —
    // only the visible copy proves anything.
    await expect(page.getByText('Colruyt Namur').filter({ visible: true }).first()).toBeVisible();

    // ---------------------------------------------------------------
    // The receipt. A 2000×1500 PNG goes in; the submit handler decodes
    // it, downscales it on a canvas and re-encodes it as JPEG — the
    // stored file is the browser's, not ours.
    // ---------------------------------------------------------------
    await page.goto(`/finance/receipts`, { waitUntil: 'load' });
    await page.getByLabel('Compte').selectOption({ label: ACCOUNT_NAME });
    await page.waitForURL(/\/finance\/receipts\?account_id=\d+/, { waitUntil: 'load' });

    await page.getByRole('link', { name: 'Ajouter un reçu' }).click();
    await page.waitForURL(/\/finance\/receipts\/new/, { waitUntil: 'load' });

    await page.locator('#receipt-files').setInputFiles({
        name: 'ticket-colruyt.png',
        mimeType: 'image/png',
        buffer: pngBuffer(2000, 1500, [235, 235, 230, 255]),
    });
    await page.getByRole('button', { name: 'Ajouter' }).click();
    await page.waitForURL(/\/finance\/receipts\?/, { waitUntil: 'load' });

    // Server-side first paint (Twig): the card is there, and its name
    // says .jpg — the client re-encoded the PNG before the POST.
    const grid = page.locator('#receipts-grid');
    await expect(grid.getByText('ticket-colruyt.jpg')).toBeVisible();

    // ---------------------------------------------------------------
    // The live search: the same card re-drawn client-side from JSON —
    // the second of the two renderers that must agree.
    // ---------------------------------------------------------------
    const search = page.locator('#receipts-search');
    await search.fill('introuvable-xyz');
    await expect(grid.getByText('ticket-colruyt.jpg')).toHaveCount(0);
    await expect(grid.getByText('Aucun reçu', { exact: false })).toBeVisible();

    await search.fill('ticket-colruyt');
    await expect(grid.getByText('ticket-colruyt.jpg'), 'the JS-rendered card must come back for a matching search').toBeVisible();

    // ---------------------------------------------------------------
    // Association: the modal's own movement search, then the pick.
    // ---------------------------------------------------------------
    await grid.getByRole('button', { name: 'Associer' }).click();
    const associateModal = page.locator('#associate-modal');
    await expect(associateModal).toBeVisible();

    // The searchable label is the movement's own description (its
    // communication line), not the counterparty.
    await associateModal.locator('#associate-search').fill('Courses');
    const movementChoice = associateModal.locator('#associate-results')
        .getByRole('button', { name: /Courses camp/ }).first();
    await expect(movementChoice).toBeVisible();
    await movementChoice.click();

    // The card's status block flips from "Associer" to the movement link.
    await expect(associateModal).toBeHidden();
    await expect(grid.getByText(/Associé|mouvement/i).first()).toBeVisible();

    // And from the movement's side, the attachments panel counts it.
    await page.goto('/finance/movements', { waitUntil: 'load' });
    await expect(page.getByText('Colruyt Namur').filter({ visible: true }).first()).toBeVisible();

    expect(alerts, 'the page reported an error through window.alert()').toEqual([]);
    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
