// End-to-end: a custom mailing list, from its creation to the e-mail that
// uses it (chantier #729, docs/chantiers/CHANTIER-listes-de-diffusion-blocs.md).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// A list is built on its own page out of blocks, and every block comes
// from a dialog that opens from another one — « Nouvelle liste », the
// choice of a kind, then the member group or the contact group. Two
// scripts share that page (mass-mail-lists.js and
// mass-mail-contact-groups.js) and meet only through a DOM contract and
// one event, `mass-mail:blocks-changed`; jsdom tests each of them alone,
// with no Bootstrap and no server. What only a browser shows:
//
//   - a dialog opened while the one before it is still fading out;
//   - a real .xlsx going through the real analysis, its preview drawn, and
//     « Annuler » leaving the database untouched — proved by reloading the
//     page, not by trusting the script that would have sent the request;
//   - the total, « Voir les destinataires » and the e-mail page agreeing
//     with the blocks the server holds;
//   - the year sentence of the e-mail page following the kinds of block
//     the chosen list carries.
//
// It ends at composing: the sentence is what lot E asks for, and the send
// itself is mass-mail.spec.js's.
//
// Every wait is on the page's state — a modal's Bootstrap verdict
// (support/modal.js), a row that appears, a text that changes — never on a
// duration.
import { expect, test } from '@playwright/test';

import { answerConfirmation } from '../support/confirm-dialog.js';
import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { closeModal, openModal } from '../support/modal.js';
import { xlsxBuffer } from '../support/xlsx.js';

const STAMP = Date.now();
const LIST_NAME = `Meute et partenaires ${STAMP}`;
const GROUP_NAME = 'Partenaires du camp';

/** The three contacts the file brings, unique per run. */
const CONTACTS = [
    ['Commune de Wavre', `commune-${STAMP}@example.invalid`],
    ['Paroisse Saint-Jean', `paroisse-${STAMP}@example.invalid`],
    ['Propriétaire du terrain', `terrain-${STAMP}@example.invalid`],
];

/**
 * The file a chief would fill from « Télécharger un modèle vide »: its two
 * read columns, the three contacts, and one line the analysis refuses —
 * so the preview has a refused line to render as text.
 */
const CONTACTS_FILE = {
    name: 'partenaires.xlsx',
    mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    buffer: xlsxBuffer([
        ['Nom', 'Adresse'],
        ...CONTACTS,
        ['Sans adresse valable', 'pas-une-adresse'],
    ]),
};

/**
 * « 1 destinataire », « 4 destinataires » — the page's own agreement.
 *
 * @param {number} count
 * @param {string} singular
 * @returns {string}
 */
function plural(count, singular) {
    return `${count} ${singular}${count > 1 ? 's' : ''}`;
}

/**
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<import('@playwright/test').Locator>} the contact-group dialog, open on a creation
 */
async function openNewContactGroup(page) {
    const chooser = await openModal(
        page,
        'mm-kind-chooser-modal',
        () => page.getByRole('button', { name: 'Ajouter des destinataires' }).click(),
    );
    return openModal(
        page,
        'mm-contact-group-modal',
        () => chooser.getByRole('button', { name: /Groupe de contacts/ }).click(),
    );
}

/**
 * Names the group and chooses the file, then waits for the analysis.
 *
 * @param {import('@playwright/test').Locator} dialog
 */
async function fillContactGroup(dialog) {
    await dialog.getByLabel('Nom du groupe').fill(GROUP_NAME);
    await dialog.locator('[data-cg-file-new]').setInputFiles(CONTACTS_FILE);
    await expect(dialog.locator('[data-cg-summary]')).toHaveText('3 contacts trouvés, 1 ligne ignorée.');
}

