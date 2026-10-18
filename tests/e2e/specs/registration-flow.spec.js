// End-to-end: a family registers a child — the whole loop from the public
// form, through the mailed tracking link, to the admin's decision.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The registration module is the application's largest anonymous intake of
// personal data (a child's identity, address, phones), and its flow is
// finished on the other side of an email — the half nothing below a
// browser can prove. Four of its rules are pinned here because each is
// only observable across the real boundary:
//
//   1. The tracking link mailed to the family carries a bearer token, and
//      the page it opens is the MINIMAL view: child's first name, date,
//      status — deliberately less than the linked/authenticated view
//      (TrackingController's own docblock). The token grants a status,
//      never the family's own address back.
//   2. "The tracking page must never reveal an acceptance before the
//      corresponding email has actually gone out" (RequestStatusService::
//      visibleStatus() — a chief deciding on Friday and sending Monday
//      must not let the parent find out in between). The admin accepts;
//      the family's page must still say "En attente d'examen", because
//      the acceptance e-mail has not been sent. Only two
//      browsers with different privileges can watch both sides disagree
//      on purpose.
//   3. The branch hint under the birth-date field is computed client-side
//      from Twig-injected slot data (public.html.twig's nonce'd inline
//      script) — a CSP or serialization regression kills it silently.
//   4. The in-year code is the only way into a CLOSED form, and it is
//      session-scoped: the code an admin generates on the config page
//      must open the form for the visitor who typed it.
//   5. An empty capacity box means "pas de limite", NEVER zero — and the
//      two must not look alike anywhere. A branch with no recorded
//      capacity is never announced full; a branch explicitly set to 0 is.
//      This is the one rule of the change whose failure mode is silent:
//      a NULL read as a number is 0, and PHP will not say a word. It
//      needs a browser because the two states have to be compared on the
//      SAME rendered page, chief side and family side, after a real
//      round trip through the form that stores them.
//   6. The management page is four sub-pages joined by one rail, and
//      nothing on them folds away (issue #843): the controls live on
//      « Réglages », the figures and the capacity table on the dashboard,
//      the list on « Demandes ». Every control used below is reached
//      without opening anything, and every POST lands back on the
//      sub-page its form lives on — which only a browser following the
//      real redirect can show.
//   7. The receipt goes out with the unit in copy (issue #843, D9): the
//      box « Copie des e-mails d'inscription » on « Réglages » saves an
//      address, and the message the family receives carries it in a `Cc:`
//      header — which only the real message, read back from the mailbox,
//      can show (PHPUnit sees a MailService double, or a PHPMailer object
//      that was never assembled by the composition root's own wiring).
//
// ORDERING
// ----------------------------------------------------------------------------
// Everything ends in a final state on purpose: the request is withdrawn
// and the form closed again (its provisioned default), because a request
// left pending or accepted would veto the year-transition workflow that
// scout-year-transition.spec.js walks later in the same run
// (Modules\Registration\Api\ScoutYearTransitionVetoProvider counts both,
// across every target year).
import { expect, test } from '@playwright/test';

import { answerConfirmation, waitForConfirmReady } from '../support/confirm-dialog.js';
import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { linkFromMail, waitForMail } from '../support/maildrop.js';
import { closeModal, openModal } from '../support/modal.js';
import { waitOutHumanCheckDelay } from '../support/human-check.js';

const SETTINGS_PATH = '/config/inscriptions/reglages';

/**
 * Clicks a button of « Réglages » whose form posts and redirects back to
 * « Réglages », and waits for the page that redirect renders.
 *
 * The barrier is the flash message the action leaves, not the URL: the
 * POST redirects to the address the browser is ALREADY on, so `waitForURL`
 * would resolve instantly and let the next assertion read the page from
 * before the save — which is how a "must not appear" assertion passes for
 * the wrong reason. Each call starts from a freshly loaded « Réglages »
 * (a flash is shown once), so the message cannot be a leftover.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} button
 * @param {string} flash the message the action confirms itself with
 */
async function submitOnSettings(page, button, flash) {
    await button.click();
    await expect(page.getByRole('alert').filter({ hasText: flash })).toBeVisible();
    expect(new URL(page.url()).pathname, 'the POST lands back on « Réglages »').toBe(SETTINGS_PATH);
}

