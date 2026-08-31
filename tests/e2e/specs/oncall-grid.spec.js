// End-to-end: the SOS on-call duty grid — a three-state cell cycle whose
// every click re-posts the whole month.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The grid's state lives in a JS object seeded from Twig (the
// `sos-admin-data` island), mutated in place on every click, and saved by
// serialising the ENTIRE month back to /admin/sos/oncall — there is no
// per-cell endpoint and no save button. A serialization drift, a CSP
// regression or a listener mishap silently turns the roster read-only,
// while PHPUnit — posting its own arrays — stays green. The on-call
// roster is what the unit's public emergency number redirects to, so
// "the grid still saves" is worth its own scenario.
//
// This first scenario is the DESKTOP grid and nothing else. The phone
// layout is no longer a second rendering of the same cells — it is a list
// of days plus one edit sheet, at a viewport where the grid does not
// exist at all — so it has a scenario of its own further down.
//
// WHAT IT DELIBERATELY LEAVES ALONE
// ----------------------------------------------------------------------------
// /config/sos (the OVH credential wizard) — every step of it calls the
// real OVH API, which a hermetic suite cannot depend on. The redirect
// application task is Tests\Modules\SosStaff territory.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';

/**
 * Puts the roster back the way the harness provisioned it — empty.
 *
 * Every scenario here writes to the SAME shared instance, and each one
 * already clears up after itself on the happy path. This exists for the
 * unhappy one: an assertion that stops a scenario half-way leaves a duty
 * behind, and a duty is not local to this page — it decides who the SOS
 * number rings, it schedules redirect transitions, and the calendar
 * renders it as a virtual event for every later spec to trip over.
 *
 * It reuses the page's own endpoint rather than reaching into the
 * database: /admin/sos/oncall replaces a whole month in one call (module
 * spec §2.6), so posting an empty month IS the reset. Going through
 * `window.ScoutMagicApi` means the real CSRF token and the real session,
 * with no second copy of either.
 *
 * Silent when there is nothing to clean: a scenario that failed before
 * signing in lands on /login, where the island does not exist.
 *
 * @param {import('@playwright/test').Page} page
 */
async function resetOnCallRoster(page) {
    // Wrapped whole: clean-up must never be what turns a passing scenario
    // red, nor add a second, confusing failure on top of a real one. A
    // closed context, a session that expired, a page that never loaded —
    // all of them mean "nothing to restore", not "something went wrong".
    try {
        await page.goto('/admin/sos', { waitUntil: 'domcontentloaded' });

        // The two months these scenarios can have written to: the one the
        // page opens on, and the next one. Read off the island rather than
        // computed from this process's clock, which need not agree with
        // the server's date.
        const months = await page.evaluate(() => {
            const island = window.ScoutMagicApi && window.ScoutMagicApi.pageData('sos-admin-data');
            if (!island) {
                return null;
            }
            return [
                { year: island.year, month: island.month },
                island.month === 12
                    ? { year: island.year + 1, month: 1 }
                    : { year: island.year, month: island.month + 1 },
            ];
        });
        if (months === null) {
            return;
        }

        for (const month of months) {
            await page.evaluate(
                (target) => window.ScoutMagicApi.postJson('/admin/sos/oncall', {
                    year: target.year,
                    month: target.month,
                    cells: [],
                }),
                month,
            );
        }
    } catch {
        // Deliberately swallowed — see above.
    }
}

test.afterEach(async ({ page }) => {
    await resetOnCallRoster(page);
});

