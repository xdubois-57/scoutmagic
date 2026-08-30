// End-to-end: the public home page boots and renders in a real browser.
//
// This is the foundational E2E scenario (see README.md § Tests de bout en
// bout for the full current list) — the first test in this repository
// that answers "can the application actually start and serve a page to a
// browser?", a question neither PHPUnit nor Vitest can answer:
//
//   - PHPUnit constructs controllers and services directly, one at a
//     time, with hand-written arguments. It never executes public/index.php,
//     so it cannot see a broken composition root. That exact failure has
//     already shipped once: a controller's constructor lost a parameter,
//     every test was updated and green, and the one call site nobody
//     updated was index.php's wiring — a TypeError on literally every
//     request (AGENTS.md § Static analysis).
//   - Vitest runs first-party browser JavaScript against a jsdom DOM,
//     with no PHP, no database, no HTTP.
//
// So this scenario exercises the real path end to end: `php -S` over the
// real public/index.php, the real configuration/secrets loading, the real
// MySQL connection, the real schema-migration check, the real module
// registry, the real router and RBAC guard on a `role_min: public` route,
// the real Twig rendering, and a real Chromium parsing the result.
//
// Assertions are semantic (roles, accessible names, the document title) —
// never CSS/structural selectors, which would break on any Bootstrap
// markup reshuffle without a single user-visible thing having changed.
//
// A second scenario lives in this file, below the first: the home page's
// three module-provided bands are ONE band chosen by priority, and that
// rule is only observable when several of them can speak at once. It
// carries its own header where it starts.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin, loginAsMember } from '../support/admin-login.js';
import { openComposer, openCreateGroupForm, waitForGroupsJsReady } from '../support/groups.js';
import { xlsxBuffer } from '../support/xlsx.js';

test('the public home page boots through public/index.php and renders in a browser', async ({ page }) => {
    // Anything the application itself got wrong in the browser: an
    // uncaught exception in first-party JavaScript, or a same-origin
    // response the server should never produce. Third-party console noise
    // is deliberately not collected — this instance loads nothing the
    // application does not control, and failing on generic console
    // messages is how an E2E gate becomes a flaky one.
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

    const response = await page.goto('/', { waitUntil: 'domcontentloaded' });

    // The navigation itself: a 5xx here means the bootstrap threw before
    // producing a page at all, which is precisely the class of failure
    // this test exists for.
    expect(response, 'the browser received no response for GET /').not.toBeNull();
    expect(response.status(), 'GET / must return HTTP 200').toBe(200);

    // Rendered by pages/home.html.twig's {% block title %}, interpolating
    // the site_name Twig global — which the composition root only has
    // once the database connection, the settings table, and SettingService
    // are all genuinely working. A hardcoded string could not prove that.
    await expect(page).toHaveTitle('Bienvenue — Unité de test E2E');

    // The page's own content, from Core\View\EditableContentService's
    // default for the 'home.intro' key.
    await expect(page.getByRole('heading', { level: 1, name: 'Bienvenue' })).toBeVisible();

    // base.html.twig's landmarks. <main> only exists if template
    // inheritance resolved at all. The primary navigation landmark is
    // matched by the menu it contains rather than by position: the layout
    // renders a second one (the breadcrumb bar), and "the first <nav> on
    // the page" is exactly the kind of incidental-structure assertion
    // that breaks on an unrelated layout change. Its "Notre unité" entry
    // is also the proof that MenuBuilder actually built the menu tree for
    // a public, logged-out visitor instead of throwing.
    await expect(page.getByRole('main')).toBeVisible();
    await expect(
        page.getByRole('navigation').filter({ hasText: 'Notre unité' }),
    ).toBeVisible();

    // The footer's RGPD link — a real, routable public URL rendered by the
    // shared layout, so a smoke test of the layout as a whole rather than
    // of the home page's own block alone.
    await expect(
        page.getByRole('contentinfo').getByRole('link', { name: 'Protection des données' }),
    ).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});

