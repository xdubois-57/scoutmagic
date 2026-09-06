// The one spec in this suite whose subject is the harness rather than the
// application, and it earns the place: ../support/calendar.js is what every
// calendar assertion in the other specs delegates to, and when it reads a
// transient state as a failure it turns a green application red at random.
//
// That happened twice. #145 fixed the half where two rectangles came from
// two different renders; #171 the half where one render was measured before
// the browser had laid it out — both cells still at x = 0, stacked at the
// left edge, and `two days of one week must sit side by side / Expected:
// > 0 / Received: 0` on a calendar that was perfectly fine. Both surfaced
// only under scripts/dast.sh, where ZAP and a TLS terminator widen every
// window, and both were invisible to `npm run e2e` in the same run.
//
// Reproducing that by driving the real application would mean racing the
// browser on purpose and hoping to lose. The state is reproduced here
// instead: a grid that HAS `display: grid` — so the check's first poll is
// satisfied at once — and has not yet received its columns, which is
// exactly the window the geometry poll has to sit through.
import { test, expect } from '@playwright/test';

import { expectRendersAsACalendar } from '../support/calendar.js';

/**
 * The markup `partials/month_day_grid.html.twig` emits, reduced to what
 * expectRendersAsACalendar() actually reads, with the real rule from
 * public/assets/css/components.css.
 *
 * `body.not-laid-out-yet` is the transient: `display: grid` is already in
 * force — the check's `expectWeekIsAGridRow()` poll passes immediately —
 * while the seven columns are not, so every cell shares one column and
 * every cell has the same x. That is the state measured on c818e3c.
 *
 * @param {boolean} stacked whether the grid starts without its columns
 */
function gridPage(stacked) {
    const days = Array.from({ length: 7 }, (_, i) => `<div class="daygrid-day">${i + 1}</div>`).join('');

    return `<!doctype html>
<style>
  .daygrid-week { display: grid; grid-template-columns: repeat(7, 1fr); }
  .daygrid-day { min-height: 44px; }
  body.not-laid-out-yet .daygrid-week { grid-template-columns: none; }
</style>
<body class="${stacked ? 'not-laid-out-yet' : ''}">
  <div class="daygrid"><div class="daygrid-week">${days}</div></div>
</body>`;
}

test('the calendar check waits for the row to be laid out instead of measuring the stack', async ({ page }) => {
    await page.setContent(gridPage(true));

    // The columns arrive late, as they do on a live grid — rental-calendar.js
    // replacing #rental-calendar-fragment's innerHTML, calendar-chief.js
    // rebuilding a month. Long enough that a check which does not wait
    // cannot miss the stacked state, short enough to stay well inside the
    // poll's budget.
    await page.evaluate(() => {
        setTimeout(() => document.body.classList.remove('not-laid-out-yet'), 1_000);
    });

    // The assertion IS that this resolves. Against the condition this
    // replaced — a poll satisfied as soon as both rectangles were readable
    // — it measures the stack, and `two days of one week must sit side by
    // side` fails on x = 0 against x = 0.
    await expectRendersAsACalendar(page.locator('.daygrid'));
});

test('and still refuses a grid that never receives its columns', async ({ page }) => {
    // The other direction, which is what makes the wait above safe to add:
    // a page that genuinely forgets its columns — the unstyled stack this
    // whole helper exists to catch — must still fail. Waiting for a state
    // that never comes is a timeout, not a pass.
    await page.setContent(gridPage(true));

    await expect(expectRendersAsACalendar(page.locator('.daygrid'))).rejects.toThrow(
        /laid out side by side/,
    );
});
