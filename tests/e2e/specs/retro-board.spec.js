// End-to-end: a rétrospective board — anonymous words, live polling,
// votes, a chief's moderation, and the token that IS the access control.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The retro board is the codebase's clearest case of two renderers that
// must never diverge: modules/retro/views/_comment.html.twig draws a
// comment server-side on first load, and public/assets/js/retro-board.js
// re-draws THE SAME comment client-side on every poll response — its own
// header says it "mirrors _comment.html.twig exactly". Vitest checks the
// JS half against a hand-written expectation; only a browser fed by the
// real /r/{token}/poll JSON can say the two halves still agree. Around
// that sit behaviours that are each browser-only:
//
//   - The polling loop itself: a second visitor's word appearing on a
//     page nobody reloaded, at the interval the server configured.
//   - youVoted survival: the like button must still be filled after the
//     poll re-renders the card (the regression _comment.html.twig warns
//     about by name).
//   - Anonymous identity: the voter is a functional cookie issued through
//     consent, falling back to the session when consent is refused —
//     "best-effort, consent refused never blocks voting" (module spec).
//     One visitor accepts, the other refuses, and both must get to vote.
//   - Moderation asymmetry: after a chief hides a word, the chief still
//     reads it (struck through) while an anonymous visitor's next poll
//     replaces body AND votes with placeholders — a data-minimisation
//     rule enforced in serializeComment(), observable only across two
//     differently-privileged browsers.
//   - The unguessable token is the whole access control: regenerating the
//     link must kill the old URL immediately (404) while the new one
//     carries every word and vote across.
//
// WHAT IT DELIBERATELY LEAVES ALONE
// ----------------------------------------------------------------------------
// The AI features (shorten, moderation warning, closing summary): the
// harness's instance has no LLM provider configured, so ai_available is
// false and the board renders none of those controls — their service
// logic is Tests\Modules\Retro territory. The points-budget vote mode is
// SummaryService/vote-repository logic with the same wire shape as
// unlimited mode; the like mode drives the shared path.
import { expect, test } from '@playwright/test';

import { answerConfirmation } from '../support/confirm-dialog.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { scaled } from '../support/timeouts.js';

const BOARD_TITLE = `Rétro camp E2E ${Date.now()}`;
const WORD_FROM_FIRST = 'Le feu de camp du samedi soir était magique';
const WORD_FROM_SECOND = 'Prévoir plus de temps pour le rangement';

/** Assertions that depend on the other browser's poll cycle (the harness
 * default interval, bounded below by 3 s in retro-board.js) get headroom
 * over the expect default. */
const POLL = { timeout: scaled(15_000) };

/**
 * The board column a word lives in, addressed the way the page's own
 * script does (data-comment-list) — the three column keys are a contract
 * between board.html.twig and retro-board.js.
 *
 * @param {import('@playwright/test').Page} page
 * @param {'good' | 'improve' | 'suggestion'} column
 */
function columnList(page, column) {
    return page.locator(`[data-comment-list="${column}"]`);
}

/**
 * Post a word through a column's real composer form.
 *
 * @param {import('@playwright/test').Page} page
 * @param {'good' | 'improve' | 'suggestion'} column
 * @param {string} body
 */
async function addWord(page, column, body) {
    const form = page.locator(`.retro-comment-form[data-column="${column}"]`);
    await form.locator('textarea').fill(body);
    await form.getByRole('button', { name: 'Ajouter' }).click();
    await expect(columnList(page, column).getByText(body)).toBeVisible();
}

