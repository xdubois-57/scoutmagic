// End-to-end: the installed app holds an exact list of pages offline —
// downloaded without being opened, with their images — and nothing else
// (#883).
//
// WHY THIS IS A BROWSER TEST
// ----------------------------------------------------------------------------
// The pass runs in public/sw.js, against the real manifest of a real
// session, writing real Cache Storage that a real navigation then reads
// with the network cut. tests/js/sw-offline-pass.test.js covers its rules
// one by one; only this shows them adding up to what the maintainer asked
// for: « les images d'une page disponible hors ligne s'affichent hors
// ligne », and a page off the list is never kept.
//
// Standalone mode is faked the only way a headless browser can: the
// matchMedia query the scripts read answers `true` from an init script.
// This file lives in the `service-worker` project, the one place workers
// run (tests/e2e/playwright.config.js).
//
// ORDERING
// ----------------------------------------------------------------------------
// The article it creates is deleted at the end, so no other spec reads it.
import { expect, test } from '@playwright/test';

import { loginAsAdmin } from '../../support/admin-login.js';
import { answerConfirmation, waitForConfirmReady } from '../../support/confirm-dialog.js';
import { answerCookieBanner } from '../../support/cookie-banner.js';
import { openModal } from '../../support/modal.js';
import { pngBuffer } from '../../support/png.js';
import { scaled } from '../../support/timeouts.js';

const ARTICLE_TITLE = `Hors ligne ${Date.now()}`;
const PASS_DONE_KEY = 'scoutmagic-offline-prefetch';

/** @param {import('@playwright/test').BrowserContext} context */
async function fakeTheInstalledApp(context) {
    // The installed app invites to notifications while the permission is
    // undecided (push-invitation.js), and that dialog would sit over every
    // click below. Answered up front: it is not what this spec is about.
    await context.grantPermissions(['notifications']);
    await context.addInitScript(() => {
        const original = window.matchMedia.bind(window);
        window.matchMedia = (query) => (query.includes('display-mode: standalone')
            ? { matches: true, media: query, onchange: null, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, dispatchEvent() { return false; } }
            : original(query));
    });
}

/**
 * Starts a fresh launch and waits for the service worker to say its pass
 * finished — offline-prefetch.js remembers a launch only then.
 *
 * @param {import('@playwright/test').Page} page
 */
async function runAPass(page) {
    await page.evaluate((key) => sessionStorage.removeItem(key), PASS_DONE_KEY);
    await page.goto('/', { waitUntil: 'load' });
    await expect.poll(
        () => page.evaluate((key) => sessionStorage.getItem(key), PASS_DONE_KEY),
        { message: 'the refresh pass never finished', timeout: 60_000 },
    ).not.toBeNull();
}

/**
 * The paths the content cache holds.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string[]>}
 */
function cachedPaths(page) {
    return page.evaluate(async () => {
        const paths = [];
        for (const name of (await caches.keys()).filter((n) => n.startsWith('content-'))) {
            for (const request of await (await caches.open(name)).keys()) {
                const url = new URL(request.url);
                paths.push(url.pathname + url.search);
            }
        }
        return paths;
    });
}

/**
 * Every image of the page has really rendered.
 *
 * @param {import('@playwright/test').Locator} images
 */
async function expectRendered(images) {
    const count = await images.count();
    expect(count, 'no image to check').toBeGreaterThan(0);
    for (let i = 0; i < count; i++) {
        await expect.poll(() => images.nth(i).evaluate((img) => img.complete && img.naturalWidth)).toBeGreaterThan(0);
    }
}

