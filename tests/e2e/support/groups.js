// Shared end-to-end helpers for the groups module: open the group-creation
// form, wait until groups.js is actually running, and unfold the message
// composer it folds away.
//
// All three are needed by every scenario that touches a group — specs/
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

/**
 * Unfold the message composer on a group page.
 *
 * modules/groups/views/show.html.twig renders the composer as a single
 * tinted line — an avatar, « Écrire un message… » and the photo and poll
 * icons — and groups.js folds the real form away behind it as soon as it
 * runs. Every scenario that writes a message therefore has to ask for the
 * form first, exactly as a member does.
 *
 * Always call it AFTER waitForGroupsJsReady(): the fold happens inside
 * that same top-level evaluation, so before it the bar is still hidden and
 * this click would have nothing to land on.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function openComposer(page) {
    await page.getByRole('button', { name: 'Écrire un message…' }).click();
    await expect(page.getByLabel('Écrire un message')).toBeVisible();
}

/**
 * Closes `#groups-detail-modal` — the one dialog every "who…?" list on a
 * group page shares — and waits for it to be gone.
 *
 * The close has to wait for the OPEN to have finished first, which is what
 * this exists to encode. Bootstrap's Modal.hide() does nothing at all
 * while the opening transition is still running, silently: the dialog
 * stays on screen with its backdrop, and the assertion after it waits out
 * its whole ceiling reporting a dialog that "will not close".
 *
 * The window is easy to land in here because the dialog is filled from a
 * fetch: groups.js calls show() and then awaits the list, so a spec that
 * asserts on the arriving content and closes immediately is timing its
 * click against the response rather than against the animation.
 * toBeVisible() does not stand in for that — it passes the instant `.show`
 * lands, which is the START of the fade.
 *
 * `:focus-within` is the proof the opening finished: Bootstrap focuses the
 * modal when it fires `shown.bs.modal`, and matching the element or any
 * descendant covers both that and anything the dialog focuses itself —
 * the same reasoning, and the same pitfall, as support/section-editor.js
 * documents at greater length.
 *
 * This cost two failures on this branch, in two different specs
 * (groups-discussion and groups-mentions), each looking like a dialog of
 * its own until they were put side by side. An earlier reading that
 * seemed to rule the transition out had in fact been taken five seconds
 * after the click, by which time the flag it read had long since cleared —
 * it said nothing about the moment that mattered.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function closeDetailDialog(page) {
    const dialog = page.locator('#groups-detail-modal');

    await expect(page.locator('#groups-detail-modal:focus-within')).toHaveCount(1);
    await dialog.locator('.btn-close').click();
    await expect(dialog).toBeHidden();
}
