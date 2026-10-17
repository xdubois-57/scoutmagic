// End-to-end: the decision e-mail, from its template to the family's page.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// registration-flow.spec.js proves the half of chantier #843 that holds
// while nothing is written: an acceptance the family must not see, because
// no acceptance e-mail can leave. This one is the other half, the one the
// chantier was for (issue #843, IT-11), and every step of it crosses a
// boundary only a browser and a real mailbox can:
//
//   1. « Gabarits » (IT-07): the acceptance and the refusal are « À
//      rédiger » on a fresh install, and writing one in the site's
//      rich-text editor — a module script, under the real CSP — with its
//      variables inserted from « Insérer un champ » turns its badge to
//      « Personnalisé ». The chips are what makes the tracking link survive
//      the editor: a typed `{{ lien_suivi }}` can be split across elements
//      as it is edited, a chip cannot.
//   2. « Réglages › Décisions automatiques » (IT-09/IT-10): with both
//      templates written, « Envoyer automatiquement les e-mails de
//      décision » can be ticked, and ticking it makes « Accepter
//      automatiquement les demandes « Oui » » available on the spot — the
//      page's script, which reads its prerequisites off the form. Nothing
//      is saved: an automatic acceptance left on would accept requests of
//      the specs that run after this one.
//   3. The decision dialog (IT-04) names the slot as the mock-up does and
//      says, now that the acceptance is written, that no e-mail leaves on
//      its own; the fiche's « E-mail de décision » block (IT-08) then sends
//      it with « Envoyer maintenant », behind its confirmation.
//   4. The e-mail really leaves (IT-05/IT-06): the body is the template
//      just written, its fields substituted — the child's first name, the
//      family's own tracking link — and the unit in copy as
//      registration-flow.spec.js saved it. The link it carries opens the
//      family's page on « Acceptée »: the decision is visible exactly once
//      its e-mail left (R3), through the new token the send stored (R6).
//
// ORDERING
// ----------------------------------------------------------------------------
// Runs after registration-grids.spec.js (alphabetical: -grids <
// -templates) and reuses the request registration-flow.spec.js filed and
// grids left WITHDRAWN: it is put back « en attente », accepted from the
// dialog, its e-mail sent, then withdrawn again — the final state that
// keeps the scout-year transition unvetoed for the spec that runs it
// later. The two templates stay written — a decision template has no
// default to go back to, and an empty one is refused — and nothing after
// this spec reads them. Like registration-grids.spec.js, it therefore runs
// once per instance, after its predecessors: scripts/e2e.sh provisions a
// fresh one for every run.
import { expect, test } from '@playwright/test';

import { answerConfirmation, waitForConfirmReady } from '../support/confirm-dialog.js';
import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { linkFromMail, waitForMail } from '../support/maildrop.js';
import { openModal } from '../support/modal.js';

// registration-flow.spec.js's family and unit copy.
const FAMILY_EMAIL = 'famille-inscription@example.invalid';
const UNIT_COPY_EMAIL = 'unite-copie@example.invalid';
const ACCEPTANCE_TEXT = `Bonne nouvelle : une place est réservée (${Date.now() % 100000}) pour`;
const ACCEPTANCE_BODY = `${ACCEPTANCE_TEXT} `;
const REFUSAL_TEXT = 'Nous sommes désolés : nous ne pouvons pas accueillir votre enfant cette année.';

/**
 * Writes one decision template on its own page under « Gabarits » and
 * comes back to the list. $fields are the variables to insert after the
 * text, each by the description « Insérer un champ » shows for it.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} name the template's name, as its pencil says it
 * @param {string} text
 * @param {string[]} fields
 */
async function writeTemplate(page, name, text, fields) {
    await page.getByRole('link', { name: `Modifier — ${name}` }).click();
    await expect(page.getByRole('heading', { name: `Modifier — ${name}` })).toBeVisible();
    // The surface becomes a textbox once its module script has wired it;
    // the field's menu is a Bootstrap dropdown, inert until the bundle ran.
    const surface = page.getByRole('textbox', { name: "Texte de l'e-mail" });
    await surface.fill(text);
    await page.waitForFunction(() => typeof (/** @type {any} */ (window)).bootstrap !== 'undefined');
    for (const field of fields) {
        await page.getByRole('button', { name: 'Insérer un champ' }).click();
        await page.getByRole('button', { name: new RegExp(field) }).click();
    }
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: `« ${name} » enregistré.` })).toBeVisible();
    expect(new URL(page.url()).pathname, 'saving goes back to the list').toBe('/config/inscriptions/gabarits');
}

