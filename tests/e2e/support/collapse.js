// Shared end-to-end helper: wait for a Bootstrap collapse to have
// finished opening before aiming at anything inside it.
//
// Every screen that hides a line's controls behind « Détail de la
// créance » has the same shape, and so has the same race. It is written
// here rather than in one spec because it was learned in one spec and
// then lost by the next: `finance-payment-labels.spec.js` performed the
// very same gesture on the very same panel with no barrier at all, and
// CI caught it on a loaded runner — the waive click did nothing, and the
// two receivables it left owing went on to fail
// `public-home-page.spec.js`, which is where the barrier already was.
import { expect } from '@playwright/test';

/**
 * Wait for the Bootstrap collapse holding `inner` to have finished opening.
 *
 * Clicking as soon as the toggle is released is a race, and it is lost
 * roughly one run in five. While the panel animates it carries
 * `.collapsing` alone; only when it settles does Bootstrap give it
 * `.collapse.show`. In that window the « Afficher le QR » link above
 * slides across the waive button's position — Playwright reports it
 * "intercepts pointer events" on a good day, and on a bad one the click
 * lands on a button that has moved and the page simply does nothing,
 * leaving the next assertion to time out against a page that looks
 * perfectly normal.
 *
 * Waiting for the settled class is what a human does without thinking:
 * they see the panel stop moving before they aim at anything inside it.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} inner a control the panel holds
 */
export async function settledPanelAround(page, inner) {
    await expect(page.locator('.collapse.show').filter({ has: inner })).toBeVisible();
}

/**
 * Performs the gesture that unfolds a Bootstrap collapse, and returns the
 * panel once it has stopped moving.
 *
 * Two barriers, for the two ways this gesture has been lost (#520):
 *
 * - BEFORE the gesture, the library. A `data-bs-toggle="collapse"` button
 *   does nothing at all until Bootstrap's delegated handler exists, and
 *   base.html.twig loads the bundle after the page's own markup — so a
 *   toggle, and even its `aria-expanded="false"`, can be on screen while
 *   the parser has not reached the script yet. A click then is swallowed,
 *   the panel never so much as starts to open, and the assertion after it
 *   reports « resolved to <div class="collapse"> » until its ceiling.
 *   registration-flow.spec.js lost exactly that in CI, after a save whose
 *   redirect it had judged arrived on that very attribute; delaying the
 *   bundle by 1.5 s reproduces it every time.
 * - AFTER it, the settled class. While it animates the panel carries
 *   `.collapsing` alone, and it is visible then already; only
 *   `.collapse.show` says it has stopped.
 *
 * It asserts on the way that the panel really was folded: a panel that
 * silently starts open would make the gesture a close, and every later
 * step would pass without anyone noticing the default had changed.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} panelId the panel's id, without its `#`
 * @param {() => Promise<unknown>} gesture what unfolds it, usually a click
 *        on its toggle
 * @returns {Promise<import('@playwright/test').Locator>} the panel, open
 */
export async function openCollapse(page, panelId, gesture) {
    // The library first, and the panel THERE before it is judged folded:
    // toBeHidden() alone is also satisfied by an element not parsed yet,
    // which would let a panel that starts open through unnoticed.
    await page.waitForFunction(() => typeof (/** @type {any} */ (window)).bootstrap !== 'undefined');
    const panel = page.locator(`#${panelId}`);
    await expect(panel).toBeAttached();
    await expect(panel, 'a panel is unfolded from folded').toBeHidden();

    await gesture();

    await expect(panel).toHaveClass(/\bcollapse\b.*\bshow\b|\bshow\b.*\bcollapse\b/);
    await expect(panel).toBeVisible();

    return panel;
}