test('two anonymous visitors write and vote on a live board, a chief moderates, and the regenerated link kills the old one', async ({ page, browser }) => {
    // Three browsers synchronising through a multi-second polling loop —
    // most of this scenario's clock is deliberate waiting, and CI's
    // coverage instrumentation stretches it further.
    test.setTimeout(scaled(180_000));

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

    // ---------------------------------------------------------------
    // A chief creates the board through the real form. The defaults are
    // the interesting configuration: like-mode votes, cookie-based
    // anti-duplicate — the anonymous path.
    // ---------------------------------------------------------------
    await loginAsAdmin(page);
    await page.goto('/retro', { waitUntil: 'domcontentloaded' });
    await page.getByRole('link', { name: 'Nouvelle' }).click();
    await page.waitForURL('**/retro/create', { waitUntil: 'domcontentloaded' });

    await page.getByLabel('Titre').fill(BOARD_TITLE);
    await page.getByRole('button', { name: 'Créer la rétrospective' }).click();
    await page.waitForURL('**/retro', { waitUntil: 'domcontentloaded' });

    // The participation link lives on the board's own configuration page.
    const boardCard = page.locator('.card', { hasText: BOARD_TITLE });
    await expect(boardCard).toBeVisible();
    await boardCard.getByRole('link', { name: 'Configurer' }).click();
    await page.waitForURL(/\/retro\/\d+\/edit$/, { waitUntil: 'domcontentloaded' });

    // The participation link is a short URL (/s/{code}) fronting the
    // board's /r/{token} page — following it below therefore also drives
    // the short-link redirector for real.
    const editUrl = page.url();
    const publicUrl = (await page.locator('.text-break', { hasText: '/s/' }).innerText()).trim();
    expect(publicUrl).toMatch(/\/s\/[A-Za-z0-9]+$/);

    // ---------------------------------------------------------------
    // Two anonymous visitors join. The first accepts cookies (voter
    // identity = the retro_voter functional cookie), the second refuses
    // (identity falls back to the session) — consent must gate the
    // cookie, never the ability to participate.
    // ---------------------------------------------------------------
    const firstVisitor = await browser.newContext();
    const secondVisitor = await browser.newContext();
    try {
        const first = await firstVisitor.newPage();
        const second = await secondVisitor.newPage();
        /** @type {string[]} */
        const visitorErrors = [];
        for (const visitorPage of [first, second]) {
            visitorPage.on('pageerror', (error) => visitorErrors.push(error.message));
        }

        await first.goto(publicUrl, { waitUntil: 'domcontentloaded' });
        await expect(first.getByRole('heading', { name: BOARD_TITLE })).toBeVisible();
        await first.getByRole('button', { name: 'Tout accepter' }).click();
        await expect(first.locator('#cookie-banner')).toBeHidden();

        await second.goto(publicUrl, { waitUntil: 'domcontentloaded' });
        await second.getByRole('button', { name: 'Tout refuser' }).click();
        await expect(second.locator('#cookie-banner')).toBeHidden();

        // ---------------------------------------------------------------
        // Words cross between the two open pages by polling alone.
        // ---------------------------------------------------------------
        await addWord(first, 'good', WORD_FROM_FIRST);
        await expect(columnList(second, 'good').getByText(WORD_FROM_FIRST), 'the poll loop must deliver the other visitor\'s word').toBeVisible(POLL);

        await addWord(second, 'improve', WORD_FROM_SECOND);
        await expect(columnList(first, 'improve').getByText(WORD_FROM_SECOND)).toBeVisible(POLL);

        // The column counter is part of the re-render contract.
        await expect(second.locator('[data-count-for="good"]')).toHaveText('1', POLL);

        // ---------------------------------------------------------------
        // Both visitors vote — the consent-refusing one included. The
        // like must survive the next poll's wholesale re-render of the
        // card (youVoted), not silently reset.
        // ---------------------------------------------------------------
        const firstWordForSecond = second.locator('.retro-comment', { hasText: WORD_FROM_FIRST });
        await firstWordForSecond.locator('.retro-vote-like').click();
        await expect(firstWordForSecond.locator('[data-comment-votes]')).toHaveText('1');

        const firstWordForFirst = first.locator('.retro-comment', { hasText: WORD_FROM_FIRST });
        await expect(firstWordForFirst.locator('[data-comment-votes]')).toHaveText('1', POLL);
        await firstWordForFirst.locator('.retro-vote-like').click();
        await expect(firstWordForFirst.locator('[data-comment-votes]')).toHaveText('2');

        // Both votes went through — now the identity behind each is
        // observable in the jars: the consenting visitor votes under the
        // functional retro_voter cookie, the refusing one under nothing
        // but their session.
        expect(
            (await firstVisitor.cookies()).some((cookie) => cookie.name === 'retro_voter'),
            'with consent, the voter identity is the functional retro_voter cookie',
        ).toBe(true);
        expect(
            (await secondVisitor.cookies()).filter((cookie) => cookie.name === 'retro_voter'),
            'without consent, no retro_voter cookie may exist',
        ).toEqual([]);

        // Survive at least one full poll cycle, then check the like is
        // still drawn as "yours" — the exact regression the comment
        // partial documents.
        await expect(firstWordForSecond.locator('[data-comment-votes]')).toHaveText('2', POLL);
        await expect(firstWordForSecond.locator('.retro-vote-like')).toHaveClass(/btn-danger/);

        // ---------------------------------------------------------------
        // The chief opens the same public page and hides the second
        // visitor's word. The chief keeps reading it; the visitor's next
        // poll must blank both the text and the count.
        // ---------------------------------------------------------------
        await page.goto(publicUrl, { waitUntil: 'domcontentloaded' });
        const wordForChief = page.locator('.retro-comment', { hasText: WORD_FROM_SECOND });
        await wordForChief.getByRole('button', { name: 'Masquer' }).click();
        await expect(wordForChief.getByText('Masqué — visible par le chef d\'unité uniquement')).toBeVisible();
        await expect(wordForChief.locator('[data-comment-body]')).toHaveText(WORD_FROM_SECOND);

        const hiddenForVisitor = second.locator('.retro-comment', { hasText: 'Mot masqué.' });
        await expect(hiddenForVisitor).toBeVisible(POLL);
        await expect(columnList(second, 'improve').getByText(WORD_FROM_SECOND)).toHaveCount(0);
        // Data minimisation, not just blanking: the placeholder card for a
        // non-chief carries neither the text nor the vote count.
        await expect(hiddenForVisitor.locator('[data-comment-votes]')).toHaveCount(0);

        // Reversible, as the chief's own warning promises: unhide
        // restores the word for everyone.
        await wordForChief.getByRole('button', { name: 'Réafficher' }).click();
        await expect(wordForChief.getByRole('button', { name: 'Masquer' })).toBeVisible();
        await expect(columnList(second, 'improve').getByText(WORD_FROM_SECOND)).toBeVisible(POLL);

        // ---------------------------------------------------------------
        // Regenerating the participation link: the old token dies on the
        // spot, the new page carries every word and vote across.
        // ---------------------------------------------------------------
        await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
        // The form's data-confirm, answered through the site's own modal
        // (base.html.twig → window.ScoutMagicConfirm). Answered explicitly
        // rather than by autoConfirm(): it is the only confirmation in the
        // scenario, and being warned before an old link dies for everybody
        // is part of what this step is about.
        await page.getByRole('button', { name: 'Régénérer le lien' }).click();
        await answerConfirmation(page);
        await page.waitForURL(/\/retro\/\d+\/edit$/, { waitUntil: 'domcontentloaded' });

        const newPublicUrl = (await page.locator('.text-break', { hasText: '/s/' }).innerText()).trim();
        expect(newPublicUrl).not.toBe(publicUrl);

        const deadResponse = await second.goto(publicUrl, { waitUntil: 'domcontentloaded' });
        expect(deadResponse?.status(), 'the regenerated-away token must be gone, not redirected').toBe(404);

        await second.goto(newPublicUrl, { waitUntil: 'domcontentloaded' });
        await expect(second.getByRole('heading', { name: BOARD_TITLE })).toBeVisible();
        await expect(columnList(second, 'good').getByText(WORD_FROM_FIRST)).toBeVisible();
        await expect(
            second.locator('.retro-comment', { hasText: WORD_FROM_FIRST }).locator('[data-comment-votes]'),
        ).toHaveText('2');

        expect(visitorErrors, 'uncaught JavaScript error in a visitor\'s browser').toEqual([]);
    } finally {
        await firstVisitor.close();
        await secondVisitor.close();
    }

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
