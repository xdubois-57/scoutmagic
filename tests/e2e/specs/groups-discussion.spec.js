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
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text wherever they identify the element (README.md
// § Tests de bout en bout), which is every control the member actually
// operates. Three things on a card cannot be reached that way and use the
// module's own JavaScript hooks instead — the same ids and classes
// groups.js itself binds to, so they are contract rather than incidental
// structure: the feed container the composer inserts into
// (#groups-feed), a message's RENDERED body (the inline edit form below
// it holds the identical text in a textarea, so text alone is ambiguous),
// and the blocks the dynamic fragments replace wholesale (.groups-poll,
// .groups-reactions, .groups-reply-bubble).
import { expect, test } from '@playwright/test';

import { loginAsAdmin, loginAsMember } from '../support/admin-login.js';

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

    await expect(feed.getByRole('article')).toHaveCount(1);
    await expect(composerError).toBeHidden();
    await expect(feed.locator(POST_BODY)).toContainText(MESSAGE);
    // The empty-state line lives inside the feed container the new card is
    // inserted into, and has to go with the first message published.
    await expect(page.getByText('Aucun message dans ce groupe pour le moment.')).toBeHidden();
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

    await expect(feed.getByRole('article')).toHaveCount(2);
    await expect(composerError).toBeHidden();

    const linkPost = feed.getByRole('article').first();
    // The card is a real link, and its accessible name is what the module
    // resolved for it: the host, then the plain URL, because no Open Graph
    // metadata could be fetched (see this file's header).
    const linkCard = linkPost.getByRole('link', { name: /example\.invalid/ });
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

    await expect(feed.getByRole('article')).toHaveCount(3);
    await expect(composerError).toBeHidden();

    // The poll boxes fold away again: form.reset() empties them but leaves
    // the <details> open, which reads as "a second poll is expected".
    await expect(composer.getByLabel('Question', { exact: true })).toBeHidden();

    const pollPost = feed.getByRole('article').first();
    const poll = pollPost.locator('.groups-poll');
    await expect(poll).toContainText(POLL_QUESTION);
    await expect(poll).toContainText('0 vote');

    await poll.getByRole('button', { name: /Oui, je viens/ }).click();
    // The whole poll block is swapped for the server's freshly tallied
    // one, so the count is the server's answer and not an optimistic
    // client-side guess.
    await expect(pollPost.locator('.groups-poll')).toContainText('1 vote');
    await expect(pollPost.locator('.groups-poll')).toContainText('100 %');

    // --- 4. A comment, through the collapsed conversation.
    //
    // The post is matched by its text rather than by its position: the
    // stream is ordered by last activity, so commenting on a post and
    // reacting to it both move it (Service\GroupActivityService) — which
    // is the feature, not something to pin an assertion on.
    const firstPost = feed.getByRole('article').filter({ hasText: MESSAGE });
    await expect(firstPost).toHaveCount(1);

    const thread = firstPost.locator('details.groups-thread');
    const threadCount = thread.locator('.groups-thread-count');
    const replyBox = thread.getByPlaceholder('Répondre…');

    // Folded away by default, composer included — a feed of twenty
    // messages has to read as twenty messages.
    await expect(replyBox).toBeHidden();
    await expect(threadCount).toHaveText('Commenter');

    await thread.locator('summary').click();
    await expect(replyBox).toBeVisible();

    await replyBox.fill('Parfait, je serai là.');
    await firstPost.getByRole('button', { name: 'Envoyer la réponse' }).click();

    await expect(firstPost.locator('.groups-reply')).toHaveCount(1);
    await expect(firstPost.locator('.groups-reply-bubble')).toContainText('Parfait, je serai là.');
    await expect(firstPost.locator('.groups-reply-error')).toBeHidden();
    // The summary counts what was just added, without a reload and with
    // the right plural.
    await expect(threadCount).toHaveText('1 commentaire');

    // --- 5. A reaction on that same message, and the dialog behind its
    // tally.
    //
    // The tally is part of the fragment the reaction endpoint renders and
    // groups.js swaps in — so opening its dialog exercises a URL that only
    // exists in that freshly rendered fragment, never in the page the
    // server first served. It rendered empty once, and `fetch('')` fetched
    // the current page and left the dialog spinning; nothing outside a
    // browser could see that.
    await firstPost.locator('.groups-reactions').first().getByRole('button', { name: 'Réagir : thumbs_up' })
        .click();
    const tally = firstPost.locator('.groups-reaction-tally').first();
    await expect(tally).toContainText('1');

    await tally.click();
    const dialog = page.locator('#groups-detail-modal');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#groups-detail-modal-body')).toContainText('Baden');
    await dialog.getByRole('button', { name: 'Fermer' }).click();
    await expect(dialog).toBeHidden();

    // --- Everything above happened without a single page load. Reload
    // once, and require the server to hand all of it back: that is what
    // separates "the DOM was updated" from "the post was stored".
    await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });

    await expect(feed.getByRole('article')).toHaveCount(3);
    // Found the same way as above — by its own words, never by a position
    // in a stream the module deliberately reorders by activity.
    const reloadedTextPost = feed.getByRole('article').filter({ hasText: MESSAGE });
    await expect(reloadedTextPost.locator(POST_BODY)).toContainText(MESSAGE);
    await expect(feed.getByRole('link', { name: /example\.invalid/ })).toHaveAttribute('href', LINK_URL);
    await expect(feed.locator('.groups-poll')).toContainText(POLL_QUESTION);
    await expect(feed.locator('.groups-poll')).toContainText('1 vote');
    await expect(reloadedTextPost.locator('.groups-reaction-tally').first()).toContainText('1');

    // The conversation comes back folded, and counted — and carries no
    // "nouveau" badge, because the only comment on it is this reader's
    // own (Repository\ReplyRepository::countNewerForPosts()).
    const reloadedThread = reloadedTextPost.locator('details.groups-thread');
    await expect(reloadedThread.locator('.groups-thread-count')).toHaveText('1 commentaire');
    await expect(reloadedThread.locator('.groups-thread-new')).toHaveCount(0);
    await expect(reloadedThread.locator('.groups-reply-bubble')).toBeHidden();

    await reloadedThread.locator('summary').click();
    await expect(reloadedThread.locator('.groups-reply-bubble')).toContainText('Parfait, je serai là.');

    // --- 7. And deleting everything brings the empty group back.
    //
    // The line lives inside the feed container the composer inserts into,
    // so it has to be put back by the same code that took it away — a
    // group that says nothing at all after its last message is deleted is
    // how it used to read until the next reload.
    page.on('dialog', (dialog) => dialog.accept());
    const emptyLine = page.getByText('Aucun message dans ce groupe pour le moment.');
    await expect(emptyLine).toBeHidden();

    for (let remaining = 3; remaining > 0; remaining -= 1) {
        const card = feed.getByRole('article').first();
        await card.getByRole('button', { name: 'Actions sur ce message' }).click();
        await card.getByRole('button', { name: 'Supprimer' }).click();
        await expect(feed.getByRole('article')).toHaveCount(remaining - 1);
    }

    await expect(emptyLine).toBeVisible();

    expect(serverErrors, 'no request in this scenario may answer 5xx').toEqual([]);
});

