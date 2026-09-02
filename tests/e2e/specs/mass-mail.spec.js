// End-to-end: un mass mail, du brouillon aux boîtes des membres.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The composition screen is a page of ordinary forms now, but the machine
// behind it is unchanged and its blast radius is the same: "the whole unit
// gets an email" — or nobody does, silently. The body is a contenteditable
// whose value only reaches the server through a hidden field the shared
// rich-text component keeps in step, the attachment is a real multipart
// POST, and the draft → test → sending states are three separate form
// submissions that each redirect. PHPUnit posts arrays it wrote itself and
// never renders a browser; Vitest never touches the server.
//
// The scenario runs the machine end to end: draft created on the creation
// page, attachment added, test send received by an arbitrary address, real
// send launched — and the REAL DELIVERY asserted in the maildrop: the
// member's mailbox gets the message, subject, attachment name and all,
// through the same batch task a production install runs. The tracking page
// then names each recipient with a state.
//
// WHAT IT DELIBERATELY LEAVES ALONE
// ----------------------------------------------------------------------------
// The role-based narrowing of lists for a chief who is NOT chef d'unité
// (MassMailController::isListAllowed()): the harness has no such account —
// its visible half stays with the module's own unit tests until the
// fixture exists.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { pngBuffer } from '../support/png.js';
import { readMailbox, waitForMail } from '../support/maildrop.js';
import { runScheduler } from '../support/scheduler.js';
import { scaled } from '../support/timeouts.js';

const SUBJECT = `Fête d'unité — infos pratiques ${Date.now()}`;
const BODY_LINE = 'Rendez-vous samedi à 14h au local, goûter offert.';
const TEST_RECIPIENT = 'relecture@example.invalid';
const ATTACHMENT_NAME = 'plan-acces.png';

