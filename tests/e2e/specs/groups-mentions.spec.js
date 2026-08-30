// End-to-end: the group behaviours the two existing groups specs leave
// open — a mention found by the autocomplete and delivered as a
// notification, a pin with its duration dialog, "Vu par" naming the
// reader, and the group's delegated gallery.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Each link of this chain crosses the browser/server line in a way the
// unit suites cannot:
//
//   - The mention autocomplete is a live fetch to /groups/{id}/
//     mention-search fed from keystrokes mid-token; picking an entry
//     rewrites the textarea, and the SERVER later re-parses that text to
//     decide who gets notified. jsdom tests each half against stubs;
//     only this walks a mention from keystroke to the other person's
//     notification centre.
//   - Épingler intercepts a plain form, asks its duration through the
//     shared dialog, writes the answer into a hidden field and lets the
//     form post — the exact "browser decides the request body" shape.
//   - "Vu par N" is meaningful only between two people: one reads, the
//     other's card updates, and the dialog names the reader — an
//     interaction of two sessions plus a privacy rule (author-only).
//   - The group gallery is the gallery module's grid served through
//     groups' delegated-album access check — a cross-module composition
//     only the composition root wires.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin, loginAsMember } from '../support/admin-login.js';
import { openCreateGroupForm, waitForGroupsJsReady } from '../support/groups.js';
import { pngBuffer } from '../support/png.js';
import { scaled } from '../support/timeouts.js';

const GROUP_NAME = `Intendance camp ${Date.now()}`;
const MESSAGE_PREFIX = 'Bienvenue dans le groupe ';
const PHOTO_MESSAGE = 'La photo du terrain pour le camp.';

