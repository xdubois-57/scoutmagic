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
//      this instance has no acceptance-email body written. Only two
//      browsers with different privileges can watch both sides disagree
//      on purpose.
//   3. The branch hint under the birth-date field is computed client-side
//      from Twig-injected slot data (public.html.twig's nonce'd inline
//      script) — a CSP or serialization regression kills it silently.
//   4. The in-year code is the only way into a CLOSED form, and it is
//      session-scoped: the code an admin generates on the config page
//      must open the form for the visitor who typed it.
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

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { linkFromMail, waitForMail } from '../support/maildrop.js';
import { waitOutHumanCheckDelay } from '../support/human-check.js';

const PARENT_NAME = 'Camille Verstraeten';
const FAMILY_EMAIL = 'famille-inscription@example.invalid';
const FAMILY_PHONE = '+32 470 11 22 33';
const FAMILY_STREET = 'Rue des Écureuils';
const CHILD_FIRST_NAME = `Zoé${Date.now() % 100000}`;
const CHILD_LAST_NAME = 'Verstraeten';

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
    await page.goto('/config/inscriptions', { waitUntil: 'domcontentloaded' });
    // The toggle is one button whose label IS the current state — waiting
    // on either label first keeps this step honest about which state the
    // page really rendered before acting on it.
    await expect(page.getByRole('button', { name: /^(Ouvrir|Fermer) maintenant$/ })).toBeVisible();
    if (await page.getByRole('button', { name: 'Ouvrir maintenant' }).isVisible()) {
        await page.getByRole('button', { name: 'Ouvrir maintenant' }).click();
    }
    await expect(page.getByRole('button', { name: 'Fermer maintenant' })).toBeVisible();

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
        await form.getByRole('checkbox', { name: /J'accepte/ }).check();

        await waitOutHumanCheckDelay(familyPage, form);
        await form.getByRole('button', { name: 'Envoyer la demande' }).click();

        await expect(familyPage.getByRole('heading', { name: 'Merci !' })).toBeVisible();
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
        const trackingUrl = linkFromMail(receipt, '/inscriptions/suivi/');

        await familyPage.goto(trackingUrl, { waitUntil: 'domcontentloaded' });
        await expect(familyPage.getByRole('heading', { name: 'Suivi de votre demande' })).toBeVisible();
        await expect(familyPage.getByText(CHILD_FIRST_NAME)).toBeVisible();
        await expect(familyPage.getByText("En attente d'examen")).toBeVisible();

        const trackingBody = await familyPage.locator('main').innerText();
        for (const [label, value] of [['street', FAMILY_STREET], ['phone', FAMILY_PHONE], ['parent name', PARENT_NAME]]) {
            expect(trackingBody, `the token view must not echo the family's ${label} back`).not.toContain(value);
        }

        // ---------------------------------------------------------------
        // The admin accepts — and the family must NOT see it yet: no
        // acceptance-email body is written on this instance, so the
        // decision stays invisible until that email really goes out.
        // ---------------------------------------------------------------
        await page.goto('/config/inscriptions', { waitUntil: 'domcontentloaded' });
        await page.getByRole('row', { name: new RegExp(CHILD_FIRST_NAME) })
            .getByRole('link', { name: 'Ouvrir' }).click();
        await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });

        await expect(page.getByText(CHILD_LAST_NAME).first()).toBeVisible();
        await page.getByRole('button', { name: 'Accepter', exact: true }).click();
        await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });

        await expect(page.getByText('visible par la famille comme « en attente »')).toBeVisible();
        await expect(
            page.locator('form[action$="/send-accepted-email"] button'),
            'with no acceptance body written, the send button must be disabled',
        ).toBeDisabled();

        await familyPage.goto(trackingUrl, { waitUntil: 'domcontentloaded' });
        await expect(
            familyPage.getByText("En attente d'examen"),
            'an unsent acceptance must stay invisible to the family',
        ).toBeVisible();

        // ---------------------------------------------------------------
        // The request ends in a final state — withdrawn shows to the
        // family immediately (only accept/refuse are email-gated), and a
        // final state is what keeps the scout-year transition unvetoed
        // for the spec that runs it later.
        // ---------------------------------------------------------------
        await page.getByRole('button', { name: 'Retirer' }).click();
        await page.waitForURL(/\/config\/inscriptions\/demandes\/\d+$/, { waitUntil: 'domcontentloaded' });

        await familyPage.goto(trackingUrl, { waitUntil: 'domcontentloaded' });
        await expect(familyPage.getByText('Retirée')).toBeVisible();

        // ---------------------------------------------------------------
        // Close the form again, then in through the side door: the
        // in-year code the admin generates must open the closed form for
        // whoever types it — and only through their own session.
        // ---------------------------------------------------------------
        await page.goto('/config/inscriptions', { waitUntil: 'domcontentloaded' });
        await page.getByRole('button', { name: 'Fermer maintenant' }).click();
        await expect(page.getByRole('button', { name: 'Ouvrir maintenant' })).toBeVisible();

        await page.getByRole('button', { name: 'Générer un nouveau code' }).click();
        await page.waitForURL('**/config/inscriptions', { waitUntil: 'domcontentloaded' });
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
        await page.goto('/config/inscriptions', { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('button', { name: 'Ouvrir maintenant' })).toBeVisible();
        await page.getByRole('button', { name: 'Désactiver' }).click();
        await expect(page.getByText('Dernier code (désactivé)')).toBeVisible();

        expect(familyErrors, 'uncaught JavaScript error in the family\'s browser').toEqual([]);
    } finally {
        await family.close();
    }

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
