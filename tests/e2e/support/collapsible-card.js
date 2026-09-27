// End-to-end support: unfolding a page whose boxes arrive collapsed.
//
// A box that folds is a real Bootstrap collapse, so its controls are in
// the DOM and `display: none` until it is opened. Playwright refuses to
// fill, check or click a hidden control, and it blames the control —
// « #field is not visible » — rather than the box nobody opened, which
// is exactly the wrong place to go looking. The rental booking pages
// fold theirs; Configuration > Maintenance used to carry eight folded
// cards on one screen, and since issue #619 splits them over six
// sub-pages where every box arrives open.
//
// A reload folds everything back, so a spec calls this again after each.
import { expect } from '@playwright/test';

/**
 * Opens a card's panel if it is folded, and waits until it is usable.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} cardId the card's id, without its `#` — the panel is
 *        `<cardId>-body`, the convention the Maintenance sub-pages
 *        (`config/maintenance/`) write for every one of their boxes.
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
