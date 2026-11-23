// End-to-end support: put a NEW article online.
//
// Since issue #1357 the news editor offers no « Publier » nor « Programmer… »
// before the first save — an unsaved article has nothing to publish — and the
// server saves a creation as a draft whatever the request asks. A scenario
// that wants a published article therefore does what the page asks of its
// author: save the draft, then publish the saved article.
import { expect } from '@playwright/test';
import { answerConfirmation, waitForConfirmReady } from './confirm-dialog.js';

/**
 * From the filled creation form: « Enregistrer le brouillon », then, on the
 * saved article's own page, « Publier » and its confirmation. Returns once
 * the page says the article is online.
 *
 * @param {import('@playwright/test').Page} page the news editor, filled in
 */
export async function saveDraftThenPublish(page) {
    await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click();
    await page.waitForURL(/\/news\/\d+\/gerer$/, { waitUntil: 'domcontentloaded' });

    // The saved article: its actions are now offered, and « Publier » asks
    // for whom in the site's dialog before it saves and puts it online.
    await waitForConfirmReady(page);
    await page.getByRole('button', { name: 'Publier', exact: true }).click();
    await answerConfirmation(page);
    await expect(page.locator('[data-news-publication-line]')).toContainText('Publiée le');
}