test('the duty grid cycles a cell through its three states, saving the month on every click', async ({ page }) => {
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

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto('/admin/sos', { waitUntil: 'load' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // The desktop table renders one clickable cell per staff member per
    // day; the harness's unit chief (Baden Powell) is its one column.
    // A mid-month day keeps the assertion independent of month edges.
    const cell = page.locator('td.sos-oncall-cell[data-date$="-15"]').first();
    await expect(cell).toBeVisible();
    const cellDate = await cell.getAttribute('data-date');
    const cellMember = await cell.getAttribute('data-member-id');
    /** The same cell, re-located after a reload. */
    const cellAgain = () => page.locator(`td.sos-oncall-cell[data-date="${cellDate}"][data-member-id="${cellMember}"]`);

    const saveStatus = page.locator('#oncall-save-status');

    /** Click the cell once and wait for the full-month save to answer. */
    async function clickAndSave() {
        const saved = page.waitForResponse((response) => response.url().includes('/admin/sos/oncall'));
        await cellAgain().click();
        expect((await saved).ok()).toBe(true);
        await expect(saveStatus).toHaveText('Enregistré.');
    }

    // ---------------------------------------------------------------
    // First click: on call. The reload proves the month really landed.
    // ---------------------------------------------------------------
    await expect(cellAgain()).toHaveText('');
    await clickAndSave();
    await expect(cellAgain()).toHaveText('✓');

    await page.reload({ waitUntil: 'load' });
    await expect(cellAgain(), 'the on-call mark must survive a reload').toHaveText('✓');

    // There used to be an assertion here that the phone rendering of this
    // same cell had moved with it (`#sos-mobile-grid .sos-oncall-cell`,
    // one class shared by a <td> and a <button>). That markup is gone: the
    // phone layout now lists days and edits them through a sheet, and for
    // the CURRENT month it starts at today — so this mid-month day has no
    // phone row at all after the 15th. Keeping the two in step is the next
    // scenario's job, at the viewport where the phone layout exists.

    // ---------------------------------------------------------------
    // Second click: unavailable. Third: back to blank — which also
    // leaves the shared instance's roster exactly as provisioned.
    // ---------------------------------------------------------------
    await clickAndSave();
    await expect(cellAgain()).toHaveText('✗');

    await clickAndSave();
    await expect(cellAgain()).toHaveText('');

    await page.reload({ waitUntil: 'load' });
    await expect(cellAgain(), 'the cleared cell must survive a reload too').toHaveText('');

    // The planned-transitions block rendered (its pagination is AJAX-fed
    // when there is more than a page of them; an empty roster just says
    // so).
    await expect(page.locator('#planned-transitions-list')).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});

// ============================================================================
// The phone layout — a list of days, and ONE edit sheet for the whole month.
//
// WHY THIS SECOND SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The phone layout used to render every Staff d'U member inside every day of
// the month: roughly 250 stacked buttons, first names cut to one word at
// 0.65rem, and a ✓ / ✗ / — code with no legend anywhere. The members moved
// into a single sheet the day rows open, which means the two things this
// screen now promises are both invisible to the other two test stacks:
//
//  - the sheet is filled from the row that was tapped and stamped with that
//    day, so its three named buttons write the right member/day pair. Vitest
//    sees the wiring in jsdom, never against a real month rendered by Twig;
//  - a day row names the person who ACTUALLY receives the calls — the first
//    roster member marked on call (module spec §2.6). The server resolves it
//    on render, the browser re-resolves it after an edit, and only a real
//    round trip can show the two agreeing.
//
// It also needs a real viewport: the day list is `d-md-none` and the desktop
// grid is `d-none d-md-block`, so at desktop width the whole layout under
// test does not exist. Phone-sized emulation is the same approach
// specs/groups-discussion.spec.js and specs/breadcrumb-separator.spec.js take.
// ============================================================================
const PHONE = { width: 390, height: 844 };

test('on a phone the month is a list of days, and one sheet edits any of them', async ({ page }) => {
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

    // Signed in at the default size, THEN narrowed: the consent banner is
    // fixed to the bottom of the viewport and covers the login form's own
    // button on an 844px-tall screen. Same order as groups-discussion.spec.js.
    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.setViewportSize(PHONE);

    await page.goto('/admin/sos', { waitUntil: 'load' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // Scoped to the page's own <main>: the navigation renders menu
    // labels at three widths at once (drawer, bar, mega-menu), so a
    // page-wide name lookup can match four nodes for one visible string
    // and fail strict mode.
    const main = page.getByRole('main');

    const dayList = page.locator('#sos-day-list');
    await expect(dayList).toBeVisible();
    // The desktop grid is the other half of the same page and must stay out
    // of the way here — this scenario is about what a phone actually shows.
    await expect(page.locator('table.sos-grid')).toBeHidden();

    // ---------------------------------------------------------------
    // The running month starts at TODAY: past days are not editable in
    // practice, and they used to occupy the first two screens.
    // ---------------------------------------------------------------
    const firstRow = dayList.locator('.sos-day-row').first();
    await expect(firstRow, 'the current month opens on today').toHaveClass(/list-group-item-warning/);
    await expect(main.getByRole('link', { name: "Aller à aujourd'hui" })).toBeVisible();

    // ---------------------------------------------------------------
    // Any other month is shown whole — and gives this scenario a day
    // that is not today, so editing it can never set off the
    // "apply the redirection immediately" path (module spec §3).
    // ---------------------------------------------------------------
    await main.getByRole('link', { name: 'Mois suivant' }).click();
    await expect(dayList).toBeVisible();
    await expect(
        dayList.locator('.sos-day-row.list-group-item-warning'),
        'another month has no today to highlight',
    ).toHaveCount(0);

    const row = dayList.locator('.sos-day-row').nth(14);
    const rowDate = await row.getAttribute('data-date');
    const rowLabel = await row.getAttribute('data-date-label');
    /** The same row, re-located after a reload. */
    const rowAgain = () => dayList.locator(`.sos-day-row[data-date="${rowDate}"]`);
    const rowTarget = () => rowAgain().locator('[data-day-target]');

    // ---------------------------------------------------------------
    // Tapping a row fills the single sheet with THAT day.
    // ---------------------------------------------------------------
    const sheet = page.locator('#sos-day-sheet');
    await expect(sheet).toBeHidden();
    await row.click();
    await expect(sheet).toBeVisible();
    // The date written out in full, exactly as the row carries it — read
    // off the page rather than composed here, so this survives any month.
    await expect(page.locator('#sos-day-sheet-title')).toHaveText(rowLabel);

    // The harness's unit chief is the roster's one member; their full name
    // is compared against itself rather than against a string written here.
    const memberName = (await sheet.locator('[data-member-name]').first().textContent()).trim();
    const memberBlock = sheet.locator('[data-sheet-member-id]').first();

    /** Press one of the three named buttons and wait for the month to land. */
    async function press(label) {
        const saved = page.waitForResponse((response) => response.url().includes('/admin/sos/oncall'));
        await memberBlock.getByRole('button', { name: label, exact: true }).click();
        expect((await saved).ok()).toBe(true);
        await expect(page.locator('#sos-day-sheet-status')).toHaveText('Enregistré.');
    }

    // ---------------------------------------------------------------
    // « Garde » — a named state, reachable without pressing through the
    // other two, which is what the single cycling button forced.
    // ---------------------------------------------------------------
    await press('Garde');
    await expect(rowTarget(), 'the row now names whoever takes the calls').toHaveText(memberName);

    await page.reload({ waitUntil: 'load' });
    await expect(rowTarget(), 'the duty must survive a reload').toHaveText(memberName);

    // ---------------------------------------------------------------
    // « Indispo », then « Rien » — which also leaves the shared
    // instance's roster exactly as it was provisioned.
    // ---------------------------------------------------------------
    await rowAgain().click();
    await expect(sheet).toBeVisible();
    await press('Indispo');
    await expect(
        rowTarget(),
        'an unavailability is not a duty — the day falls back to the default number',
    ).toContainText('Par défaut');

    await press('Rien');
    await page.reload({ waitUntil: 'load' });
    await expect(rowTarget()).toContainText('Par défaut');

    // ---------------------------------------------------------------
    // « Ma disponibilité » — the same month, the signed-in member only,
    // editable without opening anything. The harness's super-admin is
    // also the unit's chef d'unité (scripts/e2e-support.php), so they
    // are on the roster and the tab has rows.
    // ---------------------------------------------------------------
    await main.getByRole('link', { name: 'Ma disponibilité' }).click();
    const mine = page.locator('#sos-my-availability');
    await expect(mine).toBeVisible();
    const myRow = mine.locator('.list-group-item').first();
    await expect(myRow.getByRole('button', { name: 'Garde', exact: true })).toBeVisible();
    await expect(myRow.getByRole('button', { name: 'Indispo', exact: true })).toBeVisible();
    await expect(myRow.getByRole('button', { name: 'Rien', exact: true })).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});

// ============================================================================
// The default-number warning is CONDITIONAL.
//
// WHY THIS THIRD SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Saving the default number really does re-route the unit's emergency line
// on the spot — but only on a day nobody is on call, since an explicit duty
// governs the day instead (SosAdminController::applyImmediatelyIfTodayUsesDefault()).
// The screen says so before the save, and only when it is true: a permanent
// warning that is wrong half the time ends up ignored, which is worse than
// none. Whether it is true depends on data nothing else in this suite
// changes, so the scenario changes it itself and reads the page back both
// ways round.
// ============================================================================
test('the default-number warning shows only when today has no on-call', async ({ page }) => {
    /** @type {string[]} */
    const serverErrors = [];
    page.on('response', (response) => {
        if (response.status() >= 500) {
            serverErrors.push(`HTTP ${response.status()} on ${response.url()}`);
        }
    });

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto('/admin/sos', { waitUntil: 'load' });

    // Today according to the SERVER, never to this process's clock: the
    // desktop grid marks its own row, and a timezone difference between the
    // two would make this scenario edit the wrong day.
    const todayCell = page.locator('tr.is-today td.sos-oncall-cell').first();
    await expect(todayCell).toBeVisible();
    const today = await todayCell.getAttribute('data-date');
    const cellAgain = () => page.locator(`tr.is-today td.sos-oncall-cell[data-date="${today}"]`).first();

    const warning = page.locator('#default-number-immediate-warning');

    /**
     * The settings block is collapsed at the bottom of the page, and it
     * has to be re-opened after every reload.
     *
     * Scoped to <main>: « Réglages » is also the name of a Configuration
     * page (design.md §7.1), which the navigation renders at three widths
     * at once — a page-wide lookup is one nav change away from matching
     * several nodes and failing strict mode.
     */
    async function openSettings() {
        await page.getByRole('main').getByRole('button', { name: 'Réglages' }).click();
    }

    /** Click today's cell once and wait for the full-month save to answer. */
    async function cycleToday() {
        const saved = page.waitForResponse((response) => response.url().includes('/admin/sos/oncall'));
        await cellAgain().click();
        expect((await saved).ok()).toBe(true);
        await expect(page.locator('#oncall-save-status')).toHaveText('Enregistré.');
    }

    // As provisioned, nobody is on call today — so the save really would
    // re-route the line, and the warning is there to say so.
    await openSettings();
    await expect(warning).toBeVisible();
    await expect(warning).toContainText("Aujourd'hui n'a pas de garde attribuée");

    // Put somebody on call today: the default number no longer governs it,
    // so saving one would change nothing today and the warning must go.
    await cycleToday();
    await page.reload({ waitUntil: 'load' });
    await openSettings();
    await expect(warning, 'a day with an explicit duty is not re-routed by the default number').toHaveCount(0);

    // Back through « indisponible » to blank — which also leaves the shared
    // instance exactly as it was found.
    await cycleToday();
    await cycleToday();
    await page.reload({ waitUntil: 'load' });
    await openSettings();
    await expect(warning).toBeVisible();

    expect(serverErrors, 'the application returned a server error').toEqual([]);
});
