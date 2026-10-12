// End-to-end: a list item made a heading in the body of an article, saved,
// re-opened, and made plain text again (issue #885).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The article body had a toolbar of its own, wired outside the shared one,
// and both handed H2/H3/« Paragraphe » to the engine's `formatBlock`. In a
// list item the engine wraps the whole LIST in the heading —
// `<h2><ul><li>…</li></ul></h2>`, invalid HTML — so « Paragraphe » could not
// undo it on screen and the heading disappeared once saved. jsdom has no
// `execCommand` at all: only a real engine shows what it does to a list, and
// only a save and a re-open show what survived.
//
// tests/e2e/specs/editable-rich-text.spec.js walks the same gestures in the
// configuration-mode editor (editable.js), the other surface the ticket names.
//
// ORDERING
// ----------------------------------------------------------------------------
// The article is created here and deleted at the end, so no other spec reads it.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { openModal } from '../support/modal.js';
import { pngBuffer } from '../support/png.js';

const ARTICLE_TITLE = `Programme du week-end ${Date.now()}`;

/**
 * The body editor of the article — the first row of the builder.
 *
 * @param {import('@playwright/test').Page} page
 */
function bodyRow(page) {
    return page.locator('#news-fields-list > [data-key]').nth(0);
}

/**
 * Puts the caret in the text $text of the body editor, optionally after
 * filling it with $html — fired as an `input`, as typing would be, so the
 * builder's state holds it.
 *
 * @param {import('@playwright/test').Locator} editor
 * @param {string|null} html
 * @param {string} text
 */
async function caretIn(editor, html, text) {
    await editor.evaluate((surface, [seed, wanted]) => {
        if (seed !== null) {
            surface.innerHTML = seed;
            surface.dispatchEvent(new Event('input', { bubbles: true }));
        }
        surface.focus();
        const walker = document.createTreeWalker(surface, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode();
        while (node && node.textContent !== wanted) {
            node = walker.nextNode();
        }
        if (!node) {
            throw new Error('no text « ' + wanted + ' » in the editor: ' + surface.innerHTML);
        }
        const range = document.createRange();
        range.setStart(node, 1);
        range.collapse(true);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
    }, [html, text]);
}

/**
 * Saves the article and re-opens its editor.
 *
 * @param {import('@playwright/test').Page} page
 */
async function saveAndReopen(page) {
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await page.waitForURL(/\/news\/\d+\/gerer$/, { waitUntil: 'load' });
    await page.reload({ waitUntil: 'load' });
}

test('a list item made a heading in an article body survives the save, and « Paragraphe » turns either back', async ({ page }) => {
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await loginAsAdmin(page);
    await answerCookieBanner(page);

    await page.goto('/news/manage', { waitUntil: 'domcontentloaded' });
    await page.locator('main').getByRole('link', { name: 'Nouvel article' }).first().click();
    await page.getByLabel("Titre de l'article").fill(ARTICLE_TITLE);
    await page.getByLabel('Résumé en une phrase').fill('Ce qui est prévu samedi et dimanche.');
    await page.locator('#image').setInputFiles({ name: 'affiche.png', mimeType: 'image/png', buffer: pngBuffer(2, 2) });
    await page.getByRole('radio', { name: 'Public', exact: true }).check();

    let editor = bodyRow(page).getByRole('textbox', { name: 'Contenu' });
    await caretIn(editor, '<ul><li>Samedi</li><li>Dimanche</li><li>Retour</li></ul>', 'Dimanche');
    await bodyRow(page).locator('button[title="Titre 2"]').click();
    // On screen, before any save: one heading, outside the list.
    await expect(editor.locator('h2')).toHaveText('Dimanche');
    await expect(editor.locator('h2 ul, h2 li')).toHaveCount(0);

    await saveAndReopen(page);
    const articleUrl = new URL(page.url()).pathname.replace(/\/gerer$/, '');
    try {
        editor = bodyRow(page).getByRole('textbox', { name: 'Contenu' });
        await expect(editor.locator('h2')).toHaveText('Dimanche');
        await expect(editor.locator('li')).toHaveText(['Samedi', 'Retour']);

        // The published article says the same as the editor did.
        const published = await page.request.get(articleUrl);
        expect(await published.text()).toContain('<h2>Dimanche</h2>');

        // « Paragraphe » on the heading, then on a list item.
        await caretIn(editor, null, 'Dimanche');
        await bodyRow(page).locator('button[title="Paragraphe"]').click();
        await caretIn(editor, null, 'Samedi');
        await bodyRow(page).locator('button[title="Paragraphe"]').click();
        await expect(editor.locator('h2')).toHaveCount(0);

        await saveAndReopen(page);
        editor = bodyRow(page).getByRole('textbox', { name: 'Contenu' });
        await expect(editor.locator('h2')).toHaveCount(0);
        await expect(editor.locator('p')).toHaveText(['Samedi', 'Dimanche']);
        await expect(editor.locator('li')).toHaveText(['Retour']);
    } finally {
        await page.goto(`${articleUrl}/gerer`, { waitUntil: 'load' });
        const deleteModal = await openModal(page, 'news-delete-modal', () =>
            page.getByRole('button', { name: 'Supprimer', exact: true }).first().click());
        await deleteModal.locator('#news-delete-confirm-btn').click();
        await page.waitForURL('**/news/manage', { waitUntil: 'domcontentloaded' });
    }

    expect(pageErrors).toEqual([]);
});
