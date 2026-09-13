// End-to-end: applying a heading to editable text, in a real browser, and
// finding it still there after a reload.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Issue #306 is the one bug in this repository that no other layer could
// have caught, because every part of it lived in the browser:
//
//   - `document.execCommand('formatBlock', …)` is not implemented by jsdom
//     at all. Vitest can assert that the toolbar CALLS it with `<h2>`; only
//     a browser can say whether an `<h2>` appears.
//   - The heading that DID survive the click was then dropped by the
//     server's sanitiser on the way in, while the editor repainted the page
//     with its own copy of what it had sent — so it stayed visible until
//     the page was reloaded. Nothing that stops at the save request can see
//     that: the assertion has to be made after a round trip.
//
// So this scenario does what the reporter did: turn configuration mode on,
// edit a block, apply a heading, save, reload, look.
//
// The issue's third defect — editable.js and rich-text-field.js wiring the
// same toolbar twice, so every toggle was applied and undone in one click —
// is NOT here, and deliberately: the pages where both scripts load are the
// configuration pages that carry their own rich-text fields, none of which
// this spec would reach without seeding one. tests/js/editable.test.js
// imports both scripts over one modal and counts the commands, which is
// the whole of that defect and needs no server.
//
// ORDERING
// ----------------------------------------------------------------------------
// The block's text is put back, and configuration mode is turned off again,
// so the pages the other specs read are the ones they were seeded with.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { waitForServerResponse } from '../support/response.js';

/** The public page carrying `editable('contact.text', …)`. */
const PAGE = '/contact';

/**
 * Turns configuration mode on or off through the page that owns the
 * toggle — a form post whose button says which way it is about to go, so
 * the button on offer is also how the current state is read. Scoped to the
 * page body: the site header carries a « Désactiver » of its own while the
 * mode is on.
 *
 * Idempotent, and stated as the state wanted rather than as a click, so a
 * spec that failed halfway through cannot leave the next one guessing.
 *
 * @param {import('@playwright/test').Page} page
 * @param {boolean} active
 */
async function setConfigurationMode(page, active) {
    await page.goto('/config/general', { waitUntil: 'domcontentloaded' });

    const card = page.locator('#main-content');
    const toTurnItOn = card.getByRole('button', { name: 'Activer', exact: true });
    const toTurnItOff = card.getByRole('button', { name: 'Désactiver', exact: true });
    const wanted = active ? toTurnItOn : toTurnItOff;

    if (await wanted.count() > 0) {
        await wanted.click();
    }

    await expect(active ? toTurnItOff : toTurnItOn).toBeVisible();
}

/**
 * Opens the shared editor on the `contact.text` block and puts the caret
 * around everything in it, as an author who selects their paragraph does.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openEditorOnContactText(page) {
    await page.goto(PAGE, { waitUntil: 'load' });

    const block = page.locator('.editable-content[data-key="contact.text"]');
    await expect(block).toBeVisible();
    await block.hover();
    await block.locator('.editable-edit-btn').click();

    const editor = page.locator('#richTextEditorContent');
    await expect(editor).toBeVisible();

    await editor.evaluate((surface) => {
        surface.focus();
        const range = document.createRange();
        range.selectNodeContents(surface);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    });

    return editor;
}

/**
 * Clicks a toolbar button of the shared modal and saves.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} buttonName the button's accessible name
 */
async function applyAndSave(page, buttonName) {
    await page.locator('#richTextEditorModal').getByRole('button', { name: buttonName, exact: true }).click();

    const saved = waitForServerResponse(page, (response) =>
        response.url().includes('/api/editable-content') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();

    return (await saved).json();
}

test('a heading applied in the shared editor is still a heading after the page is reloaded', async ({ page }) => {
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    /** @type {string[]} */
    const serverErrors = [];
    page.on('response', (response) => {
        if (response.status() >= 500) {
            serverErrors.push(`HTTP ${response.status()} on ${response.url()}`);
        }
    });

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await setConfigurationMode(page, true);

    try {
        const editor = await openEditorOnContactText(page);
        // The reporter's own gesture: select the paragraph, click « H2 ».
        // editable.js used to call execCommand('formatBlock', false, null),
        // which every browser answers by doing nothing at all.
        expect(await applyAndSave(page, 'H2')).toMatchObject({ success: true });

        // Before the reload, because the two are the same claim only when
        // the block is repainted with what the server stored rather than
        // with the editor's own copy of what it sent.
        await expect(page.locator('.editable-content[data-key="contact.text"] h2')).toBeVisible();

        await page.reload({ waitUntil: 'load' });

        // « visible une fois le texte sauvé, mais disparait quand la page
        // est rechargée » — this is the line that said so.
        await expect(page.locator('.editable-content[data-key="contact.text"] h2')).toBeVisible();
    } finally {
        // Back to a paragraph, whatever happened above, so the next spec
        // reads the page it was seeded with.
        const restored = await openEditorOnContactText(page).catch(() => null);
        if (restored !== null) {
            await applyAndSave(page, 'Paragraphe').catch(() => null);
        }
        await setConfigurationMode(page, false).catch(() => null);
    }

    expect(pageErrors).toEqual([]);
    expect(serverErrors).toEqual([]);
});
