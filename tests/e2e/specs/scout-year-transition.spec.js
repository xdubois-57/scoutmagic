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
// The workflow, as the page words it — three phases, fourteen steps, and
// only three of them observed by the site (import, activate_staff,
// activate_public); every other one is a human decision, marked by hand:
//
//   Préparation — avant la date d'encodage Desk
//     1. departures             "Départs de l'unité"
//     2. passage                "Passages de branche"
//     3. confirm_registrations  "Nouvelles inscriptions"
//   Encodage et contrôles Desk — à partir de cette date
//     4. desk_staff             "Encoder les nouveaux staffs dans Desk"
//     5. desk_members           "Encoder les animés dans Desk"
//     6. import                 "Importer les données Desk pour <cible>"  (observed)
//     7. fees                   "Vérifier les cotisations de <cible>"
//     8. preview                "Prévisualiser le site de <cible>"
//   Mise à jour du site et publication — "au tour des staffs d'agir"
//     9. activate_staff         "Activer <cible> pour les staffs"         (observed)
//    10. ephemerides            "Éphémérides de l'année"
//    11. badges                 "Attribuer les badges"
//    12. trombinoscope          "Photos individuelles des animateurs"
//    13. staff_photos           "Photos de groupe des staffs"
//    14. activate_public        "Activer <cible> pour tout le monde"      (observed)
//
// Steps 1–3 belong to the Inscriptions module, 7 to Cotisations, 10 to
// Calendrier, 12 to Trombinoscope; a step whose module is off is not in the
// workflow at all.
//
// The blocks that are real, all refused by the server: the staff year
// needs a successful import of the target; the switch for everyone needs
// the staff year on the target, that import, and no open registration.
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
// *relationally* (the public year becomes the year step 8 previewed, and so
// on), which is what the workflow actually promises.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { expect, test } from '@playwright/test';

import { autoConfirm } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
// Shared with specs/optional-module-dependencies.spec.js, which switches
// modules off for the opposite reason — see support/modules.js.
import { toggleModule } from '../support/modules.js';
import { waitForServerResponse } from '../support/response.js';

const DESK_FIXTURE = path.join(
    path.dirname(fileURLToPath(import.meta.url)),
    // The same Desk export tests/Core/Import/ already uses — one fixture
    // for both suites, never a second copy that could drift from the
    // format Core\Import actually parses.
    '../../fixtures/desk_export_sample.csv',
);

/**
 * The Desk export this scenario uploads: the shared sample, plus ONE row —
 * the super-admin, as chef d'unité of the target year.
 *
 * WHY THE EXTRA ROW EXISTS. A Desk import replaces a roster:
 * DeskImportService calls deactivateAllForYear() before writing, so after
 * step 6 the only members of the target year are the ones this file names.
 * The shared sample names six children and no unit chief, so the account
 * every later scenario signs in as stopped being Staff d'U the moment this
 * spec moved the public year forward — and pages gated on « chef d'unité »
 * (/config/retro, /config/banner) began answering 403 in
 * zz-module-boot-matrix, which runs after this one.
 *
 * A real unit's export always carries its own chief; the six-row sample
 * simply is not a real unit. Adding the row here rather than to
 * tests/fixtures/desk_export_sample.csv keeps that file byte-identical for
 * tests/Core/Import/, which asserts on its exact contents.
 *
 * `FONCTION` is the column DeskCsvParser reads as the function code, and
 * `E2E-CDU` is the admin-role function scripts/e2e-support.php already
 * seeds (e2eSeedUnitChiefFunctionForAdmin). Section is left EMPTY on
 * purpose: UnitStaffSectionService::syncMembership() is what puts every
 * admin-role function with no section into « Staff d'U », which is exactly
 * how a real chief lands there.
 */
