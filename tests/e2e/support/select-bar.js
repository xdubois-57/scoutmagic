// Choosing an option in a partials/select_bar.html.twig instance.
//
// The select bar replaced the chip picker, and the interaction genuinely
// changed: the options used to be chips sitting open on the page, so a
// spec could click one directly. They now live in a disclosure panel that
// is closed until the visitor opens it — which is the point of the
// component (a section list is open-ended and its labels are long, so it
// gets one full-width row instead of two wrapped lines of chips). A spec
// that clicks the option without opening the panel first times out
// waiting for an element that is really, correctly, not visible yet.
//
// The panel is a native <details>/<summary>, so this needs no JavaScript
// on the page and no waiting for a script to boot — clicking the summary
// is what opens it, in a real browser exactly as with JS disabled.

/**
 * Opens a select bar's panel and clicks one of its options.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} pickerId the bar's DOM id (its `picker_id`)
 * @param {string} optionName the option's visible label
 */
export async function chooseInSelectBar(page, pickerId, optionName) {
    const bar = page.locator(`#${pickerId}`);
    const panel = bar.locator('details');

    // A bar with a single option renders as static text with no control
    // and nothing to choose — surface that as a real message rather than
    // a bare timeout on the summary.
    if (await panel.count() === 0) {
        throw new Error(
            `Select bar "#${pickerId}" has no panel: it rendered as static text `
            + `(a single option) or empty, so "${optionName}" cannot be chosen.`
        );
    }

    if (!await panel.evaluate((el) => /** @type {HTMLDetailsElement} */ (el).open)) {
        await bar.locator('summary').click();
    }

    await bar.getByRole('link', { name: optionName }).click();
}
