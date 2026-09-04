// End-to-end: the installed application pre-downloads its offline pages
// ONCE per launch, never on every page.
//
// Why this is a browser test: public/assets/js/offline-prefetch.js is
// unit-tested in isolation (tests/js/offline-prefetch.test.js), but what
// the site does to a server is the sum of a real page, its real config
// blob, sessionStorage that really survives a navigation, and a real
// service worker — none of which jsdom has. The regression this guards is
// the one measured in docs/chantiers/CHANTIER-performance.md: every
// navigation of the installed app used to re-render the whole offline
// whitelist on the server (34 documents per tap against 4 in a tab).
//
// Standalone mode is faked the only way a headless browser can: the
// matchMedia query the script reads is answered `true` from an init
// script. Nothing else about the page is different from a real launch.
import { expect, test } from '@playwright/test';

import { loginAsMember } from '../support/admin-login.js';
import { answerCookieBanner } from '../support/cookie-banner.js';

test('the installed app fetches the offline manifest once per launch, not once per page', async ({ page, context }) => {
    await context.addInitScript(() => {
        const original = window.matchMedia.bind(window);
        window.matchMedia = (query) => (query.includes('display-mode: standalone')
            ? { matches: true, media: query, onchange: null, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, dispatchEvent() { return false; } }
            : original(query));
    });

    /** @type {string[]} */
    const manifestRequests = [];
    page.on('request', (request) => {
        if (new URL(request.url()).pathname === '/api/offline/manifest') {
            manifestRequests.push(request.url());
        }
    });

    await page.goto('/', { waitUntil: 'domcontentloaded' });
    // Functional consent is what the offline cache, and its pre-download, are gated on.
    await answerCookieBanner(page, { accept: true });
    await loginAsMember(page);

    // The first page of the launch: the pre-download runs, in idle time, after load.
    await page.goto('/', { waitUntil: 'load' });
    await expect.poll(() => manifestRequests.length, {
        message: 'the first page of a launch must pre-download the offline manifest',
        timeout: 15_000,
    }).toBe(1);

    // Three more pages of the same launch: nothing more is fetched.
    for (const path of ['/contact', '/account', '/notifications']) {
        await page.goto(path, { waitUntil: 'load' });
        await page.waitForTimeout(2_000);
    }
    expect(manifestRequests, 'the next pages of the same launch must not run the pre-download again').toHaveLength(1);
});
