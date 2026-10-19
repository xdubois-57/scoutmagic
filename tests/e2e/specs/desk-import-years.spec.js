// End-to-end: which scout years a Desk import may go into
// (Core\Import\DeskImportYearPolicy, specifications.md §7), driven through
// the real Import Desk page, the real Réglages page and real uploads.
//
// What PHPUnit cannot see and this does: the page and the server agreeing
// on one rule — the select offers what the POST accepts, the confirmation
// dialog of admin-import.js is what fills `confirmed_year`, and a request
// that skips the page (a year the select never offered, a submit that
// never asked) is refused by the server all the same.
//
// ============================================================================
// NOT DEPENDING ON TODAY'S DATE
// ----------------------------------------------------------------------------
// The rule places its two MM-JJ dates in the calendar year the PUBLIC year
// ends in, and compares today with them. An E2E run happens on whatever
// day it happens, so the first scenario never reads the calendar: it puts
// the public year one year back (support/public-year.js — no page can move
// the public year backwards), which puts both dates in the past on every
// day of the year. The period is then « from the Desk-encoding date »
// whatever the date: only the year after the public one is importable.
// Then configuration mode, which opens every year behind the dialog.
//
// The transition window itself (« Du … au … », both years, the public one
// asked first) exists only between 1 January and 30 August of the year the
// public year ends in: the latest dates the settings accept are 01-01 and
// 08-31, and nothing before 1 September can be placed in a later year.
// From 1 September to 31 December no setting and no public year makes it
// today — only a server clock would, and the application has none to
// inject. The second scenario therefore drives the window through the
// settings when it can exist, and says it is skipped otherwise; every
// boundary of the window is covered day by day in
// tests/Core/Import/DeskImportYearPolicyTest.php.
// ============================================================================
//
// Every scenario puts back what it moved (public year, the two dates,
// configuration mode) in a `finally`, so the scenarios after it — the
// scout-year transition first — start from the harness's own state.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { expect, test } from '@playwright/test';

import { loginAsAdmin } from '../support/admin-login.js';
import { answerConfirmation } from '../support/confirm-dialog.js';
import { openCollapse } from '../support/collapse.js';
import { openModal } from '../support/modal.js';
import { previousYearLabel, setPublicYear } from '../support/public-year.js';
import { waitForServerResponse } from '../support/response.js';

const DESK_FIXTURE = path.join(
    path.dirname(fileURLToPath(import.meta.url)),
    // The same Desk export tests/Core/Import/ uses — never a second copy.
    '../../fixtures/desk_export_sample.csv',
);

const NEXT_START = 'scout_year_import_next_start_date';
const ENCODING = 'scout_year_desk_encoding_date';
const DEFAULTS = { [NEXT_START]: '07-01', [ENCODING]: '08-15' };

/**
 * Saves one setting through the Réglages page as a person does: unfold
 * its group, open its row, type, « Enregistrer ». Resolves with the
 * page's own answer; on success the page reloads, and this waits for it.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} key
 * @param {string} value
 * @returns {Promise<{success: boolean, error?: string}>}
 */
async function saveSetting(page, key, value) {
    await page.goto('/config/settings', { waitUntil: 'load' });
    const row = page.locator(`.setting-row[data-key="${key}"]`);
    // Each module group folds into `settings-group-{its data-module}`
    // (config/settings.html.twig).
    const groupId = `settings-group-${await row.getAttribute('data-module')}`;
    await openCollapse(page, groupId, () => page.locator(`[aria-controls="${groupId}"]`).click());

    const modal = await openModal(page, 'settingEditModal', () => row.click());
    await modal.locator('#settingEditInputContainer input').fill(value);

    // settings.js reloads the page on success and shows the refusal in the
    // dialog otherwise — the body of a response the page has navigated
    // away from can no longer be read, so the outcome is read off the page.
    const error = modal.locator('#settingEditError');
    const reloaded = page.waitForEvent('load').then(() => true, () => false);
    const refused = error.waitFor({ state: 'visible' }).then(() => false, () => true);
    await modal.locator('#settingEditSave').click();
    const success = await Promise.race([reloaded, refused]);

    return success ? { success } : { success, error: (await error.innerText()).trim() };
}

/**
 * Both dates, in an order every step of which is a coherent pair (the
 * page refuses an opening on or after the encoding date, checked against
 * the value stored for the other one).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} nextStart
 * @param {string} encoding
 */