// ============================================================================
// Two people in one conversation.
//
// Three of this module's behaviours only exist between two members, and no
// amount of care makes them reachable with one: a comment is never new to
// the person who wrote it, "Signaler" is never offered on your own message,
// and the badge that says something arrived means nothing if you put it
// there yourself. scripts/e2e-support.php provisions a second, ordinary
// member (no super-admin flag, no function, so RoleResolver puts them at
// `identified`) and a section both of them belong to.
//
// A section group rather than an invitation one: its membership is DERIVED
// per request from member_section_periods (Service\GroupAccessService), so
// both members are in it the moment it exists — which is also how most
// real groups in a unit come to be.
// ============================================================================
const SECTION_NAME = 'Meute E2E';
const SECTION_GROUP_NAME = `Meute E2E ${Date.now()}`;
const ANNOUNCEMENT = 'Le camp est confirmé du 1er au 10 juillet.';
const COMMENT = 'Super, on peut aider au montage ?';

test('a comment from somebody else is announced as new, and can be reported without a reload', async ({ page }) => {
    /** @type {string[]} */
    const serverErrors = [];
    page.on('response', (response) => {
        if (response.status() >= 500) {
            serverErrors.push(`HTTP ${response.status()} on ${response.url()}`);
        }
    });

    // --- The section's group, and one announcement in it.
    await loginAsAdmin(page);
    await page.goto('/groups', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Nom du groupe').fill(SECTION_GROUP_NAME);
    await page.getByLabel('Section').selectOption({ label: SECTION_NAME });
    await page.getByRole('button', { name: 'Créer' }).click();

    await expect(page.getByRole('heading', { name: SECTION_GROUP_NAME })).toBeVisible();
    const groupUrl = new URL(page.url()).pathname;

    await page.getByLabel('Écrire un message').fill(ANNOUNCEMENT);
    await page.locator('#groups-post-form').getByRole('button', { name: 'Publier' }).click();
    await expect(page.locator('#groups-feed').getByRole('article')).toHaveCount(1);

    // --- The other member: in the group without ever being invited, because
    // they are in its section.
    await page.context().clearCookies();
    await loginAsMember(page);
    await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#groups-feed').getByRole('article')).toHaveCount(1);

    const theirThread = page.locator('details.groups-thread');
    await theirThread.locator('summary').click();
    await theirThread.getByPlaceholder('Répondre…').fill(COMMENT);

    // Typed but not sent — and it survives losing the page, in this
    // browser and nowhere else. Waiting on the cache key rather than on a
    // duration: the save is debounced, and a fixed wait would either be
    // flaky or slow.
    const draftKey = await theirThread.locator('.groups-reply-form').evaluate(
        (form) => `groups-reply-draft-${form.dataset.groupId}-${form.dataset.postId}`,
    );
    await page.waitForFunction((key) => localStorage.getItem(key) !== null, draftKey);
    await page.reload({ waitUntil: 'domcontentloaded' });

    const restoredThread = page.locator('details.groups-thread');
    // Opened by itself: a draft nobody is shown is a draft nobody
    // finishes, and this one is behind a fold.
    await expect(restoredThread).toHaveJSProperty('open', true);
    await expect(restoredThread.getByPlaceholder('Répondre…')).toHaveValue(COMMENT);

    await page.getByRole('button', { name: 'Envoyer la réponse' }).click();
    await expect(page.locator('.groups-reply-bubble')).toContainText(COMMENT);
    // Sent, so there is nothing left to recover.
    expect(await page.evaluate((key) => localStorage.getItem(key), draftKey)).toBeNull();

    // --- Back to the author. The comment arrived after their last visit and
    // is not their own, so the conversation says so — and has opened itself,
    // because a badge you have to click to act on is a badge that gets
    // ignored.
    await page.context().clearCookies();
    await loginAsAdmin(page);
    await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });

    const thread = page.locator('details.groups-thread');
    await expect(thread.locator('.groups-thread-new')).toHaveText('1 nouveau');
    await expect(thread).toHaveJSProperty('open', true);
    await expect(thread.locator('.groups-reply-bubble')).toContainText(COMMENT);
    await expect(thread.locator('.groups-thread-count')).toHaveText('1 commentaire');

    // --- Reporting it. Offered at all only because somebody else wrote it,
    // and answered where the reader is looking instead of by reloading the
    // whole group for one line of reassurance.
    page.once('dialog', (dialog) => dialog.accept());
    const reply = page.locator('.groups-reply').first();
    await reply.getByRole('button', { name: 'Actions sur cette réponse' }).click();
    await reply.getByRole('button', { name: 'Signaler' }).click();

    await expect(reply.locator('.groups-report-confirmation')).toContainText('votre signalement a bien été transmis');
    // Still the same page, and the comment still there: reporting hides
    // nothing on its own (it takes groups_report_hide_threshold reports),
    // and the reporter is told nothing about what came of it.
    expect(new URL(page.url()).pathname).toBe(groupUrl);
    await expect(thread.locator('.groups-reply-bubble')).toContainText(COMMENT);
    // The entry is gone: a second report by the same member is refused by
    // the UNIQUE index anyway, and offering it again would invite the
    // reporter to read something into nothing visibly happening.
    await expect(reply.getByRole('button', { name: 'Signaler' })).toHaveCount(0);

    // And after a reload: the badge is gone (the comment was new on the
    // visit before, and this module marks a group read when you open it —
    // Controller\GroupController::show()), so the conversation is folded
    // again. The report survived too: the menu does not offer it a second
    // time.
    await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });

    const reloadedThread = page.locator('details.groups-thread');
    await expect(reloadedThread.locator('.groups-thread-new')).toHaveCount(0);
    await expect(reloadedThread).toHaveJSProperty('open', false);

    await reloadedThread.locator('summary').click();
    const reloadedReply = page.locator('.groups-reply').first();
    await reloadedReply.getByRole('button', { name: 'Actions sur cette réponse' }).click();
    await expect(reloadedReply.getByRole('button', { name: 'Signaler' })).toHaveCount(0);
    // The rest of the menu is still there — the entry was removed, not the
    // menu, and a moderator can still act on the comment.
    await expect(reloadedReply.getByRole('button', { name: 'Supprimer' })).toBeVisible();

    expect(serverErrors, 'no request in this scenario may answer 5xx').toEqual([]);
});

