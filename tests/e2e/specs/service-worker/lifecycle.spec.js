// End-to-end: public/sw.js as a service worker, not as a module.
//
// Why this file exists at all. `tests/js/sw.test.js` exercises the real
// file and says, in its own first lines, what it cannot do: « No PHP
// server, no MySQL, no real network and no real Service Worker runtime:
// fetch, Response and the Cache Storage API are all mocked below ». That
// is the right call under jsdom, which has no worker runtime. And
// `playwright.config.js` blocked workers for the whole suite, with its own
// good reason. Both statements were true; neither said the other existed,
// so the worker's LIFECYCLE — install, activate, a request answered out of
// a real Cache Storage, a navigation made with the network down — was
// exercised nowhere, and no red anywhere would have announced it (issue
// #452).
//
// This spec runs under the `service-worker` project, the only one with
// `serviceWorkers: 'allow'`. Everything else in specs/ keeps the block.
import { expect, test } from '@playwright/test';

/**
 * The registration base.html.twig performs on window load, waited out
 * until the worker is not merely present but activated.
 *
 * `navigator.serviceWorker.ready` is not that promise, and the difference
 * is measurable rather than theoretical: it resolves as soon as there IS
 * an active worker, and this spec read « activating » from it on its
 * first run — activate()'s own waitUntil (the cache purge) was still in
 * flight. Reading the state once would therefore have been a race, and
 * reading the precache before it finished would have been a second one.
 *
 * `controller` is a separate question, asked separately below: the first
 * page load of a fresh registration activates a worker that does not yet
 * control it, and a spec conflating the two would be flaky for a reason
 * that has nothing to do with the worker.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<{state: string, scope: string}>}
 */
async function activatedWorker(page) {
    const state = () => page.evaluate(async () => {
        const registration = await navigator.serviceWorker.ready;

        return registration.active ? registration.active.state : 'none';
    });

    await expect
        .poll(state, { message: 'install and activate both have to have run' })
        .toBe('activated');

    return page.evaluate(async () => {
        const registration = await navigator.serviceWorker.ready;

        return { state: registration.active.state, scope: registration.scope };
    });
}

test('the worker installs, activates and precaches the shell', async ({ page }) => {
    await page.goto('/', { waitUntil: 'load' });

    const worker = await activatedWorker(page);
    expect(worker.scope, 'the worker sits at the web root, never under /assets/').toBe(`${new URL('/', page.url()).href}`);

    // What install() actually wrote, read back from a real Cache Storage.
    const shell = await page.evaluate(async () => {
        const names = await caches.keys();
        const name = names.find((candidate) => candidate.startsWith('app-shell-'));
        if (name === undefined) {
            return null;
        }

        const entries = await (await caches.open(name)).keys();

        return { name, paths: entries.map((request) => new URL(request.url).pathname) };
    });

    expect(shell, 'install() must open a cache whose name carries the app version').not.toBeNull();
    // A sample rather than the whole list: this spec is about the
    // lifecycle running, and tests/js/sw.test.js already holds
    // APP_SHELL_URLS against base.html.twig entry by entry.
    expect(shell.paths).toContain('/offline');
    expect(shell.paths).toContain('/assets/css/app.css');
    expect(shell.paths).toContain('/assets/js/api.js');
});

test('a navigation with the network down is answered from the cache', async ({ page, context }) => {
    await page.goto('/', { waitUntil: 'load' });
    await activatedWorker(page);

    // The worker activates on the first load but does not control that
    // page. The second load is the one it answers — which is also how a
    // real visitor meets it, on their next navigation.
    await page.goto('/', { waitUntil: 'load' });
    await expect
        .poll(
            () => page.evaluate(() => navigator.serviceWorker.controller !== null),
            { message: 'the worker must be controlling the page before the network is cut' },
        )
        .toBe(true);

    await context.setOffline(true);

    try {
        // A page nothing cached, asked for with no network at all. Without
        // a worker this is the browser's own error interstitial; with one,
        // handleNavigate() answers the precached /offline page.
        await page.goto('/contact', { waitUntil: 'domcontentloaded' });

        await expect(
            page.getByRole('heading', { name: 'Pas de connexion' }),
            'the refusal a visitor sees offline is the application\'s page, never the browser\'s',
        ).toBeVisible();
    } finally {
        await context.setOffline(false);
    }
});

test('a shell asset is served from the cache while the network is down', async ({ page, context }) => {
    await page.goto('/', { waitUntil: 'load' });
    await activatedWorker(page);
    await page.goto('/', { waitUntil: 'load' });
    await expect
        .poll(() => page.evaluate(() => navigator.serviceWorker.controller !== null))
        .toBe(true);

    await context.setOffline(true);

    try {
        const stylesheet = await page.evaluate(async () => {
            const response = await fetch('/assets/css/app.css');

            return { ok: response.ok, length: (await response.text()).length };
        });

        expect(stylesheet.ok, 'a precached stylesheet must still answer with no network').toBe(true);
        expect(stylesheet.length, 'and answer with the file, not an empty body').toBeGreaterThan(0);
    } finally {
        await context.setOffline(false);
    }
});
