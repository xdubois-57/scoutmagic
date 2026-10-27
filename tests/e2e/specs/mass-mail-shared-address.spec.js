// End-to-end: a family address shared by two children — one message per
// address, one entry per child, one notification per account (issue #999).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The three notions the chantier separated (docs/chantiers/
// CHANTIER-envois-groupes.md §2) only meet at runtime, and in four places
// no unit test sees together: the freeze (one message per distinct
// address, one target per member — modules/mass_mail MassMailService), the
// batch task the instance's own cron runs (one notification per account
// and per communication or row — SendBatchHandler), the member page
// (« Communications récentes », one line per target — MemberPageService
// through Api\MassMailQueryInterface), and the notification centre. PHPUnit
// covers each against arrays it wrote; only the browser shows a parent
// what they will actually get.
//
// THE HOUSEHOLD
// ----------------------------------------------------------------------------
// Seeded per test by scripts/e2e-support.php seed-shared-address-siblings
// (tests/e2e/support/shared-address.js): Hathi and Rikki, alone in a
// section of their own, whose Desk address is the same family address —
// also the login of the parents' account — and who each have a confirmed
// address of their own. Per test, with a stamp, so the two tests (and a
// `--repeat-each`) never read each other's mail, entries or notifications.
//
//   classic list « Section - Fratrie E2E … »  → 3 messages (family, Hathi's,
//     Rikki's), one entry on each page, ONE notification for the parents;
//   mail merge, two rows with Hathi's Tiers   → 2 messages per address of
//     Hathi's (4), two entries on Hathi's page, none on Rikki's, and one
//     notification per row (2).
//
// WHAT IT DELIBERATELY LEAVES ALONE
// ----------------------------------------------------------------------------
// The other rows of the chantier's table (« Non envoyée », a member not
// targeted who shares the address, a resend that notifies nobody): they
// are Tests\Modules\MassMail territory, and a browser adds nothing to them
// but minutes. The composition page's own machine is mass-mail.spec.js's
// and mass-mail-merge.spec.js's.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin, loginWithCredentials } from '../support/admin-login.js';
import { readMailbox } from '../support/maildrop.js';
import { runScheduler } from '../support/scheduler.js';
import { seedSharedAddressSiblings } from '../support/shared-address.js';
import { scaled } from '../support/timeouts.js';
import { xlsxBuffer } from '../support/xlsx.js';

/**
 * A stamp for one test: [0-9a-z], short enough for the seed's limit.
 *
 * @returns {string}
 */
function newStamp() {
    return `${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`;
}

/**
 * Error collectors, attached before the first navigation.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {{ pageErrors: string[], serverErrors: string[] }}
 */
function collectErrors(page) {
    /** @type {string[]} */
    const pageErrors = [];
    /** @type {string[]} */
    const serverErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('response', (response) => {
        if (response.status() >= 500) {
            serverErrors.push(`HTTP ${response.status()} on ${response.url()}`);
        }
    });

    return { pageErrors, serverErrors };
}

/**
 * From the draft's page in test mode: launch the real send, then turn the
 * instance's cron until the mailbox holds `expected` messages under the
 * run's tag — a batch that re-arms itself needs several passes. The first
 * pass of a run may also carry the instance's first automatic backup
 * (Core\Maintenance\Task\AutoBackupHandler, seeded due on the first
 * request), hence the roomy ceiling.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} tag
 * @param {number} expected
 * @returns {Promise<Array<{ to: string, subject: string, text: string, raw: string }>>}
 */
async function launchAndDeliver(page, tag, expected) {
    await page.getByRole('button', { name: "Lancer l'envoi" }).click();
    await page.waitForURL(/\/mass-mail\/\d+$/, { waitUntil: 'load' });
    await expect(page.locator('#mm-status')).toHaveText(/Envoi en cours|Envoyé/);

    const tagged = () => readMailbox().filter((message) => message.subject.includes(tag));
    await expect.poll(async () => {
        await runScheduler();

        return tagged().length;
    }, {
        message: `the batch task must deliver ${expected} messages tagged ${tag}`,
        timeout: scaled(150_000),
        intervals: [1_000],
    }).toBeGreaterThanOrEqual(expected);

    // One more pass, then the count again: a message the batch would send
    // twice would show up here rather than pass for « at least ».
    await runScheduler();

    return tagged();
}

/**
 * The « Communications récentes » card of a member page.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} memberYearId
 * @returns {Promise<import('@playwright/test').Locator>}
 */