test('a mass mail walks draft → test → sending, and really lands in the members\' mailboxes', async ({ page }) => {
    // The real delivery below turns the instance's own cron several times
    // and waits for the batch to drain — well past the default test
    // budget.
    test.setTimeout(scaled(180_000));

    const memberEmail = process.env.E2E_MEMBER_EMAIL;
    if (!memberEmail) {
        throw new Error('E2E_MEMBER_EMAIL is not set — run via `npm run e2e`.');
    }

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

    // "Lancer l'envoi" asks before it freezes the recipients — through the
    // site's own modal (public/assets/js/mass-mail-compose.js →
    // window.ScoutMagicConfirm), which no Playwright dialog handler can
    // see. Answered here the way the chief answers it, and installed
    // before the first navigation because the observer is an init script.
    await autoConfirm(page);

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto('/mass-mail', { waitUntil: 'load' });

    // ---------------------------------------------------------------
    // Draft, on the creation page. The list options are server-rendered
    // now; that a section list is offered at all is already an assertion.
    // ---------------------------------------------------------------
    await page.getByRole('link', { name: 'Nouvel email' }).click();
    await page.waitForURL(/\/mass-mail\/new$/, { waitUntil: 'load' });

    await page.locator('#mm-list').selectOption({ label: 'Section - Meute E2E' });
    await page.locator('#mm-subject').fill(SUBJECT);
    await page.locator('#mm-body-content').fill(BODY_LINE);

    // Nothing to attach to yet — the page says so rather than offering a
    // control that could not work.
    await expect(page.getByText("Enregistrez d'abord le brouillon")).toBeVisible();

    await page.getByRole('button', { name: 'Créer le brouillon' }).click();

    // Creation lands on the draft's own page.
    await page.waitForURL(/\/mass-mail\/\d+$/, { waitUntil: 'load' });
    await expect(page.locator('#mm-status')).toHaveText('Brouillon');
    await expect(page.getByRole('heading', { level: 1, name: SUBJECT })).toBeVisible();

    // ---------------------------------------------------------------
    // Attachment, now that the draft exists: a real multipart POST.
    // ---------------------------------------------------------------
    // Attachments are restricted to PDF and images
    // (MassMailController::ATTACHMENT_ALLOWED_MIMES).
    await page.locator('input[name="file"]').setInputFiles({
        name: ATTACHMENT_NAME,
        mimeType: 'image/png',
        buffer: pngBuffer(4, 4),
    });
    await page.getByRole('button', { name: 'Ajouter' }).click();
    await page.waitForURL(/\/mass-mail\/\d+$/, { waitUntil: 'load' });
    await expect(page.locator('#mm-attachments-list').getByText(ATTACHMENT_NAME)).toBeVisible();

    // ---------------------------------------------------------------
    // Test mode: the machine's middle state, and a real test message to
    // an arbitrary address.
    // ---------------------------------------------------------------
    await page.getByRole('button', { name: 'Passer en mode test' }).click();
    await page.waitForURL(/\/mass-mail\/\d+$/, { waitUntil: 'load' });
    await expect(page.locator('#mm-status')).toHaveText('Test');

    await page.locator('#mm-test-send-email').fill(TEST_RECIPIENT);
    await page.getByRole('button', { name: 'Envoyer le test' }).click();

    const testMail = await waitForMail(
        (message) => message.to.includes(TEST_RECIPIENT) && message.subject.includes(SUBJECT),
        { description: `a test send addressed to ${TEST_RECIPIENT}`, timeout: scaled(20_000) },
    );
    expect(testMail.text).toContain(BODY_LINE);

    // ---------------------------------------------------------------
    // The real send. The batch task delivers to the section's members;
    // the member's mailbox is the proof, headers to attachment.
    // ---------------------------------------------------------------
    await page.getByRole('button', { name: "Lancer l'envoi" }).click();
    await page.waitForURL(/\/mass-mail\/\d+$/, { waitUntil: 'load' });
    await expect(page.locator('#mm-status')).toHaveText(/Envoi en cours|Envoyé/);

    // The batch task is driven by the scheduler, and public/cron.php is the
    // only thing that runs one — the application does not turn its own
    // queue on the tail of a request any more. So the scenario turns it,
    // once per polling attempt: a batch that re-arms itself for the next
    // chunk needs several passes, and the tracking page is reloaded in the
    // same loop because it is also the page under test.
    await page.getByRole('link', { name: 'Suivi' }).click();
    await page.waitForURL(/\/mass-mail\/\d+\/tracking/, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(memberEmail).first()).toBeVisible();

    await expect.poll(async () => {
        await runScheduler();
        await page.reload({ waitUntil: 'domcontentloaded' });
        return readMailbox().some(
            (message) => message.to.includes(memberEmail) && message.subject.includes(SUBJECT),
        );
    }, {
        message: `the mass mail must reach ${memberEmail} once the batch task runs`,
        timeout: scaled(120_000),
        intervals: [2_000],
    }).toBe(true);

    const delivered = await waitForMail(
        (message) => message.to.includes(memberEmail) && message.subject.includes(SUBJECT),
        { description: `the mass mail delivered to ${memberEmail}` },
    );
    expect(delivered.text).toContain(BODY_LINE);
    expect(delivered.raw, 'the attachment must travel with the message').toContain(ATTACHMENT_NAME);

    // And the tracking page now records the delivery per recipient.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Envoyé', { exact: false }).first()).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});

// The composition page's scout-year block, for a list whose year is not
// the chief's to choose.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The registration module contributes its own mailing list to mass_mail
// (Modules\Registration\Api\ExternalMailingListProvider), and that list is
// always its own fixed target year: Modules\MassMail\Service\
// MailingListService::resolveMembersForYears() resolves it once, tagged
// with targetScoutYearId(), and never re-scopes it against whatever the
// dialog's year checkboxes say. The server has always ignored them for it;
// only the interface kept offering them, advertising a choice that does not
// exist.
//
// The rule is worth a browser precisely because both halves of it are
// cross-module and only exist together at runtime: the option is only in
// the picker because the registration module is enabled and its provider
// reached mass_mail through the composition root (public/index.php
// re-registers the list service with it), and the block only disappears
// because mass-mail-compose.js's updateListTypeUi() ran against the real
// options the server rendered. Vitest sees the script with a fixture it
// wrote itself; PHPUnit sees the list service with no browser. Neither can
// see the two meet.
//
// Locators are the page's own ids where the module's JavaScript binds to
// them (a contract, per ARCHITECTURE.md § 15) and visible French text
// everywhere else — including the list option itself, which is named after
// a scout year and so cannot be written down here.
test('the registration list hides the scout-year choice it never had, and says why', async ({ page }) => {
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto('/mass-mail/new', { waitUntil: 'load' });

    const yearZone = page.locator('#mm-scout-year-zone');
    const note = page.getByText("Cette liste vise toujours l'année d'inscription.");

    // An ordinary list first: the years are a real question there, and the
    // note has nothing to say.
    await page.locator('#mm-list').selectOption({ label: 'Section - Meute E2E' });
    await expect(yearZone).toBeVisible();
    await expect(page.getByText('Année(s) scoute(s)')).toBeVisible();
    await expect(note).toBeHidden();

    // The registration list is labelled after its target year
    // (ExternalMailingListService::describeMailingList()), so the label is
    // read off the page rather than written down — the same reason
    // scout-year-transition.spec.js reads its steps.
    const optionLabels = (await page.locator('#mm-list option').allTextContents())
        .map((label) => label.trim());
    const registrationLabels = optionLabels.filter((label) => /^Inscriptions \d{4}-\d{4}$/.test(label));
    expect(
        registrationLabels,
        `exactly one registration list must be offered — the picker holds ${optionLabels.join(' | ')}`,
    ).toHaveLength(1);

    await page.locator('#mm-list').selectOption({ label: registrationLabels[0] });

    await expect(yearZone).toBeHidden();
    await expect(note).toBeVisible();

    // And back: the block returns, the note goes away. A rule, not a
    // one-way glitch.
    await page.locator('#mm-list').selectOption({ label: 'Section - Meute E2E' });
    await expect(yearZone).toBeVisible();
    await expect(note).toBeHidden();

    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
