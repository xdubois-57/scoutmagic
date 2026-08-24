// End-to-end support: opening a settings screen's editing dialog.
//
// A settings page used to be a stack of forms standing open, and a spec
// could fill one straight away. design.md §1.9 made each section a read
// card with a « Modifier » that opens a dialog, so the fields are in the
// DOM but hidden — and Playwright refuses to fill a hidden control, with
// a timeout that blames the field rather than the dialog nobody opened.
//
// The same trap confirm-dialog.js documents applies here: Bootstrap's
// Modal.hide() does nothing while the opening transition runs, so acting
// on a dialog that is merely present rather than settled leaves it and
// its backdrop on screen, swallowing every later click. Playwright's own
// actionability check ("stable", i.e. not animating) is what this helper
// leans on — `toBeVisible()` alone would return too early.
import { expect } from '@playwright/test';

/**
 * Clicks the « Modifier »/« Ajouter » button that owns a dialog and waits
 * for the dialog to be usable.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} dialogId The dialog's id, without its `#`.
 * @returns {Promise<import('@playwright/test').Locator>} the dialog
 */
export async function openSectionEditor(page, dialogId) {
    const dialog = page.locator(`#${dialogId}`);

    await page.locator(`[data-bs-target="#${dialogId}"]`).first().click();
    await expect(dialog).toBeVisible();

    // section-editor.js focuses the dialog's first real field on
    // `shown.bs.modal` — the one event that means "Bootstrap has finished
    // opening this". Waiting for the focus to have landed is waiting for
    // that event without reaching into Bootstrap's internals.
    await expect(dialog.locator(':focus')).toHaveCount(1);

    return dialog;
}
