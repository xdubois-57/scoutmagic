// Waiting for a RESPONSE is not the same kind of wait as an action.
//
// playwright.config.js sets actionTimeout to scaled(10_000), which is the
// ceiling page.waitForResponse() inherits when it is given none. Ten
// seconds is generous for clicking a button; it is not generous for the
// server round trip that click provokes, served by a single-worker PHP
// built-in server whose other requests queue behind this one. On a
// contended CI runner that ceiling is genuinely reachable — it is what
// failed registration-grids.spec.js on main (« waitForResponse: Timeout
// 10000ms exceeded », 52 of 53 specs green in the same run), and what
// scout-year-transition.spec.js records having hit before it.
//
// navigationTimeout, scaled(30_000), is the config's own ceiling for the
// closest thing there is: a request going to the server and coming back.
// That is the number used here.
//
// This helper does NOT paper over a race. Every call site registers the
// wait BEFORE the action that triggers the request, which is what makes
// the response impossible to miss; where the request may never be made at
// all, the fix is a marker the page sets — see scout-year-transition.spec.js
// — and no ceiling, however large, is the right answer.
import { scaled } from './timeouts.js';

/**
 * page.waitForResponse() with a ceiling that fits a server round trip.
 *
 * @param {import('@playwright/test').Page} page
 * @param {(response: import('@playwright/test').Response) => boolean} matcher
 * @returns {Promise<import('@playwright/test').Response>}
 */
export function waitForServerResponse(page, matcher) {
    return page.waitForResponse(matcher, { timeout: scaled(30_000) });
}