// ============================================================================
// On a phone, and with the JavaScript switched off.
//
// Two things nothing else in this suite can see. The reaction picker's
// fold is pure CSS behind a media query, so it does not exist for jsdom
// and does not exist at desktop width; and this module's standing promise
// — every control still works with no JavaScript at all — has never been
// checked by anything but the reading of it.
//
// Both halves matter together: the fold is only acceptable BECAUSE the
// picker comes back when the script does not load. A test for the first
// without the second would be signing off the wrong thing.
// ============================================================================
const PHONE = { width: 390, height: 844 };

test('on a phone the reaction picker folds away — and comes back when the script does not load', async ({ page }) => {
    // Logged in at the default size, THEN narrowed: the consent banner is
    // fixed to the bottom of the viewport, and on a 844px-tall screen it
    // covers the login form's own button. That is the banner's business,
    // not this scenario's — specs/cookie-consent-reject.spec.js owns it.
    await loginAsAdmin(page);
    await page.getByRole('button', { name: 'Tout refuser' }).click();
    await page.setViewportSize(PHONE);

    await page.goto('/groups', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Nom du groupe').fill(`Téléphone ${Date.now()}`);
    await page.getByRole('button', { name: 'Créer' }).click();
    const groupUrl = new URL(page.url()).pathname;

    await page.getByLabel('Écrire un message').fill('Un message à réagir.');
    await page.locator('#groups-post-form').getByRole('button', { name: 'Publier' }).click();
    await expect(page.locator('#groups-feed').getByRole('article')).toHaveCount(1);

    const reactions = page.locator('#groups-feed .groups-reactions').first();
    // exact: the six emoji buttons are each labelled "Réagir : <clé>", so
    // a substring match would find all seven.
    const toggle = reactions.getByRole('button', { name: 'Réagir', exact: true });
    const thumbsUp = reactions.getByRole('button', { name: 'Réagir : thumbs_up' });

    // Folded: one button instead of six.
    await expect(toggle).toBeVisible();
    await expect(thumbsUp).toBeHidden();

    await toggle.click();
    // The picker takes the toggle's place rather than sitting beside it,
    // so the row is no wider open than closed.
    await expect(thumbsUp).toBeVisible();
    await expect(toggle).toBeHidden();

    // And the reaction itself still goes through the same dynamic path.
    await thumbsUp.click();
    await expect(reactions.locator('.groups-reaction-tally').first()).toContainText('1');

    // --- Now the same page with groups.js never arriving.
    //
    // The fold is gated on a class that file puts on <html>, so without it
    // the six buttons must be exactly where they have always been — and
    // the toggle, which nothing could open, must not be shown at all.
    await page.route('**/assets/js/groups.js', (route) => route.abort());
    await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });

    const plainReactions = page.locator('#groups-feed .groups-reactions').first();
    await expect(plainReactions.getByRole('button', { name: 'Réagir : thumbs_up' })).toBeVisible();
    await expect(plainReactions.getByRole('button', { name: 'Réagir', exact: true })).toBeHidden();

    // The rest of the module degrades the same way: the conversation is a
    // native <details> and the composer a real form, so both still work.
    await expect(page.locator('details.groups-thread')).toHaveCount(1);
    await expect(page.locator('#groups-post-form')).toBeVisible();
});

