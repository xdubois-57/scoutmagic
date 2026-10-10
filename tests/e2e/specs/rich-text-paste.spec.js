// End-to-end: pasting formatted text into the shared editor, reformatting
// it with the toolbar, and finding exactly the same HTML after a save and a
// reload (issue #844).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// What the reporter hit lived between three parties no single unit test
// holds together: the browser's own paste and editing commands, the
// editor's canonical form (public/assets/js/rich-text-link.js), and the
// server's sanitiser. Vitest proves the canonical form on a fixture
// (tests/js/rich-text-canonical.test.js), PHPUnit proves the sanitiser keeps
// it (tests/Core/Security/RichTextCanonicalContractTest.php); only a real
// Chromium can say that a paste really lands as that HTML, that the Bold
// button can then really take the bold OFF what was pasted — the « difficile
// à modifier avec les boutons » of the issue — and that a save and a reload
// change nothing.
//
// The paste is a REAL one: the HTML is written to Chromium's clipboard
// (the page is granted clipboard access) and pasted with the keyboard. A
// synthetic ClipboardEvent would not do — the editor deliberately never
// reads the clipboard's HTML as a string, and lets the browser's own paste
// land in a hidden bin instead (rich-text-link.js, wireSurface()), and a
// synthetic event has no default action to land anywhere. Safari, where the
// original report came from (#306), is not in the CI's browser set; the
// same paste path and canonical form run there, and stay a manual check on
// a Mac.
//
// SELECTORS
// ----------------------------------------------------------------------------
// Buttons are found by their accessible names (« Gras », « Enregistrer »).
// Three things are reached structurally, deliberately:
//   - the editable block, `.editable-content[data-key="contact.text"]`, and
//     its hover pencil `.editable-edit-btn` — the block is page content with
//     no role of its own, and `data-key` is the one thing that says WHICH
//     editable text it is; editable-rich-text.spec.js reaches it the same way;
//   - `#richTextEditorContent`, the editing surface — what is asserted is its
//     HTML, which only the element itself carries, and the h2 checked inside
//     it is a structural fact about that HTML, not something on the page a
//     reader would name.
//
// ORDERING
// ----------------------------------------------------------------------------
// The block's original HTML is put back, and configuration mode turned off
// again, so the pages the other specs read are the ones they were seeded
// with — as editable-rich-text.spec.js does on the same block.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { openModal } from '../support/modal.js';
import { waitForServerResponse } from '../support/response.js';

// The page writes the fragment to the clipboard itself; Chromium asks for
// these before it lets it.
test.use({ permissions: ['clipboard-read', 'clipboard-write'] });

/** The public page carrying `editable('contact.text', …)`. */
const PAGE = '/contact';

// What a word processor puts on the clipboard: Google Docs' non-bold <b>
// wrapper, a heading, bold and italic as styled spans, colours and fonts,
// and a list.
const PASTED = '<meta charset="utf-8"><b style="font-weight:normal;" id="docs-internal-guid-e2e">'
    + '<h1 style="color:#c00;font-family:Arial">Programme</h1>'
    + '<p dir="ltr" style="line-height:1.38"><span style="font-weight:400">Rendez-vous </span>'
    + '<span style="font-weight:700;color:#00f">samedi</span><span style="font-weight:400"> à </span>'
    + '<span style="font-style:italic">14&nbsp;h</span></p>'
    + '<ul><li dir="ltr"><p><span style="font-family:Arial">Un</span></p></li><li><p>Deux</p></li></ul></b>';

// The same text as the toolbar would have written it.
const CANONICAL = '<h2>Programme</h2><p>Rendez-vous <strong>samedi</strong> à <em>14&nbsp;h</em></p>'
    + '<ul><li>Un</li><li>Deux</li></ul>';

// …and once the Bold button has taken the bold off « samedi ».
const UNBOLDED = '<h2>Programme</h2><p>Rendez-vous samedi à <em>14&nbsp;h</em></p>'
    + '<ul><li>Un</li><li>Deux</li></ul>';

