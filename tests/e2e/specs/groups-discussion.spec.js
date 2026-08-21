// End-to-end: publishing in a discussion group, through the real
// composer, in a real browser.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The groups composer is the one place in ScoutMagic where the browser,
// and only the browser, decides what the server receives: public/assets/
// js/groups.js intercepts the form's own submit, builds a FormData from
// it by hand and POSTs that instead (module spec: "no reload to publish a
// post"). Nothing below the browser can see whether that hand-built body
// still carries the fields the form declares — PHPUnit sees a $_POST
// array a test wrote itself, and Vitest sees a jsdom form with no server
// behind it.
//
// That gap was not theoretical. The composer disabled its textarea to
// grey itself out *before* snapshotting the FormData, and a disabled
// control contributes nothing to a form's data set — so `body` never left
// the browser, and every text-only message came back refused with
// « Un message ne peut pas être vide. » This scenario is the regression
// test for that class of bug: it types a message into the real composer
// and requires the message to come back from the real server, stored.
//
// It stays one scenario rather than five (AGENTS.md § Tests: the E2E
// suite is a release gate, not a coverage tool) and covers, in one pass
// through one group, the four shapes a post can take — plain text, a
// message carrying a link, a poll, and a reply — plus a reaction, then
// reloads the page once and requires all of it to have survived the round
// trip to the database.
//
// WHAT IT DELIBERATELY DOES NOT ASSERT
// ----------------------------------------------------------------------------
// A link's Open Graph metadata. Fetching one is an outbound HTTP request
// to a member-supplied URL, and Modules\Gallery\Service\OgScraperService
// refuses those to a private address or a non-default port (SECURITY.md
// §17) — which is exactly what a throwaway instance on 127.0.0.1:<port>
// is. The documented degradation is what this asserts instead, and it is
// the more valuable half anyway: the URL is detected in the body, removed
// from the stored text, and rendered as its own card with the host and
// the plain link on it. The metadata-carrying path is covered where it
// can be exercised honestly — Tests\Modules\Groups\Service\
// PostLinkServiceTest with a stubbed fetcher, and tests/js/groups.test.js
// for the live preview card the composer draws while typing.
//
// FIXTURE
// ----------------------------------------------------------------------------
// scripts/e2e-support.php provisions the super-admin a member identity
// and a first/last name (e2e_seed_member_for_admin) — both are hard
// requirements of GroupAccessService, not shortcuts around it. It seeds
// them for the CURRENT scout year, so this scenario has to run before
// specs/scout-year-transition.spec.js moves the public year on; Playwright
// orders spec files alphabetically, and "groups-" sorts before
// "scout-year-", which is what keeps that true.
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from '../support/admin-login.js';

// Unique per run so a re-run against a database that somehow survived
// (E2E_DB_NAME pointed elsewhere, a killed teardown) still starts from an
// unambiguous group rather than matching an older one by name.
const GROUP_NAME = `Composeur E2E ${Date.now()}`;

const MESSAGE = 'Rendez-vous samedi à 9h devant le local.';
const LINK_MESSAGE_PREFIX = 'Le programme est ici : ';
const LINK_URL = 'https://example.invalid/programme-du-camp';
const POLL_QUESTION = 'Qui vient au week-end de novembre ?';

// A post's own body, and never a reply's: reply_card.html.twig reuses the
// same .groups-post-body class for a reply's text, and the inline edit
// form under each card carries the identical text again in a textarea.
// The id prefix (partials/post_body.html.twig) is the one thing only a
// post's body has.
const POST_BODY = '[id^="post-body-"]';