async function saveDates(page, nextStart, encoding) {
    const current = await currentDates(page);
    const order = nextStart < current[ENCODING]
        ? [[NEXT_START, nextStart], [ENCODING, encoding]]
        : [[ENCODING, encoding], [NEXT_START, nextStart]];
    for (const [key, value] of order) {
        if (current[key] === value) {
            continue;
        }
        const answer = await saveSetting(page, key, value);
        expect(answer.success, `saving ${key}=${value}: ${answer.error ?? ''}`).toBe(true);
    }
}

/**
 * The two dates as stored, read off the Réglages rows.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<Record<string, string>>}
 */
async function currentDates(page) {
    await page.goto('/config/settings', { waitUntil: 'domcontentloaded' });
    const read = async (key) => page.locator(`.setting-row[data-key="${key}"]`).getAttribute('data-value');

    return { [NEXT_START]: (await read(NEXT_START)) ?? '', [ENCODING]: (await read(ENCODING)) ?? '' };
}

/**
 * Same helper as specs/scout-year-transition.spec.js: idempotent, stated
 * as the state wanted.
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

/**
 * The Import Desk page, once admin-import.js is wired: before that mark,
 * « Importer » submits natively and asks nothing.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openImportPage(page) {
    await page.goto('/admin/import', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Import Desk' })).toBeVisible();
    const form = page.locator('#desk-import-form');
    await expect(form).toHaveAttribute('data-year-confirm-ready', '1');

    return form;
}

/**
 * The public year the import page names (« L'année publique est … »).
 *
 * @param {import('@playwright/test').Page} page
 */
async function publicYearOnImportPage(page) {
    const card = page.locator('.card').filter({
        has: page.getByRole('heading', { level: 2, name: "Années importables aujourd'hui" }),
    });

    return (await card.locator('strong').first().innerText()).trim();
}

/**
 * @param {import('@playwright/test').Locator} form
 */
async function chooseFile(form) {
    await form.getByLabel('Fichier CSV exporté de Desk').setInputFiles({
        name: 'desk_export.csv',
        mimeType: 'text/csv',
        buffer: fs.readFileSync(DESK_FIXTURE),
    });
}

/**
 * The labels of the years the select offers, as the page writes them.
 *
 * @param {import('@playwright/test').Locator} form
 */
async function offeredYears(form) {
    return form.getByLabel('Année scoute cible').locator('option').allInnerTexts();
}

/**
 * The period the page marks as today's (aria-current="date"), or null.
 *
 * @param {import('@playwright/test').Page} page
 */
async function periodOfToday(page) {
    const current = page.locator('[data-import-period][aria-current="date"]');

    return (await current.count()) === 0 ? null : current.getAttribute('data-import-period');
}

/**
 * Submits the form WITHOUT its submit event — HTMLFormElement.submit()
 * fires none — so admin-import.js never asks and `confirmed_year` stays
 * empty: what a replayed or hand-made POST looks like to the server.
 *
 * @param {import('@playwright/test').Page} page
 */
async function submitBehindThePagesBack(page) {
    await Promise.all([
        waitForServerResponse(page, (r) => r.url().endsWith('/admin/import') && r.request().method() === 'POST'),
        page.evaluate(() => (/** @type {HTMLFormElement} */ (document.getElementById('desk-import-form'))).submit()),
    ]);
    await page.waitForLoadState('domcontentloaded');
}