test('a mention travels to a notification, a pin asks its duration, "Vu par" names the reader, and the group gallery serves the photo', async ({ page, browser }) => {
    // Two sessions, a media-processing pipeline, and the once-a-minute
    // scheduler behind it.
    test.setTimeout(scaled(240_000));

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
    // An invitation group with the ordinary member invited in — the
    // same fixture path groups-management.spec.js pins in detail.
    // ---------------------------------------------------------------
    await page.goto('/groups', { waitUntil: 'domcontentloaded' });
    await openCreateGroupForm(page);
    const creation = page.locator('form[action="/groups"]');
    await creation.getByLabel('Nom du groupe').fill(GROUP_NAME);
    await creation.getByLabel('Section').selectOption({ label: 'Sur invitation (sans section)' });
    await creation.getByRole('button', { name: 'Créer' }).click();
    await page.waitForURL(/\/groups\/\d+$/, { waitUntil: 'domcontentloaded' });
    const groupUrl = new URL(page.url()).pathname;
    await waitForGroupsJsReady(page);

    await page.goto(`${groupUrl}/members`, { waitUntil: 'domcontentloaded' });
    await waitForGroupsJsReady(page);
    await page.locator('#invite-member-search').fill('Kaa');
    await page.locator('#invite-member-results').getByText('Kaa', { exact: false }).first().click();
    await page.getByRole('button', { name: 'Inviter' }).click();
    await page.waitForURL('**/members', { waitUntil: 'domcontentloaded' });

    // ---------------------------------------------------------------
    // The mention. "@Ka" typed key by key wakes the autocomplete; the
    // pick completes the name in the textarea; publishing carries it.
    // ---------------------------------------------------------------
    await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });
    await waitForGroupsJsReady(page);

    const composer = page.locator('#groups-post-form');
    const editor = page.getByLabel('Écrire un message');
    await editor.fill(MESSAGE_PREFIX);
    await editor.pressSequentially('@Ka', { delay: 80 });

    const mentionOption = page.locator('.groups-mention-option', { hasText: 'Kaa' }).first();
    await expect(mentionOption, 'the autocomplete must offer the group member').toBeVisible();
    await mentionOption.click();
    await expect(editor).toHaveValue(new RegExp('@Kaa'));

    await composer.getByRole('button', { name: 'Publier' }).click();
    // Scoped to the feed and its rendered article: the inline edit form
    // under a card repeats the same text in a hidden textarea.
    const feed = page.locator('#groups-feed');
    await expect(feed.getByRole('article').filter({ hasText: MESSAGE_PREFIX }).first()).toBeVisible();

    // ---------------------------------------------------------------
    // Épingler: the dropdown's plain form, intercepted by the duration
    // dialog; "1 semaine" goes into the hidden field and the form posts.
    // ---------------------------------------------------------------
    await page.getByLabel('Actions sur ce message').first().click();
    await page.getByRole('button', { name: 'Épingler' }).click();

    const durationDialog = page.locator('#groups-detail-modal');
    await expect(durationDialog).toBeVisible();
    await expect(durationDialog.getByText('Pendant combien de temps ?')).toBeVisible();
    await durationDialog.getByRole('button', { name: '1 semaine' }).click();

    // Pinning posts the form and the server redirects back to this same
    // page, where the card's menu now offers « Désépingler » — rendered by
    // Twig (partials/post_card.html.twig), so it is in the document the
    // moment the new page arrives and waits on nothing else.
    //
    // This assertion was flaky under the security scan, and the ceiling
    // was NOT the reason — it failed with 60 s to spend, polling 122 times
    // and finding nothing. The cause was in the application: the duration
    // dialog resolved its promise from Bootstrap's 'hidden.bs.modal', so
    // the form was submitted only after the fade-out finished, and
    // Bootstrap's hide() does nothing at all when the modal is still
    // transitioning IN. A click landing in that window left the promise
    // unsettled forever and the message simply never pinned — for a quick
    // human on a loaded server exactly as for this test. Fixed in
    // public/assets/js/groups.js (askPinDuration now resolves on the
    // choice); tests/js/groups-pin-duration.test.js pins the behaviour.
    //
    // The locator re-resolves across the navigation, so waiting on it is
    // the whole wait — it just needs a ceiling that scales.
    await expect(page.getByText('Désépingler')).toHaveCount(1, { timeout: scaled(15_000) });

    // ---------------------------------------------------------------
    // The other person: notified of the mention, and their visit to the
    // group is what turns the author's "Vu par" counter.
    // ---------------------------------------------------------------
    const member = await browser.newContext();
    try {
        const memberPage = await member.newPage();
        await loginAsMember(memberPage);
        await answerCookieBanner(memberPage);

        await memberPage.goto('/notifications', { waitUntil: 'domcontentloaded' });
        await expect(
            memberPage.getByRole('button', { name: /Vous êtes cité/ }).first(),
            'the mention must reach the member\'s notification centre',
        ).toBeVisible();
        await expect(memberPage.getByRole('button', { name: new RegExp(GROUP_NAME) }).first()).toBeVisible();

        await memberPage.goto(groupUrl, { waitUntil: 'domcontentloaded' });
        await waitForGroupsJsReady(memberPage);
        await expect(memberPage.getByRole('article').filter({ hasText: MESSAGE_PREFIX }).first()).toBeVisible();

        // ---------------------------------------------------------------
        // Back on the author's side: "Vu par 1 membre", and the dialog
        // names exactly who.
        // ---------------------------------------------------------------
        await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });
        await waitForGroupsJsReady(page);

        const seenBy = page.locator('.groups-seen-by').first();
        await expect(seenBy).toHaveText(/Vu par 1 membre/);
        await seenBy.click();
        await expect(durationDialog).toBeVisible();
        await expect(durationDialog.getByText('Kaa', { exact: false })).toBeVisible();
        await durationDialog.locator('.btn-close').click();
        await expect(durationDialog).toBeHidden();

        // ---------------------------------------------------------------
        // A photo post, and the group's gallery serving it through the
        // delegated-album access check — for the member too.
        // ---------------------------------------------------------------
        await editor.fill(PHOTO_MESSAGE);
        await page.locator('#groups-media-input').setInputFiles({
            name: 'terrain.png',
            mimeType: 'image/png',
            buffer: pngBuffer(900, 600, [90, 140, 80, 255]),
        });
        await composer.getByRole('button', { name: 'Publier' }).click();
        await expect(feed.getByRole('article').filter({ hasText: PHOTO_MESSAGE }).first()).toBeVisible();

        // The gallery page needs the ProcessPhoto task to have produced a
        // thumbnail; reloading is also what drives the cron that runs it.
        await memberPage.goto(`${groupUrl}/gallery`, { waitUntil: 'domcontentloaded' });
        await expect.poll(async () => {
            await memberPage.reload({ waitUntil: 'load' });
            return memberPage.locator('.gallery-lightbox-trigger img').count();
        }, {
            message: 'the group gallery must serve the post\'s photo once processed',
            timeout: scaled(120_000),
            intervals: [3_000],
        }).toBeGreaterThan(0);
    } finally {
        await member.close();
    }

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
