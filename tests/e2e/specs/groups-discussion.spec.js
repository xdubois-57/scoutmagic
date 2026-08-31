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
// through one group, the composer's own fold — one tinted line by
// default, the whole form one click behind it, and open on arrival
// whenever it already holds something nobody has published yet — then the
// four shapes a post can take — plain text, a message carrying a link, a
// poll, and a reply — plus a reaction, then reloads the page once and
// requires all of it to have survived the round trip to the database.
//
// WHAT IT DELIBERATELY DOES NOT ASSERT
// ----------------------------------------------------------------------------
// A link's Open Graph metadata. Fetching one is an outbound HTTP request
// to a member-supplied URL, and Modules\Gallery\Service\OgScraperService
// refuses those to a private address or a non-default port (SECURITY.md
// §17) — which is exactly what a throwaway instance on localhost:<port>
// is (it resolves to the loopback address, on a non-default port). The documented degradation is what this asserts instead, and it is
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

import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin, loginAsMember } from '../support/admin-login.js';
// Shared with specs/groups-management.spec.js — see support/groups.js.
import { openComposer, openCreateGroupForm, waitForGroupsJsReady } from '../support/groups.js';

// Unique per run so a re-run against a database that somehow survived
// (E2E_DB_NAME pointed elsewhere, a killed teardown) still starts from an
// unambiguous group rather than matching an older one by name.
const GROUP_NAME = `Composeur E2E ${Date.now()}`;

