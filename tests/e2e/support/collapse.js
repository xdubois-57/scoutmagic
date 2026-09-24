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
