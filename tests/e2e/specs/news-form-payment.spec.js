// End-to-end: an actualité with a paying form on it, from the chief who
// builds it to the family who signs up and is asked for money.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Two things about this feature exist ONLY in a browser talking to a real
// server, and are invisible to every other suite:
//
//   1. The form the chief builds never travels as form fields. public/
//      assets/js/news-form-builder.js keeps the whole field list in a
//      JavaScript array and serialises it into ONE hidden input
//      (`fields_json`) on submit — exactly the class of hand-built request
//      body that put three bugs in the groups composer (see
//      groups-discussion.spec.js). PHPUnit sees a $_POST a test wrote
//      itself; Vitest sees a jsdom form with no server behind it. Nothing
//      but a browser can say that what the builder drew is what the
//      server stored.
//   2. The money is a cross-module handshake. modules/news declares no
//      foreign key to finance and holds only nullable interfaces
//      (ExpectedReceivableInterface, StructuredCommunicationInterface,
//      SepaQrCodeInterface, FinanceAccountInterface — ARCHITECTURE.md
//      §7.5). Whether an account configured in Finances actually turns
//      into a structured communication, a receivable, and a SEPA QR code
//      on a confirmation page is a question about the composition root,
//      which is the one thing public/index.php alone assembles.
//
// It also pins one rule that is a security decision rather than a
// feature: an ANONYMOUS submission's confirmation email carries the
// payment summary and NOT the answers (Service\ResponseService — the
// recipient address and the answer text are both attacker-controlled
// there, and echoing one to the other would make a DKIM-signed relay out
// of it). That is only observable where a real email is produced, which
// the harness's maildrop now makes possible.
//
// WHAT IT DELIBERATELY LEAVES ALONE
// ----------------------------------------------------------------------------
// The bank side of the payment: reconciling a statement against a
// receivable, partial payments, and the receivable's own lifecycle are
// Tests\Modules\Finance\Service\ExpectedReceivableServiceTest's, where
// they can be driven exhaustively. This scenario stops where the unit's
// own screen stops — the amount is due, the communication is quoted, and
// the responses page shows it unpaid.
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text throughout, the field builder's own edit panel
// included. That panel used to be the exception — its controls were built
// in JavaScript with <label> elements carrying no `for`, so they named
// nothing and had to be reached by structure. They are properly associated
// now (news-form-builder.js), which is why every one of them below is
// addressed by the name a chief actually reads, and why this scenario is
// also the regression test for that: a label that stops naming its control
// fails here, in a real accessibility tree rather than a simulated one.
//
// The one structural handle left is the panel each field opens
// (`[data-key]`), which is the identity news-form-builder.js gives a field
// in its own state array — a contract of that script, and the only way to
// say "the second field's panel" rather than "some panel".
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from '../support/admin-login.js';
import { waitForMail } from '../support/maildrop.js';
import { waitOutHumanCheckDelay } from '../support/human-check.js';

// Unique per run, so a re-run against a database that somehow survived
// still matches its own article rather than an older one by name.
const ARTICLE_TITLE = `Week-end de rentrée ${Date.now()}`;
const ARTICLE_SUMMARY = "Deux jours à Han-sur-Lesse pour lancer l'année.";
const PLACES_LABEL = 'Nombre de participants';
const REMARKS_LABEL = 'Allergies ou remarques';
const REMARKS_ANSWER = 'Sans gluten pour le samedi soir.';
const PRICE_PER_PLACE = '12.50';
const CAPACITY = '3';

const ACCOUNT_NAME = 'Compte camps E2E';
const ACCOUNT_IBAN = 'BE71 0961 2345 6769';
const ACCOUNT_HOLDER = 'Unité de test E2E';

const FAMILY_EMAIL = 'famille@example.invalid';
const SECOND_FAMILY_EMAIL = 'autre-famille@example.invalid';

/** A 1×1 PNG — the article's featured image is mandatory server-side. */
const PIXEL_PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
);

/**
 * The builder's row for one field, and the panel that opens under it when
 * the row is clicked. Both are keyed on `data-key`, the identity
 * news-form-builder.js gives each field in its own state array — a
 * contract of that script, not incidental markup. Scoping to it is what
 * makes "the capacity box of THIS field" unambiguous; everything inside is
 * then addressed by its accessible name.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} index position in the field list (0 is the pinned text block)
 */
