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
 * @param {import('@playwright/test').Locator} grid A `.daygrid` element.
 */
export async function expectRendersAsACalendar(grid) {
    await expect(grid).toBeVisible();

    const week = grid.locator('.daygrid-week').first();

    const display = await week.evaluate((node) => getComputedStyle(node).display);
    expect(display, 'components.css must be linked, or a week is a stack').toBe('grid');

    // The observable consequence of that grid, stated in geometry: two
    // days of the same week sit side by side. A future CSS change that
    // keeps `display: grid` but loses the seven columns still fails here.
    const first = await week.locator('.daygrid-day').nth(0).boundingBox();
    const second = await week.locator('.daygrid-day').nth(1).boundingBox();

    expect(first).not.toBeNull();
    expect(second).not.toBeNull();
    expect(second.x).toBeGreaterThan(first.x);
    expect(Math.abs(second.y - first.y)).toBeLessThan(2);

    // A cell tall enough to stack 42 of them down the page is the very
    // symptom being ruled out.
    expect(first.height).toBeLessThan(200);
}