/**
 * Collects page errors and server errors for the closing assertions.
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

test('a Desk import goes only into the years the rule opens, and the server holds the rule', async ({ page }) => {
    const assertNoErrors = watchErrors(page);
    await loginAsAdmin(page);

    await openImportPage(page);
    const pinnedYear = await publicYearOnImportPage(page);
    const previousYear = previousYearLabel(pinnedYear);

    try {
        // -------------------------------------------------------------
        // The two dates belong to Réglages, and are refused there as a
        // pair: an encoding date before the opening names both values.
        // -------------------------------------------------------------
        await saveDates(page, DEFAULTS[NEXT_START], DEFAULTS[ENCODING]);
        const refused = await saveSetting(page, ENCODING, '06-01');
        expect(refused.success).toBe(false);
        await expect(page.locator('#settingEditError')).toHaveText(
            "L'ouverture de l'import pour l'année suivante (07-01) doit précéder la date d'encodage Desk (06-01).",
        );

        await saveDates(page, '06-20', '08-10');

        // -------------------------------------------------------------
        // The public year one year back: both dates of the rule lie in
        // the past, whatever today is, so the period is « from the
        // encoding date » — and the page shows the rule with the dates
        // just saved.
        // -------------------------------------------------------------
        const previousYearId = await setPublicYear(previousYear);

        let form = await openImportPage(page);
        expect(await publicYearOnImportPage(page)).toBe(previousYear);
        await expect(page.locator('[data-import-period="before"]')).toContainText('Avant le 20/06');
        await expect(page.locator('[data-import-period="between"]')).toContainText('Du 20/06 au 09/08');
        await expect(page.locator('[data-import-period="after"]')).toContainText('À partir du 10/08');
        expect(await periodOfToday(page)).toBe('after');

        // Only the next year — the public year itself is closed.
        expect(await offeredYears(form)).toEqual([`${pinnedYear} — année suivante`]);
        await expect(form).toContainText("Une seule année est importable aujourd'hui.");
        await expect(page.locator('#desk-import-last')).toContainText(`Dernier import de ${pinnedYear}`);

        // -------------------------------------------------------------
        // Refused by the server: the public year, which the select never
        // offered, posted all the same (an option added to the form —
        // the shape of a forged POST, CSRF token and file included).
        // -------------------------------------------------------------
        await form.getByLabel('Année scoute cible').evaluate((select, year) => {
            const option = document.createElement('option');
            option.value = String(year.id);
            option.textContent = year.label;
            select.appendChild(option);
        }, { id: previousYearId, label: previousYear });
        await form.getByLabel('Année scoute cible').selectOption(String(previousYearId));
        await chooseFile(form);
        await Promise.all([
            waitForServerResponse(page, (r) => r.url().endsWith('/admin/import') && r.request().method() === 'POST'),
            form.getByRole('button', { name: 'Importer', exact: true }).click(),
        ]);

        await expect(page.getByText(
            `L'année ${previousYear} ne peut pas recevoir d'import Desk aujourd'hui, rien n'a été importé. `
            + `Seule l'année ${pinnedYear} est importable aujourd'hui.`,
        )).toBeVisible();
        await expect(page.getByText(/membres importés/)).toHaveCount(0);

        // -------------------------------------------------------------
        // Configuration mode opens every existing year — read on the
        // server, never sent by the browser — and a year outside the
        // usual rule asks first.
        // -------------------------------------------------------------
        await setConfigurationMode(page, true);
        form = await openImportPage(page);
        await expect(page.getByText('Mode configuration actif : toutes les années existantes sont importables.'))
            .toBeVisible();
        expect(await periodOfToday(page), 'configuration mode marks no period as today').toBeNull();
        expect(await offeredYears(form)).toEqual(expect.arrayContaining([
            `${pinnedYear} — année suivante`,
            `${previousYear} — année publique`,
        ]));

        // The « Dernier import » block follows the select, without a request.
        await form.getByLabel('Année scoute cible').selectOption(String(previousYearId));
        await expect(page.locator('#desk-import-last')).toContainText(`Dernier import de ${previousYear}`);
        await expect(page.locator('#desk-import-last')).toContainText(`Aucun import pour ${previousYear}.`);

        // Asked, and declined: nothing is posted, the file stays chosen.
        /** @type {string[]} */
        const posts = [];
        const recordPost = (request) => {
            if (request.method() === 'POST' && request.url().endsWith('/admin/import')) {
                posts.push(request.url());
            }
        };
        page.on('request', recordPost);
        await chooseFile(form);
        await form.getByRole('button', { name: 'Importer', exact: true }).click();
        const question = await answerConfirmation(page, { accept: false });
        expect(question).toBe(
            `${previousYear} n'est importable qu'en mode configuration. Le fichier remplacera les membres de `
            + `${previousYear}.`,
        );
        expect(posts, 'declining the dialog posts nothing').toEqual([]);
        page.off('request', recordPost);
        expect(await form.getByLabel('Fichier CSV exporté de Desk').evaluate(
            (input) => (/** @type {HTMLInputElement} */ (input)).files?.length ?? 0,
        )).toBe(1);
        await expect(form.locator('#desk-import-confirmed-year')).toHaveValue('');

        // The same form submitted without its dialog — no confirmed_year:
        // the server asks for the confirmation itself.
        await submitBehindThePagesBack(page);
        await expect(page.getByText(`L'import dans ${previousYear} demande une confirmation, rien n'a été importé.`))
            .toBeVisible();

        // Asked, and confirmed: the form leaves WITH `confirmed_year` equal
        // to the year. The request is caught at the browser and answered
        // there, never imported: an import replaces a year's roster, and
        // the sections it leaves without members drop out of every
        // selector for the scenarios after this one. That the server
        // accepts a confirmed out-of-rule year is what
        // scout-year-transition.spec.js does for real, in this same mode.
        /** @type {string[]} */
        const confirmedPosts = [];
        await page.route('**/admin/import', async (route) => {
            if (route.request().method() !== 'POST') {
                await route.continue();
                return;
            }
            confirmedPosts.push(route.request().postDataBuffer()?.toString('latin1') ?? '');
            await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>intercepté</title>' });
        });
        form = await openImportPage(page);
        await form.getByLabel('Année scoute cible').selectOption(String(previousYearId));
        await chooseFile(form);
        await form.getByRole('button', { name: 'Importer', exact: true }).click();
        await answerConfirmation(page, { accept: true });
        await expect.poll(() => confirmedPosts.length).toBe(1);
        await page.unroute('**/admin/import');

        const field = (name) => new RegExp(`name="${name}"\\r\\n\\r\\n([^\\r]*)\\r\\n`).exec(confirmedPosts[0])?.[1];
        expect(field('scout_year_id')).toBe(String(previousYearId));
        expect(field('confirmed_year'), 'the dialog fills confirmed_year with the year').toBe(String(previousYearId));
    } finally {
        await setConfigurationMode(page, false);
        await setPublicYear(pinnedYear);
        await saveDates(page, DEFAULTS[NEXT_START], DEFAULTS[ENCODING]);
    }

    assertNoErrors();
});