function fieldRow(page, index) {
    return page.locator('#news-fields-list > [data-key]').nth(index);
}

test('a chief publishes an article with a paying form, and a family signs up and is asked to pay', async ({ page }) => {
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

    // Both the finance page and the builder report failures through
    // window.alert(), which Playwright dismisses silently by default.
    /** @type {string[]} */
    const alerts = [];
    page.on('dialog', async (dialog) => {
        alerts.push(dialog.message());
        await dialog.dismiss();
    });

    await loginAsAdmin(page);

    // ---------------------------------------------------------------
    // The unit's treasurer gives Finances an account to be paid on. Only
    // an ACTIVE account is ever offered to another module, and a bank
    // account earns that the moment it has both an IBAN and a holder
    // (Service\FinanceService::activateIfEligible()) — which is why both
    // are filled here rather than just the name.
    // ---------------------------------------------------------------
    await page.goto('/config/finance/accounts', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Nouveau compte' }).click();

    await page.getByLabel('Nom', { exact: true }).fill(ACCOUNT_NAME);
    await page.getByLabel('IBAN').fill(ACCOUNT_IBAN);
    await page.getByLabel('Nom du titulaire').fill(ACCOUNT_HOLDER);
    await page.getByRole('button', { name: 'Enregistrer' }).click();

    // The page reloads itself once the account is stored, so the row
    // appearing IS the round trip completing.
    const accountRow = page.getByRole('row', { name: new RegExp(ACCOUNT_NAME) });
    await expect(accountRow).toBeVisible();
    await expect(accountRow.getByText('Actif')).toBeVisible();
    expect(alerts, 'configuring the finance account failed').toEqual([]);

    // ---------------------------------------------------------------
    // The chief writes the article and builds the form on it. Every
    // control below is the real editor's — nothing is posted by hand.
    // ---------------------------------------------------------------
    await page.goto('/news/manage', { waitUntil: 'domcontentloaded' });
    // Scoped to the page's content, and .first() within it: the management
    // list offers the same link twice (its header, and an empty-state call
    // to action). Page-wide .first() is what put a hidden navigation link
    // in front of a click elsewhere in this suite — see
    // support/admin-login.js's logoutControl().
    await page.locator('main').getByRole('link', { name: 'Nouvel article' }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'Nouvel article' })).toBeVisible();

    await page.getByLabel("Titre de l'article").fill(ARTICLE_TITLE);
    await page.getByLabel('Résumé en une phrase').fill(ARTICLE_SUMMARY);
    // Mandatory server-side (Service\ArticleService): an article without a
    // featured image is refused, so this is part of the flow.
    await page.locator('#image').setInputFiles({
        name: 'affiche.png',
        mimeType: 'image/png',
        buffer: PIXEL_PNG,
    });

    // Public — which is also what makes the form itself public
    // (NewsController::extractFormSettings() derives the form's access
    // from the article's visibility; there is no separate control).
    await page.getByRole('radio', { name: 'Public', exact: true }).check();

    // A brand-new article opens on its pinned "bloc de texte", already
    // expanded, and that block IS the article's body. Reached by the name
    // its caption gives it: the editor is a contenteditable, which no
    // <label for> can point at, so news-form-builder.js names it with
    // role="textbox" + aria-labelledby instead.
    const bodyEditor = fieldRow(page, 0).getByRole('textbox', { name: 'Contenu' });
    await expect(bodyEditor).toBeVisible();
    await bodyEditor.fill('Rendez-vous le 12 septembre devant le local, avec le pique-nique du midi.');

    // --- A priced, capped number field: how many places, at how much. ---
    await page.getByRole('button', { name: 'Ajouter un champ' }).click();
    await page.getByRole('button', { name: 'Nombre' }).click();

    const placesPanel = fieldRow(page, 1);
    await placesPanel.getByLabel('Libellé du champ').fill(PLACES_LABEL);
    await placesPanel.getByLabel('Limiter la capacité').check();
    await placesPanel.getByLabel('Capacité maximale').fill(CAPACITY);
    await placesPanel.getByLabel('Prix unitaire (€)').fill(PRICE_PER_PLACE);

    // --- And a free-text one, so the confirmation email has something it
    // must NOT echo back to an anonymous address. ---
    await page.getByRole('button', { name: 'Ajouter un champ' }).click();
    await page.getByRole('button', { name: 'Texte court' }).click();

    const remarksPanel = fieldRow(page, 2);
    await remarksPanel.getByLabel('Libellé du champ').fill(REMARKS_LABEL);
    await remarksPanel.getByLabel('Obligatoire').uncheck();

    // The payment box appears only once a field really carries a price —
    // not merely because the Finances module is installed.
    const paymentSettings = page.locator('#news-payment-settings');
    await expect(paymentSettings).toBeVisible();
    await paymentSettings.getByLabel('Compte destinataire').selectOption({ label: ACCOUNT_NAME });

    await page.getByRole('button', { name: 'Enregistrer' }).click();

    // Saving lands back on the article's own editor (NewsController::
    // store()), now with an id — which is also where the short link and
    // the A4 poster appear, so it is the page a chief really ends on.
    await page.waitForURL(/\/news\/\d+\/edit$/, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: "Éditer l'article" })).toBeVisible();

    const articleUrl = new URL(page.url()).pathname.replace(/\/edit$/, '');

    // The article really is in the unit's list, under the title typed
    // above — the save was a save, not a redirect.
    await page.goto('/news/manage', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('link', { name: ARTICLE_TITLE })).toBeVisible();

    // ---------------------------------------------------------------
    // A family reads the article. Anonymous, in its own cookie jar: a
    // public article's form takes no login, and running it in the chief's
    // session would quietly skip the anti-bot barriers that only apply to
    // a visitor who is not identified.
    // ---------------------------------------------------------------
    const family = await page.context().browser().newContext();
    try {
        const familyPage = await family.newPage();
        /** @type {string[]} */
        const familyServerErrors = [];
        familyPage.on('response', (response) => {
            if (response.status() >= 500) {
                familyServerErrors.push(`HTTP ${response.status()} on ${response.url()}`);
            }
        });

        await familyPage.goto('/news', { waitUntil: 'domcontentloaded' });

        // Deal with the consent banner the way a visitor must before it
        // stops standing in front of the page — and refuse, which also
        // makes the rest of this scenario a statement that signing up and
        // paying needs no non-essential cookie at all.
        await familyPage.getByRole('button', { name: 'Tout refuser' }).click();
        await expect(familyPage.locator('#cookie-banner')).toBeHidden();

        await familyPage.getByRole('link', { name: ARTICLE_TITLE }).click();

        await expect(familyPage.getByRole('heading', { level: 1, name: ARTICLE_TITLE })).toBeVisible();
        await expect(familyPage.getByText('Rendez-vous le 12 septembre')).toBeVisible();
        await expect(familyPage.getByRole('heading', { name: 'Formulaire' })).toBeVisible();

        const places = familyPage.getByLabel(new RegExp(PLACES_LABEL));
        await expect(familyPage.getByText(`Il reste ${CAPACITY} places`)).toBeVisible();

        // The running total is drawn by news-form-builder.js from the
        // price the server rendered onto the field — the one number the
        // family sees before committing, and one no other suite can
        // watch being computed against real data.
        await familyPage.getByLabel('Email de contact').fill(FAMILY_EMAIL);
        await places.fill('2');
        await expect(familyPage.locator('#news-payment-total')).toHaveText('25,00');
        await expect(familyPage.getByText(`${PLACES_LABEL} : 2 × 12,50€ = 25,00€`)).toBeVisible();

        await familyPage.getByLabel(REMARKS_LABEL).fill(REMARKS_ANSWER);

        await waitOutHumanCheckDelay(familyPage);
        await familyPage.getByRole('button', { name: 'Envoyer' }).click();

        // ---------------------------------------------------------------
        // The confirmation page: what to pay, to whom, and under which
        // communication — plus the SEPA QR code the family scans.
        // ---------------------------------------------------------------
        await expect(familyPage.getByRole('heading', { name: 'Votre réponse a été enregistrée' })).toBeVisible();
        await expect(familyPage.getByText('Montant à payer : 25,00 €')).toBeVisible();
        await expect(familyPage.getByText(`IBAN : ${ACCOUNT_IBAN.replace(/ /g, '')}`)).toBeVisible();
        await expect(familyPage.getByText(`Compte : ${ACCOUNT_HOLDER}`)).toBeVisible();
        await expect(familyPage.getByRole('img', { name: 'QR code SEPA' })).toBeVisible();

        // The structured communication is generated by Finances and is
        // what ties the future bank transfer to this response. Read off
        // the page rather than guessed — its shape is that module's
        // business, not this scenario's.
        const communication = (await familyPage.locator('p', { hasText: 'Communication structurée' })
            .innerText()).replace('Communication structurée :', '').trim();
        expect(communication, 'the confirmation must quote a structured communication').not.toBe('');

        // ---------------------------------------------------------------
        // The receipt that reaches the family's mailbox — and what it must
        // not contain. On an ANONYMOUS submission both the recipient and
        // the free-text answers are supplied by whoever filled the form,
        // so ResponseService sends the unit-generated payment summary and
        // none of that text (audit M14). Nothing but a real email can say
        // whether that still holds.
        // ---------------------------------------------------------------
        const receipt = await waitForMail(
            (message) => message.to.includes(FAMILY_EMAIL) && message.subject.includes(ARTICLE_TITLE),
            { description: `a confirmation addressed to ${FAMILY_EMAIL}` },
        );

        expect(receipt.text).toContain(communication);
        expect(receipt.text).toContain('25,00');
        expect(
            receipt.text,
            'an anonymous submitter\'s own free text must never be relayed back by a DKIM-signed mail',
        ).not.toContain(REMARKS_ANSWER);

        // ---------------------------------------------------------------
        // And the places really are gone. A second family finds one left,
        // takes it, and the field closes itself.
        // ---------------------------------------------------------------
        await familyPage.goto(articleUrl, { waitUntil: 'domcontentloaded' });
        await expect(familyPage.getByText('Il reste 1 place', { exact: false })).toBeVisible();
        await expect(familyPage.getByLabel(new RegExp(PLACES_LABEL))).toHaveAttribute('max', '1');

        await familyPage.getByLabel('Email de contact').fill(SECOND_FAMILY_EMAIL);
        await familyPage.getByLabel(new RegExp(PLACES_LABEL)).fill('1');
        await expect(familyPage.locator('#news-payment-total')).toHaveText('12,50');
        await waitOutHumanCheckDelay(familyPage);
        await familyPage.getByRole('button', { name: 'Envoyer' }).click();
        await expect(familyPage.getByRole('heading', { name: 'Votre réponse a été enregistrée' })).toBeVisible();

        await familyPage.goto(articleUrl, { waitUntil: 'domcontentloaded' });
        // The exhausted field no longer renders a disabled-but-required
        // input (which made the whole form unsubmittable): its label stays
        // with a "Complet" notice, and no input is rendered at all.
        await expect(familyPage.getByText("Complet — cette option n'est plus proposée.")).toBeVisible();
        await expect(familyPage.getByLabel(new RegExp(PLACES_LABEL))).toHaveCount(0);

        expect(familyServerErrors, 'the application returned a server error to the visitor').toEqual([]);
    } finally {
        await family.close();
    }

    // ---------------------------------------------------------------
    // Back in the unit: the two sign-ups are there, with what each owes
    // and the fact that nobody has paid yet.
    // ---------------------------------------------------------------
    await page.goto(`${articleUrl}/form/responses`, { waitUntil: 'domcontentloaded' });

    // The page renders the same rows twice — a table for a desktop and
    // cards for a phone — so every assertion is scoped to the table.
    const responses = page.getByRole('table');
    // Anchored: one address is a suffix of the other, and an unanchored
    // match would resolve to both rows at once.
    const rowFor = (email) => responses.getByRole('row', { name: new RegExp(`^${email.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`) });

    await expect(rowFor(FAMILY_EMAIL)).toBeVisible();
    await expect(rowFor(SECOND_FAMILY_EMAIL)).toBeVisible();

    const firstResponse = rowFor(FAMILY_EMAIL);
    await expect(firstResponse.getByText('25,00 €')).toBeVisible();
    await expect(firstResponse.getByText(REMARKS_ANSWER)).toBeVisible();
    await expect(firstResponse.getByText('Non payé')).toBeVisible();

    expect(alerts, 'the application reported an error through window.alert()').toEqual([]);
    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