const MESSAGE = 'Rendez-vous samedi à 9h devant le local.';
const LINK_MESSAGE_PREFIX = 'Le programme est ici : ';
const LINK_URL = 'https://example.invalid/programme-du-camp';
const POLL_QUESTION = 'Qui vient au week-end de novembre ?';
const UNSENT_DRAFT = 'Brouillon jamais publié, à retrouver au retour.';

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
    // TEMPORARY INSTRUMENTATION — remove once the stuck-modal flake at the
    // « Fermer » step below is understood. An uncaught page error is the
    // one explanation nothing has been able to rule out: Bootstrap clears
    // Modal._isTransitioning inside the very callback that first calls
    // _focustrap.activate(), so a throw in there leaves the flag true for
    // good, and every later hide() then returns immediately and silently.
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => {
        pageErrors.push(`pageerror: ${error.message}`);
    });
    page.on('console', (message) => {
        if (message.type() === 'error') {
            pageErrors.push(`console.error: ${message.text()}`);
        }
    });
    // The deletions in step 7 stand behind the site's own confirmation
    // modal (base.html.twig's data-confirm handler → window.ScoutMagicConfirm).
    // Installed here rather than where it is needed, because the observer
    // rides in on an init script and must precede the first navigation.
    await autoConfirm(page);

    await loginAsAdmin(page);

    // --- The group itself, created through the real form on /groups.
    // "Sur invitation (sans section)" is the default option: an invitation
    // group needs no section, and its creator becomes its first member and
    // its moderator (Modules\Groups\Service\GroupService).
    await page.goto('/groups', { waitUntil: 'domcontentloaded' });
    // The form is folded away by default — /groups is a list of the
    // groups you are in, not a form for one that does not exist yet.
    await openCreateGroupForm(page);
    await page.getByLabel('Nom du groupe').fill(GROUP_NAME);
    await page.getByRole('button', { name: 'Créer' }).click();
    await waitForGroupsJsReady(page);

    await expect(page.getByRole('heading', { name: GROUP_NAME })).toBeVisible();
    const groupUrl = new URL(page.url()).pathname;
    expect(groupUrl, 'creating a group must land on that group').toMatch(/^\/groups\/\d+$/);

    const composer = page.locator('#groups-post-form');
    const composerBar = page.getByRole('button', { name: 'Écrire un message…' });
    const composerError = page.locator('#groups-post-error');
    const feed = page.locator('#groups-feed');

    // --- 0. The composer is one line until it is asked for.
    //
    // A group is read far more often than it is written in, and the open
    // form used a phone's whole first screen before the first message.
    // The bar is what a member of the group with a complete profile is
    // offered; the form is one click behind it, unchanged.
    await expect(
        composerBar,
        'a member of the group with a complete profile must be offered the composer',
    ).toBeVisible();
    await expect(composer).toBeHidden();
    await expect(page.getByText('Aucun message dans ce groupe pour le moment.')).toBeVisible();

    await openComposer(page);
    await expect(composer).toBeVisible();
    await expect(composerBar).toBeHidden();

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
    // Signed the way this module names anyone: the ACCOUNT — the human who
    // typed it — then the memberships that account carries, in
    // parentheses. Never the other way round, which used to put a child's
    // totem in front of a message a parent had written
    // (Modules\Groups\Service\MemberIdentityService).
    await expect(feed.getByRole('article').first().locator('.groups-identity').first())
        .toHaveText('Baden Powell');
    await expect(feed.getByRole('article').first().locator('.groups-identity-members').first())
        .toHaveText('(Baden)');
    // The empty-state line lives inside the feed container the new card is
    // inserted into, and has to go with the first message published.
    await expect(page.getByText('Aucun message dans ce groupe pour le moment.')).toBeHidden();
    // The composer is usable again, and emptied — not left greyed out.
    await expect(page.getByLabel('Écrire un message')).toHaveValue('');
    await expect(page.getByLabel('Écrire un message')).toBeEnabled();
    // And still open: somebody who has just written is the likeliest
    // person on the page to write again, so publishing never folds the
    // form back away under them.
    await expect(composerBar).toBeHidden();

    // --- 1b. A message typed and not published brings the composer back
    // OPEN on the next load.
    //
    // groups.js caches it in this browser and nowhere else (module
    // setting groups_draft_ttl_minutes), and folding the composer away
    // would hide the one thing that cache exists to give back. Waiting on
    // the cache key rather than on a duration: the save is debounced, and
    // a fixed wait would either be flaky or slow.
    const draftKey = `groups-draft-${groupUrl.split('/').pop()}`;
    await page.getByLabel('Écrire un message').fill(UNSENT_DRAFT);
    await page.waitForFunction((key) => localStorage.getItem(key) !== null, draftKey);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await waitForGroupsJsReady(page);

    await expect(composer, 'a draft must never be folded away out of sight').toBeVisible();
    await expect(composerBar).toBeHidden();
    await expect(page.getByLabel('Écrire un message')).toHaveValue(UNSENT_DRAFT);

    // Emptied again, so what follows starts from a composer holding
    // nothing of its own. It stays OPEN through the rest of this scenario:
    // the fold is decided once, when the page loads, and the draft above
    // is what decided it for this load — which is why steps 2 and 3 below
    // need no openComposer() of their own even though a reload sits
    // between them and step 0's click.
    await page.getByLabel('Écrire un message').fill('');
    await page.waitForFunction((key) => localStorage.getItem(key) === null, draftKey);

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
    await composer.locator('summary', { hasText: 'Sondage' }).click();
    await composer.getByLabel('Question', { exact: true }).fill(POLL_QUESTION);
    await composer.getByLabel('Choix 1', { exact: true }).fill('Oui, je viens');
    await composer.getByLabel('Choix 2', { exact: true }).fill('Non, je ne peux pas');
    // Typing in the last box adds a fresh empty one after it, so the
    // control always has exactly one waiting at the end (groups.js). The
    // third box exists in the server's own markup; a fourth appearing is
    // the script's rule at work.
    await expect(composer.locator('[name="poll_options[]"]')).toHaveCount(3);
    await composer.getByLabel('Choix 3', { exact: true }).fill('Peut-être');
    await expect(composer.locator('[name="poll_options[]"]')).toHaveCount(4);
    await composer.getByRole('button', { name: 'Publier' }).click();

    await expect(feed.getByRole('article')).toHaveCount(3);
    await expect(composerError).toBeHidden();

    // The poll boxes fold away again: form.reset() empties them but leaves
    // the <details> open, which reads as "a second poll is expected".
    await expect(composer.getByLabel('Question', { exact: true })).toBeHidden();

    const pollPost = feed.getByRole('article').first();
    const poll = pollPost.locator('.groups-poll');
    await expect(poll).toContainText(POLL_QUESTION);
    await expect(poll).toContainText('0 personne a répondu');

    await poll.getByRole('button', { name: /Oui, je viens/ }).click();
    // The whole poll block is swapped for the server's freshly tallied
    // one, so the count is the server's answer and not an optimistic
    // client-side guess.
    await expect(pollPost.locator('.groups-poll')).toContainText('1 personne a répondu');
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
    // A comment is signed by the same rule as the message above it — and
    // this card came back from the reply endpoint, not from the page the
    // server first rendered, so both paths are covered.
    await expect(firstPost.locator('.groups-reply .groups-identity').first())
        .toHaveText('Baden Powell');
    await expect(firstPost.locator('.groups-reply .groups-identity-members').first())
        .toHaveText('(Baden)');
    await expect(firstPost.locator('.groups-reply-error')).toBeHidden();
    // The summary counts what was just added, without a reload and with
    // the right plural.
    await expect(threadCount).toHaveText('1 commentaire');

    // --- A URL typed in a comment is a real link.
    //
    // Only observable here: Core\View\TextLinker escapes the text and
    // then builds the anchor around it, and whether that produced a
    // working <a> or an escaped one is a question about rendered HTML
    // that neither PHPUnit's string assertions nor jsdom-without-a-server
    // can answer as directly.
    await replyBox.fill('Le formulaire est ici ' + LINK_URL + ' merci !');
    await firstPost.getByRole('button', { name: 'Envoyer la réponse' }).click();

    await expect(firstPost.locator('.groups-reply')).toHaveCount(2);
    const link = firstPost.locator('.groups-reply-bubble a').first();
    await expect(link).toHaveAttribute('href', LINK_URL);
    // User-generated content pointing at the open web: opened beside the
    // conversation, and telling the destination nothing about where it
    // was linked from.
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', 'nofollow ugc noopener noreferrer');
    // The sentence around it survived as text, and the trailing "!" is
    // part of that sentence rather than part of the link.
    await expect(firstPost.locator('.groups-reply-bubble').nth(1)).toContainText('Le formulaire est ici');
    await expect(firstPost.locator('.groups-reply-bubble').nth(1)).toContainText('merci !');

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
    // "Qui a réagi" names the same human the same way, which is the whole
    // point of resolving identity in one service: one person must not read
    // as two different people across two surfaces.
    await expect(dialog.locator('#groups-detail-modal-body')).toContainText('Baden Powell (Baden)');
    // TEMPORARY INSTRUMENTATION, round two. Round one answered the
    // question it was asked and killed its own hypothesis: at failure
    // Bootstrap held isShown=true, isTransitioning=FALSE, a live instance,
    // one backdrop, body.modal-open, and pageErrors was EMPTY. In that
    // state hide() would have worked — so hide() was never called, and no
    // exception prevented it. The remaining suspect is the event itself: a
    // `click` only exists where mousedown and mouseup share a target, and
    // Playwright reports its action done once it has dispatched the mouse
    // events, whether or not a click was synthesised from them.
    await page.evaluate(() => {
        const w = /** @type {any} */ (window);
        w.__closeProbe = { down: [], up: [], click: [], dismissMatched: 0 };
        const describe = (event) => {
            const target = /** @type {HTMLElement} */ (event.target);
            return target && target.tagName
                ? target.tagName + (target.className ? '.' + String(target.className).split(' ')[0] : '')
                : String(target);
        };
        document.addEventListener('mousedown', (e) => w.__closeProbe.down.push(describe(e)), true);
        document.addEventListener('mouseup', (e) => w.__closeProbe.up.push(describe(e)), true);
        document.addEventListener('click', (e) => {
            w.__closeProbe.click.push(describe(e));
            const target = /** @type {HTMLElement} */ (e.target);
            if (target && target.closest && target.closest('[data-bs-dismiss="modal"]')) {
                w.__closeProbe.dismissMatched += 1;
            }
        }, true);
    });
    await dialog.getByRole('button', { name: 'Fermer' }).click();
    // TEMPORARY INSTRUMENTATION — see the pageErrors listener at the top.
    // This assertion fails about one DAST run in ten with the dialog still
    // carrying `modal fade show` and `display: block`, and four
    // explanations have already been tested and disproved: the opening
    // transition (reducedMotion now zeroes every duration Bootstrap
    // measures), a second openDetailDialog() reopening it (the trace shows
    // exactly one /reactions request), a leaked listener cancelling the
    // hide (there is no hide.bs.modal listener anywhere, and no dispose()
    // call to strand the instance), and the body being replaced under it
    // (the trace's DOM snapshots are back-references, so it never
    // changed). What is left is Bootstrap's own view of the dialog, which
    // no artifact so far records — so record it.
    try {
        await expect(dialog).toBeHidden({ timeout: 5_000 });
    } catch (failure) {
        const state = await page.evaluate(() => {
            const element = document.getElementById('groups-detail-modal');
            const bs = /** @type {any} */ (window).bootstrap;
            const instance = bs && bs.Modal ? bs.Modal.getInstance(element) : null;
            return {
                className: element ? element.className : '(absent)',
                inlineDisplay: element ? element.style.display : '(absent)',
                hasInstance: Boolean(instance),
                isShown: instance ? instance._isShown : null,
                isTransitioning: instance ? instance._isTransitioning : null,
                focustrapActive: instance && instance._focustrap ? instance._focustrap._isActive : null,
                backdrops: document.querySelectorAll('.modal-backdrop').length,
                bodyHasModalOpen: document.body.classList.contains('modal-open'),
                // Round two: did a click event exist at all, and did it
                // land on something Bootstrap's delegated dismiss handler
                // would have matched?
                probe: /** @type {any} */ (window).__closeProbe,
                dismissButtons: document.querySelectorAll('#groups-detail-modal [data-bs-dismiss="modal"]').length,
                activeElement: document.activeElement
                    ? document.activeElement.tagName
                        + (document.activeElement.className
                            ? '.' + String(document.activeElement.className).split(' ')[0]
                            : '')
                    : '(none)',
            };
        });
        // eslint-disable-next-line no-console
        console.log('[MODAL-STUCK] state=' + JSON.stringify(state)
            + ' pageErrors=' + JSON.stringify(pageErrors));
        throw failure;
    }

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
    await expect(feed.locator('.groups-poll')).toContainText('1 personne a répondu');
    await expect(reloadedTextPost.locator('.groups-reaction-tally').first()).toContainText('1');

    // The conversation comes back folded, and counted — and carries no
    // "nouveau" badge, because every comment on it is this reader's own
    // (Repository\ReplyRepository::countNewerForPosts()).
    const reloadedThread = reloadedTextPost.locator('details.groups-thread');
    await expect(reloadedThread.locator('.groups-thread-count')).toHaveText('2 commentaires');
    await expect(reloadedThread.locator('.groups-thread-new')).toHaveCount(0);
    await expect(reloadedThread.locator('.groups-reply-bubble').first()).toBeHidden();

    await reloadedThread.locator('summary').click();
    await expect(reloadedThread.locator('.groups-reply-bubble').first()).toContainText('Parfait, je serai là.');
    // The link survived the round trip as a link, not as escaped text —
    // the server rendered this one, the fragment above rendered the other.
    await expect(reloadedThread.locator('.groups-reply-bubble a').first())
        .toHaveAttribute('href', LINK_URL);

    // --- 7. And deleting everything brings the empty group back.
    //
    // The line lives inside the feed container the composer inserts into,
    // so it has to be put back by the same code that took it away — a
    // group that says nothing at all after its last message is deleted is
    // how it used to read until the next reload.
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
// Four of this module's behaviours only exist between two members, and no
// amount of care makes them reachable with one: a comment is never new to
// the person who wrote it, "Signaler" is never offered on your own message,
// the badge that says something arrived means nothing if you put it there
// yourself, and « Nouveau message » is a notification for everybody in the
// group EXCEPT the person who just wrote it — a rule that reads as "no
// notification at all" with a single account, and as the real rule with
// two. scripts/e2e-support.php provisions a second, ordinary
// member (no super-admin flag, no function, so RoleResolver puts them at
// `identified`) and a section both of them belong to.
//
// A section group rather than an invitation one: its membership is DERIVED
// per request from member_section_periods (Service\GroupAccessService), so
// both members are in it the moment it exists — which is also how most
// real groups in a unit come to be.
// ============================================================================
/**
 * One row of the notification CENTRE, and nothing else on the page.
 *
 * The same title is drawn four times over: partials/
 * notification_dropdown.html.twig repeats it in each of the three nav
 * surfaces (mobile header, mobile offcanvas, desktop bar), and
 * notifications/index.html.twig renders the real row. A page-wide
 * getByText() therefore resolves to four nodes and dies on strict mode.
 *
 * Scoping matters more for the ABSENCE assertion below than for the
 * presence ones: that dropdown shows only the FIVE most recent UNREAD
 * notifications, so "zero page-wide" could mean "never sent" or merely
 * "pushed out of a five-row preview" — two very different facts, and only
 * one of them is the rule being tested. base.html.twig's <main
 * id="main-content"> holds the centre's list and none of the chrome.
 *
 * The row itself is a submit button (each row is its own tiny POST form,
 * so marking it read can carry a CSRF token), whose accessible name is
 * its title, its body and its time — the same shape
 * specs/groups-mentions.spec.js already reads a notification with.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} name substring of the row's accessible name
 */
