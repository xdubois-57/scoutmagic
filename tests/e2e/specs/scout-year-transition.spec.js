// End-to-end: the complete scout-year transition, driven through the real
// "Année scoute" page in a real browser.
//
// ============================================================================
// SOURCE OF TRUTH
// ----------------------------------------------------------------------------
// This scenario is a transcription of the workflow the "Année scoute" page
// itself describes — core/View/templates/admin/scout_year.html.twig, whose
// phases and steps come from Core\ScoutYear\ScoutYearTransitionService.
// That page is the specification; this file only follows it.
//
// **When that page changes, this test must change with it.** Both the page
// and the service carry a matching reminder comment pointing back here, so
// whoever edits one is told about the other.
//
// The workflow, as the page words it — three phases, fourteen steps:
//
//   Préparation — avant la date d'encodage Desk
//     1. departures             "…animés qui se désinscrivent en <cible>"
//     2. passage                "…section des animés qui changent de branche"
//     3. confirm_registrations  "Confirmer les inscriptions…"
//   Encodage dans Desk — à partir de cette date
//     4. desk_staff             "Encoder les nouveaux staffs dans Desk"
//     5. desk_members           "Encoder les animés dans Desk…"
//     6. fees                   "Mettre à jour les cotisations"
//     7. preview                "Prévisualiser le site de l'année prochaine"
//     8. import                 "Importer les données Desk"
//   Mise à jour du site — "au tour des staffs d'agir"
//     9. activate_staff         "Activer l'année <cible> pour les staffs"
//    10. ephemerides            "Encoder les éphémérides de l'année sur le site"
//    11. badges                 "Encoder les badges…"
//    12. trombinoscope          "Mettre à jour le trombinoscope"
//    13. staff_photos           "Mettre à jour les photos de staff"
//    14. activate_public        "Activer pour tout le monde"
//
// Steps 1–3 belong to the Inscriptions module, 10 to Calendrier, 12 to
// Trombinoscope; a step whose module is off is not in the workflow at all.
// ============================================================================
//
// Why this is worth an end-to-end test rather than more unit tests: the
// year in effect for a given request is decided by
// Core\ScoutYear\ScoutYearResolver from three sources at once — a
// session-only preview, a global staff-year setting, and the global public
// year — and the whole point of the workflow is that those three disagree
// with each other, on purpose, for part of it. That disagreement is only
// real across genuine HTTP requests carrying a genuine session; a unit
// test asserting each source separately cannot see it. This is also the
// one scenario in the suite that exercises an authenticated admin session,
// a file upload, a module being switched on mid-flight, and a multi-request
// stateful workflow.
//
// This scenario deliberately mutates global instance state (it switches a
// module off and back on, moves the public year, and imports members):
// that is the feature under test. Every `npm run e2e` provisions a fresh
// database with every module activated, and the suite runs on a single
// worker, so nothing leaks between runs — but a future scenario that
// depends on the public year, or on which modules are on, must not assume
// it still is what the harness provisioned.
//
// No year label is ever hardcoded: ScoutYearService derives them from the
// calendar, so a hardcoded "2025-2026" would start failing on its own in
// September. Every label is read from the page and then asserted
// *relationally* (the public year becomes the year step 7 previewed, and so
// on), which is what the workflow actually promises.
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { expect, test } from '@playwright/test';

import { loginAsAdmin } from '../support/admin-login.js';
// Shared with specs/optional-module-dependencies.spec.js, which switches
// modules off for the opposite reason — see support/modules.js.
import { toggleModule } from '../support/modules.js';

const DESK_FIXTURE = path.join(
    path.dirname(fileURLToPath(import.meta.url)),
    // The same Desk export tests/Core/Import/ already uses — one fixture
    // for both suites, never a second copy that could drift from the
    // format Core\Import actually parses.
    '../../fixtures/desk_export_sample.csv',
);

/**
 * The card carrying a given year heading ("Année publique", "Année du
 * staff"). Anchored on the heading — a real semantic landmark — because
 * the value sits as a sibling inside the same card, which is precisely
 * what the page means by it; only the "same card" part is structural.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} heading
 */
function yearCard(page, heading) {
    return page.locator('.card').filter({
        has: page.getByRole('heading', { level: 2, name: heading }),
    });
}

