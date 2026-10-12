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
import { openModal } from '../support/modal.js';
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
 * @returns {Promise<import('@playwright/test').Locator>} the editor's dialog, open
 */
async function openEditorOnContactText(page) {
    await page.goto(PAGE, { waitUntil: 'load' });

    const block = page.locator('.editable-content[data-key="contact.text"]');
    await expect(block).toBeVisible();
    await block.hover();
    const dialog = await openModal(page, 'richTextEditorModal', () =>
        block.locator('.editable-edit-btn').click());

    const editor = dialog.locator('#richTextEditorContent');
    await expect(editor).toBeVisible();

    await editor.evaluate((surface) => {
        surface.focus();
        const range = document.createRange();
        range.selectNodeContents(surface);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    });

    return dialog;
}

/**
 * Clicks a toolbar button of the shared modal and saves.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} dialog the editor, open
 * @param {string} buttonName the button's accessible name
 */
async function applyAndSave(page, dialog, buttonName) {
    await dialog.getByRole('button', { name: buttonName, exact: true }).click();

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
        const dialog = await openEditorOnContactText(page);
        // The reporter's own gesture: select the paragraph, click « H2 ».
        // editable.js used to call execCommand('formatBlock', false, null),
        // which every browser answers by doing nothing at all.
        expect(await applyAndSave(page, dialog, 'H2')).toMatchObject({ success: true });

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
            await applyAndSave(page, restored, 'Paragraphe').catch(() => null);
        }
        await setConfigurationMode(page, false).catch(() => null);
    }

    expect(pageErrors).toEqual([]);
    expect(serverErrors).toEqual([]);
});

/**
 * Puts $html in the open editor and the caret in the text $caretIn — the
 * author who clicked into one line of a list before choosing a style.
 *
 * @param {import('@playwright/test').Locator} dialog the editor, open
 * @param {string|null} html what the surface holds, or null to keep it
 * @param {string} caretIn the text of the node the caret goes in
 */
async function caretIn(dialog, html, caretIn) {
    await dialog.locator('#richTextEditorContent').evaluate((surface, [seed, text]) => {
        if (seed !== null) {
            surface.innerHTML = seed;
        }
        surface.focus();
        const walker = document.createTreeWalker(surface, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode();
        while (node && node.textContent !== text) {
            node = walker.nextNode();
        }
        if (!node) {
            throw new Error('no text « ' + text + ' » in the editor: ' + surface.innerHTML);
        }
        const range = document.createRange();
        range.setStart(node, 1);
        range.collapse(true);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
    }, [html, caretIn]);
}

// Issue #885. The engine's formatBlock in a list item wrapped the whole
// list in a heading — invalid HTML the canonical form then dropped on save,
// so the heading was gone after a reload — and « Paragraphe » could not
// undo it on screen.
test('a list item made a heading is still a heading after a reload, and « Paragraphe » turns either back', async ({ page }) => {
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await setConfigurationMode(page, true);

    const block = page.locator('.editable-content[data-key="contact.text"]');
    let original = null;
    try {
        let dialog = await openEditorOnContactText(page);
        original = await dialog.locator('#richTextEditorContent').evaluate((surface) => surface.innerHTML);

        await caretIn(dialog, '<ul><li>premier</li><li>deuxième</li><li>troisième</li></ul>', 'deuxième');
        expect(await applyAndSave(page, dialog, 'H2')).toMatchObject({ success: true });

        await page.reload({ waitUntil: 'load' });
        await expect(block.locator('h2')).toHaveText('deuxième');
        await expect(block.locator('ul')).toHaveCount(2);
        await expect(block.locator('li')).toHaveText(['premier', 'troisième']);

        // « Paragraphe » on that heading: plain text again.
        dialog = await openEditorOnContactText(page);
        await caretIn(dialog, null, 'deuxième');
        expect(await applyAndSave(page, dialog, 'Paragraphe')).toMatchObject({ success: true });
        await page.reload({ waitUntil: 'load' });
        await expect(block.locator('h2')).toHaveCount(0);
        await expect(block.locator('p', { hasText: 'deuxième' })).toBeVisible();

        // « Paragraphe » in a list item: the item leaves its list.
        dialog = await openEditorOnContactText(page);
        await caretIn(dialog, null, 'premier');
        expect(await applyAndSave(page, dialog, 'Paragraphe')).toMatchObject({ success: true });
        await page.reload({ waitUntil: 'load' });
        await expect(block.locator('p', { hasText: 'premier' })).toBeVisible();
        await expect(block.locator('li')).toHaveText(['troisième']);
    } finally {
        if (original !== null) {
            const restored = await openEditorOnContactText(page).catch(() => null);
            if (restored !== null) {
                await restored.locator('#richTextEditorContent')
                    .evaluate((surface, html) => { surface.innerHTML = html; }, original)
                    .catch(() => null);
                const saved = waitForServerResponse(page, (response) =>
                    response.url().includes('/api/editable-content') && response.request().method() === 'POST');
                await page.getByRole('button', { name: 'Enregistrer', exact: true }).click().catch(() => null);
                await saved.catch(() => null);
            }
        }
        await setConfigurationMode(page, false).catch(() => null);
    }

    expect(pageErrors).toEqual([]);
});