function deskExportCarryingTheUnitChief() {
    const raw = fs.readFileSync(DESK_FIXTURE, 'utf8');
    const lines = raw.split(/\r?\n/).filter((line) => line.trim() !== '');
    const header = lines[0].split(';');

    const row = new Array(header.length).fill('');
    const set = (column, value) => {
        const index = header.indexOf(column);
        if (index === -1) {
            throw new Error(`Desk export has no "${column}" column — the fixture format changed.`);
        }
        row[index] = value;
    };

    set('Nom', 'Cheffe');
    set('Prenom', 'Unite');
    set('Genre', 'F');
    set('Date de naissance', '01/01/1990');
    set('Email Tiers', process.env.E2E_ADMIN_EMAIL ?? 'admin@example.invalid');
    set('Rue', 'Rue du Staff');
    set('No', '1');
    set('Code Postal', '1000');
    set('Ville', 'Bruxelles');
    set('Pays', 'Belgique');
    set("Type d'adresse", 'Domicile');
    set('Courrier fédération', 'true');
    set("Courrier d'unité", 'true');
    set('Groupe unités', 'SV025');
    set("Fonction au sein de l'unité", 'SV025U');
    set('FONCTION', 'E2E-CDU');
    set('Tiers', 'T900');
    set('Date début', '01/09/2025');
    set('Fonction principale', 'true');

    return [...lines, row.join(';')].join('\n') + '\n';
}

/**
 * The card carrying a given year heading ("Année publique", "Année du
 * staff", "Année cible", "Prévisualisation"). Anchored on the heading — a
 * real semantic landmark — because the value sits as a sibling inside the
 * same card, which is precisely what the page means by it.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} heading
 */
function yearCard(page, heading) {
    return page.locator('[data-year-cards] .card').filter({
        has: page.getByRole('heading', { level: 2, name: heading }),
    });
}

/**
 * The year a card shows, as text.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} heading
 */
async function yearOf(page, heading) {
    return (await yearCard(page, heading).locator('p.fs-5').innerText()).trim();
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
 * The disc of a human step — the only control that marks it. Named by
 * its effect (« Étape 4 : Cliquer pour marquer comme faite »).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} key
 */
function disc(page, key) {
    return step(page, key).getByRole('button', { name: /^Étape \d+ :/ });
}

/**
 * A step's Détails dialog, open or not.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} key
 */
function details(page, key) {
    return page.locator(`#step-details-${key}`);
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
 * Run one action of the page and wait until the page has swapped in the
 * state the server sent back. Nothing reloads (CHANTIER-annee-scoute.md §2.8): the
 * request is JSON, and the page marks itself busy for exactly as long as
 * the call runs (public/assets/js/admin-scout-year.js), so the end of the
 * busy state is the moment the new state is in the DOM.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} route the /admin/scout-year/* route the action posts to
 * @param {() => Promise<void>} trigger
 */
async function act(page, route, trigger) {
    // Wait for the page to REACT before clicking it: a click landing
    // before admin-scout-year.js bound its listener posts the plain form
    // and reloads the page instead (same marker convention as
    // support/sos.js and support/confirm-dialog.js).
    await page.waitForFunction(() => document.documentElement.classList.contains('scout-year-js'));
    // The busy flag of the previous action is still on the container (it
    // survives the swap): clear it so the wait below sees THIS action end.
    await page.evaluate(() => document.getElementById('scout-year-state')?.removeAttribute('aria-busy'));

    await Promise.all([
        waitForServerResponse(page, (response) =>
            response.url().endsWith(route) && response.request().method() === 'POST'
            && (response.request().headers()['content-type'] ?? '').includes('application/json')),
        trigger(),
    ]);
    await expect(page.locator('#scout-year-state')).toHaveAttribute('aria-busy', 'false');
}

/**
 * Mark (or unmark) a human step by clicking its disc.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} key
 */