/**
 * One step of the workflow, by the stable key the workflow gives it
 * (ScoutYearTransitionService's step catalogue). The key is a real
 * contract — it is what the check-off endpoint accepts and what the
 * scout_year_transition_steps rows are keyed on — not incidental markup,
 * and it survives the renumbering that switching a module on causes.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} key
 */
function step(page, key) {
    return page.locator(`[data-step="${key}"]`);
}

/**
 * One phase, by its own key.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} key
 */
function phase(page, key) {
    return page.locator(`[data-phase="${key}"]`);
}

/**
 * A step's own form, identified by the route it posts to. The three year
 * selects on this page all share the accessible name "Année" (each
 * belongs to its own step), so the route is what disambiguates them — and
 * a route is a real contract from public/index.php's routing table, not
 * incidental markup.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} action
 */
function stepForm(page, action) {
    return page.locator(`form[action="${action}"]`);
}

/**
 * Pick a year by its label in one step's own "Année" select.
 *
 * @param {import('@playwright/test').Locator} form
 * @param {string} label
 */
async function chooseYear(form, label) {
    await form.getByLabel('Année').selectOption({ label });
}

/**
 * Tick (or untick) a manual step and wait for the page it reloads. The
 * checkbox submits its own form on change — there is no save button — so
 * the navigation is part of the interaction, not an afterthought.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} key
 */
async function toggleStep(page, key) {
    // The POST is what the round-trip hangs on, and the page it redirects
    // to is already the one we are on — so waiting on the URL would resolve
    // instantly and assert against the pre-submit DOM. Wait on the request
    // itself, then on the reload it causes.
    await Promise.all([
        page.waitForResponse((response) =>
            response.url().includes('/admin/scout-year/step') && response.request().method() === 'POST'),
        step(page, key).getByRole('checkbox').click(),
    ]);
    await page.waitForLoadState('domcontentloaded');
}

/**
 * Every step key currently in the workflow, in the order the page renders
 * them.
 *
 * @param {import('@playwright/test').Page} page
 */
async function stepKeys(page) {
    return page.locator('[data-step]').evaluateAll(
        (nodes) => nodes.map((node) => node.getAttribute('data-step')),
    );
}

