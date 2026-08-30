// End-to-end: un mass mail, du brouillon aux boîtes des membres.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The compose dialog is entirely client-built: MM_DATA (a Twig→JS blob)
// drives which sections and lists are even offered, the body is a
// contenteditable the script serialises itself, and the draft → test →
// sending status machine exists as fetches from four different buttons.
// The blast radius of a regression here is "the whole unit gets an
// email" — or nobody does, silently. Vitest has no file for any of it;
// PHPUnit posts arrays it wrote itself.
//
// The scenario runs the machine end to end: draft saved, attachment
// added (multipart FormData from the dialog), test send received by an
// arbitrary address, real send launched — and the REAL DELIVERY asserted
// in the maildrop: the member's mailbox gets the message, subject,
// attachment name and all, through the same batch task a production
// install runs. The tracking page then names each recipient with a
// state.
//
// WHAT IT DELIBERATELY LEAVES ALONE
// ----------------------------------------------------------------------------
// The role-based narrowing of lists for a chief who is NOT chef d'unité
// (isListAllowed()): the harness has no such account — its visible half
// stays with the module's own unit tests until the fixture exists.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { pngBuffer } from '../support/png.js';
import { readMailbox, waitForMail } from '../support/maildrop.js';
import { scaled } from '../support/timeouts.js';

const SUBJECT = `Fête d'unité — infos pratiques ${Date.now()}`;
const BODY_LINE = 'Rendez-vous samedi à 14h au local, goûter offert.';
const TEST_RECIPIENT = 'relecture@example.invalid';
const ATTACHMENT_NAME = 'plan-acces.png';

test('a mass mail walks draft → test → sending, and really lands in the members\' mailboxes', async ({ page }) => {
    // The real delivery below waits out the poor-man's cron (at most one
    // scheduler pass per minute) — well past the default test budget.
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
    // site's own modal now (public/assets/js/mass-mail-list.js →
    // window.ScoutMagicConfirm), which no Playwright dialog handler can
    // see. Answered here the way the chief answers it, and installed
    // before the first navigation because the observer is an init script.
    await autoConfirm(page);

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto('/mass-mail', { waitUntil: 'load' });

    // ---------------------------------------------------------------
    // Draft. Everything in the dialog is script-rendered from MM_DATA —
    // the section list options existing at all is already an assertion.
    // ---------------------------------------------------------------
    await page.locator('#mm-new-btn').click();
    const dialog = page.locator('#mm-modal');
    await expect(dialog).toBeVisible();

    const listSelect = dialog.locator('#mm-list');
    await listSelect.selectOption({ label: 'Section - Meute E2E' });

    await dialog.locator('#mm-subject').fill(SUBJECT);
    await dialog.locator('#mm-body-content').fill(BODY_LINE);

    // No draft yet — the dialog itself says attachments must wait.
    await expect(dialog.locator('#mm-attachment-hint')).toBeVisible();

    await dialog.locator('#mm-save-btn').click();

    // Saving re-renders the list row with its status badge — via a real
    // window.location.reload() (list.html.twig's mm-save-btn handler),
    // not a dynamic DOM update, so the freshly rendered row's own
    // .mm-open-btn only gets a click listener once THIS reload's copy of
    // the page's own inline <script> (querySelectorAll('.mm-open-btn')
    // at the bottom of the page) has run. The row itself can already be
    // visible before that — rendering reaches it well before parsing
    // reaches the script further down — so an explicit wait here is
    // required; the assertions above only prove the row exists, not that
    // the page has finished loading.
    const row = page.getByRole('row', { name: new RegExp(SUBJECT.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) });
    await expect(row).toBeVisible();
    await expect(row.getByText('Brouillon')).toBeVisible();
    await page.waitForLoadState('domcontentloaded');

    // ---------------------------------------------------------------
    // Attachment, now that the draft exists: a real multipart POST.
    // ---------------------------------------------------------------
    // Saving reloaded the page, and mass-mail-list.js is deferred: the row
    // is server-rendered and therefore visible BEFORE the script that
    // makes its buttons do anything has run. Without this wait the click
    // below lands on a button with no listener yet and the dialog simply
    // never opens.
    await page.waitForLoadState('load');
    await row.locator('.mm-open-btn').click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#mm-subject')).toHaveValue(SUBJECT);

    // Attachments are restricted to PDF and images
    // (MassMailController::ATTACHMENT_ALLOWED_MIMES).
    await dialog.locator('#mm-attachment-file').setInputFiles({
        name: ATTACHMENT_NAME,
        mimeType: 'image/png',
        buffer: pngBuffer(4, 4),
    });
    await dialog.locator('#mm-attachment-upload-btn').click();
    await expect(dialog.locator('#mm-attachments-list').getByText(ATTACHMENT_NAME)).toBeVisible();

    // ---------------------------------------------------------------
    // Test mode: the machine's middle state, and a real test message to
    // an arbitrary address.
    // ---------------------------------------------------------------
    await dialog.locator('#mm-to-test-btn').click();
    await expect(dialog.locator('#mm-status-badge')).toHaveText('Test');

    await dialog.locator('#mm-test-send-email').fill(TEST_RECIPIENT);
    await dialog.locator('#mm-test-send-btn').click();

    const testMail = await waitForMail(
        (message) => message.to.includes(TEST_RECIPIENT) && message.subject.includes(SUBJECT),
        { description: `a test send addressed to ${TEST_RECIPIENT}`, timeout: scaled(20_000) },
    );
    expect(testMail.text).toContain(BODY_LINE);

    // ---------------------------------------------------------------
    // The real send. The batch task delivers to the section's members;
    // the member's mailbox is the proof, headers to attachment.
    // ---------------------------------------------------------------
    await dialog.locator('#mm-start-sending-btn').click();
    await expect(dialog.locator('#mm-status-badge')).toHaveText(/Envoi en cours|Envoyé/);

    // The batch task is driven by the scheduler, which this instance runs
    // through the poor-man's cron — a pass at most once per minute, and
    // only on the tail of a request. Reloading the tracking page is both
    // the request stream that lets the cron fire AND the page under test.
    await dialog.locator('#mm-tracking-link').click();
    await page.waitForURL(/\/mass-mail\/\d+\/tracking/, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(memberEmail).first()).toBeVisible();

    await expect.poll(async () => {
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