test('the list is held offline without being opened, with its images, and nothing else is kept', async ({ page, context }) => {
    // A whole journey — an article published, two passes run, the network
    // cut and restored, the article back to draft and deleted: past the
    // default 60 s on a CI runner that measures coverage, while each step
    // keeps its own timeout.
    test.setTimeout(scaled(120_000));
    await fakeTheInstalledApp(context);

    /** @type {string[]} */
    const manifestRequests = [];
    context.on('request', (request) => {
        if (new URL(request.url()).pathname === '/api/offline/manifest') {
            manifestRequests.push(request.url());
        }
    });

    // A second load, so the worker installed by the first controls the page.
    await page.goto('/', { waitUntil: 'load' });
    await answerCookieBanner(page, { accept: true });
    await loginAsAdmin(page);
    await page.goto('/', { waitUntil: 'load' });
    await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
    // The launch's own pass, first: a later request would join it while it
    // runs, with a list fetched before the article below existed.
    await runAPass(page);

    // A public article with a cover image, so the first page of the news
    // has an article — and an image — to hold.
    await page.goto('/news/manage', { waitUntil: 'domcontentloaded' });
    await page.locator('main').getByRole('link', { name: 'Nouvel article' }).first().click();
    await page.getByLabel("Titre de l'article").fill(ARTICLE_TITLE);
    await page.getByLabel('Résumé en une phrase').fill('Lu dans le train.');
    await page.locator('#image').setInputFiles({ name: 'affiche.png', mimeType: 'image/png', buffer: pngBuffer(40, 40) });
    await page.getByRole('radio', { name: 'Public', exact: true }).check();
    // Published straight away (#845): a draft is on no list, so nothing
    // would hold it.
    await waitForConfirmReady(page);
    await page.getByRole('button', { name: 'Publier', exact: true }).click();
    await answerConfirmation(page);
    await page.waitForURL(/\/news\/\d+\/gerer$/, { waitUntil: 'domcontentloaded' });
    const articleUrl = new URL(page.url()).pathname.replace(/\/gerer$/, '');

    try {
        await runAPass(page);

        // A management page under the same prefix, visited online in the
        // installed app: never kept.
        await page.goto('/news/manage', { waitUntil: 'load' });
        await page.goto('/', { waitUntil: 'load' });
        const held = await cachedPaths(page);
        expect(held).toContain(articleUrl);
        expect(held).toContain('/trombinoscope');
        expect(held).toContain('/chefs/staffs');
        expect(held).toContain('/aide/installer-application');
        expect(held, 'a page off the list was cached').not.toContain('/news/manage');
        expect(held.some((path) => /^\/files\/\d+$/.test(path)), 'an original file was cached').toBe(false);

        // The same launch asks for no second pass: each page finds the
        // launch done and asks nothing, even once idle — when it would ask.
        const passes = manifestRequests.length;
        for (const path of ['/contact', '/account', '/notifications']) {
            await page.goto(path, { waitUntil: 'load' });
            expect(await page.evaluate((key) => sessionStorage.getItem(key), PASS_DONE_KEY)).not.toBeNull();
            await page.evaluate(() => new Promise((resolve) => requestIdleCallback(() => resolve(null), { timeout: 5_000 })));
        }
        expect(manifestRequests).toHaveLength(passes);

        // Offline emulation alone lets the worker's navigation preload
        // through in Chromium: every request is refused as well, so what
        // answers below can only be the worker's own cache.
        await context.setOffline(true);
        await context.route('**/*', (route) => route.abort('internetdisconnected'));

        // Never opened before the network went: the article, its cover.
        await page.goto(articleUrl, { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { level: 1, name: ARTICLE_TITLE })).toBeVisible();
        // The worker's own banner: this is its stored copy, not the network.
        await expect(page.getByText(/Version hors ligne du/)).toBeVisible();
        await expectRendered(page.locator('main img[src^="/files/"]'));

        // The trombinoscope, the Staffs page and a help topic.
        await page.goto('/trombinoscope', { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.goto('/chefs/staffs', { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.goto('/aide/installer-application', { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

        // Read-only: a form is there, its submit is not.
        await page.goto('/account', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('form:not([data-offline-safe]) button[type="submit"]').first()).toBeDisabled();

        // The page off the list: the screen says why.
        await page.goto('/news/manage', { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { name: 'Pas de connexion' })).toBeVisible();
        await expect(page.locator('#offline-conditions')).toContainText('cookies fonctionnels sont activés');
    } finally {
        await context.unrouteAll({ behavior: 'ignoreErrors' });
        await context.setOffline(false);
        // Only a draft is deleted (#845): back to draft first.
        await page.goto(`${articleUrl}/gerer`, { waitUntil: 'load' });
        await waitForConfirmReady(page);
        await page.getByRole('button', { name: 'Repasser en brouillon' }).click();
        await answerConfirmation(page);
        await page.waitForURL(/\/news\/\d+\/gerer$/, { waitUntil: 'load' });
        const deleteModal = await openModal(page, 'news-delete-modal', () =>
            page.getByRole('button', { name: 'Supprimer', exact: true }).first().click());
        await deleteModal.locator('#news-delete-confirm-btn').click();
        await page.waitForURL('**/news/manage', { waitUntil: 'domcontentloaded' });
    }
});