// End-to-end: the home page carries ONE band, and priority decides which.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Three independent modules can each put a band above the welcome text —
// finance's "reste à payer", groups' "du nouveau dans vos groupes", and
// the Banners module's editorial message — and the page renders exactly
// one of them, the highest-priority one that has something to say
// (pages/home.html.twig's if/elseif chain, and the matching
// short-circuit in Core\Http\Controller\PageController::home()).
//
// That rule is only observable when SEVERAL of them can speak at once,
// and no other suite can put them in that state:
//
//   - PHPUnit wires the providers by hand. It proves the chain picks the
//     right one from the summaries a test wrote itself, never that a real
//     finance campaign and a real unread group produce those summaries at
//     the same moment for the same visitor.
//   - Vitest sees no PHP at all.
//
// The rule is also a rule about a REGRESSION that is invisible per-band:
// each band renders perfectly on its own, and the failure mode is a page
// that stacks two or three of them — exactly what a browser sees and a
// unit test of either module does not.
//
// It runs against the real modules end to end: a real bank account, a
// real campaign created from a real .xlsx upload (the only thing in the
// codebase that produces a receivable attached to a MEMBER, which is what
// the band reads), a real group, and a real message posted in it by
// somebody else — because "there is money due AND unread activity" is a
// state assembled by two modules that know nothing about each other.
//
// The group is the SEEDED SECTION's, not one opened on invitation. That
// is a correctness requirement rather than a preference, and it is
// explained where the group is created: the invitation-group creation
// quota is already spent by the time this file runs in a full suite.
//
// LOCATORS
// ----------------------------------------------------------------------------
// Visible text and roles throughout — each band is identified by the
// French sentence a family actually reads. Three places fall back to a
// handle, each of them a contract rather than incidental structure:
// `#home-payment-due`, the id the payment band carries for exactly this
// purpose (Tests\Core\Http\Controller\PageControllerTest asserts on the
// same one); `#groups-post-form` and `[id^="post-body-"]`, which
// public/assets/js/groups.js itself binds to, borrowed from
// specs/groups-management.spec.js along with the steps that use them; and
// the count of `.alert` blocks inside the page's own grid, which is how
// "exactly one band" is expressed at all — no role or name can say "and
// nothing else". That count is also why the search for the group band's
// sentence is scoped to those blocks: the page's contextual help panel
// explains the band in the same words.
//
// HOUSEKEEPING
// ----------------------------------------------------------------------------
// The scenario ends by abandoning the receivable and opening the group,
// so it leaves the instance's home page as it found it rather than
// showing a leftover band to every scenario that runs after it — and an
// afterEach finishes the job when an assertion stops the scenario before
// it gets there. Both bands appear on the administrator's home page, and
// every later spec signs in as that same administrator, so one failure
// here must not become a failure somewhere unrelated.
const ACCOUNT_NAME = `Compte accueil E2E ${Date.now()}`;
const ACCOUNT_IBAN = 'BE68 5390 0754 7034';
const CAMPAIGN_LABEL = `Cotisation accueil ${Date.now()}`;
const GROUP_NAME = `Accueil E2E ${Date.now()}`;
/**
 * The section scripts/e2e-support.php gives BOTH seeded people a period
 * in (e2e_seed_section_with_both_members). Its group is therefore a group
 * they are both in without anybody being invited — and, unlike an
 * invitation group, one the creation quota does not count.
 */
const SECTION_NAME = 'Meute E2E';
const MEMBER_MESSAGE = "Le local est libre samedi si quelqu'un veut préparer le matériel.";
/** A post's own rendered body — never a reply's, never an edit textarea. */
const POST_BODY = '[id^="post-body-"]';
/** The .xlsx MIME type, as a browser sends it for a real spreadsheet. */
const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

/**
 * The home page's own bands — the alerts inside the page's grid, which is
 * where all three render. Deliberately not "every alert on the page":
 * base.html.twig puts a flash message directly under <main> and its own
 * strips above it, and neither is a band this rule talks about.
 *
 * @param {import('@playwright/test').Page} page
 */
function homeBands(page) {
    return page.locator('main .row .alert');
}

/**
 * What the scenario has created and not yet cleared, for the teardown
 * below. Each is set the moment the thing exists and cleared again by the
 * scenario's own closing gestures, so the teardown only ever finishes
 * what an early failure interrupted — and never abandons a receivable
 * twice, which would toggle it back to due.
 *
 * @type {string|null}
 */
let createdCampaignPath = null;
/** @type {string|null} */
let createdGroupPath = null;

/**
 * Leave the instance's home page as this file found it, whether the
 * scenario reached its own last line or died on an assertion half-way.
 *
 * This matters more than tidiness: an unpaid receivable and an unread
 * group both put a band on the ADMINISTRATOR's home page, and every
 * scenario that runs after this file signs in as that same
 * administrator. A failure here used to leave those bands behind, so one
 * broken assertion could go on to break specs that have nothing to do
 * with it. Cleaning up in an afterEach rather than at the end of the test
 * body is the whole point.
 *
 * Deliberately best-effort: a teardown that throws would replace the real
 * failure with its own, which is exactly the report nobody can act on.
 */
