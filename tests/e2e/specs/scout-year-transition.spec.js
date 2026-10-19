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
    // Wait for the page to REACT before clicking it.
    //
    // Ticking a step is not markup: public/assets/js/admin-scout-year.js
    // binds a `change` listener that submits the step's form. A click
    // landing before that listener exists toggles the box and submits
    // nothing, so the POST below never comes and this helper waits out its
    // ceiling on a request the page was never going to make. That is what
    // made this spec fail intermittently — « waitForResponse: Timeout
    // 10000ms exceeded » on one run, 71/71 on the next.
    //
    // Same marker convention as support/sos.js and support/confirm-dialog.js.
    await page.waitForFunction(
        () => document.documentElement.classList.contains('scout-year-js')
    );

    // The POST is what the round-trip hangs on, and the page it redirects
    // to is already the one we are on — so waiting on the URL would resolve
    // instantly and assert against the pre-submit DOM. Wait on the request
    // itself, then on the reload it causes.
    await Promise.all([
        waitForServerResponse(page, (response) =>
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

    // The last step guards itself with the site's own confirmation
    // dialog ("Activer cette année pour tout le monde ?", `data-confirm`
    // on the form). Playwright cannot see it as a dialog at all, so the
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
    // every control is already usable except the ones the server would
    // refuse anyway — the staff year and the switch, both waiting for a
    // successful import of the target year.
    // ---------------------------------------------------------------
    await expect(previewForm.getByRole('button', { name: 'Prévisualiser' })).toBeEnabled();
    await expect(step(page, 'departures').getByRole('link', { name: 'Voir les départs' })).toBeVisible();

    // Hard block: no staff year without a successful import of it. The
    // page says so in words as well as through the control, and the step's
    // own primary action is the import that unblocks it.
    await expect(step(page, 'activate_staff')).toHaveAttribute('data-state', 'blocked');
    await expect(staffForm.getByRole('button', { name: 'Activer pour le staff' })).toBeDisabled();
    await expect(step(page, 'activate_staff')).toContainText(`Aucun import Desk réussi pour ${targetYear}.`);
    await expect(step(page, 'activate_staff').getByRole('link', { name: `Importer ${targetYear}` })).toBeVisible();

    // Hard block: the switch needs the staff year on the target, that
    // import, and no open registration — every reason said at once.
    await expect(step(page, 'activate_public')).toHaveAttribute('data-state', 'blocked');
    await expect(publicForm.getByRole('button', { name: 'Activer pour tout le monde' })).toBeDisabled();
    await expect(step(page, 'activate_public')).toContainText(`${targetYear} n'est pas activée pour les staffs`);
    await expect(yearCard(page, 'Année du staff')).toHaveCount(0);

    // The Desk-encoding date is a signpost: it labels the phases and says
    // which period the calendar is in. It disables nothing — the whole
    // reason it is a date and not a rule (specifications.md §16.4).
    await expect(phase(page, 'preparation')).toContainText('Idéalement avant le');
    await expect(phase(page, 'desk')).toContainText('Plutôt à partir du');
    await expect(page.getByText('elles ne déclenchent et ne bloquent jamais rien')).toBeVisible();

    // ---------------------------------------------------------------
    // Steps 4, 5 and 7: the work that happens somewhere the site cannot
    // see — in Desk, or in a staff meeting. Nothing observable marks them
    // done, so someone says so, and the site remembers who and when.
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

    // The three steps the site observes have no checkbox at all —
    // ticking "activer pour tout le monde" by hand would be a plain lie.
    for (const key of ['import', 'activate_staff', 'activate_public']) {
        await expect(step(page, key).getByRole('checkbox')).toHaveCount(0);
        await expect(step(page, key)).toHaveAttribute('data-nature', 'observed');
    }
    // Previewing, on the other hand, is a human step: the preview is not a
    // review, so whoever reviewed it says so.
    await expect(step(page, 'preview')).toHaveAttribute('data-nature', 'human');
    await expect(step(page, 'preview').getByRole('checkbox')).toHaveCount(1);

    // ---------------------------------------------------------------
    // Step 8 — "Prévisualiser le site de <cible>". The preview only
    // concerns this session, so both halves are asserted: the visitor
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
    // The preview marks nothing on its own…
    await expect(step(page, 'preview').locator('.step-circle--done')).toHaveCount(0);
    await expect(step(page, 'preview')).toContainText('Prévisualisation active dans votre session.');
    // …so the chief who reviewed it marks the step.
    await toggleStep(page, 'preview');
    await expect(step(page, 'preview').locator('.step-circle--done')).toBeVisible();

    // ---------------------------------------------------------------
    // Step 6 — "Importer les données Desk pour <cible>". Followed through
    // the page's own link, and done with a real multipart upload of a real
    // Desk export: the step is complete only once import_journal holds a
    // successful import of the target year, which no amount of clicking
    // can fake.
    // ---------------------------------------------------------------
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
    await expect(step(page, 'import').locator('.step-circle--done')).toBeVisible();
    await expect(step(page, 'import')).toContainText('Dernier import réussi :');
    await expect(step(page, 'import').getByRole('link', { name: 'Voir le rapport' })).toBeVisible();

    // Now that the year has sections, the photo step can measure itself —
    // and the missing photos are a point to check, never the step done.
    await expect(step(page, 'staff_photos')).toContainText('ont une photo de groupe de');
    await expect(step(page, 'staff_photos')).toContainText('à vérifier');
    await expect(step(page, 'staff_photos').locator('.step-circle--done')).toHaveCount(0);

    // The import is what unblocks the staff year.
    await expect(step(page, 'activate_staff')).toHaveAttribute('data-state', 'todo');
    await expect(staffForm.getByRole('button', { name: 'Activer pour le staff' })).toBeEnabled();

    // ---------------------------------------------------------------
    // Step 9 — "Activer <cible> pour les staffs". The page's own
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
    // Steps 10–13 — the site's own content for the new year. Human
    // decisions, all four: whatever the site counts (events, photos) is a
    // point to check, never the step done.
    // ---------------------------------------------------------------
    await expect(step(page, 'ephemerides').getByRole('link', { name: 'Ouvrir le calendrier' })).toBeVisible();
    await expect(step(page, 'badges').getByRole('link', { name: 'Ouvrir Staffs' })).toBeVisible();
    await expect(step(page, 'trombinoscope').getByRole('link', { name: 'Aller aux animateurs' })).toBeVisible();

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
    // deliberately, that the preview step is a human decision: with the
    // preview gone, the mark the chief made stays.
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
    await expect(step(page, 'preview').locator('.step-circle--done')).toBeVisible();
    await expect(step(page, 'preview')).toContainText('Prévisualisation inactive dans votre session.');

    // The preview never gated the final step either: what does — the
    // staff year on the target, its import, no open registration — is
    // all in place.
    await expect(step(page, 'activate_public')).toHaveAttribute('data-state', 'todo');
    await expect(publicForm.getByRole('button', { name: 'Activer pour tout le monde' })).toBeEnabled();

    // ---------------------------------------------------------------
    // Step 14 — "Activer <cible> pour tout le monde": the whole site
    // moves, "de façon permanente", and the staff year is cleared
    // automatically.
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
    await expect(page.getByText(`Prévisualiser le site de ${targetYear}`)).toHaveCount(0);
    await expect(publicForm.getByRole('button', { name: 'Activer pour tout le monde' })).toBeDisabled();
    // The page reads the switch back from the journal.
    await expect(page.locator('[data-last-switch]')).toContainText(`Dernière bascule : ${outgoingYear} → ${targetYear}`);

    // Every manual tick above belonged to the transition that just
    // finished. The next one starts blank on its own — no reset step, and
    // nothing for anyone to clear by hand.
    for (const key of ['desk_staff', 'desk_members', 'fees', 'preview', 'ephemerides', 'badges', 'trombinoscope', 'staff_photos']) {
        await expect(step(page, key).getByRole('checkbox')).not.toBeChecked();
    }

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