function notificationRow(page, name) {
    return page.getByRole('main').getByRole('button', { name });
}

const SECTION_NAME = 'Meute E2E';
const SECTION_GROUP_NAME = `Meute E2E ${Date.now()}`;
const ANNOUNCEMENT = 'Le camp est confirmé du 1er au 10 juillet.';
const COMMENT = 'Super, on peut aider au montage ?';

test('a message notifies the group but never its own author, and a comment from somebody else is announced as new and can be reported without a reload', async ({ page }) => {
    /** @type {string[]} */
    const serverErrors = [];
    page.on('response', (response) => {
        if (response.status() >= 500) {
            serverErrors.push(`HTTP ${response.status()} on ${response.url()}`);
        }
    });
    // « Signaler » asks first, through the site's own confirmation modal
    // (public/assets/js/groups.js → window.ScoutMagicConfirm). Installed
    // before the first navigation: the observer is an init script.
    await autoConfirm(page);

    // --- The section's group, and one announcement in it.
    await loginAsAdmin(page);
    await page.goto('/groups', { waitUntil: 'domcontentloaded' });
    await openCreateGroupForm(page);
    await page.getByLabel('Nom du groupe').fill(SECTION_GROUP_NAME);
    await page.getByLabel('Section').selectOption({ label: SECTION_NAME });
    await page.getByRole('button', { name: 'Créer' }).click();
    await waitForGroupsJsReady(page);

    await expect(page.getByRole('heading', { name: SECTION_GROUP_NAME })).toBeVisible();
    const groupUrl = new URL(page.url()).pathname;

    await openComposer(page);
    await page.getByLabel('Écrire un message').fill(ANNOUNCEMENT);
    await page.locator('#groups-post-form').getByRole('button', { name: 'Publier' }).click();
    await expect(page.locator('#groups-feed').getByRole('article')).toHaveCount(1);

    // --- And the author's own notification centre stays silent about it.
    //
    // Core\Notification\NotificationService::dispatch() only suppresses the
    // actor's push and email — the in-app row is still written, and that row
    // is what feeds the unread badge and the home page's « Du nouveau dans
    // vos groupes ». So the actor is dropped from the audience in the module
    // instead (Modules\Groups\Service\GroupNotificationService::
    // postPublished()), and nothing below the browser sees the result of
    // that on the page a member actually reads.
    //
    // On its own this assertion would also pass if no notification had been
    // sent at all; the member's centre, checked immediately below, is what
    // makes the pair mean something.
    const announcementNotice = `Nouveau message — ${SECTION_GROUP_NAME}`;
    await page.goto('/notifications', { waitUntil: 'domcontentloaded' });
    await expect(
        notificationRow(page, announcementNotice),
        'the author of a message must never be notified about their own message',
    ).toHaveCount(0);

    // --- The other member: in the group without ever being invited, because
    // they are in its section.
    await page.context().clearCookies();
    await loginAsMember(page);

    // Same message, same group, the other side of the rule: everybody else
    // in the group IS told.
    await page.goto('/notifications', { waitUntil: 'domcontentloaded' });
    const theirNotice = notificationRow(page, announcementNotice);
    await expect(theirNotice).toBeVisible();
    // Title and body on the SAME row, rather than each found somewhere on
    // the page: the excerpt belongs to this notification or to none.
    await expect(theirNotice).toContainText(ANNOUNCEMENT);

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

    // Their centre is not simply empty, which is what turns the absence
    // asserted above into a rule rather than a broken page: the comment
    // somebody ELSE wrote is in it, and their own message still is not.
    await page.goto('/notifications', { waitUntil: 'domcontentloaded' });
    await expect(notificationRow(page, `Réponse à votre message — ${SECTION_GROUP_NAME}`)).toBeVisible();
    await expect(notificationRow(page, announcementNotice)).toHaveCount(0);

    await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });

    const thread = page.locator('details.groups-thread');
    await expect(thread.locator('.groups-thread-new')).toHaveText('1 nouveau');
    await expect(thread).toHaveJSProperty('open', true);
    await expect(thread.locator('.groups-reply-bubble')).toContainText(COMMENT);
    await expect(thread.locator('.groups-thread-count')).toHaveText('1 commentaire');
    // And it is signed by the OTHER human, account first — a second
    // account, resolved on a page rendered entirely server-side, so the
    // rule holds for somebody who is not the reader.
    await expect(thread.locator('.groups-reply .groups-identity').first()).toHaveText('Kaa Serpent');
    await expect(thread.locator('.groups-reply .groups-identity-members').first()).toHaveText('(Kaa)');

    // --- Reporting it. Offered at all only because somebody else wrote it,
    // and answered where the reader is looking instead of by reloading the
    // whole group for one line of reassurance.
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
    await openCreateGroupForm(page);
    await page.getByLabel('Nom du groupe').fill(`Téléphone ${Date.now()}`);
    await page.getByRole('button', { name: 'Créer' }).click();
    await waitForGroupsJsReady(page);
    const groupUrl = new URL(page.url()).pathname;

    await openComposer(page);
    await page.getByLabel('Écrire un message').fill('Un message à réagir.');
    await page.locator('#groups-post-form').getByRole('button', { name: 'Publier' }).click();
    await expect(page.locator('#groups-feed').getByRole('article')).toHaveCount(1);

    const reactions = page.locator('#groups-feed .groups-reactions').first();
    // exact: the six emoji buttons are each labelled "Réagir : <clé>", so
    // a substring match would find all seven.
    const toggle = reactions.getByRole('button', { name: 'Réagir', exact: true });
    const picker = reactions.locator('.groups-reaction-picker');
    const thumbsUp = reactions.getByRole('button', { name: 'Réagir : thumbs_up' });

    // Folded: one button instead of six.
    await expect(toggle).toBeVisible();
    await expect(thumbsUp).toBeHidden();

    // Folded, not merely invisible: it is clipped to zero width, which is
    // what lets it UNFOLD rather than appear. A `display: none` picker
    // could not be animated at all, so this is the assertion that keeps
    // the effect from quietly regressing to a snap.
    const foldedWidth = await picker.evaluate((el) => el.getBoundingClientRect().width);
    expect(foldedWidth).toBe(0);
    expect(
        await picker.evaluate((el) => getComputedStyle(el).transitionProperty),
        'the picker has to animate open, not appear',
    ).toContain('max-width');

    await toggle.click();
    // The picker takes the toggle's place rather than sitting beside it,
    // so the row is no wider open than closed.
    await expect(thumbsUp).toBeVisible();
    await expect(toggle).toBeHidden();
    // Unfolded to its full run of six.
    expect(await picker.evaluate((el) => el.getBoundingClientRect().width)).toBeGreaterThan(foldedWidth);

    // And the reaction itself still goes through the same dynamic path.
    await thumbsUp.click();
    await expect(reactions.locator('.groups-reaction-tally').first()).toContainText('1');

    // --- Now the same page with groups.js never arriving.
    //
    // The fold is gated on a class that file puts on <html>, so without it
    // the six buttons must be exactly where they have always been — and
    // the toggle, which nothing could open, must not be shown at all.
    // The reference carries asset()'s ?v=… since versioning landed, so
    // the glob has to allow the query string too.
    await page.route('**/assets/js/groups.js*', (route) => route.abort());
    await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });

    const plainReactions = page.locator('#groups-feed .groups-reactions').first();
    await expect(plainReactions.getByRole('button', { name: 'Réagir : thumbs_up' })).toBeVisible();
    await expect(plainReactions.getByRole('button', { name: 'Réagir', exact: true })).toBeHidden();
    // Nothing is clipped either: the fold is gated on the same class, so
    // the picker keeps its full width and needs no button to reveal it.
    expect(
        await plainReactions.locator('.groups-reaction-picker').evaluate((el) => el.getBoundingClientRect().width),
    ).toBeGreaterThan(0);

    // The rest of the module degrades the same way: the conversation is a
    // native <details> and the composer a real form, so both still work.
    await expect(page.locator('details.groups-thread')).toHaveCount(1);
    await expect(page.locator('#groups-post-form')).toBeVisible();
    // Including the composer's own fold, which is built the same way
    // round: the bar is rendered hidden and only groups.js ever reveals
    // it, so a browser that never runs the file keeps the whole form and
    // is never shown a line it could not open.
    await expect(page.getByRole('button', { name: 'Écrire un message…' })).toBeHidden();
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
    await openCreateGroupForm(page);
    await page.getByLabel('Nom du groupe').fill(`Photo ${Date.now()}`);
    await page.getByRole('button', { name: 'Créer' }).click();
    await waitForGroupsJsReady(page);

    await openComposer(page);
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