test('une liste se construit par blocs et la page d\'un e-mail en tient compte', async ({ page }) => {
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto('/admin/listes-de-diffusion', { waitUntil: 'load' });

    // --- « Nouvelle liste » creates the list and opens its page, empty. ---
    const identity = await openModal(
        page,
        'mm-list-identity-modal',
        () => page.getByRole('button', { name: 'Nouvelle liste' }).click(),
    );
    await identity.getByLabel('Nom').fill(LIST_NAME);
    await identity.getByLabel('Description').fill('La meute et ceux qui l\'accueillent au camp.');
    await identity.getByRole('button', { name: 'Enregistrer' }).click();
    await page.waitForURL(/\/admin\/listes-de-diffusion\/\d+$/, { waitUntil: 'load' });
    const listUrl = page.url();

    await expect(page.getByRole('heading', { level: 1, name: LIST_NAME })).toBeVisible();
    const total = page.locator('[data-list-total]');
    await expect(total).toContainText('0 destinataire');
    await expect(page.getByText('Ajoutez des groupes de membres de l\'unité')).toBeVisible();
    await expect(page.locator('[data-list-blocks]')).toBeEmpty();

    // --- A member group: nothing to save before one choice. ---
    const chooser = await openModal(
        page,
        'mm-kind-chooser-modal',
        () => page.getByRole('button', { name: 'Ajouter des destinataires' }).click(),
    );
    const member = await openModal(
        page,
        'mm-member-group-modal',
        () => chooser.getByRole('button', { name: /Groupe de membres/ }).click(),
    );
    await expect(member.getByRole('button', { name: 'Enregistrer' })).toBeDisabled();
    await expect(member.locator('[data-member-group-sentence]'))
        .toHaveText('Choisissez au moins une fonction, une section ou un badge.');

    const sections = member.locator('#mm-section-picker');
    await sections.locator('summary').click();
    await sections.getByRole('button', { name: 'Meute E2E' }).click();
    await sections.locator('summary').click();
    await expect(member.locator('[data-member-group-sentence]')).toHaveText('Les membres de la section Meute E2E.');
    await expect(member.locator('[data-member-group-count]')).toContainText(/\d+ membres? en \d{4}-\d{4}/);
    await closeModal(page, 'mm-member-group-modal', () => member.getByRole('button', { name: 'Enregistrer' }).click());

    const memberRow = page.locator('[data-member-group-open]');
    await expect(memberRow).toHaveCount(1);
    await expect(memberRow).toContainText('Les membres de la section Meute E2E.');
    await expect(page.getByRole('heading', { name: 'Groupes de membres' })).toBeVisible();

    // The member count and the year, read off the page rather than written
    // down: the throwaway instance decides both.
    const memberLine = await memberRow.locator('span.small').innerText();
    const memberMatch = /^(\d+) membres? en (\d{4}-\d{4})$/.exec(memberLine.trim());
    expect(memberMatch, `unexpected member-group line « ${memberLine} »`).not.toBeNull();
    const members = Number(memberMatch?.[1]);
    const year = String(memberMatch?.[2]);

    // --- A contact group: the file is analysed, « Annuler » writes nothing. ---
    let contactDialog = await openNewContactGroup(page);
    await expect(contactDialog.getByRole('button', { name: 'Enregistrer' })).toBeDisabled();
    await fillContactGroup(contactDialog);
    // The refused line is the chief's own text, rendered as text.
    await expect(contactDialog.locator('[data-cg-line-errors] li')).toHaveCount(1);
    await expect(contactDialog.locator('[data-cg-line-errors]')).toContainText('pas-une-adresse');
    await expect(contactDialog.getByRole('button', { name: 'Enregistrer' })).toBeEnabled();
    await closeModal(page, 'mm-contact-group-modal', () => contactDialog.getByRole('button', { name: 'Annuler' }).click());

    // Nothing on the page — and nothing on the server either: a reload
    // draws the blocks from the database.
    await expect(page.locator('[data-contact-group-open][data-block-id]')).toHaveCount(0);
    await page.reload({ waitUntil: 'load' });
    await expect(memberRow).toHaveCount(1);
    await expect(page.locator('[data-contact-group-open][data-block-id]')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Groupes de contacts' })).toHaveCount(0);

    // Reopened, the dialog starts over: no name, no file, no preview.
    contactDialog = await openNewContactGroup(page);
    await expect(contactDialog.getByLabel('Nom du groupe')).toHaveValue('');
    await expect(contactDialog.locator('[data-cg-preview]')).toBeHidden();
    await expect(contactDialog.getByRole('button', { name: 'Enregistrer' })).toBeDisabled();

    // --- « Enregistrer » writes the name and the file together. ---
    await fillContactGroup(contactDialog);
    await closeModal(page, 'mm-contact-group-modal', () => contactDialog.getByRole('button', { name: 'Enregistrer' }).click());

    const contactRow = page.locator('[data-contact-group-open][data-block-id]');
    await expect(contactRow).toHaveCount(1);
    await expect(contactRow).toContainText(GROUP_NAME);
    await expect(contactRow).toContainText('3 contacts');
    await expect(page.getByRole('heading', { name: 'Groupes de contacts' })).toBeVisible();

    // --- The total adds both kinds up. ---
    const recipientsTotal = members + CONTACTS.length;
    await expect(total).toContainText(`${plural(recipientsTotal, 'destinataire')} en ${year}`);
    await expect(total).toContainText(`${plural(members, 'membre')} pour ${year} et 3 contacts.`);
    await expect(total).toContainText('Une personne désignée par plusieurs blocs ne reçoit l\'e-mail qu\'une fois.');

    // --- « Voir les destinataires »: the same people, each with its block. ---
    const recipients = await openModal(
        page,
        'mm-recipients-modal',
        () => page.getByRole('button', { name: 'Voir les destinataires' }).click(),
    );
    await expect(recipients.getByRole('heading')).toHaveText(`${plural(recipientsTotal, 'destinataire')} en ${year}`);
    const rows = recipients.locator('[data-recipients-rows] li');
    await expect(rows).toHaveCount(recipientsTotal);
    await expect(rows.filter({ hasText: GROUP_NAME })).toHaveCount(CONTACTS.length);
    await recipients.getByLabel('Rechercher un destinataire').fill('paroisse');
    await expect(rows.locator('visible=true')).toHaveCount(1);
    await expect(rows.locator('visible=true')).toContainText(CONTACTS[1][1]);
    await closeModal(page, 'mm-recipients-modal', () => recipients.getByRole('button', { name: 'Fermer' }).click());

    // --- The e-mail page: both kinds, so the years concern the members only. ---
    const mixedNote = page.locator('#mm-custom-list-mixed-note');
    const contactsNote = page.locator('#mm-custom-list-contacts-note');
    await page.goto('/mass-mail/new', { waitUntil: 'load' });
    await page.locator('#mm-list').selectOption({ label: LIST_NAME });
    await expect(mixedNote).toBeVisible();
    await expect(mixedNote).toHaveText(
        'Les années choisies s\'appliquent aux groupes de membres de cette liste. '
        + 'Ses groupes de contacts sont toujours inclus.',
    );
    await expect(contactsNote).toBeHidden();

    // --- Removing a block asks first, and says no e-mail leaves. ---
    await page.goto(listUrl, { waitUntil: 'load' });
    await expect(memberRow).toHaveCount(1);
    await page.getByRole('button', { name: 'Retirer ce groupe de membres' }).click();
    const question = await answerConfirmation(page);
    expect(question).toContain('Les e-mails déjà envoyés ne changent pas.');
    expect(question).toContain('Aucun e-mail n\'est envoyé.');
    await expect(memberRow).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Groupes de membres' })).toHaveCount(0);
    await expect(contactRow).toHaveCount(1);
    await expect(total).toContainText('3 destinataires en ');

    // --- Contacts only: the year changes nothing, and the page says so. ---
    await page.goto('/mass-mail/new', { waitUntil: 'load' });
    await page.locator('#mm-list').selectOption({ label: LIST_NAME });
    await expect(contactsNote).toBeVisible();
    await expect(contactsNote).toHaveText('Cette liste ne contient que des contacts : l\'année ne change rien.');
    await expect(mixedNote).toBeHidden();

    // A default list says nothing of the kind.
    await page.locator('#mm-list').selectOption({ label: 'Section - Meute E2E' });
    await expect(contactsNote).toBeHidden();
    await expect(mixedNote).toBeHidden();

    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
