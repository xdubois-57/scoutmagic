// End-to-end: an article brought over from the unit's previous site is
// read-only (chantier « Migration de données », lot I; decision D11; the
// format is docs/formats/data-migration/news-history.md).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The lock is tested on the server, route by route, in
// ImportedArticleLockTest, and the import itself in
// NewsHistoryImporterOnMysqlTest. What neither crosses is what a person
// sees once the import is over: its page saying it cannot be changed, the
// manage list opening the article rather than its editor, and the editor's
// own address leading back to the article. The
// six steps of an import are walked in data-migration-photos.spec.js; this
// scenario goes through them straight, without an interruption.
//
// THE ARCHIVE
// ----------------------------------------------------------------------------
// tests/fixtures/data-migration/scoutmagic-news-history/exemple.zip, as an
// exporter would hand it over: three articles and one form, four items to
// import. « Inscriptions pour la nouvelle année » is public, so it is the
// one opened here.
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { expect, test } from '@playwright/test';

import { loginAsAdmin } from '../support/admin-login.js';
import { openModal } from '../support/modal.js';
import { scaled } from '../support/timeouts.js';

const ARCHIVE = path.join(
    path.dirname(fileURLToPath(import.meta.url)),
    '../../fixtures/data-migration/scoutmagic-news-history/exemple.zip',
);

const PAGE = '/config/migration-donnees/actualites';
const TITLE = 'Inscriptions pour la nouvelle année';

/**
 * Collects page errors and server errors for the closing assertions (as
 * desk-import-years.spec.js does): a slice answered 500 and then retried by
 * data-migration.js must still fail the scenario.
 *
 * @param {import('@playwright/test').Page} page
 */
function watchErrors(page) {
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

    return () => {
        expect(serverErrors, 'the application returned a server error').toEqual([]);
        expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
    };
}

test('an imported article is read-only, on its page and at its editor\'s address', async ({ page }) => {
    const assertNoErrors = watchErrors(page);
    await loginAsAdmin(page);

    await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Actualités et formulaires' })).toBeVisible();
    await page.getByLabel('Choisir le fichier ZIP').setInputFiles(ARCHIVE);
    await expect(page.getByText('exemple.zip est valable.')).toBeVisible({ timeout: scaled(30_000) });

    const dialog = await openModal(page, 'data-migration-confirm', () => page.getByRole('button', { name: 'Importer 4 actualités' }).click());
    await dialog.getByRole('button', { name: 'Importer', exact: true }).click();
    await expect(page.getByText(/^Import terminé le /)).toBeVisible({ timeout: scaled(30_000) });

    // The list: the article is there, marked.
    await page.goto('/news', { waitUntil: 'domcontentloaded' });
    const link = page.getByRole('link', { name: TITLE }).first();
    await expect(link).toBeVisible();
    const href = await link.getAttribute('href');
    expect(href).toMatch(/^\/news\/\d+/);
    const articlePath = (href ?? '').replace(/[?#].*$/, '');

    // Its page: the note.
    await page.goto(articlePath, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: TITLE })).toBeVisible();
    const note = page.locator('[data-news-imported]');
    await expect(note).toContainText('Importé');
    await expect(note).toContainText('Il ne peut pas être modifié.');

    // The manage list, where every other article's title opens its editor:
    // the imported one's opens the article itself (row.is_imported).
    await page.goto('/news/manage', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('link', { name: TITLE }).first()).toHaveAttribute('href', articlePath);

    // The editor's address, typed by hand, leads back to the article.
    await page.goto(`${articlePath}/gerer`, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(new RegExp(`${articlePath}$`));
    await expect(note).toBeVisible();

    assertNoErrors();
});
