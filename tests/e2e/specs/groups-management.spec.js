// End-to-end: running a discussion group — the moderator's side of the
// module, and what each of their decisions does to the person on the
// other end of it.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// specs/groups-discussion.spec.js covers writing IN a group. This one
// covers deciding WHO may, and it is a different problem: every answer
// here is the intersection of two sessions. A group's membership is not a
// list the page renders, it is a permission recomputed per request from
// three sources at once (Service\GroupAccessService: an explicit
// membership row, a section period derived from the Desk import, and the
// group's own closed/archived state) — and the only honest way to state
// what an invitation, a promotion, a closure or a removal DID is to ask
// the other person's browser.
//
// That is precisely what no other suite can do. PHPUnit can assert that
// GroupAccessService returns false for a member id; it cannot assert that
// the composer is gone from the other person's page, that the group has
// left their list, or that the route now answers 404 to their session.
// And 404-not-403 is a deliberate rule of this module (a 403 would
// confirm the group exists to somebody who should not know it does), so
// the status code the OTHER browser receives is itself the assertion.
//
// It also pins the one moderator control that is pure JavaScript: the
// invite box is a search-as-you-type over /groups/{id}/member-search that
// groups.js swaps in for the plain <select>, setting the select's value
// behind the scenes. Nothing below the browser sees that swap happen.
//
// FIXTURE
// ----------------------------------------------------------------------------
// The harness's two accounts (scripts/e2e-support.php): the super-admin,
// who reaches `role_min: chief` and may therefore create a group, and the
// ordinary member, who is `identified` and may not. The group created
// here is an INVITATION group deliberately — a section group's membership
// is derived from the Desk import and cannot be granted or taken away
// from a page, so it could not show any of this.
//
// Runs before specs/scout-year-transition.spec.js moves the public year
// on, which Playwright's alphabetical file ordering already guarantees
// ("groups-" sorts before "scout-year-").
//
// LOCATORS
// ----------------------------------------------------------------------------
// Roles and visible text throughout. The two exceptions are the ones
// groups.js binds to itself, so they are contract rather than incidental
// structure: the invite search box and its results list (#invite-member-
// search / #invite-member-results), and a post's rendered body
// ([id^="post-body-"]), which text alone cannot tell from the identical
// text inside the inline edit form under the same card.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin, loginAsMember } from '../support/admin-login.js';
// Shared with specs/groups-discussion.spec.js — see support/groups.js.
import { openCreateGroupForm, waitForGroupsJsReady } from '../support/groups.js';

// Unique per run, so a re-run against a database that somehow survived
// still matches its own group rather than an older one by name.
const GROUP_NAME = `Intendance E2E ${Date.now()}`;
const GROUP_DESCRIPTION = "Tout ce qui concerne le matériel et les courses.";
const MEMBER_MESSAGE = 'Il reste deux tentes à réparer avant le camp.';

/** A post's own rendered body — never a reply's, never an edit textarea. */
const POST_BODY = '[id^="post-body-"]';

/**
 * One person's row on the members page. Bootstrap's list-group markup is
 * built from <div>s, so the rows carry no `listitem` role to query — the
 * class is the only handle, and it is stable markup of that page rather
 * than a layout accident.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} name as the row displays it
 */
function memberRow(page, name) {
    return page.locator('.list-group-item').filter({ hasText: name });
}

/**
 * Submit one of the moderator's small forms and wait for the reload it
 * causes, rather than for the click. Each posts to its own route and
 * redirects back to the page we are already on, so waiting on the URL
 * would resolve instantly against the pre-submit DOM.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} control the button to press
 * @param {string} route the path fragment its form posts to
 */
async function submitAndReload(page, control, route) {
    await Promise.all([
        page.waitForResponse((response) =>
            response.url().includes(route) && response.request().method() === 'POST'),
        control.click(),
    ]);
    await page.waitForLoadState('domcontentloaded');
}