test.afterEach(async ({ page }) => {
    const campaignPath = createdCampaignPath;
    const groupPath = createdGroupPath;
    createdCampaignPath = null;
    createdGroupPath = null;

    if (campaignPath !== null) {
        try {
            await page.goto(campaignPath, { waitUntil: 'domcontentloaded' });
            await page.getByRole('button', { name: 'Détail de la créance' })
                .filter({ visible: true }).first().click();
            await Promise.all([
                page.waitForURL(/\/finance\/campaigns\/\d+\?filter=/, { waitUntil: 'domcontentloaded' }),
                page.getByRole('button', { name: 'Abandonner la créance' })
                    .filter({ visible: true }).first().click(),
            ]);
        } catch {
            // Nothing to add: the scenario's own failure is the report.
        }
    }

    if (groupPath !== null) {
        try {
            // Opening a group is what marks it read.
            await page.goto(groupPath, { waitUntil: 'domcontentloaded' });
        } catch {
            // Same.
        }
    }
});

test('with money due and unread group activity at once, the home page shows one band and it is the payment one', async ({ page, browser }) => {
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

    // Abandoning a receivable asks first, through the site's own modal
    // (design.md §7.5), which Playwright never sees as a dialog.
    // Installed before the first navigation: it is an init script.
    await autoConfirm(page);

    await loginAsAdmin(page);
    // The banner is fixed to the bottom of the viewport and would sit over
    // the controls at the foot of the pages below.
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // MONEY DUE. An account, then a campaign billing the administrator's
    // own member — the same member their address is linked to, which is
    // what Modules\Finance\Service\FamilyPaymentService resolves the band
    // from.
    // ---------------------------------------------------------------
    await page.goto('/config/finance/accounts', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Nouveau compte' }).click();
    await page.getByLabel(/^Nom \*$/).fill(ACCOUNT_NAME);
    await page.getByLabel('IBAN').fill(ACCOUNT_IBAN);
    await page.getByLabel('Nom du titulaire').fill('Unité de test E2E');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByRole('row', { name: new RegExp(ACCOUNT_NAME) })).toBeVisible();

    await page.goto('/finance/campaigns/new', { waitUntil: 'load' });
    await page.getByLabel(/^Nom de la campagne/).fill(CAMPAIGN_LABEL);
    await page.getByLabel(/^Compte destinataire/).selectOption({ label: ACCOUNT_NAME });
    // "Identifiant Desk" is one of the two identifier columns the importer
    // accepts, and 'E2E-ADMIN' is the desk id scripts/e2e-support.php gives
    // the administrator's member. There is deliberately no matching on a
    // name anywhere in that importer, so the identifier is what ties this
    // amount to the visitor whose home page is checked below.
    await page.getByLabel(/^Fichier Excel/).setInputFiles({
        name: 'campagne.xlsx',
        mimeType: XLSX_MIME,
        buffer: xlsxBuffer([
            ['Identifiant Desk', 'Nom', 'Montant'],
            ['E2E-ADMIN', 'Powell Baden', '42,50'],
        ]),
    });
    await page.getByRole('button', { name: 'Créer la campagne' }).click();

    await page.waitForURL(/\/finance\/campaigns\/\d+$/, { waitUntil: 'domcontentloaded' });
    const campaignUrl = new URL(page.url()).pathname;
    // Recorded before the first assertion that could fail with money
    // still owed: from here on the teardown can clear it.
    createdCampaignPath = campaignUrl;
    await expect(page.getByRole('heading', { level: 1, name: CAMPAIGN_LABEL })).toBeVisible();

    // ---------------------------------------------------------------
    // UNREAD GROUP ACTIVITY. A group both seeded people are in, and a
    // message from the other one — a message is never new to whoever
    // wrote it, so the second browser is the point rather than a
    // convenience.
    //
    // The SECTION's group, not an invitation group, and that is not a
    // detail: Modules\Groups\Controller\GroupController::create()
    // enforces a per-creator quota of open INVITATION groups
    // (GroupMembershipService::DEFAULT_CREATION_QUOTA, five), and by the
    // time this file runs the administrator has already opened that many
    // across specs/groups-discussion, groups-management and
    // groups-mentions. The sixth is refused with a French flash and a
    // redirect back to /groups — which is what happens in a FULL suite
    // run and never when this spec runs alone. A section group is exempt
    // (the controller says why: the scheduled task creates those anyway),
    // and it also makes the other member a member without an invitation,
    // because they have a period in that section — the same derivation
    // specs/groups-discussion.spec.js leans on.
    // ---------------------------------------------------------------
    await page.goto('/groups', { waitUntil: 'domcontentloaded' });
    await openCreateGroupForm(page);
    const creation = page.locator('form[action="/groups"]');
    await creation.getByLabel('Nom du groupe').fill(GROUP_NAME);
    await creation.getByLabel('Section').selectOption({ label: SECTION_NAME });
    await Promise.all([
        page.waitForURL(/\/groups\/\d+$/, { waitUntil: 'domcontentloaded' }),
        creation.getByRole('button', { name: 'Créer' }).click(),
    ]);

    const groupUrl = new URL(page.url()).pathname;
    // Recorded for the teardown, which has to be able to clear this group
    // even when an assertion below never lets the scenario finish.
    createdGroupPath = groupUrl;
    await expect(page.getByRole('heading', { level: 1, name: GROUP_NAME })).toBeVisible();
    await waitForGroupsJsReady(page);

    const memberContext = await browser.newContext();
    try {
        const memberPage = await memberContext.newPage();
        await loginAsMember(memberPage);
        await answerCookieBanner(memberPage);

        await memberPage.goto(groupUrl, { waitUntil: 'domcontentloaded' });
        await waitForGroupsJsReady(memberPage);
        // groups.js folds the composer away behind a one-line bar as soon
        // as it runs (modules/groups/views/show.html.twig), so writing a
        // message starts by asking for the form — the same click a member
        // makes. Without it the form below stays `d-none`.
        await openComposer(memberPage);
        const composer = memberPage.locator('#groups-post-form');
        await composer.getByLabel('Écrire un message').fill(MEMBER_MESSAGE);
        await composer.getByRole('button', { name: 'Publier' }).click();
        // The post's own rendered body, never the edit textarea that
        // carries the same text (same handle as specs/
        // groups-management.spec.js, and for the same reason).
        await expect(memberPage.locator(POST_BODY, { hasText: MEMBER_MESSAGE })).toBeVisible();
    } finally {
        await memberContext.close();
    }

    // ---------------------------------------------------------------
    // THE RULE. Both providers now have something to say, and the home
    // page says exactly one of the two things — the money.
    // ---------------------------------------------------------------
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const paymentBand = page.locator('#home-payment-due');
    await expect(paymentBand, 'the payment band must be the one shown').toBeVisible();
    await expect(paymentBand).toContainText('reste à payer');
    await expect(paymentBand).toContainText('42,50');
    // Its own campaign, not a leftover from another scenario.
    await expect(paymentBand).toContainText(CAMPAIGN_LABEL);
    // One style for all three bands (design.md §7.8 / plain Bootstrap):
    // the band is informative, not a warning.
    await expect(paymentBand).toHaveClass(/alert-info/);

    // Scoped to the bands rather than to the whole document: the page's
    // contextual help panel (rendered after <main>, folded away) explains
    // the group band to a reader in those very words, so a document-wide
    // search for the sentence finds the help topic and never fails.
    await expect(
        homeBands(page).filter({ hasText: 'Du nouveau dans vos groupes' }),
        'the group band must give way to the payment band, not stack under it',
    ).toHaveCount(0);
    await expect(homeBands(page), 'the home page carries ONE band, never a stack').toHaveCount(1);

    // ---------------------------------------------------------------
    // AND THE NEXT ONE DOWN. Abandon the receivable — a real treasurer's
    // gesture, and the only way to make the top of the chain fall silent
    // without inventing state — and the group band takes the same slot.
    // ---------------------------------------------------------------
    await page.goto(`${campaignUrl}`, { waitUntil: 'domcontentloaded' });
    // The line's controls live in a Bootstrap collapse, rendered twice
    // (desktop table + phone cards); only the copy this viewport shows can
    // be clicked.
    await page.getByRole('button', { name: 'Détail de la créance' }).filter({ visible: true }).first().click();
    const waive = page.getByRole('button', { name: 'Abandonner la créance' }).filter({ visible: true }).first();
    // Waiting on the redirect the POST causes, not on the POST itself: the
    // response resolves while the browser is still navigating, and the
    // next goto() would then race it.
    await Promise.all([
        page.waitForURL(/\/finance\/campaigns\/\d+\?filter=/, { waitUntil: 'domcontentloaded' }),
        waive.click(),
    ]);
    // The gesture landed, in the campaign's own count of abandoned lines.
    await expect(page.getByRole('link', { name: 'Abandonnées (1)' })).toBeVisible();
    // Done by hand: the teardown must not abandon it a second time, which
    // the same form would read as "annuler l'abandon".
    createdCampaignPath = null;

    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#home-payment-due'), 'an abandoned receivable owes nothing').toHaveCount(0);
    const groupBand = homeBands(page).filter({ hasText: 'Du nouveau dans vos groupes' });
    await expect(groupBand, 'with nothing due, the group band takes the slot').toBeVisible();
    await expect(groupBand).toHaveClass(/alert-info/);
    await expect(homeBands(page), 'still ONE band, never two').toHaveCount(1);

    // Housekeeping: opening the group is what clears its unread state, so
    // the scenarios that run after this one find the home page as it was.
    // The teardown does the same for a run that never reaches this line.
    await page.goto(groupUrl, { waitUntil: 'domcontentloaded' });
    createdGroupPath = null;

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
