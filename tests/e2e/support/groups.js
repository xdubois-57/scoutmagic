// Shared end-to-end helpers for the groups module: open the group-creation
// form, and wait until groups.js is actually running.
//
// Both are needed by every scenario that touches a group — specs/
// groups-discussion.spec.js writes in one, specs/groups-management.spec.js
// runs one — and each is a fact about the module rather than about either
// scenario, so they live here instead of once per file.
import { expect } from '@playwright/test';

/**
 * Open the "Créer un groupe" disclosure on /groups.
 *
 * The form is folded away by default (modules/groups/views/list.html.twig):
 * /groups is a list of the groups you are in, not a form for one that does
 * not exist yet. A native <details>, so this is the same click a chief
 * makes and it needs no JavaScript at all.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function openCreateGroupForm(page) {
    await page.locator('summary', { hasText: 'Créer un groupe' }).click();
    await expect(page.getByLabel('Nom du groupe')).toBeVisible();
}

/**
 * Wait for groups.js's own "I am running" signal (public/assets/js/
 * groups.js sets `document.documentElement.classList.add('groups-js')` once
 * its own, deferred, top-level code has finished running).
 *
 * Creating a group is a real, unenhanced form POST (list.html.twig never
 * loads groups.js), so it always lands on a freshly navigated
 * /groups/{id} — a real page, with the deferred script only just starting
 * to load. Without this wait, an automated click on "Publier" straight
 * after can beat that load: the composer's own submit handler (the
 * fetch()-based, no-reload path) is not attached yet, so the browser falls
 * back to a genuine, unenhanced form submit — not a bug, the same intended
 * progressive-enhancement fallback every dynamic action here has — but it
 * reloads the page a second time and makes everything timed after it
 * non-deterministic: on a slow enough run the SAME race can then repeat on
 * that reload, for instance losing the `change` event a later
 * `setInputFiles()` fires on a reply's image input. A real member never
 * hits this: reading the form and typing a message takes far longer than
 * the script needs to finish loading.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function waitForGroupsJsReady(page) {
    await page.waitForFunction(() => document.documentElement.classList.contains('groups-js'));
}