test('a moderator opens a group, invites somebody, promotes, closes, reopens and removes them', async ({ page, browser }) => {
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

    // Several of the moderator's controls guard themselves with a
    // window.confirm() (removing somebody, closing the group), which
    // Playwright dismisses by default — that would silently cancel the
    // submit and leave the assertion below looking inexplicable.
    page.on('dialog', (dialog) => dialog.accept());

    await loginAsAdmin(page);
    // The banner is fixed to the bottom of the viewport and would sit over
    // the controls at the foot of the members page further down.
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // A chief opens a group. "Sur invitation" rather than a section's:
    // a section group's membership follows the Desk import and cannot be
    // granted or withdrawn here, so none of what follows would exist.
    // ---------------------------------------------------------------
    await page.goto('/groups', { waitUntil: 'domcontentloaded' });
    await openCreateGroupForm(page);

    const creation = page.locator('form[action="/groups"]');
    await creation.getByLabel('Nom du groupe').fill(GROUP_NAME);
    await creation.getByLabel('Section').selectOption({ label: 'Sur invitation (sans section)' });
    await creation.getByRole('button', { name: 'Créer' }).click();

    await page.waitForURL(/\/groups\/\d+$/, { waitUntil: 'domcontentloaded' });
    const groupUrl = new URL(page.url()).pathname;

    await expect(page.getByRole('heading', { level: 1, name: GROUP_NAME })).toBeVisible();
    // exact: the edit form below carries "Limiter ce groupe sur invitation
    // …" too, and a substring match would find both.
    await expect(page.getByText('Sur invitation', { exact: true })).toBeVisible();
    // Creating a group makes you its moderator — nobody grants it.
    await expect(page.getByText('Modérateur', { exact: true })).toBeVisible();
    await waitForGroupsJsReady(page);

    // ---------------------------------------------------------------
    // And gives it a one-line purpose, through the disclosure the module
    // offers a moderator and nobody else.
    // ---------------------------------------------------------------
    // A plain <details>/<summary> disclosure (no JavaScript at all), and a
    // <summary> exposes no role a query could name — its visible text is
    // what a moderator clicks, and what this clicks.
    await page.getByText('Modifier le groupe').click();
    const editForm = page.locator(`form[action="${groupUrl}/edit"]`);
    await editForm.getByLabel('Description (facultatif)').fill(GROUP_DESCRIPTION);
    await submitAndReload(page, editForm.getByRole('button', { name: 'Enregistrer' }), '/edit');

    // Scoped to the rendered paragraph: the edit form left open above
    // holds the identical text in its textarea.
    await expect(page.getByRole('paragraph').filter({ hasText: GROUP_DESCRIPTION })).toBeVisible();

    // ---------------------------------------------------------------
    // The other person, in their own browser. Nothing has been granted
    // yet, so the group must not merely be unreadable — it must not
    // exist as far as they are concerned. 404, never 403: a 403 would
    // confirm to a stranger that this group is there.
    // ---------------------------------------------------------------
    const memberContext = await browser.newContext();
    try {
        const memberPage = await memberContext.newPage();
        /** @type {string[]} */
        const memberServerErrors = [];
        memberPage.on('response', (response) => {
            if (response.status() >= 500) {
                memberServerErrors.push(`HTTP ${response.status()} on ${response.url()}`);
            }
        });

        await loginAsMember(memberPage);
        await answerCookieBanner(memberPage);

        await memberPage.goto('/groups', { waitUntil: 'domcontentloaded' });
        await expect(memberPage.getByRole('link', { name: new RegExp(GROUP_NAME) })).toHaveCount(0);
        // An ordinary member is not offered the creation form at all —
        // POST /groups is `role_min: chief`.
        // The disclosure itself, not a heading: it is a <summary> whose
        // label only looks like one (`class="h6"`).
        await expect(memberPage.locator('summary', { hasText: 'Créer un groupe' })).toHaveCount(0);

        const beforeInvite = await memberPage.goto(groupUrl, { waitUntil: 'domcontentloaded' });
        expect(beforeInvite.status(), 'a non-member must get 404, never 403').toBe(404);

        // ---------------------------------------------------------------
        // The moderator invites them — through the search box groups.js
        // puts in front of the plain select, which is the only place that
        // swap is observable.
        // ---------------------------------------------------------------
        await page.goto(`${groupUrl}/members`, { waitUntil: 'domcontentloaded' });
        await waitForGroupsJsReady(page);

        // The creator is already an explicit member of their own group, so
        // the list is not empty — what must be absent is the other person.
        const kaaRow = memberRow(page, 'Kaa');
        await expect(kaaRow).toHaveCount(0);

        const search = page.locator('#invite-member-search');
        await expect(search, 'groups.js must reveal its own search box').toBeVisible();
        await expect(page.locator('#invite-member'), 'and hide the plain select it stands in for').toBeHidden();

        await search.fill('Kaa');
        const results = page.locator('#invite-member-results');
        await expect(results.getByText('Kaa', { exact: false }).first()).toBeVisible();
        await results.getByText('Kaa', { exact: false }).first().click();

        await submitAndReload(page, page.getByRole('button', { name: 'Inviter' }), '/invite-member');

        await expect(kaaRow).toBeVisible();

        // ---------------------------------------------------------------
        // Which the other browser feels immediately: the group is in
        // their list, it opens, and they may write in it.
        // ---------------------------------------------------------------
        await memberPage.goto('/groups', { waitUntil: 'domcontentloaded' });
        await expect(memberPage.getByRole('link', { name: new RegExp(GROUP_NAME) })).toBeVisible();
        await expect(memberPage.getByText(GROUP_DESCRIPTION)).toBeVisible();

        await memberPage.goto(groupUrl, { waitUntil: 'domcontentloaded' });
        await waitForGroupsJsReady(memberPage);

        const composer = memberPage.locator('#groups-post-form');
        await expect(composer).toBeVisible();
        await composer.getByLabel('Écrire un message').fill(MEMBER_MESSAGE);
        await composer.getByRole('button', { name: 'Publier' }).click();

        await expect(memberPage.locator(POST_BODY, { hasText: MEMBER_MESSAGE })).toBeVisible();

        // ---------------------------------------------------------------
        // The moderator pins it, and it is pinned for the author too —
        // pinning is the group's state, not the moderator's view of it.
        // ---------------------------------------------------------------
        await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });
        const postCard = page.locator('article').filter({ hasText: MEMBER_MESSAGE });
        await postCard.getByRole('button', { name: 'Actions sur ce message' }).click();
        await postCard.getByRole('button', { name: 'Épingler' }).click();

        // Pinning asks first, because it is exclusive and dated: a group
        // keeps one pinned message, and the moderator says for how long
        // (public/assets/js/groups.js's askPinDuration()). The dialog is
        // the only way through with the script running — which is exactly
        // what this scenario exists to exercise, since neither PHPUnit nor
        // Vitest sees the browser hand-build this submit.
        const pinDialog = page.locator('#groups-detail-modal');
        await expect(pinDialog.getByText('Pendant combien de temps ?')).toBeVisible();
        await Promise.all([
            page.waitForResponse((response) =>
                response.url().includes('/pin') && response.request().method() === 'POST'),
            pinDialog.getByRole('button', { name: '1 semaine' }).click(),
        ]);
        await page.waitForLoadState('domcontentloaded');

        await expect(page.getByText('Épinglé')).toBeVisible();

        await memberPage.reload({ waitUntil: 'domcontentloaded' });
        await expect(memberPage.getByText('Épinglé')).toBeVisible();

        // ---------------------------------------------------------------
        // Promotion: a second moderator, which the promoted person sees
        // named on their own group list.
        // ---------------------------------------------------------------
        await page.goto(`${groupUrl}/members`, { waitUntil: 'domcontentloaded' });
        await submitAndReload(page, kaaRow.getByRole('button', { name: 'Nommer modérateur' }), '/moderator');

        await expect(kaaRow.getByText('Modérateur')).toBeVisible();
        await expect(kaaRow.getByRole('button', { name: 'Retirer la modération' })).toBeVisible();

        await memberPage.goto('/groups', { waitUntil: 'domcontentloaded' });
        await expect(
            memberPage.getByRole('link', { name: new RegExp(GROUP_NAME) }).getByText('Modérateur'),
        ).toBeVisible();

        // ---------------------------------------------------------------
        // Closing: still readable, no longer writable — and the site says
        // exactly that rather than silently removing the composer.
        // ---------------------------------------------------------------
        await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });
        await submitAndReload(page, page.getByRole('button', { name: 'Clôturer' }), '/close');
        // exact: the flash message and the "you can still read it" notice
        // both contain the word too.
        await expect(page.getByText('Clôturé', { exact: true })).toBeVisible();

        await memberPage.goto(groupUrl, { waitUntil: 'domcontentloaded' });
        await expect(memberPage.locator('#groups-post-form')).toHaveCount(0);
        await expect(
            memberPage.getByText('Ce groupe est clôturé : vous pouvez encore le consulter, mais plus y publier.'),
        ).toBeVisible();
        // Still readable — closing is not hiding.
        await expect(memberPage.locator(POST_BODY, { hasText: MEMBER_MESSAGE })).toBeVisible();

        // ---------------------------------------------------------------
        // And reopening gives the composer back. A group created this
        // year is not tied to a past one, so it may be reopened at all.
        // ---------------------------------------------------------------
        await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });
        await submitAndReload(page, page.getByRole('button', { name: 'Rouvrir' }), '/reopen');

        await memberPage.goto(groupUrl, { waitUntil: 'domcontentloaded' });
        await expect(memberPage.locator('#groups-post-form')).toBeVisible();

        // ---------------------------------------------------------------
        // Removal: the group goes back to not existing for them, exactly
        // as before the invitation — and their message stays in it.
        // ---------------------------------------------------------------
        await page.goto(`${groupUrl}/members`, { waitUntil: 'domcontentloaded' });
        // Scoped to its own form rather than matched by name: this button
        // carries only an icon plus a visually-hidden "Retirer", and the
        // promotion button beside it is "Retirer la modération" — the
        // route the form posts to is the unambiguous thing about it.
        await submitAndReload(
            page,
            kaaRow.locator('form[action$="/remove-member"]').getByRole('button'),
            '/remove-member',
        );

        await expect(kaaRow).toHaveCount(0);

        await memberPage.goto('/groups', { waitUntil: 'domcontentloaded' });
        await expect(memberPage.getByRole('link', { name: new RegExp(GROUP_NAME) })).toHaveCount(0);

        const afterRemoval = await memberPage.goto(groupUrl, { waitUntil: 'domcontentloaded' });
        expect(afterRemoval.status(), 'a removed member must get 404, never 403').toBe(404);

        // What they wrote belongs to the group, not to their membership.
        await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });
        await expect(page.locator(POST_BODY, { hasText: MEMBER_MESSAGE })).toBeVisible();

        expect(memberServerErrors, 'the application returned a server error to the member').toEqual([]);
    } finally {
        await memberContext.close();
    }

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
