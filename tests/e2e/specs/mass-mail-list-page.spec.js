// End-to-end: the page of one mailing list, with a member group.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The page of a list chains three dialogs — « Nouvelle liste », the choice
// of a kind of block, the member group — and each opens from the one
// before it closes. jsdom has no Bootstrap: a dialog that opens on top of
// one still fading out, a select bar whose panel never reaches the script,
// or a « Enregistrer » that stays disabled after a real click are things
// only a browser shows. This is the short path through them; the whole
// journey (contact groups, the e-mail page) is mass-mail-lists.spec.js's.
import { expect, test } from '@playwright/test';

import { answerConfirmation } from '../support/confirm-dialog.js';
import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { closeModal, openModal } from '../support/modal.js';

const LIST_NAME = `Meute et amis ${Date.now()}`;

test('une liste se crée, reçoit un groupe de membres et le retire', async ({ page }) => {
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto('/admin/listes-de-diffusion', { waitUntil: 'load' });

    // « Nouvelle liste » creates the list and opens its page.
    const identity = await openModal(
        page,
        'mm-list-identity-modal',
        () => page.getByRole('button', { name: 'Nouvelle liste' }).click()
    );
    await identity.getByLabel('Nom').fill(LIST_NAME);
    await identity.getByLabel('Description').fill('La meute, pour les annonces de fin d\'année.');
    await identity.getByRole('button', { name: 'Enregistrer' }).click();
    await page.waitForURL(/\/admin\/listes-de-diffusion\/\d+$/, { waitUntil: 'load' });

    await expect(page.getByRole('heading', { level: 1, name: LIST_NAME })).toBeVisible();
    await expect(page.locator('[data-list-total]')).toContainText('0 destinataire');

    // The kind, then the member group.
    const chooser = await openModal(
        page,
        'mm-kind-chooser-modal',
        () => page.getByRole('button', { name: 'Ajouter des destinataires' }).click()
    );
    // The member-group dialog opens once the chooser is gone.
    const dialog = await openModal(
        page,
        'mm-member-group-modal',
        () => chooser.getByRole('button', { name: /Groupe de membres/ }).click()
    );
    await expect(dialog.getByRole('button', { name: 'Enregistrer' })).toBeDisabled();
    await expect(dialog.locator('[data-member-group-sentence]'))
        .toHaveText('Choisissez au moins une fonction, une section ou un badge.');

    // A multi select bar: its rows are buttons, toggled in an open panel.
    const sections = dialog.locator('#mm-section-picker');
    await sections.locator('summary').click();
    await sections.getByRole('button', { name: 'Meute E2E' }).click();
    await sections.locator('summary').click();
    await expect(dialog.locator('[data-member-group-sentence]')).toHaveText('Les membres de la section Meute E2E.');
    await expect(dialog.locator('[data-member-group-count]')).toContainText(/\d+ membres? en \d{4}-\d{4}/);

    await closeModal(page, 'mm-member-group-modal', () => dialog.getByRole('button', { name: 'Enregistrer' }).click());

    const row = page.locator('[data-member-group-open]');
    await expect(row).toHaveCount(1);
    await expect(row).toContainText('Les membres de la section Meute E2E.');
    await expect(page.getByRole('heading', { name: 'Groupes de membres' })).toBeVisible();
    await expect(page.locator('[data-list-total]')).toContainText('Voir les destinataires');

    // « Voir les destinataires » answers, for the effective year.
    const recipients = await openModal(
        page,
        'mm-recipients-modal',
        () => page.getByRole('button', { name: 'Voir les destinataires' }).click()
    );
    await expect(recipients.getByRole('heading')).toHaveText(/\d+ destinataires? en \d{4}-\d{4}/);
    await closeModal(page, 'mm-recipients-modal', () => recipients.getByRole('button', { name: 'Fermer' }).click());

    // Removing it asks first, and says no e-mail leaves.
    await page.getByRole('button', { name: 'Retirer ce groupe de membres' }).click();
    const question = await answerConfirmation(page);
    expect(question).toContain('Aucun e-mail n\'est envoyé.');
    await expect(page.locator('[data-member-group-open]')).toHaveCount(0);
    await expect(page.locator('[data-list-total]')).toContainText('0 destinataire');

    expect(pageErrors).toEqual([]);
});
