// Shared end-to-end helper: assert that a month grid really renders as a
// calendar.
//
// The failure this exists for is invisible to every markup assertion in
// the PHP suite. `partials/month_day_grid.html.twig` is styled solely by
// public/assets/css/components.css, which base.html.twig deliberately does
// NOT load — it is opt-in per page. A page that forgets to link it emits
// exactly the right 42 cells with exactly the right classes, passes every
// controller test, and shows a human a vertical stack of unstyled squares:
// `.daygrid-week` falls back to `display: block`, the seven days of a week
// stop sharing a row, and the calendar is unreadable and unusable.
//
// Only a real browser computing real styles can tell those two apart,
// which is why this check lives in the end-to-end suite and nowhere else.
import { expect } from '@playwright/test';

/**
 * `display: grid`, waited for rather than sampled once.
 *
 * toBeVisible() passes on an UNSTYLED element — an unstyled div is a
 * perfectly visible block — so it is no guarantee that components.css has
 * been applied by the time the next line runs. A one-shot
 * getComputedStyle() therefore reads `block` whenever the stylesheet is
 * still in flight, and reports a correctly-built calendar as a stack of
 * squares. That is the same one-shot hazard this file already documents
 * for boundingBox() below, and the same remedy: poll the pair of
 * questions until they answer, instead of asking once.
 *
 * It does NOT weaken what is being asserted. A page that genuinely
 * forgets to link components.css never reaches `grid`, the poll times
 * out, and the failure reads exactly as before.
 *
 * @param {import('@playwright/test').Locator} week
 */
async function expectWeekIsAGridRow(week) {
    await expect
        .poll(
            () => week.evaluate((node) => getComputedStyle(node).display),
            { message: 'components.css must be linked, or a week is a stack' }
        )
        .toBe('grid');
}

/**
 * The first two day cells of a week, measured together and waited for
 * rather than sampled once.
 *
 * Two hazards, and the second is the one that kept coming back.
 *
 * A cell measured a millisecond ago can be DETACHED by the time the next
 * line asks for its neighbour: these grids are live —
 * public/assets/js/rental-calendar.js replaces #rental-calendar-fragment's
 * innerHTML, calendar-chief.js rebuilds the month on a page. evaluateAll()
 * runs once inside the page, so both rectangles come out of the same
 * layout pass and a re-render cannot slip between them.
 *
 * But a single layout pass is not the same as a FINISHED one. Waiting only
 * for the two rectangles to be readable returns as soon as the cells
 * exist, which is before the grid has been laid out — both cells still at
 * x = 0, stacked at the left edge. The caller's assertion then read that
 * transient as a broken calendar: `two days of one week must sit side by
 * side / Expected: > 0 / Received: 0`, intermittently and only under
 * scripts/dast.sh, where every request crosses ZAP and a TLS terminator so
 * every window is widest. The browser suite passed the same spec in the
 * same run, which is what says it was never the calendar (#171).
 *
 * So the poll waits for what its message claims: the cells laid out side
 * by side. This is exactly what expectWeekIsAGridRow() above does for
 * `display`, and it weakens nothing for the same reason — a page that
 * genuinely stacks its days never reaches that state, the poll times out,
 * and the failure still names the symptom.
 *
 * @param {import('@playwright/test').Locator} days the week's day cells
 * @returns {Promise<{first: {x: number, y: number, height: number}, second: {x: number, y: number}}>}
 */
async function measureFirstTwoDays(days) {
    /** @type {{first: {x: number, y: number, height: number}, second: {x: number, y: number}}|null} */
    let box = null;

    await expect
        .poll(async () => {
            // ONE evaluation in the page, so both rectangles come from
            // the same layout pass.
            const pair = await days.evaluateAll((nodes) => {
                if (nodes.length < 2) {
                    return null;
                }
                const a = nodes[0].getBoundingClientRect();
                const b = nodes[1].getBoundingClientRect();

                return {
                    first: { x: a.x, y: a.y, height: a.height },
                    second: { x: b.x, y: b.y },
                };
            });
            if (pair === null) {
                return false;
            }
            box = pair;

            // Not merely readable: actually laid out as a row.
            return pair.second.x > pair.first.x;
        }, { message: "the week's first two day cells must be laid out side by side" })
        .toBe(true);

    if (box === null) {
        throw new Error('unreachable: the poll only succeeds once both cells were measured');
    }

    return box;
}

/**
 * @param {import('@playwright/test').Locator} grid A `.daygrid` element.
 */
export async function expectRendersAsACalendar(grid) {
    await expect(grid).toBeVisible();

    const week = grid.locator('.daygrid-week').first();

    await expectWeekIsAGridRow(week);

    // The observable consequence of that grid, stated in geometry: two
    // days of the same week sit side by side. A future CSS change that
    // keeps `display: grid` but loses the seven columns still fails here.
    //
    // A week is seven days — partials/month_day_grid.html.twig emits
    // exactly that, padding included — so this is an invariant of the
    // markup and not of any one month.
    const days = week.locator('.daygrid-day');
    await expect(days).toHaveCount(7);

    // Measured together, and waited for until the row is actually laid
    // out — see measureFirstTwoDays() for the two hazards that shape it.
    const box = await measureFirstTwoDays(days);

    expect(box.second.x, 'two days of one week must sit side by side').toBeGreaterThan(box.first.x);
    expect(Math.abs(box.second.y - box.first.y), 'and share a row').toBeLessThan(2);

    // A cell tall enough to stack 42 of them down the page is the very
    // symptom being ruled out.
    expect(box.first.height).toBeLessThan(200);
}

/**
 * The same statement for the OTHER month grid: the calendar module's
 * `partials/month_grid.html.twig` (`.calendar-week` rows, event bars laid
 * over the days), which depends on the very same opt-in components.css as
 * the day grid above and fails the very same way without it — 42 unstyled
 * squares stacked down the page, green in every PHP suite.
 *
 * @param {import('@playwright/test').Locator} container an element
 *   wrapping the rendered month (the `.calendar-week` rows are found
 *   within it).
 */
export async function expectRendersAsAnEventCalendar(container) {
    const week = container.locator('.calendar-week').first();
    await expect(week).toBeVisible();

    await expectWeekIsAGridRow(week);

    const days = week.locator('.calendar-day-cell');
    await expect(days).toHaveCount(7);

    // The same measurement as the day grid above, for the same reasons:
    // this month is live too — public/assets/js/calendar-chief.js rebuilds
    // it on a month change.
    const box = await measureFirstTwoDays(days);

    expect(box.second.x, 'two days of one week must sit side by side').toBeGreaterThan(box.first.x);
    expect(Math.abs(box.second.y - box.first.y), 'and share a row').toBeLessThan(2);
}
