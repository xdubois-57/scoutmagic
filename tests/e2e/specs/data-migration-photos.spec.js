// End-to-end: the data migration module's shared six-step page, driven
// through one import from the deposit to the re-run — the member photos
// (chantier « Migration de données », lot I; the format is
// docs/formats/data-migration/member-photos.md).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Every step of an import is covered by PHPUnit on its own: the engine's
// slices and the run's states in RunServiceOnMysqlTest, the photos importer
// against the very archive used here in MemberPhotosImportOnMysqlTest. What
// none of them crosses is the chain a person walks: the ZIP going up in
// fragments through chunked-upload.js, the page polling `…/avancer` until
// the server draws the next step, the past scout years ticked in the
// confirmation dialog (D5), a pause, the home page offering to resume, the
// report, and a re-run of the same file that writes nothing twice (D12).
// One scenario rather than one per step (AGENTS.md § Tests: the E2E suite
// is a release gate, not a coverage tool).
//
// THE ARCHIVE
// ----------------------------------------------------------------------------
// tests/fixtures/data-migration/scoutmagic-member-photos/exemple.zip, as an
// exporter would hand it over — never a copy. Four lines for the two
// members every instance has (`E2E-ADMIN`, `E2E-MEMBER`, seeded by
// scripts/e2e-support.php), on 2022-2023 and 2023-2024: three photos are
// imported, the fourth is a second photo for a member and year an earlier
// line already gives one, and is ignored. Neither year exists on a fresh
// instance (it only has the current one, and desk-import-years.spec.js,
// which runs later, only ever adds the previous one), so the confirmation
// offers both, ticked.
//
// THE INTERRUPTION
// ----------------------------------------------------------------------------
// Four photos fit in one slice of about five seconds, so a pause clicked
// while the page polls would race the slice that finishes the run. The
// scenario therefore holds the page's `…/avancer` requests back (they fail
// as a network failure would, which the page answers by trying again) from
// the confirmation until the pause is recorded: the run is interrupted
// before any slice ran, deterministically. The resume then runs with the
// network back, as a person who reopens the page does.
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { expect, test } from '@playwright/test';

import { loginAsAdmin } from '../support/admin-login.js';
import { openModal } from '../support/modal.js';
import { toggleModule } from '../support/modules.js';
import { scaled } from '../support/timeouts.js';

const ARCHIVE = path.join(
    path.dirname(fileURLToPath(import.meta.url)),
    '../../fixtures/data-migration/scoutmagic-member-photos/exemple.zip',
);

const HOME = '/admin/migration-donnees';
const PAGE = `${HOME}/photos`;
const ADVANCE = `**${PAGE}/avancer`;

/**
 * The figure of one stat tile, found by its exact label: « Ignorés » on
 * the preview and « Ignorés : données manquantes ou non reconnues » on the
 * report are two different tiles.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
function tile(page, label) {
    return page.locator('.stat-tiles .card-body')
        .filter({ has: page.getByText(label, { exact: true }) })
        .locator('.fs-3');
}

test('a photo archive is imported, interrupted, resumed, and re-run without a duplicate', async ({ page }) => {
    await loginAsAdmin(page);

    // « Activer le module »: the instance ships with every module on
    // (all-modules-enabled.spec.js), so it is switched off and on again
    // through the real Modules page, which is what an administrator does.
    await toggleModule(page, 'Migration de données', false);
    await toggleModule(page, 'Migration de données', true);

    await page.goto(HOME, { waitUntil: 'domcontentloaded' });
    const row = page.getByRole('listitem').filter({ has: page.getByRole('heading', { name: 'Photos des membres' }) });
    await row.getByRole('link', { name: 'Ouvrir' }).click();
    await page.waitForURL(`**${PAGE}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Photos des membres' })).toBeVisible();

    // Step 1: the deposit. data-migration.js reloads the page once the
    // last fragment is in; the analysis (step 2) then polls until the
    // server draws the preview.
    await page.getByLabel('Choisir le fichier ZIP').setInputFiles(ARCHIVE);
    await expect(page.getByText('exemple.zip est valable.')).toBeVisible({ timeout: scaled(30_000) });

    // Step 3: what the import will do.
    await expect(tile(page, 'À importer')).toHaveText('3');
    await expect(tile(page, 'Déjà présents')).toHaveText('0');
    await expect(tile(page, 'Ignorés')).toHaveText('1');
    const olderYear = page.getByRole('checkbox', { name: /^2022-2023/ });
    const laterYear = page.getByRole('checkbox', { name: /^2023-2024/ });
    await expect(olderYear).toBeChecked();
    await expect(laterYear).toBeChecked();

    // Step 4, with the slices held back from here on (see the header).
    await page.route(ADVANCE, (route) => route.abort('failed'));
    const dialog = await openModal(page, 'data-migration-confirm', () => page.getByRole('button', { name: 'Importer 3 photos' }).click());
    await dialog.getByRole('button', { name: 'Importer', exact: true }).click();

    // Step 5: running, nothing written yet — then paused.
    await expect(page.getByText('Import en cours')).toBeVisible();
    await expect(page.locator('#data-migration-count-imported')).toHaveText('0');
    await page.getByRole('button', { name: 'Mettre en pause' }).click();
    await expect(page.getByText('Import interrompu à 0 %.')).toBeVisible();

    // The home page says so, and offers to resume.
    await page.goto(HOME, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('L\'import « Photos des membres » a été interrompu à 0 %.')).toBeVisible();
    await page.unroute(ADVANCE);
    await row.getByRole('link', { name: 'Reprendre' }).click();
    await page.waitForURL(`**${PAGE}`, { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Reprendre l\'import' }).click();

    // Step 6: the report, once the slices have run.
    await expect(page.getByText(/^Import terminé le /)).toBeVisible({ timeout: scaled(30_000) });
    await expect(tile(page, 'Importés')).toHaveText('3');
    await expect(tile(page, 'Déjà présents')).toHaveText('0');
    await expect(tile(page, 'Ignorés : données manquantes ou non reconnues')).toHaveText('1');
    await expect(tile(page, 'Erreurs techniques')).toHaveText('0');
    // D8: the old site's reference and the reason, never a name.
    await page.getByText('1 ignorés', { exact: true }).click();
    await expect(page.getByRole('cell', { name: 'desk E2E-MEMBER · 2023-2024' })).toBeVisible();
    await expect(
        page.getByRole('cell', { name: 'Une photo précédente du fichier vise déjà ce membre et cette année' }).first(),
    ).toBeVisible();

    // The re-run of the same file: analysed again, and nothing left to write.
    await page.getByRole('button', { name: 'Relancer le même fichier' }).click();
    await expect(page.getByText('exemple.zip est valable.')).toBeVisible({ timeout: scaled(30_000) });
    await expect(tile(page, 'À importer')).toHaveText('0');
    await expect(tile(page, 'Déjà présents')).toHaveText('3');
    await expect(tile(page, 'Ignorés')).toHaveText('1');
    // The two years exist now: nothing left to tick.
    await expect(olderYear).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Importer 0 photos' })).toBeDisabled();
});