// ============================================================================
// A photo in a comment: seen before it is sent, and enough on its own.
//
// Both halves were reported as broken together, and one fault explained
// both. A group's FIRST photo creates its gallery album mid-request
// (Service\PostMediaService::ensureAlbumId()); DiscussionGroup is
// immutable, so the instance the controller still held read as null and
// the card it rendered for the browser to insert found no media at all.
// A comment carrying nothing BUT a photo therefore arrived as an empty
// bubble — indistinguishable from "you cannot attach a photo without
// text". A reload showed it, which is what made it hard to see.
//
// So this scenario uses a brand-new group deliberately: on any group that
// has ever held a photo, the bug does not reproduce.
// ============================================================================

// A real 1×1 PNG rather than random bytes: the upload path sniffs the type
// from the content (Core\File\UploadHandler), so anything else is rejected
// before it can prove anything.
const ONE_PIXEL_PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
);

test('a comment can be a photo and nothing else, and the photo is visible before sending', async ({ page }) => {
    /** @type {string[]} */
    const serverErrors = [];
    page.on('response', (response) => {
        if (response.status() >= 500) {
            serverErrors.push(`HTTP ${response.status()} on ${response.url()}`);
        }
    });

    await loginAsAdmin(page);
    await page.goto('/groups', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Nom du groupe').fill(`Photo ${Date.now()}`);
    await page.getByRole('button', { name: 'Créer' }).click();

    await page.getByLabel('Écrire un message').fill('Des photos du week-end ?');
    await page.locator('#groups-post-form').getByRole('button', { name: 'Publier' }).click();
    await expect(page.locator('#groups-feed').getByRole('article')).toHaveCount(1);

    const thread = page.locator('details.groups-thread');
    await thread.locator('summary').click();

    const preview = thread.locator('.groups-reply-image-preview');
    await expect(preview).toBeHidden();

    await thread.locator('.groups-reply-image').setInputFiles({
        name: 'photo.png',
        mimeType: 'image/png',
        buffer: ONE_PIXEL_PNG,
    });

    // Visible immediately, drawn from the file itself — nothing has been
    // sent yet, and the comment box is still empty.
    await expect(preview).toBeVisible();
    await expect(preview.locator('.groups-reply-image-name')).toHaveText('photo.png');
    await expect(preview.locator('.groups-reply-image-thumb-img')).toHaveAttribute('src', /^blob:/);
    await expect(thread.getByPlaceholder('Répondre…')).toHaveValue('');

    // Changing your mind really detaches it, rather than only hiding it.
    await preview.getByRole('button', { name: 'Retirer cette photo' }).click();
    await expect(preview).toBeHidden();
    await expect(thread.locator('.groups-reply-image')).toHaveJSProperty('value', '');

    await thread.locator('.groups-reply-image').setInputFiles({
        name: 'photo.png',
        mimeType: 'image/png',
        buffer: ONE_PIXEL_PNG,
    });
    await expect(preview).toBeVisible();

    // --- Sent with no text at all.
    await page.getByRole('button', { name: 'Envoyer la réponse' }).click();

    const comment = thread.locator('.groups-reply').first();
    await expect(comment).toHaveCount(1);
    await expect(thread.locator('.groups-reply-error')).toBeHidden();
    // The photo is ON the comment, in the card the server rendered for the
    // browser to insert — this is the assertion the album-id bug failed.
    await expect(comment.locator('.groups-reply-image-link')).toHaveCount(1);
    await expect(thread.locator('.groups-thread-count')).toHaveText('1 commentaire');
    // And the composer is empty again, photo included.
    await expect(preview).toBeHidden();

    // Still there after a real reload: stored, not just drawn.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.locator('details.groups-thread summary').click();
    await expect(page.locator('.groups-reply-image-link')).toHaveCount(1);

    expect(serverErrors, 'no request in this scenario may answer 5xx').toEqual([]);
});