test('in the transition window the public year is asked for first, and the server demands the answer', async ({ page }) => {
    const assertNoErrors = watchErrors(page);
    await loginAsAdmin(page);

    await openImportPage(page);
    const publicYear = await publicYearOnImportPage(page);
    const nextYear = `${Number.parseInt(publicYear.slice(5), 10)}-${Number.parseInt(publicYear.slice(5), 10) + 1}`;
    const endsIn = publicYear.slice(5);
    // Today on the application clock (Europe/Brussels), as 'YYYY-MM-DD'.
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Brussels' }).format(new Date());
    test.skip(
        !(today >= `${endsIn}-01-01` && today < `${endsIn}-08-31`),
        `the transition window of ${publicYear} cannot include ${today}: the latest dates the settings accept `
        + `are 01-01 and 08-31 of ${endsIn} (see this file's header)`,
    );

    try {
        // The widest window the settings accept: today is inside it.
        await saveDates(page, '01-01', '08-31');

        const form = await openImportPage(page);
        expect(await periodOfToday(page)).toBe('between');
        expect(await offeredYears(form)).toEqual([
            `${nextYear} — année suivante`,
            `${publicYear} — année publique`,
        ]);
        // The next year is the one proposed.
        await expect(form.getByLabel('Année scoute cible').locator('option:checked')).toContainText(nextYear);

        // The public year is the one the families see: asked first.
        // Declined here — this scenario never replaces the fixture's roster.
        await form.getByLabel('Année scoute cible').selectOption({ label: `${publicYear} — année publique` });
        await chooseFile(form);
        await form.getByRole('button', { name: 'Importer', exact: true }).click();
        const question = await answerConfirmation(page, { accept: false });
        expect(question).toBe(
            `L'année suivante ${nextYear} est déjà importable. Ce fichier modifiera les données de ${publicYear}, `
            + "l'année que voient les familles aujourd'hui.",
        );

        // Posted without the answer: refused, nothing imported.
        await submitBehindThePagesBack(page);
        await expect(page.getByText(`L'import dans ${publicYear} demande une confirmation, rien n'a été importé.`))
            .toBeVisible();
        await expect(page.getByText(/membres importés/)).toHaveCount(0);
    } finally {
        await saveDates(page, DEFAULTS[NEXT_START], DEFAULTS[ENCODING]);
    }

    assertNoErrors();
});