test('a decision e-mail written in « Gabarits » is sent from the fiche and shows the family its decision', async ({ page, browser }) => {
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

    await loginAsAdmin(page);
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // « Gabarits »: both decisions to write, then written.
    // ---------------------------------------------------------------
    await page.goto('/config/inscriptions/gabarits', { waitUntil: 'domcontentloaded' });
    const rail = page.getByRole('navigation', { name: "Pages Formulaire d'inscription" });
    await expect(rail.getByRole('link', { name: 'Gabarits', exact: true })).toHaveAttribute('aria-current', 'page');
    const row = (name) => page.locator('.list-editor-item').filter({ hasText: name });
    await expect(row('Accusé de réception de la demande')).toContainText('Par défaut');
    await expect(row('Acceptation de la demande')).toContainText('À rédiger');
    await expect(row('Refus de la demande')).toContainText('À rédiger');
    await expect(page.getByText("Les gabarits d'acceptation et de refus doivent être rédigés")).toBeVisible();

    await writeTemplate(page, 'Acceptation de la demande', ACCEPTANCE_BODY, [
        "Prénom de l'enfant",
        'Lien de suivi de la demande',
    ]);
    await writeTemplate(page, 'Refus de la demande', REFUSAL_TEXT, []);
    await expect(row('Acceptation de la demande')).toContainText('Personnalisé');
    await expect(row('Refus de la demande')).toContainText('Personnalisé');
    await expect(page.getByText("Les gabarits d'acceptation et de refus doivent être rédigés")).toHaveCount(0);

    // ---------------------------------------------------------------
    // « Décisions automatiques »: the sending can be ticked now, and the
    // acceptance follows it on the page — nothing saved.
    // ---------------------------------------------------------------
    await page.goto('/config/inscriptions/reglages', { waitUntil: 'domcontentloaded' });
    const automationBox = page.locator('form[action="/config/inscriptions/decisions-automatiques"]');
    const autoSend = automationBox.getByRole('checkbox', { name: 'Envoyer automatiquement les e-mails de décision' });
    const autoAccept = automationBox.getByRole('checkbox', { name: 'Accepter automatiquement les demandes « Oui »' });
    await expect(autoSend, 'both templates written: the sending may be switched on').toBeEnabled();
    await expect(autoSend).not.toBeChecked();
    await expect(autoAccept).toBeDisabled();
    await autoSend.check();
    await expect(autoAccept, 'its prerequisites met, the acceptance is offered without a reload').toBeEnabled();
    await expect(automationBox.getByText('Le site accepte lui-même, chaque heure')).toBeVisible();
    await autoSend.uncheck();
    await expect(autoAccept).toBeDisabled();
    await expect(
        automationBox.getByText("Indisponible : activez d'abord l'envoi automatique des e-mails de décision"),
    ).toBeVisible();

    // ---------------------------------------------------------------
    // The request back « en attente », then accepted from the dialog.
    // ---------------------------------------------------------------
    await page.goto('/config/inscriptions/demandes', { waitUntil: 'domcontentloaded' });
    // One request exists on a canonical run — registration-flow's.
    const childLink = page.getByRole('row', { name: /Zoé/ }).first().getByRole('link', { name: /Zoé/ });
    const childName = (await childLink.innerText()).match(/Zoé\d*/);
    expect(childName, 'registration-flow.spec.js left its request in the list').not.toBeNull();
    const childFirstName = String(childName?.[0]);
    await childLink.click();
    await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });
    const fichePath = new URL(page.url()).pathname;
    // Waited for by the badge each POST produces: the redirect lands on
    // the address already on screen (registration-grids.spec.js).
    await page.getByRole('button', { name: 'Revenir en attente' }).click();
    await expect(page.getByText('En attente', { exact: true })).toBeVisible();

    await page.goto('/config/inscriptions/demandes', { waitUntil: 'domcontentloaded' });
    const requestRow = page.getByRole('row', { name: new RegExp(childFirstName) }).first();
    const slotName = /( · \d(re|e) année|Hors des tranches d'âge)/;
    await expect(requestRow, 'the list\'s « Créneau » names the slot as the mock-up does').toContainText(slotName);
    const decide = requestRow.getByRole('button', { name: /^Décider/ });
    const dialogId = String(await decide.getAttribute('data-bs-target')).replace(/^#/, '');
    const dialog = await openModal(page, dialogId, () => decide.click());
    // The header names the slot the same way.
    await expect(dialog.getByText(/(\S · \d(re|e) année|Hors des tranches d'âge) · .+ · reçue le /)).toBeVisible();
    await expect(dialog.getByText('Recommandation', { exact: true })).toBeVisible();
    await expect(
        dialog.getByText("Si vous acceptez : aucun e-mail ne part tout seul, vous l'envoyez depuis la fiche."),
    ).toBeVisible();
    await dialog.getByRole('button', { name: 'Accepter', exact: true }).click();
    await expect(
        page.getByRole('alert').filter({ hasText: "Demande acceptée. Aucun e-mail n'est parti : envoyez-le depuis la fiche" }),
    ).toBeVisible();

    // ---------------------------------------------------------------
    // The fiche sends it: « Non planifié », « Envoyer maintenant », asked
    // first, then « Envoyé le … ».
    // ---------------------------------------------------------------
    await page.goto(fichePath, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Acceptée', { exact: true })).toBeVisible();
    await expect(page.getByText('Non planifié', { exact: true })).toBeVisible();
    await waitForConfirmReady(page);
    await page.getByRole('button', { name: 'Envoyer maintenant' }).click();
    expect(await answerConfirmation(page)).toContain('Il part immédiatement et ne peut pas être rappelé.');
    await expect(
        page.getByRole('alert').filter({ hasText: "L'e-mail d'acceptation est parti : la famille voit maintenant la décision." }),
    ).toBeVisible();
    await expect(page.getByText(/^Envoyé le /).first()).toBeVisible();

    // ---------------------------------------------------------------
    // The family's mailbox, and the page its link opens.
    // ---------------------------------------------------------------
    const acceptance = await waitForMail(
        (message) => message.to.includes(FAMILY_EMAIL) && message.text.includes(ACCEPTANCE_TEXT),
        { description: `the acceptance e-mail written in « Gabarits », addressed to ${FAMILY_EMAIL}` },
    );
    // The editor separates a chip from the text with a non-breaking space,
    // which \s matches.
    expect(acceptance.text, 'the child\'s first name is substituted into the template').toMatch(
        new RegExp(`${ACCEPTANCE_TEXT.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*${childFirstName}`),
    );
    expect(acceptance.text, 'no variable left unsubstituted').not.toContain('{{');
    expect(acceptance.raw, 'the decision goes out with the unit in copy')
        .toMatch(new RegExp(`^Cc:\\s*${UNIT_COPY_EMAIL.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`, 'mi'));
    const trackingUrl = linkFromMail(acceptance, '/inscriptions/suivi/');

    const family = await browser.newContext();
    try {
        const familyPage = await family.newPage();
        await familyPage.goto(trackingUrl, { waitUntil: 'domcontentloaded' });
        const trackingMain = familyPage.locator('#main-content');
        await expect(trackingMain.getByText(childFirstName)).toBeVisible();
        await expect(trackingMain.getByText('Acceptée', { exact: true }), 'the decision shows once its e-mail left')
            .toBeVisible();
        const shown = await trackingMain.innerText();
        for (const staffOnly of ['Recommandation', 'Toujours Oui', 'Note interne', 'Motif']) {
            expect(shown, `the family's page never shows « ${staffOnly} »`).not.toContain(staffOnly);
        }
    } finally {
        await family.close();
    }

    // ---------------------------------------------------------------
    // Back to the final state: withdrawn, which asks first.
    // ---------------------------------------------------------------
    await page.goto(fichePath, { waitUntil: 'domcontentloaded' });
    await waitForConfirmReady(page);
    await page.getByRole('button', { name: 'Retirer' }).click();
    expect(await answerConfirmation(page)).toContain('déjà acceptée');
    await expect(page.getByText('Retirée', { exact: true })).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