/**
 * Submits the « Capacités » sub-box of « Capacités par branche ». Its
 * button is scoped to the form: the page has five other « Enregistrer »
 * — the schedule's, the automatic decisions', the thresholds', the
 * priorities' and the copy's.
 *
 * @param {import('@playwright/test').Page} page
 */
async function saveCapacities(page) {
    const capacities = page.locator('form[action="/config/inscriptions"]');
    await submitOnSettings(
        page,
        capacities.getByRole('button', { name: 'Enregistrer', exact: true }),
        'Capacités enregistrées.',
    );
}

const PARENT_NAME = 'Camille Verstraeten';
const FAMILY_EMAIL = 'famille-inscription@example.invalid';
const FAMILY_PHONE = '+32 470 11 22 33';
const FAMILY_STREET = 'Rue des Écureuils';
const CHILD_FIRST_NAME = `Zoé${Date.now() % 100000}`;
const CHILD_LAST_NAME = 'Verstraeten';
const PREVIOUS_UNIT = '57e Unité Saint-Michel, Etterbeek';
const UNIT_COPY_EMAIL = 'unite-copie@example.invalid';
const DECISION_NOTE = 'Fratrie à confirmer par téléphone.';

test('a family registers a child, follows the mailed tracking link, and the admin\'s decisions surface exactly when they should', async ({ page, browser }) => {
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

    // ---------------------------------------------------------------
    // The admin opens the registrations — the provisioned default is
    // closed (registration_form_open = '0').
    // ---------------------------------------------------------------
    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto(SETTINGS_PATH, { waitUntil: 'domcontentloaded' });

    // Nothing on these pages folds away any more: no collapse toggle in
    // the page's own content, and the rail names the four sub-pages.
    await expect(page.locator('#main-content [data-bs-toggle="collapse"]')).toHaveCount(0);
    const rail = page.getByRole('navigation', { name: "Pages Formulaire d'inscription" });
    for (const tab of ['Tableau de bord', 'Demandes', 'Gabarits', 'Réglages']) {
        await expect(rail.getByRole('link', { name: tab, exact: true })).toBeVisible();
    }
    await expect(rail.getByRole('link', { name: 'Réglages', exact: true })).toHaveAttribute('aria-current', 'page');

    // The toggle is one button whose label IS the current state — waiting
    // on either label first keeps this step honest about which state the
    // page really rendered before acting on it.
    await expect(page.getByRole('button', { name: /^(Ouvrir|Fermer) maintenant$/ })).toBeVisible();
    if (await page.getByRole('button', { name: 'Ouvrir maintenant' }).isVisible()) {
        await submitOnSettings(page, page.getByRole('button', { name: 'Ouvrir maintenant' }), 'Formulaire ouvert.');
    }
    await expect(page.getByRole('button', { name: 'Fermer maintenant' })).toBeVisible();

    // ---------------------------------------------------------------
    // Capacities: the default really is in the database, and an empty
    // box is "pas de limite" — never a zero.
    //
    // The two states are driven through the real form and read back off
    // the « Vérification des capacités » table, because that table is
    // where a chief would first see an unconfigured branch mislabelled as
    // a full one. « Complet » is the exact level badge that must appear
    // for a 0 and must NOT appear for an empty box — the same word, read
    // through the same thresholds, as the public page. The grid is on
    // « Réglages », the table on the dashboard.
    // ---------------------------------------------------------------
    await expect(
        page.getByRole('checkbox', { name: "Gérer les listes d'attente" }),
        'the waitlist switch lives in the capacity box it governs, and is on by default',
    ).toBeChecked();

    const capacityBoxes = page.locator('form[action="/config/inscriptions"] input[name^="capacity["]');
    await expect(capacityBoxes.first(), 'the default capacity is stored, so the box comes back filled').toHaveValue('15');
    const capacityCount = await capacityBoxes.count();
    expect(capacityCount, 'the fixture branch must give the grid at least one slot').toBeGreaterThan(0);

    // 0 — the branch is deliberately closed, and says so.
    await capacityBoxes.first().fill('0');
    await saveCapacities(page);
    await page.goto('/config/inscriptions', { waitUntil: 'domcontentloaded' });
    await expect(
        page.getByText('Complet', { exact: true }),
        'a capacity of zero is a branch closed on purpose — it must still read as full',
    ).toBeVisible();
    // The dashboard's first figure says what « Réglages » just did.
    await expect(page.locator('#registration-dashboard-figures')).toContainText('Ouvert');

    // Empty — no limit at all. Same table, opposite reading.
    await page.goto(SETTINGS_PATH, { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < capacityCount; i++) {
        await capacityBoxes.nth(i).fill('');
    }
    await saveCapacities(page);
    await page.goto('/config/inscriptions', { waitUntil: 'domcontentloaded' });
    await expect(
        page.getByText('Complet', { exact: true }),
        'a branch with NO recorded capacity must never be announced full',
    ).toHaveCount(0);
    await expect(page.getByText('Sans limite', { exact: true }).first()).toBeVisible();

    // ---------------------------------------------------------------
    // The unit in copy of what it writes to the family: on by default,
    // and the address typed here is the one the receipt will carry.
    // ---------------------------------------------------------------
    await page.goto(SETTINGS_PATH, { waitUntil: 'domcontentloaded' });
    const copyBox = page.locator('form[action="/config/inscriptions/copie"]');
    await expect(copyBox.getByRole('checkbox', { name: 'Mettre une adresse en copie' })).toBeChecked();
    await copyBox.getByLabel('Adresse e-mail en copie').fill(UNIT_COPY_EMAIL);
    await submitOnSettings(
        page,
        copyBox.getByRole('button', { name: 'Enregistrer', exact: true }),
        'Réglages enregistrés.',
    );
    await expect(copyBox.getByLabel('Adresse e-mail en copie')).toHaveValue(UNIT_COPY_EMAIL);

    // « Décisions automatiques » (#843, IT-09): off by default, a delay of
    // 24 hours, and the switch free from the start — the acceptance and the
    // refusal ship with a text, so no template is ever « à rédiger ».
    const automationBox = page.locator('form[action="/config/inscriptions/decisions-automatiques"]');
    const autoSend = automationBox.getByRole('checkbox', { name: 'Envoyer automatiquement les e-mails de décision' });
    await expect(autoSend).not.toBeChecked();
    await expect(autoSend).toBeEnabled();
    await expect(automationBox.getByLabel('Délai avant envoi')).toHaveValue('24');
    // Its automatic acceptance of the « Oui » requests (IT-10): off, and
    // locked for as long as the automatic sending is — its date picker
    // beside it.
    const autoAccept = automationBox.getByRole('checkbox', { name: 'Accepter automatiquement les demandes « Oui »' });
    await expect(autoAccept).not.toBeChecked();
    await expect(autoAccept).toBeDisabled();
    await expect(
        automationBox.getByText("Indisponible : activez d'abord l'envoi automatique des e-mails de décision"),
    ).toBeVisible();
    await expect(automationBox.getByLabel('À partir du')).toBeVisible();

    // ---------------------------------------------------------------
    // The family fills the public form — anonymous, own cookie jar,
    // consent refused: registering a child must need no non-essential
    // cookie.
    // ---------------------------------------------------------------
    const family = await browser.newContext();
    try {
        const familyPage = await family.newPage();
        /** @type {string[]} */
        const familyErrors = [];
        familyPage.on('pageerror', (error) => familyErrors.push(error.message));

        await familyPage.goto('/inscriptions', { waitUntil: 'domcontentloaded' });
        await familyPage.getByRole('button', { name: 'Tout refuser' }).click();
        await expect(familyPage.locator('#cookie-banner')).toBeHidden();

        // The other half of the same rule, on the side that matters most:
        // every capacity was just cleared, so no birth year may be
        // announced « Complet » to a family. The badges are still shown —
        // the waitlist switch is on — they just all read « Disponible ».
        await expect(
            familyPage.getByText('Complet', { exact: true }),
            'no capacity recorded anywhere: nothing may be announced full to a family',
        ).toHaveCount(0);
        await expect(familyPage.getByText('Disponible', { exact: true }).first()).toBeVisible();

        // Every mandatory label ends in the required-marker asterisk, so
        // each is addressed by an anchored prefix — "Téléphone *" and
        // "Téléphone (2, facultatif)" must never resolve to each other.
        const form = familyPage.locator('#registration-form');
        await form.getByLabel(/^Nom et prénom du parent/).fill(PARENT_NAME);
        await form.getByLabel(/^Email/).fill(FAMILY_EMAIL);
        await form.getByLabel(/^Téléphone \*/).fill(FAMILY_PHONE);
        await form.getByLabel(/^N° \*/).fill('12');
        await form.getByLabel(/^Rue/).fill(FAMILY_STREET);
        await form.getByLabel(/^Code postal/).fill('1000');
        await form.getByLabel(/^Ville/).fill('Bruxelles');
        await form.getByLabel(/^Prénom \*/).fill(CHILD_FIRST_NAME);
        await form.getByLabel(/^Nom \*/).fill(CHILD_LAST_NAME);
        await form.getByLabel(/^Date de naissance/).fill('2015-06-15');

        // The hint under the date is the nonce'd inline script running
        // against the Twig-injected slot data — whatever it concludes
        // (a branch, or "outside the unit's brackets"), it must say
        // SOMETHING the moment a date is picked.
        await expect(familyPage.locator('#birth-date-branch-hint')).not.toBeEmpty();

        await form.getByLabel(/^Genre/).selectOption('F');

        // « Déjà membre d'une autre unité Les Scouts ? » is required, and
        // answering « Oui » is what unfolds « Laquelle ? » — the whole
        // point of the question, since the unit is what changes the
        // chief's encoding procedure in Desk (issue #331).
        await form.getByLabel(/^Déjà membre d'une autre unité/).selectOption('yes');
        await form.getByLabel(/^Laquelle/).fill(PREVIOUS_UNIT);

        await form.getByRole('checkbox', { name: /J'accepte/ }).check();

        await waitOutHumanCheckDelay(familyPage, form);
        await form.getByRole('button', { name: 'Envoyer la demande' }).click();

        // partials/page_header.html.twig gives the page its own <h1> —
        // « Demande envoyée » — and « Merci ! » leads the paragraph under
        // it, where it used to be the heading itself.
        await expect(familyPage.getByRole('heading', { level: 1, name: 'Demande envoyée' })).toBeVisible();
        await expect(familyPage.getByText('Merci !')).toBeVisible();
        await expect(familyPage.getByText(`pour ${CHILD_FIRST_NAME} a bien été reçue`)).toBeVisible();

        // ---------------------------------------------------------------
        // The receipt email, and the minimal tracking view behind its
        // bearer link: the child's first name and a status — and none of
        // the personal data the family typed a minute ago.
        // ---------------------------------------------------------------
        // Matched on the child's (run-unique) first name as well as the
        // address, so the message found is THIS submission's receipt.
        const receipt = await waitForMail(
            (message) => message.to.includes(FAMILY_EMAIL) && message.text.includes(CHILD_FIRST_NAME),
            { description: `a registration receipt for ${CHILD_FIRST_NAME} addressed to ${FAMILY_EMAIL}` },
        );
        expect(receipt.raw, 'the receipt carries the unit in copy, as saved on « Réglages »')
            .toMatch(new RegExp(`^Cc:\\s*${UNIT_COPY_EMAIL.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`, 'mi'));
        const trackingUrl = linkFromMail(receipt, '/inscriptions/suivi/');

        await familyPage.goto(trackingUrl, { waitUntil: 'domcontentloaded' });
        await expect(familyPage.getByRole('heading', { name: 'Suivi de votre demande' })).toBeVisible();
        // Scoped to the page's own content, not the whole document: this
        // page now carries a contextual help panel, and the topic covering
        // it lists the very statuses asserted here — so an unscoped
        // getByText() matches the page AND its help, and fails on strict
        // mode. What is being asserted is what the family is shown.
        const trackingMain = familyPage.locator('#main-content');
        await expect(trackingMain.getByText(CHILD_FIRST_NAME)).toBeVisible();
        await expect(trackingMain.getByText("En attente d'examen")).toBeVisible();

        const trackingBody = await familyPage.locator('main').innerText();
        for (const [label, value] of [['street', FAMILY_STREET], ['phone', FAMILY_PHONE], ['parent name', PARENT_NAME]]) {
            expect(trackingBody, `the token view must not echo the family's ${label} back`).not.toContain(value);
        }

        // ---------------------------------------------------------------
        // The admin accepts from the list's decision dialog (« Action
        // rapide », issue #843 IT-04) — and the family must NOT see it
        // yet: no acceptance-email body is written on this instance, so
        // the decision stays invisible until that email really goes out.
        //
        // « Refuser » is tried first and taken back: it must ask for the
        // reason before anything can be confirmed, and closing the dialog
        // must put it back as it was. That half is the dialog's script,
        // which only a browser under the real CSP runs.
        // ---------------------------------------------------------------
        await page.goto('/config/inscriptions/demandes', { waitUntil: 'domcontentloaded' });
        const requestRow = page.getByRole('row', { name: new RegExp(CHILD_FIRST_NAME) });
        const decide = requestRow.getByRole('button', { name: /^Décider/ });
        // The dialog is one Bootstrap modal per request, opened through
        // support/modal.js so nothing is aimed at it before Bootstrap says
        // it is open (#520).
        const dialogId = String(await decide.getAttribute('data-bs-target')).replace(/^#/, '');
        let dialog = await openModal(page, dialogId, () => decide.click());
        await expect(dialog.getByRole('heading', { name: `Décider — ${CHILD_FIRST_NAME} ${CHILD_LAST_NAME}` }))
            .toBeVisible();
        // No template is ever « à rédiger » (#932): nothing leaves on its own
        // while the automatic sending is off, and the fiche sends it by hand.
        await expect(dialog.getByText("Si vous acceptez : aucun e-mail ne part tout seul", { exact: false }))
            .toBeVisible();
        await expect(dialog.getByText('ne peut partir', { exact: false })).toHaveCount(0);

        await dialog.getByRole('button', { name: 'Refuser', exact: true }).click();
        const confirmRefusal = dialog.getByRole('button', { name: 'Confirmer le refus' });
        const reason = dialog.getByLabel('Motif du refus');
        await expect(confirmRefusal).toBeVisible();
        await reason.selectOption('');
        await expect(confirmRefusal, 'no refusal without its reason').toBeDisabled();
        await reason.selectOption('other');
        await expect(confirmRefusal).toBeEnabled();
        await closeModal(page, dialogId, () => dialog.getByRole('button', { name: 'Annuler' }).click());

        dialog = await openModal(page, dialogId, () => decide.click());
        await expect(dialog.getByRole('button', { name: 'Confirmer le refus' }), 'a closed dialog opens afresh')
            .toBeHidden();
        await dialog.getByLabel('Note interne').fill(DECISION_NOTE);
        await dialog.getByRole('button', { name: 'Accepter', exact: true }).click();
        await expect(
            page.getByRole('alert').filter({ hasText: "Demande acceptée. Aucun e-mail n'est parti" }),
            'the decision lands back on the list, and says that no e-mail left',
        ).toBeVisible();
        expect(new URL(page.url()).pathname).toBe('/config/inscriptions/demandes');

        await page.getByRole('row', { name: new RegExp(CHILD_FIRST_NAME) })
            .getByRole('link', { name: new RegExp(CHILD_FIRST_NAME) }).click();
        await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });

        await expect(page.getByText(CHILD_LAST_NAME).first()).toBeVisible();
        // What the family answered about a previous unit reaches the
        // fiche the chief encodes from, not just the database.
        await expect(page.getByText(`Oui — ${PREVIOUS_UNIT}`)).toBeVisible();
        // The note written in the dialog is the fiche's own.
        await expect(page.getByLabel('Note interne', { exact: true })).toHaveValue(DECISION_NOTE);
        await expect(page.getByText('Acceptée', { exact: true })).toBeVisible();

        await expect(page.getByText('visible par la famille comme « en attente »')).toBeVisible();
        // The « E-mail de décision » block (#843, IT-08): nothing is planned
        // and nothing has left, so the acceptance stays to send by hand. It
        // is never « Gabarit à rédiger » (#932): the shipped text can go.
        await expect(page.getByText('Non planifié', { exact: true })).toBeVisible();
        await expect(page.getByText('Gabarit à rédiger', { exact: true })).toHaveCount(0);

        await familyPage.goto(trackingUrl, { waitUntil: 'domcontentloaded' });
        await expect(
            familyPage.locator('#main-content').getByText("En attente d'examen"),
            'an unsent acceptance must stay invisible to the family',
        ).toBeVisible();

        // ---------------------------------------------------------------
        // The request ends in a final state — withdrawn shows to the
        // family immediately (only accept/refuse are email-gated), and a
        // final state is what keeps the scout-year transition unvetoed
        // for the spec that runs it later.
        // ---------------------------------------------------------------
        // **The withdrawal asks first** (#485), and the wording is the one
        // written for a request that has ALREADY been accepted — which is
        // this scenario's state, and the case the issue singled out: the
        // family may already hold the acceptance e-mail, so the manager is
        // told that before the place is taken back. Answered explicitly
        // rather than through autoConfirm(), because being asked at all is
        // part of what this step is for.
        // `waitForConfirmReady()` first, which the helper's own docblock
        // requires before clicking anything inside a `form[data-confirm]`:
        // confirm.js loads at the END of base.html.twig, so a click that
        // beats its delegated listener submits the form NATIVELY, no dialog
        // is ever built, and answerConfirmation() then waits out its
        // ceiling on a question that was never going to be asked. The
        // `waitForURL()` above does not cover it — it resolves against a URL
        // that already matches, since nothing navigates between the
        // acceptance and this withdrawal.
        await waitForConfirmReady(page);
        await page.getByRole('button', { name: 'Retirer' }).click();
        const asked = await answerConfirmation(page);
        expect(asked).toContain('déjà acceptée');
        expect(asked).toContain('perd sa place');
        await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });

        await familyPage.goto(trackingUrl, { waitUntil: 'domcontentloaded' });
        await expect(familyPage.locator('#main-content').getByText('Retirée')).toBeVisible();

        // ---------------------------------------------------------------
        // Close the form again, then in through the side door: the
        // in-year code the admin generates must open the closed form for
        // whoever types it — and only through their own session.
        // ---------------------------------------------------------------
        await page.goto(SETTINGS_PATH, { waitUntil: 'domcontentloaded' });
        await submitOnSettings(page, page.getByRole('button', { name: 'Fermer maintenant' }), 'Formulaire fermé.');
        await expect(page.getByRole('button', { name: 'Ouvrir maintenant' })).toBeVisible();

        await page.goto(SETTINGS_PATH, { waitUntil: 'domcontentloaded' });
        // Generating a code asks first (design.md §7.5) — a new one would
        // invalidate any code already handed out. waitForConfirmReady()
        // before the click, as its docblock requires: a click that beats
        // confirm.js's listener submits natively and no question is asked.
        await waitForConfirmReady(page);
        await page.getByRole('button', { name: 'Générer un nouveau code' }).click();
        expect(await answerConfirmation(page)).toContain('Générer un code ?');
        await expect(page.getByRole('alert').filter({ hasText: 'Nouveau code généré.' })).toBeVisible();
        const yearCode = (await page.locator('p', { hasText: 'Code actif :' }).locator('strong').innerText()).trim();
        expect(yearCode).not.toBe('');

        await familyPage.goto('/inscriptions', { waitUntil: 'domcontentloaded' });
        await expect(familyPage.getByText('Les inscriptions ne sont pas ouvertes pour le moment.')).toBeVisible();
        await expect(familyPage.locator('#registration-form')).toHaveCount(0);

        // A valid code renders the unlocked page directly (no redirect —
        // PublicRegistrationController::verifyCode()), so the form
        // appearing IS the acceptance.
        await familyPage.getByLabel("Code d'inscription en cours d'année").fill(yearCode);
        await familyPage.getByRole('button', { name: 'Valider le code' }).click();
        await expect(familyPage.locator('#registration-form'), 'a valid in-year code must reopen the form for this visitor').toBeVisible();

        // The code is a door, not a switch: the admin-side state stays
        // closed, and the code dies with its deactivation.
        await page.goto(SETTINGS_PATH, { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('button', { name: 'Ouvrir maintenant' })).toBeVisible();
        await waitForConfirmReady(page);
        await page.getByRole('button', { name: 'Désactiver' }).click();
        expect(await answerConfirmation(page)).toContain('ne pourront plus accéder au formulaire');
        await expect(page.getByText('Dernier code (désactivé)')).toBeVisible();

        expect(familyErrors, 'uncaught JavaScript error in the family\'s browser').toEqual([]);
    } finally {
        await family.close();
    }

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