test('a member writes in a discussion group: a message, a link, a poll, a reply and a reaction', async ({ page }) => {
    /** @type {string[]} */
    const serverErrors = [];
    page.on('response', (response) => {
        if (response.status() >= 500) {
            serverErrors.push(`HTTP ${response.status()} on ${response.url()}`);
        }
    });

    await loginAsAdmin(page);

    // --- The group itself, created through the real form on /groups.
    // "Sur invitation (sans section)" is the default option: an invitation
    // group needs no section, and its creator becomes its first member and
    // its moderator (Modules\Groups\Service\GroupService).
    await page.goto('/groups', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Nom du groupe').fill(GROUP_NAME);
    await page.getByRole('button', { name: 'Créer' }).click();

    await expect(page.getByRole('heading', { name: GROUP_NAME })).toBeVisible();
    const groupUrl = new URL(page.url()).pathname;
    expect(groupUrl, 'creating a group must land on that group').toMatch(/^\/groups\/\d+$/);

    const composer = page.locator('#groups-post-form');
    const composerError = page.locator('#groups-post-error');
    const feed = page.locator('#groups-feed');

    await expect(
        composer,
        'a member of the group with a complete profile must be offered the composer',
    ).toBeVisible();
    await expect(page.getByText('Aucun message dans ce groupe pour le moment.')).toBeVisible();

    // --- 1. A plain text message.
    //
    // The whole reason this scenario exists: the composer greys itself out
    // for the round trip, and the message must still arrive. An inline
    // error here IS the bug — asserted explicitly, because the feed
    // assertion alone would time out with a much less useful message.
    await page.getByLabel('Écrire un message').fill(MESSAGE);
    await composer.getByRole('button', { name: 'Publier' }).click();

    await expect(feed.locator('article')).toHaveCount(1);
    await expect(composerError).toBeHidden();
    await expect(feed.locator(POST_BODY)).toContainText(MESSAGE);
    // The empty-state line lives inside the feed container the new card is
    // inserted into, and has to go with the first message published.
    await expect(page.getByText('Aucun message dans ce groupe pour le moment.')).toHaveCount(0);
    // The composer is usable again, and emptied — not left greyed out.
    await expect(page.getByLabel('Écrire un message')).toHaveValue('');
    await expect(page.getByLabel('Écrire un message')).toBeEnabled();

    // --- 2. A message carrying a link.
    //
    // No "Lien" field exists: the first URL typed anywhere in the body is
    // detected server-side, removed from the stored text and rendered as
    // its own card (Modules\Groups\Service\PostLinkService).
    await page.getByLabel('Écrire un message').fill(LINK_MESSAGE_PREFIX + LINK_URL);
    await composer.getByRole('button', { name: 'Publier' }).click();

    await expect(feed.locator('article')).toHaveCount(2);
    await expect(composerError).toBeHidden();

    const linkPost = feed.locator('article').first();
    const linkCard = linkPost.locator('a.groups-link-preview');
    await expect(linkCard).toHaveAttribute('href', LINK_URL);
    await expect(linkCard).toContainText('example.invalid');
    await expect(linkCard).toContainText(LINK_URL);
    // The sentence keeps its own words and loses only the URL — the card
    // below it is what represents the link now.
    await expect(linkPost.locator(POST_BODY)).toContainText(LINK_MESSAGE_PREFIX.trim());
    await expect(linkPost.locator(POST_BODY)).not.toContainText(LINK_URL);

    // --- 3. A poll, and a vote in it.
    //
    // The poll boxes are plain inputs behind a <details>, always in the
    // DOM; leaving them empty is what makes an ordinary message. Filled
    // in, they must travel with the composer's hand-built request exactly
    // as the message does.
    await composer.locator('summary', { hasText: 'Ajouter un sondage' }).click();
    await composer.getByLabel('Question', { exact: true }).fill(POLL_QUESTION);
    await composer.getByLabel('Choix 1', { exact: true }).fill('Oui, je viens');
    await composer.getByLabel('Choix 2', { exact: true }).fill('Non, je ne peux pas');
    await composer.getByRole('button', { name: 'Publier' }).click();

    await expect(feed.locator('article')).toHaveCount(3);
    await expect(composerError).toBeHidden();

    const pollPost = feed.locator('article').first();
    const poll = pollPost.locator('.groups-poll');
    await expect(poll).toContainText(POLL_QUESTION);
    await expect(poll).toContainText('0 vote');

    await poll.getByRole('button', { name: /Oui, je viens/ }).click();
    // The whole poll block is swapped for the server's freshly tallied
    // one, so the count is the server's answer and not an optimistic
    // client-side guess.
    await expect(pollPost.locator('.groups-poll')).toContainText('1 vote');
    await expect(pollPost.locator('.groups-poll')).toContainText('100 %');

    // --- 4. A reply (a comment) on the first message published.
    //
    // Matched by its text rather than by its position: the stream is
    // ordered by last activity, so replying to a post and reacting to it
    // both move it (Service\GroupActivityService) — which is the feature,
    // not something to pin an assertion on.
    const firstPost = feed.locator('article').filter({ hasText: MESSAGE });
    await expect(firstPost).toHaveCount(1);
    await firstPost.getByPlaceholder('Répondre…').fill('Parfait, je serai là.');
    await firstPost.getByRole('button', { name: 'Envoyer la réponse' }).click();

    await expect(firstPost.locator('.groups-reply')).toHaveCount(1);
    await expect(firstPost.locator('.groups-reply-bubble')).toContainText('Parfait, je serai là.');
    await expect(firstPost.locator('.groups-reply-error')).toBeHidden();

    // --- 5. A reaction on that same message.
    await firstPost.locator('.groups-reactions').first().getByRole('button', { name: 'Réagir : thumbs_up' })
        .click();
    await expect(firstPost.locator('.groups-reaction-tally').first()).toContainText('1');

    // --- Everything above happened without a single page load. Reload
    // once, and require the server to hand all of it back: that is what
    // separates "the DOM was updated" from "the post was stored".
    await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });

    await expect(feed.locator('article')).toHaveCount(3);
    // Found the same way as above — by its own words, never by a position
    // in a stream the module deliberately reorders by activity.
    const reloadedTextPost = feed.locator('article').filter({ hasText: MESSAGE });
    await expect(reloadedTextPost.locator(POST_BODY)).toContainText(MESSAGE);
    await expect(feed.locator('a.groups-link-preview')).toHaveAttribute('href', LINK_URL);
    await expect(feed.locator('.groups-poll')).toContainText(POLL_QUESTION);
    await expect(feed.locator('.groups-poll')).toContainText('1 vote');
    await expect(reloadedTextPost.locator('.groups-reply-bubble')).toContainText('Parfait, je serai là.');
    await expect(reloadedTextPost.locator('.groups-reaction-tally').first()).toContainText('1');

    expect(serverErrors, 'no request in this scenario may answer 5xx').toEqual([]);
});
