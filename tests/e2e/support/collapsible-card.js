// End-to-end support: unfolding a page whose boxes arrive collapsed.
//
// Configuration > Maintenance carries eight cards and only the first two
// are open on arrival; the rest are real Bootstrap collapses, so their
// controls are in the DOM and `display: none`. Playwright refuses to
// fill, check or click a hidden control, and it blames the control —
// « #full-backup-password is not visible » — rather than the box nobody
// opened, which is exactly the wrong place to go looking.
//
// A reload folds everything back, and this page reloads itself twice in
// the backup scenario (its own `location.reload()` when a background job
// reports done), so a spec calls this again after each.
import { expect } from '@playwright/test';

/**
 * Opens a card's panel if it is folded, and waits until it is usable.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} cardId the card's id, without its `#` — the panel is
 *        `<cardId>-body`, the convention `config/maintenance.html.twig`
 *        writes for every one of its boxes.
 * @returns {Promise<import('@playwright/test').Locator>} the panel
 */
export async function openCard(page, cardId) {
    const panel = page.locator(`#${cardId}-body`);

    // The trigger is a `data-bs-target` button, so what opens the panel
    // is Bootstrap's own delegated data-api — not any code in this repo.
    // A click landing before bootstrap.bundle.min.js has run does
    // nothing at all, silently, and the assertion below then waits out
    // its ceiling reporting the panel as hidden (the same race
    // support/section-editor.js documents at length for dialogs).
    await page.waitForFunction(() => typeof (/** @type {any} */ (window)).bootstrap !== 'undefined');

    if (!(await panel.isVisible())) {
        await page.locator(`[data-bs-target="#${cardId}-body"]`).click();
    }

    // Visible AND settled: Playwright's actionability check waits for the
    // element to stop animating, and a 350 ms collapse transition is
    // long enough for a click aimed at a control inside to land on the
    // wrong place.
    await expect(panel).toBeVisible();

    return panel;
}