test('the whole site transitions to the next scout year through the documented workflow', async ({ page }) => {
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

    // The last step guards itself with a window.confirm() ("Activer cette
    // année pour tout le monde ?"). Playwright dismisses dialogs by
    // default, which would silently cancel the submit, so accepting it is
    // part of driving the page as a human would.
    page.on('dialog', (dialog) => dialog.accept());

    await loginAsAdmin(page);

    await page.goto('/admin/scout-year', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Année scoute' })).toBeVisible();

    // ---------------------------------------------------------------
    // Graceful degradation (ARCHITECTURE.md §7.5), driven in both
    // directions. The harness provisions the instance with EVERY module
    // activated (scripts/e2e-support.php), so the workflow starts whole —
    // and the way to prove a step really belongs to its module is to take
    // that module away and watch the step go, then give it back.
    // ---------------------------------------------------------------
    await expect(phase(page, 'preparation')).toHaveCount(1);
    for (const key of ['departures', 'passage', 'confirm_registrations']) {
        await expect(step(page, key)).toHaveCount(1);
    }

    // Switch Inscriptions OFF through the real Modules page.
    await toggleModule(page, 'Inscriptions', false);

    await page.goto('/admin/scout-year', { waitUntil: 'domcontentloaded' });

    // The three steps that module owns — and the whole "Préparation"
    // phase, which is nothing but those three — are now simply not there.
    // Not greyed out: absent.
    await expect(phase(page, 'preparation')).toHaveCount(0);
    for (const key of ['departures', 'passage', 'confirm_registrations']) {
        await expect(step(page, key)).toHaveCount(0);
    }
    // Calendrier's and Trombinoscope's steps are untouched: switching one
    // module off never costs another module its own contribution.
    await expect(step(page, 'ephemerides')).toHaveCount(1);
    await expect(step(page, 'trombinoscope')).toHaveCount(1);

    // The surviving steps are numbered 1..n over what this site actually
    // has — a chief never reads a number pointing at a step that is not on
    // their page.
    const shortWorkflow = await stepKeys(page);
    expect(shortWorkflow).toHaveLength(11);
    await expect(step(page, shortWorkflow[0]).locator('.step-circle')).toHaveText('1');

    // ---------------------------------------------------------------
    // Switch Inscriptions back on, and the workflow grows its preparation
    // phase back — the rest of the scenario runs on the whole workflow.
    // ---------------------------------------------------------------
    await toggleModule(page, 'Inscriptions', true);

    await page.goto('/admin/scout-year', { waitUntil: 'domcontentloaded' });

    expect(await stepKeys(page)).toEqual([
        'departures',
        'passage',
        'confirm_registrations',
        'desk_staff',
        'desk_members',
        'fees',
        'preview',
        'import',
        'activate_staff',
        'ephemerides',
        'badges',
        'trombinoscope',
        'staff_photos',
        'activate_public',
    ]);
    await expect(phase(page, 'preparation')).toHaveCount(1);
    await expect(phase(page, 'desk')).toHaveCount(1);
    await expect(phase(page, 'site')).toHaveCount(1);

    const publicYearCard = yearCard(page, 'Année publique');
    const previewForm = stepForm(page, '/admin/scout-year/preview');
    const staffForm = stepForm(page, '/admin/scout-year/activate-staff');
    const publicForm = stepForm(page, '/admin/scout-year/activate-public');

    // The year the workflow is about to move away from, and the one it
    // targets — both read from the page, never assumed.
    // textContent() rather than innerText() for the option: Chromium
    // reports no rendered text for an <option> inside a closed <select>.
    const outgoingYear = (await publicYearCard.locator('p.fs-5').innerText()).trim();
    const targetYear = (await previewForm.getByLabel('Année').locator('option:checked').textContent() ?? '').trim();

    expect(targetYear, 'the page must target a different year than the current public one')
        .not.toBe(outgoingYear);

    // ---------------------------------------------------------------
    // The order is advice, not a gate. Nothing has been done yet, and
    // every control except one is already usable — including step 9's,
    // eight steps ahead of where the page says the chief currently is.
    // ---------------------------------------------------------------
    await expect(previewForm.getByRole('button', { name: 'Prévisualiser' })).toBeEnabled();
    await expect(staffForm.getByRole('button', { name: 'Activer pour le staff' })).toBeEnabled();
    await expect(step(page, 'departures').getByRole('link', { name: 'Aller aux Départs' })).toBeVisible();

    // The one real gate: the public year cannot move before a staff year
    // exists. The page says so in words as well as through the control.
    await expect(publicForm.getByRole('button', { name: 'Activer pour tout le monde' })).toBeDisabled();
    await expect(page.getByText("Activez d'abord une année pour le staff.")).toBeVisible();
    await expect(yearCard(page, 'Année du staff')).toHaveCount(0);

    // The Desk-encoding date is a signpost: it labels the phases and says
    // which period the calendar is in. It disables nothing — the whole
    // reason it is a date and not a rule (specifications.md §16.4).
    await expect(phase(page, 'preparation')).toContainText('Idéalement avant le');
    await expect(phase(page, 'desk')).toContainText('Plutôt à partir du');
    await expect(page.getByText('elles ne déclenchent et ne bloquent jamais rien')).toBeVisible();

    // ---------------------------------------------------------------
    // Steps 1–6: the work that happens somewhere the site cannot see —
    // in Desk, or in a staff meeting. Nothing observable marks them done,
    // so someone says so, and the site remembers who and when.
    // ---------------------------------------------------------------
    const deskStaff = step(page, 'desk_staff');
    await expect(deskStaff.getByRole('checkbox')).not.toBeChecked();
    await expect(deskStaff).toContainText('Marquer cette étape comme faite');

    await toggleStep(page, 'desk_staff');

    await expect(step(page, 'desk_staff').getByRole('checkbox')).toBeChecked();
    await expect(step(page, 'desk_staff')).toContainText('Fait');
    // The marker turns into a check, exactly like an auto-derived step's.
    await expect(step(page, 'desk_staff').locator('.step-circle--done')).toBeVisible();

    // It survives a reload — this is stored state, not a checkbox the
    // browser happens to be holding.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(step(page, 'desk_staff').getByRole('checkbox')).toBeChecked();

    // And it can be taken back: a step ticked by mistake is not a
    // one-way door.
    await toggleStep(page, 'desk_staff');
    await expect(step(page, 'desk_staff').getByRole('checkbox')).not.toBeChecked();
    await toggleStep(page, 'desk_staff');

    for (const key of ['desk_members', 'fees']) {
        await toggleStep(page, key);
        await expect(step(page, key).getByRole('checkbox')).toBeChecked();
    }

    // The steps the site works out for itself have no checkbox at all —
    // ticking "activer pour tout le monde" by hand would be a plain lie.
    for (const key of ['preview', 'import', 'activate_staff', 'activate_public']) {
        await expect(step(page, key).getByRole('checkbox')).toHaveCount(0);
    }

    // ---------------------------------------------------------------
    // Step 7 — "Prévisualiser le site de l'année prochaine".
    // The page promises this "ne concerne que votre session et n'affecte
    // aucun autre utilisateur", so both halves are asserted: the visitor
    // does move, and the year everyone else sees does not.
    // ---------------------------------------------------------------
    await chooseYear(previewForm, targetYear);
    await previewForm.getByRole('button', { name: 'Prévisualiser' }).click();

    await expect(page.getByRole('alert').filter({ hasText: 'Prévisualisation activée pour cette session.' }))
        .toBeVisible();
    // base.html.twig's site-wide banner — the preview is visible on every
    // page, and says in so many words that it is session-scoped.
    await expect(page.getByRole('alert').filter({ hasText: `Vous visualisez le site en ${targetYear} (session uniquement).` }))
        .toBeVisible();
    await expect(publicYearCard).toContainText(outgoingYear);
    await expect(step(page, 'preview').locator('.step-circle--done')).toBeVisible();

    // ---------------------------------------------------------------
    // Step 8 — "Importer les données Desk". Followed through the page's
    // own link, and done with a real multipart upload of a real Desk
    // export: the step is complete only once the target year genuinely
    // has members (ScoutYearResolver::countMembers() > 0), which no
    // amount of clicking can fake.
    // ---------------------------------------------------------------
    await step(page, 'import').getByRole('link', { name: "Aller à l'import Desk" }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Import Desk' })).toBeVisible();

    const importForm = page.locator('form[action="/admin/import"]');
    await importForm.getByLabel('Année scoute cible').selectOption({ label: targetYear });
    await importForm.getByLabel('Fichier CSV à importer').setInputFiles(DESK_FIXTURE);
    // exact: Chromium exposes <input type="file"> as a button too, and its
    // accessible name ("Fichier CSV à importer") contains "importer".
    await importForm.getByRole('button', { name: 'Importer', exact: true }).click();

    await expect(page.getByText(/membres importés/)).toBeVisible();

    await page.goto('/admin/scout-year', { waitUntil: 'domcontentloaded' });
    await expect(step(page, 'import').locator('.step-circle--done')).toBeVisible();
    await expect(step(page, 'import')).toContainText(`membre(s) importé(s) pour ${targetYear}`);

    // Now that the year has sections, the photo step can measure itself —
    // and says none of them has this year's photo yet.
    await expect(step(page, 'staff_photos')).toContainText('section(s) photographiée(s)');
    await expect(step(page, 'staff_photos').locator('.step-circle--done')).toHaveCount(0);

    // ---------------------------------------------------------------
    // Step 9 — "Activer l'année <cible> pour les staffs". The page's own
    // wording is the assertion: chiefs move, "les animés et les visiteurs
    // restent sur l'année courante".
    // ---------------------------------------------------------------
    await chooseYear(staffForm, targetYear);
    await staffForm.getByRole('button', { name: 'Activer pour le staff' }).click();
    // A real, unenhanced form submit — same reload every other step-toggle
    // in this scenario waits out (see toggleStep() above, and
    // toggleModule() in support/modules.js)
    // before touching the DOM again. Skipping it here let a later
    // toggleStep() click reach a checkbox before the page's own inline
    // "wire up .js-step-checkbox" <script> (core/View/templates/admin/
    // scout_year.html.twig's own {% block scripts %}, which every step's
    // checkbox needs) had run — the click still toggled the checkbox
    // itself (a native, unconditional browser behaviour), but nothing
    // then submitted its form, so the expected POST never arrived and
    // toggleStep() timed out waiting for it.
    await page.waitForLoadState('domcontentloaded');

    await expect(page.getByRole('alert').filter({ hasText: `Année ${targetYear} activée pour le staff.` }))
        .toBeVisible();

    const staffYearCard = yearCard(page, 'Année du staff');
    await expect(staffYearCard).toContainText(targetYear);
    await expect(staffYearCard).toContainText('Vue par les animateurs et intendants uniquement.');
    // The half that matters most, and the one a unit test cannot see: the
    // year everyone else is served has still not moved.
    await expect(publicYearCard).toContainText(outgoingYear);

    // ---------------------------------------------------------------
    // Steps 10–13 — the site's own content for the new year. Ticked by
    // hand here: this instance keeps no calendar and takes no photos, and
    // a signal that will never fire must not leave a step unfinishable.
    // ---------------------------------------------------------------
    await expect(step(page, 'ephemerides').getByRole('link', { name: 'Aller au calendrier' })).toBeVisible();
    await expect(step(page, 'badges').getByRole('link', { name: 'Aller aux staffs' })).toBeVisible();
    await expect(step(page, 'trombinoscope').getByRole('link', { name: 'Aller au trombinoscope' })).toBeVisible();

    for (const key of ['ephemerides', 'badges', 'trombinoscope', 'staff_photos']) {
        await toggleStep(page, key);
        await expect(step(page, key).locator('.step-circle--done')).toBeVisible();
    }

    // ---------------------------------------------------------------
    // The staff year, seen without a preview shadowing it.
    //
    // ScoutYearResolver resolves preview → staff → public in that order,
    // so this session's own preview is still what it sees. Dropping it
    // through the page's own "Revenir à l'année courante" control is what
    // reveals the staff year underneath — and it also demonstrates,
    // deliberately, that the preview step tracks live session state:
    // with the preview gone it stops reading as done.
    // ---------------------------------------------------------------
    // Two controls carry this name — the site-wide banner's close button
    // and the preview step's own — so this is scoped to the banner, which
    // is the one a visitor reaches from any page.
    await page
        .getByRole('alert')
        .filter({ hasText: `Vous visualisez le site en ${targetYear} (session uniquement).` })
        .getByRole('button', { name: "Revenir à l'année courante" })
        .click();

    await expect(page.getByRole('alert').filter({ hasText: `Le staff voit actuellement l'année ${targetYear}.` }))
        .toBeVisible();
    await expect(publicYearCard).toContainText(outgoingYear);
    await expect(step(page, 'preview').locator('.step-circle--done')).toHaveCount(0);

    // Unlike before, losing the preview does not lock the final step: the
    // only thing that ever gated it is the staff year, and that is set.
    await expect(publicForm.getByRole('button', { name: 'Activer pour tout le monde' })).toBeEnabled();

    // ---------------------------------------------------------------
    // Step 14 — "Activer pour tout le monde": the whole site moves, "de
    // façon permanente", and the staff year is cleared automatically.
    // ---------------------------------------------------------------
    await chooseYear(publicForm, targetYear);
    await publicForm.getByRole('button', { name: 'Activer pour tout le monde' }).click();

    await expect(page.getByRole('alert').filter({ hasText: `Année ${targetYear} activée pour tout le monde.` }))
        .toBeVisible();
    await expect(publicYearCard).toContainText(targetYear);
    await expect(publicYearCard).toContainText('Année vue par tout le monde.');
    // "…et désactive l'année du staff": staff and public are aligned
    // again, so the staff card is gone entirely.
    await expect(yearCard(page, 'Année du staff')).toHaveCount(0);

    // And the workflow has rolled forward: with the site now on what used
    // to be the target, the page describes the transition to the year
    // after it, from the top. That is the clearest single proof the
    // transition actually committed rather than merely flashing a message.
    await expect(page.getByText(`Prévisualiser le site de l'année prochaine (${targetYear})`)).toHaveCount(0);
    await expect(publicForm.getByRole('button', { name: 'Activer pour tout le monde' })).toBeDisabled();

    // Every manual tick above belonged to the transition that just
    // finished. The next one starts blank on its own — no reset step, and
    // nothing for anyone to clear by hand.
    for (const key of ['desk_staff', 'desk_members', 'fees', 'ephemerides', 'badges', 'trombinoscope', 'staff_photos']) {
        await expect(step(page, key).getByRole('checkbox')).not.toBeChecked();
    }

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
