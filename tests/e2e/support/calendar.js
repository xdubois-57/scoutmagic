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

    // Both boxes read together, and retried until both answer.
    //
    // boundingBox() is a one-shot measurement, and on the rental pages
    // this grid is LIVE: public/assets/js/rental-calendar.js replaces
    // #rental-calendar-fragment's innerHTML when a month is paged or an
    // estimate recomputed, so the cell measured a millisecond ago can be
    // detached by the time the next line asks for its neighbour, and
    // boundingBox() then answers null. Measuring the two separately, even
    // straight after a toBeVisible(), is therefore a race — one that fails
    // as "expect(second).not.toBeNull()" and reads as a broken calendar
    // when the calendar is fine. Polling the PAIR is what makes the
    // comparison below a comparison of two cells that coexisted.
    /** @type {{first: {x: number, y: number, height: number}, second: {x: number}}} */
    let box;
    await expect
        .poll(async () => {
            const first = await days.nth(0).boundingBox();
            const second = await days.nth(1).boundingBox();
            if (first === null || second === null) {
                return false;
            }
            box = { first, second };

            return true;
        }, { message: "the week's first two day cells must both be laid out at the same moment" })
        .toBe(true);

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

    // The same paired poll as the day grid above, for the same reason:
    // this month is live too — public/assets/js/calendar-chief.js rebuilds
    // it on a month change — so measuring the two cells separately can
    // measure one that no longer exists.
    /** @type {{first: {x: number, y: number}, second: {x: number, y: number}}} */
    let box;
    await expect
        .poll(async () => {
            const first = await days.nth(0).boundingBox();
            const second = await days.nth(1).boundingBox();
            if (first === null || second === null) {
                return false;
            }
            box = { first, second };

            return true;
        }, { message: "the week's first two day cells must both be laid out at the same moment" })
        .toBe(true);

    expect(box.second.x, 'two days of one week must sit side by side').toBeGreaterThan(box.first.x);
    expect(Math.abs(box.second.y - box.first.y), 'and share a row').toBeLessThan(2);
}