/**
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
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<import('@playwright/test').Locator>} the editor's dialog, open
 */
async function openEditor(page) {
    await page.goto(PAGE, { waitUntil: 'load' });

    const block = page.locator('.editable-content[data-key="contact.text"]');
    await expect(block).toBeVisible();
    await block.hover();
    const dialog = await openModal(page, 'richTextEditorModal', () =>
        block.locator('.editable-edit-btn').click());
    await expect(dialog.locator('#richTextEditorContent')).toBeVisible();

    return dialog;
}

/**
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<{success: boolean, value: string}>}
 */
async function save(page) {
    const saved = waitForServerResponse(page, (response) =>
        response.url().includes('/api/editable-content') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();

    return (await saved).json();
}

/** @param {import('@playwright/test').Locator} dialog */
function editorHtml(dialog) {
    return dialog.locator('#richTextEditorContent').evaluate((surface) => surface.innerHTML);
}

test('a pasted text is the toolbar\'s own HTML, stays editable, and is unchanged by a save and a reload', async ({ page }) => {
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

    /** @type {string|null} */
    let original = null;

    try {
        let dialog = await openEditor(page);
        original = await editorHtml(dialog);

        // Put the fragment on the clipboard, empty the editor, put the
        // caret in it, paste with the keyboard.
        await page.evaluate(async (html) => {
            await navigator.clipboard.write([new ClipboardItem({
                'text/html': new Blob([html], { type: 'text/html' }),
                'text/plain': new Blob(['Programme'], { type: 'text/plain' }),
            })]);
        }, PASTED);
        await dialog.locator('#richTextEditorContent').evaluate((surface) => {
            surface.innerHTML = '';
            surface.focus();
            const range = document.createRange();
            range.selectNodeContents(surface);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        });
        await page.keyboard.press('ControlOrMeta+V');
        await expect(dialog.locator('#richTextEditorContent h2')).toHaveText('Programme');

        // Nothing of the clipboard's styling reached the page…
        const pasted = await editorHtml(dialog);
        expect(pasted).not.toContain('style=');
        expect(pasted).not.toContain('<span');
        expect(pasted).not.toContain('<h1');
        expect(pasted).toContain('<strong>samedi</strong>');
        // …and what the editor sends is exactly the toolbar's grammar.
        expect(await page.evaluate((html) => window.ScoutMagicRichText.canonicalHtml(html), pasted)).toBe(CANONICAL);

        // The reporter's second gesture: take the bold off a pasted word
        // with the Bold button. A styled span the button did not recognise
        // was what made this « difficile à modifier ».
        await dialog.locator('#richTextEditorContent').evaluate((surface) => {
            const bold = surface.querySelector('strong');
            const range = document.createRange();
            range.selectNodeContents(bold);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        });
        await dialog.getByRole('button', { name: 'Gras', exact: true }).click();

        const stored = await save(page);
        expect(stored).toMatchObject({ success: true });
        // The server kept what the editor sent, byte for byte but for the
        // non-breaking space, which PHP writes as the character itself.
        expect(stored.value.replace(/ /g, '&nbsp;')).toBe(UNBOLDED);

        // Reload, reopen: the same HTML, nothing to clean up a second time.
        await page.reload({ waitUntil: 'load' });
        await expect(page.locator('.editable-content[data-key="contact.text"] h2')).toHaveText('Programme');
        dialog = await openEditor(page);
        expect(await editorHtml(dialog)).toBe(UNBOLDED);
    } finally {
        // Back to the text the seed wrote, whatever happened above.
        if (original !== null) {
            const restore = await openEditor(page).catch(() => null);
            if (restore !== null) {
                await restore.locator('#richTextEditorContent')
                    .evaluate((surface, html) => { surface.innerHTML = html; }, original)
                    .catch(() => null);
                await save(page).catch(() => null);
            }
        }
        await setConfigurationMode(page, false).catch(() => null);
    }

    expect(pageErrors).toEqual([]);
    expect(serverErrors).toEqual([]);
});