async function toggleStep(page, key) {
    await act(page, '/admin/scout-year/step', () => disc(page, key).click());
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

/**
 * Turns configuration mode on or off through the page that owns the
 * toggle (same helper as specs/editable-rich-text.spec.js): idempotent,
 * stated as the state wanted.
 *
 * @param {import('@playwright/test').Page} page
 * @param {boolean} active
 */
async function setConfigurationMode(page, active) {
    await page.goto('/config/general', { waitUntil: 'domcontentloaded' });

    const card = page.locator('#main-content');
    const toTurnItOn = card.getByRole('button', { name: 'Activer', exact: true });
    const toTurnItOff = card.getByRole('button', { name: 'Désactiver', exact: true });
    const wanted = active ? toTurnItOn : toTurnItOff;

    if (await wanted.count() > 0) {
        await wanted.click();
    }

    await expect(active ? toTurnItOff : toTurnItOn).toBeVisible();
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

    // The step buttons that act at once (preview, leave it, activate for
    // the staff) ask first through the site's own confirmation dialog
    // (ScoutMagicConfirm.ask()). Playwright cannot see it as a dialog at all, so the
    // click resolves nothing unless something answers it — driving the
    // page as a human would. Installed before the first navigation,
    // because the observer is an init script.
    await autoConfirm(page, { native: false });

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
        'import',
        'fees',
        'preview',
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
    const staffYearCard = yearCard(page, 'Année du staff');

    // The year the workflow is about to move away from, and the one it
    // targets — both read from the page, never assumed.
    const outgoingYear = await yearOf(page, 'Année publique');
    const targetYear = await yearOf(page, 'Année cible');

    expect(targetYear, 'the page must target a different year than the current public one')
        .not.toBe(outgoingYear);

    // Every action from here on stays on the page: count the navigations
    // of the main frame while the page is driven through its own controls.
    let navigations = 0;
    page.on('framenavigated', (frame) => {
        if (frame === page.mainFrame()) {
            navigations++;
        }
    });

    // ---------------------------------------------------------------
    // The order is advice, not a gate. Nothing has been done yet, and
    // every control is already usable except the ones the server would
    // refuse anyway — the staff year and the switch, both waiting for a
    // successful import of the target year.
    // ---------------------------------------------------------------
    await expect(step(page, 'preview').getByRole('button', { name: `Prévisualiser ${targetYear}` })).toBeEnabled();
    await expect(step(page, 'departures').getByRole('link', { name: "Aller aux départs de l'unité" })).toBeVisible();

    // Hard block: no staff year without a successful import of it. The
    // page says so in words, the step's one action is the import that
    // unblocks it, and the activation waits, disabled, in its dialog.
    await expect(step(page, 'activate_staff')).toHaveAttribute('data-state', 'blocked');
    await expect(step(page, 'activate_staff')).toContainText(`Aucun import Desk réussi pour ${targetYear}.`);
    await expect(step(page, 'activate_staff').getByRole('link', { name: `Importer ${targetYear}` })).toBeVisible();
    await expect(details(page, 'activate_staff').getByRole('button', { name: 'Activer pour les staffs', includeHidden: true }))
        .toBeDisabled();

    // Hard block: the switch needs the staff year on the target, that
    // import, and no open registration — every reason said at once, and
    // the review's confirmation is disabled.
    await expect(step(page, 'activate_public')).toHaveAttribute('data-state', 'blocked');
    await expect(step(page, 'activate_public')).toContainText(`${targetYear} n'est pas activée pour les staffs`);
    await expect(page.locator('#scout-year-review').getByRole('button', { name: `Basculer sur ${targetYear}`, includeHidden: true }))
        .toBeDisabled();
    await expect(staffYearCard).toContainText('Pas encore activée');
    await expect(page.locator('[data-summary]')).toContainText('blocage');

    // The Desk-encoding date is a signpost: it labels the phases and says
    // which period the calendar is in. It disables nothing — the whole
    // reason it is a date and not a rule (specifications.md §16.4).
    await expect(phase(page, 'preparation')).toContainText('Idéalement avant le');
    await expect(phase(page, 'desk')).toContainText('Plutôt à partir du');
    await expect(page.getByText('elles ne déclenchent et ne bloquent jamais rien')).toBeVisible();

    // ---------------------------------------------------------------
    // Steps 4, 5 and 7: the work that happens somewhere the site cannot
    // see — in Desk, or in a staff meeting. Nothing observable marks them
    // done, so someone says so with the step's disc, and the site
    // remembers who and when.
    // ---------------------------------------------------------------
    await expect(disc(page, 'desk_staff')).toHaveAttribute('aria-pressed', 'false');
    await expect(disc(page, 'desk_staff')).toHaveAttribute('title', 'Cliquer pour marquer comme faite');

    await toggleStep(page, 'desk_staff');

    await expect(disc(page, 'desk_staff')).toHaveAttribute('aria-pressed', 'true');
    await expect(step(page, 'desk_staff')).toContainText('Étape faite');
    await expect(step(page, 'desk_staff').locator('.step-circle--done')).toBeVisible();
    await expect(details(page, 'desk_staff')).toContainText('Marquée comme faite le');
    await expect(page.locator('[data-summary-done]')).toContainText('1 étape faite sur 14');

    // It survives a reload — this is stored state, not something the
    // browser happens to be holding.
    await page.reload({ waitUntil: 'domcontentloaded' });
    navigations = 0;
    await expect(disc(page, 'desk_staff')).toHaveAttribute('aria-pressed', 'true');

    // And it can be taken back: a step marked by mistake is not a
    // one-way door.
    await toggleStep(page, 'desk_staff');
    await expect(disc(page, 'desk_staff')).toHaveAttribute('aria-pressed', 'false');
    await toggleStep(page, 'desk_staff');

    for (const key of ['desk_members', 'fees']) {
        await toggleStep(page, key);
        await expect(disc(page, key)).toHaveAttribute('aria-pressed', 'true');
    }

    // The three steps the site observes have no disc to press —
    // marking "activer pour tout le monde" by hand would be a plain lie.
    for (const key of ['import', 'activate_staff', 'activate_public']) {
        await expect(disc(page, key)).toHaveCount(0);
        await expect(step(page, key)).toHaveAttribute('data-nature', 'observed');
    }
    // Previewing, on the other hand, is a human step: the preview is not a
    // review, so whoever reviewed it says so.
    await expect(step(page, 'preview')).toHaveAttribute('data-nature', 'human');
    await expect(disc(page, 'preview')).toHaveCount(1);

    // ---------------------------------------------------------------
    // Step 8 — "Prévisualiser le site de <cible>". The preview only
    // concerns this session, so both halves are asserted: the visitor
    // does move, and the year everyone else sees does not. The button
    // asks first (the site's own dialog, answered by autoConfirm()).
    // ---------------------------------------------------------------
    await act(page, '/admin/scout-year/preview',
        () => step(page, 'preview').getByRole('button', { name: `Prévisualiser ${targetYear}` }).click());

    // base.html.twig's site-wide banner, swapped in with the state — the
    // preview is visible on every page, and says it is session-scoped.
    await expect(page.getByRole('alert').filter({ hasText: `Vous visualisez le site en ${targetYear} (session uniquement).` }))
        .toBeVisible();
    await expect(yearCard(page, 'Prévisualisation')).toContainText(targetYear);
    await expect(publicYearCard).toContainText(outgoingYear);
    // The preview marks nothing on its own…
    await expect(step(page, 'preview').locator('.step-circle--done')).toHaveCount(0);
    await expect(details(page, 'preview')).toContainText('Prévisualisation active dans votre session.');
    // …so the chief who reviewed it marks the step.
    await toggleStep(page, 'preview');
    await expect(step(page, 'preview').locator('.step-circle--done')).toBeVisible();

    expect(navigations, 'an action of the Année scoute page reloaded it').toBe(0);

    // ---------------------------------------------------------------
    // Step 6 — "Importer les données Desk pour <cible>". Followed through
    // the page's own link, and done with a real multipart upload of a real
    // Desk export: the step is complete only once import_journal holds a
    // successful import of the target year, which no amount of clicking
    // can fake.
    //
    // The import accepts the target year only from the opening date
    // (1 July by default) of the summer that ends the public year
    // (Core\Import\DeskImportYearPolicy). This scenario runs on whatever
    // day the suite runs, so it imports in configuration mode, which opens
    // every existing year behind a confirmation the site's own dialog asks
    // — answered by autoConfirm() — and turns the mode off again at once.
    // ---------------------------------------------------------------
    await setConfigurationMode(page, true);
    await page.goto('/admin/scout-year', { waitUntil: 'domcontentloaded' });
    await step(page, 'import').getByRole('link', { name: `Aller à l'import Desk (${targetYear})` }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Import Desk' })).toBeVisible();

    const importForm = page.locator('form[action="/admin/import"]');
    // The step's link carries the target year, and the page selects it.
    await expect(importForm.getByLabel('Année scoute cible').locator('option:checked')).toContainText(targetYear);
    await importForm.getByLabel('Fichier CSV exporté de Desk').setInputFiles({
        name: 'desk_export.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(deskExportCarryingTheUnitChief(), 'utf8'),
    });
    // exact: Chromium exposes <input type="file"> as a button too, so a
    // loose « Importer » could match more than the submit button.
    await importForm.getByRole('button', { name: 'Importer', exact: true }).click();

    await expect(page.getByText(/membres importés/)).toBeVisible();
    await setConfigurationMode(page, false);

    await page.goto('/admin/scout-year', { waitUntil: 'domcontentloaded' });
    navigations = 0;
    await expect(step(page, 'import').locator('.step-circle--done')).toBeVisible();
    await expect(details(page, 'import')).toContainText('Dernier import réussi :');
    await expect(step(page, 'import').getByRole('link', { name: 'Voir le rapport' })).toBeVisible();

    // Now that the year has sections, the photo step can measure itself —
    // and the missing photos are a point to check, never the step done.
    await expect(details(page, 'staff_photos')).toContainText('ont une photo de groupe de');
    await expect(step(page, 'staff_photos')).toContainText('à vérifier');
    await expect(step(page, 'staff_photos').locator('.step-circle--done')).toHaveCount(0);

    // The import is what unblocks the staff year: the step's one action
    // is the activation itself now.
    await expect(step(page, 'activate_staff')).toHaveAttribute('data-state', 'todo');
    const activateStaff = step(page, 'activate_staff').getByRole('button', { name: 'Activer pour les staffs' });
    await expect(activateStaff).toBeEnabled();

    // ---------------------------------------------------------------
    // Step 9 — "Activer <cible> pour les staffs". Asked first, then the
    // staff card follows; the year everyone else is served does not move.
    // ---------------------------------------------------------------
    await act(page, '/admin/scout-year/activate-staff', () => activateStaff.click());

    await expect(page.locator('.toast-body').filter({ hasText: `Année ${targetYear} activée pour le staff.` }))
        .toBeVisible();
    await expect(staffYearCard).toContainText(targetYear);
    await expect(staffYearCard).toContainText('Activée pour les staffs');
    await expect(step(page, 'activate_staff')).toHaveAttribute('data-state', 'done');
    // The half that matters most, and the one a unit test cannot see: the
    // year everyone else is served has still not moved.
    await expect(publicYearCard).toContainText(outgoingYear);

    // ---------------------------------------------------------------
    // Steps 10–13 — the site's own content for the new year. Human
    // decisions, all four: whatever the site counts (events, photos) is a
    // point to check, never the step done.
    // ---------------------------------------------------------------
    await expect(step(page, 'ephemerides').getByRole('link', { name: 'Aller au calendrier' })).toBeVisible();
    await expect(step(page, 'badges').getByRole('link', { name: 'Aller aux staffs et badges' })).toBeVisible();
    await expect(step(page, 'trombinoscope').getByRole('link', { name: 'Aller aux animateurs' })).toBeVisible();

    for (const key of ['ephemerides', 'badges', 'trombinoscope', 'staff_photos']) {
        await toggleStep(page, key);
        await expect(step(page, key).locator('.step-circle--done')).toBeVisible();
    }

    // ---------------------------------------------------------------
    // The staff year, seen without a preview shadowing it.
    //
    // ScoutYearResolver resolves preview → staff → public in that order,
    // so this session's own preview is still what it sees. Leaving it
    // through the preview step's own button is what reveals the staff
    // year underneath — and it also demonstrates, deliberately, that the
    // preview step is a human decision: with the preview gone, the mark
    // the chief made stays.
    // ---------------------------------------------------------------
    await act(page, '/admin/scout-year/clear-preview',
        () => step(page, 'preview').getByRole('button', { name: 'Quitter la prévisualisation' }).click());

    await expect(page.getByRole('alert').filter({ hasText: `Le staff voit actuellement l'année ${targetYear}.` }))
        .toBeVisible();
    await expect(yearCard(page, 'Prévisualisation')).toContainText('—');
    await expect(publicYearCard).toContainText(outgoingYear);
    await expect(step(page, 'preview').locator('.step-circle--done')).toBeVisible();
    await expect(details(page, 'preview')).toContainText('Prévisualisation inactive dans votre session.');

    // The preview never gated the final step either: what does — the
    // staff year on the target, its import, no open registration — is
    // all in place.
    await expect(step(page, 'activate_public')).toHaveAttribute('data-state', 'todo');

    // ---------------------------------------------------------------
    // Step 14 — "Activer <cible> pour tout le monde": the review first,
    // « {publique} → {cible} », then the switch. The whole site moves, and
    // the staff year is cleared automatically.
    // ---------------------------------------------------------------
    await page.waitForFunction(() => document.documentElement.classList.contains('scout-year-js'));
    await step(page, 'activate_public').getByRole('button', { name: 'Revoir avant la bascule…' }).click();
    const review = page.locator('#scout-year-review');
    await expect(review).toBeVisible();
    await expect(review.locator('[data-review-switch]')).toContainText(outgoingYear);
    await expect(review.locator('[data-review-switch]')).toContainText(targetYear);
    await expect(review.locator('[data-review-blockers]')).toHaveCount(0);
    const switchButton = review.getByRole('button', { name: `Basculer sur ${targetYear}` });
    await expect(switchButton).toBeEnabled();

    await act(page, '/admin/scout-year/activate-public', () => switchButton.click());

    await expect(page.locator('.toast-body').filter({ hasText: `Année ${targetYear} activée pour tout le monde.` }))
        .toBeVisible();
    await expect(publicYearCard).toContainText(targetYear);
    // "…et désactive l'année du staff": staff and public are aligned
    // again, so no staff year is active.
    await expect(staffYearCard).toContainText('Pas encore activée');
    await expect(page.locator('.modal-backdrop')).toHaveCount(0);

    // And the workflow has rolled forward: with the site now on what used
    // to be the target, the page describes the transition to the year
    // after it, from the top. That is the clearest single proof the
    // transition actually committed rather than merely flashing a message.
    await expect(page.getByText(`Prévisualiser le site de ${targetYear}`)).toHaveCount(0);
    expect(await yearOf(page, 'Année cible')).not.toBe(targetYear);
    // The page reads the switch back from the journal.
    await expect(page.locator('[data-last-switch]')).toContainText(`Dernière bascule : ${outgoingYear} → ${targetYear}`);

    // Every manual mark above belonged to the transition that just
    // finished. The next one starts blank on its own — no reset step, and
    // nothing for anyone to clear by hand.
    for (const key of ['desk_staff', 'desk_members', 'fees', 'preview', 'ephemerides', 'badges', 'trombinoscope', 'staff_photos']) {
        await expect(disc(page, key)).toHaveAttribute('aria-pressed', 'false');
    }

    expect(navigations, 'an action of the Année scoute page reloaded it').toBe(0);

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
