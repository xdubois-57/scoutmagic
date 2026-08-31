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

    // The trigger is a `data-bs-target` button, so what opens the dialog
    // is Bootstrap's own delegated data-api — not any code in this repo.
    // A click landing before bootstrap.bundle.min.js has run therefore
    // does nothing at all: no error, no effect, and the assertion below
    // then waits out its full ceiling on a dialog nobody asked to open,
    // reporting it as "hidden" as though the dialog were at fault.
    //
    // This is the readiness convention groups.js and sos-admin.js each
    // publish for their own listeners (`groups-js`, `sos-js`), applied to
    // the library this trigger depends on: window.bootstrap exists only
    // once that bundle has executed.
    //
    // It earned its place. Across five 20-sample batches of the DAST job,
    // three failures on three different specs — camps-place-and-review's
    // #review-modal, rental-lifecycle's #gestionnaires-edit and
    // rental-management's #tarification-edit — were all this helper's
    // click, and each looked like a separate dialog bug until they were
    // put side by side. `npm run e2e` loses the race far more rarely; the
    // scan proxies every asset through OWASP ZAP and a TLS terminator, so
    // the bundle lands later relative to everything else.
    await page.waitForFunction(() => typeof (/** @type {any} */ (window)).bootstrap !== 'undefined');

    await page.locator(`[data-bs-target="#${dialogId}"]`).first().click();
    await expect(dialog).toBeVisible();

    // Waiting for the focus to have landed is waiting for `shown.bs.modal`
    // without reaching into Bootstrap's internals: that event is when the
    // opening transition is over and the dialog is usable.
    //
    // `:focus-within`, not `:focus` on a descendant. Two things focus this
    // dialog at the same moment and either may win: Bootstrap's focus trap
    // focuses the modal ELEMENT (`trapElement.focus()`, plus a `focusin`
    // handler that pulls focus back to it), while section-editor.js's
    // `shown.bs.modal` handler focuses the first real field inside — and
    // focusFirstField() legitimately focuses nothing at all when every
    // control is hidden, disabled or readonly. A descendant-only match
    // therefore counted 0 whenever the root won, which made this helper
    // fail roughly one run in two: four failures across local runs and CI,
    // including one where the same spec passed in the e2e job and failed
    // in the DAST job on the identical commit. `:focus-within` matches the
    // element itself or any descendant, so it is true for every legitimate
    // outcome and still false until the dialog has actually been focused.
    await expect(page.locator(`#${dialogId}:focus-within`)).toHaveCount(1);

    return dialog;
}