async function recentCommunications(page, memberYearId) {
    await page.goto(`/members/${memberYearId}`, { waitUntil: 'domcontentloaded' });
    const card = page.locator('.card', { has: page.getByRole('heading', { name: 'Communications récentes' }) });
    await expect(card).toBeVisible();

    return card;
}

test('a classic list sends one message per address, files it on each child\'s page, and notifies the parents once', async ({ page, browser }) => {
    test.setTimeout(scaled(240_000));

    const stamp = newStamp();
    const household = await seedSharedAddressSiblings(stamp);
    const [hathi, rikki] = household.siblings;
    const subject = `Réunion de rentrée ${stamp}`;

    const { pageErrors, serverErrors } = collectErrors(page);
    await autoConfirm(page);
    await loginAsAdmin(page);
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // The section's own list, draft → test → sending.
    // ---------------------------------------------------------------
    await page.goto('/mass-mail/new', { waitUntil: 'load' });
    await page.locator('#mm-list').selectOption({ label: `Section - ${household.section_name}` });
    await page.locator('#mm-subject').fill(subject);
    await page.locator('#mm-body-content').fill('Rendez-vous samedi à 14 h au local.');
    await page.getByRole('button', { name: 'Créer le brouillon' }).click();
    await page.waitForURL(/\/mass-mail\/\d+$/, { waitUntil: 'load' });

    await page.getByRole('button', { name: 'Passer en mode test' }).click();
    await page.waitForURL(/\/mass-mail\/\d+$/, { waitUntil: 'load' });
    await expect(page.locator('#mm-status')).toHaveText('Test');

    // ---------------------------------------------------------------
    // Three addresses, three messages: the family address once, however
    // many children it is the Desk address of.
    // ---------------------------------------------------------------
    const delivered = await launchAndDeliver(page, stamp, 3);
    const recipients = delivered.map((message) => message.to).sort();
    expect(recipients, 'one message per distinct address, never two to the family address').toHaveLength(3);
    for (const address of [household.family_email, hathi.own_email, rikki.own_email]) {
        expect(
            recipients.filter((to) => to.includes(address)),
            `exactly one message to ${address}`,
        ).toHaveLength(1);
    }

    // ---------------------------------------------------------------
    // The parents' side: one notification, both pages, each with the
    // communication once, and « concerne aussi » linking the two.
    // ---------------------------------------------------------------
    const parents = await browser.newContext();
    try {
        const parentPage = await parents.newPage();
        const parentErrors = collectErrors(parentPage);
        await loginWithCredentials(parentPage, household.family_email, household.family_password);
        await answerCookieBanner(parentPage);

        await parentPage.goto('/notifications', { waitUntil: 'domcontentloaded' });
        const notifications = parentPage.getByRole('button', { name: new RegExp(subject) });
        await expect(
            notifications,
            'one notification for the account, though two of its children were targeted',
        ).toHaveCount(1);

        for (const sibling of [hathi, rikki]) {
            const card = await recentCommunications(parentPage, sibling.member_year_id);
            await expect(
                card.getByRole('link', { name: subject }),
                `${sibling.first_name}'s page lists the communication once`,
            ).toHaveCount(1);
        }

        // The notification opens the entry of one of the two children…
        await parentPage.goto('/notifications', { waitUntil: 'domcontentloaded' });
        await notifications.click();
        await parentPage.waitForURL(/\/members\/\d+\/emails\/\d+$/, { waitUntil: 'domcontentloaded' });
        await expect(parentPage.getByRole('heading', { level: 1, name: subject })).toBeVisible();
        const landedOn = Number.parseInt(new URL(parentPage.url()).pathname.split('/')[2], 10);
        expect([hathi.member_year_id, rikki.member_year_id]).toContain(landedOn);
        const other = landedOn === hathi.member_year_id ? rikki : hathi;

        // …and names the other, whom this account may open, with a link to
        // their own entry.
        // Scoped to the sentence: the navigation names both children too.
        const alsoConcerns = parentPage.locator('p', { hasText: 'Cette communication concerne aussi' });
        await expect(alsoConcerns).toBeVisible();
        await alsoConcerns.getByRole('link', { name: new RegExp(other.first_name) }).click();
        await parentPage.waitForURL(
            new RegExp(`/members/${other.member_year_id}/emails/\\d+$`),
            { waitUntil: 'domcontentloaded' },
        );
        await expect(parentPage.getByRole('heading', { level: 1, name: subject })).toBeVisible();

        expect(parentErrors.serverErrors, 'the application returned a server error').toEqual([]);
        expect(parentErrors.pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
    } finally {
        await parents.close();
    }

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});

test('a mail merge with two rows for the same Tiers sends two messages per address and files two entries', async ({ page, browser }) => {
    test.setTimeout(scaled(240_000));

    const stamp = newStamp();
    const household = await seedSharedAddressSiblings(stamp);
    const [hathi, rikki] = household.siblings;

    const { pageErrors, serverErrors } = collectErrors(page);
    await autoConfirm(page);
    await loginAsAdmin(page);
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // Two rows, both Hathi's Tiers, each with its own values.
    // ---------------------------------------------------------------
    await page.goto('/mass-mail/new', { waitUntil: 'load' });
    await page.locator('#mm-list').selectOption({ label: 'Publipostage — fichier Excel' });
    await page.locator('#mm-merge-file').setInputFiles({
        name: 'activites.xlsx',
        mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        buffer: xlsxBuffer([
            ['Tiers', 'Activite', 'Montant'],
            [hathi.desk_id, 'Hike', '12'],
            [hathi.desk_id, 'Camp', '145'],
        ]),
    });
    await expect(page.locator('#mm-merge-info-state')).toContainText('2 lignes');

    await page.locator('#mm-subject').fill(`{{Activite}} ${stamp}`);
    const body = page.locator('#mm-body-content');
    await body.click();
    await page.keyboard.type('Pour {{Activite}}, le montant est de {{Montant}} €.');
    await page.getByRole('button', { name: 'Créer le brouillon' }).click();
    await page.waitForURL(/\/mass-mail\/\d+$/, { waitUntil: 'load' });

    await page.getByRole('button', { name: 'Passer en mode test' }).click();
    await page.waitForURL(/\/mass-mail\/\d+$/, { waitUntil: 'load' });
    await expect(page.locator('#mm-status')).toHaveText('Test');

    // ---------------------------------------------------------------
    // Two rows × Hathi's two addresses: four messages, each address one
    // per row with that row's values — the rows are never merged — and
    // nothing to Rikki's own address: sharing the family address does not
    // make a sibling a recipient.
    // ---------------------------------------------------------------
    const delivered = await launchAndDeliver(page, stamp, 4);
    expect(delivered, 'two messages per address of the Tiers').toHaveLength(4);
    for (const address of [household.family_email, hathi.own_email]) {
        const toAddress = delivered.filter((message) => message.to.includes(address));
        // Read by the rendered row value: the subject header also carries
        // the site's prefix (Core\Mail\MailService).
        const rows = toAddress.map((message) => (['Camp', 'Hike'].find(
            (activity) => message.subject.includes(`${activity} ${stamp}`),
        ) ?? message.subject));
        expect(rows.sort(), `one message per row to ${address}`).toEqual(['Camp', 'Hike']);
        expect(toAddress.find((message) => message.subject.includes(`Camp ${stamp}`))?.text)
            .toContain('Pour Camp, le montant est de 145 €.');
    }
    expect(delivered.filter((message) => message.to.includes(rikki.own_email))).toHaveLength(0);

    // ---------------------------------------------------------------
    // Two entries on Hathi's page, none on Rikki's; one notification per
    // row for the parents.
    // ---------------------------------------------------------------
    const parents = await browser.newContext();
    try {
        const parentPage = await parents.newPage();
        const parentErrors = collectErrors(parentPage);
        await loginWithCredentials(parentPage, household.family_email, household.family_password);
        await answerCookieBanner(parentPage);

        const hathiCard = await recentCommunications(parentPage, hathi.member_year_id);
        await expect(hathiCard.getByRole('link', { name: `Hike ${stamp}` })).toHaveCount(1);
        await expect(hathiCard.getByRole('link', { name: `Camp ${stamp}` })).toHaveCount(1);

        const rikkiCard = await recentCommunications(parentPage, rikki.member_year_id);
        await expect(rikkiCard.getByRole('link', { name: new RegExp(stamp) })).toHaveCount(0);

        await parentPage.goto('/notifications', { waitUntil: 'domcontentloaded' });
        await expect(
            parentPage.getByRole('button', { name: new RegExp(stamp) }),
            'one notification per row of the file',
        ).toHaveCount(2);

        expect(parentErrors.serverErrors, 'the application returned a server error').toEqual([]);
        expect(parentErrors.pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
    } finally {
        await parents.close();
    }

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
