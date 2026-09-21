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

    // Arm the listener BEFORE the click, so the event cannot fire before
    // anything is listening. That ordering is the whole fix; doing it
    // after the click is the race, not a shortcut.
    await page.evaluate((id) => {
        const target = document.getElementById(id);
        if (target === null) {
            return;
        }

        const shown = /** @type {any} */ (window).__sectionEditorShown ??= {};
        shown[id] = false;
        target.addEventListener('shown.bs.modal', () => { shown[id] = true; }, { once: true });
    }, dialogId);

    await page.locator(`[data-bs-target="#${dialogId}"]`).first().click();
    await expect(dialog).toBeVisible();

    // What this waits for is `shown.bs.modal` itself — the event Bootstrap
    // fires when the opening transition is over and the dialog is usable.
    //
    // Two earlier versions waited for the FOCUS to have landed instead,
    // as a proxy for that event. Both lost, for the same underlying
    // reason: focus is not guaranteed to land at all. `:focus` on a
    // descendant counted 0 whenever Bootstrap's focus trap won the race
    // and focused the modal root; `:focus-within` then covered that case
    // but still counts 0 when nothing inside is focusable —
    // focusFirstField() legitimately focuses nothing when every control is
    // hidden, disabled or readonly — or when the browser has no focus to
    // give, which is why this kept failing in the DAST job while the same
    // spec passed in the e2e job on the identical commit. Under OWASP ZAP
    // and a TLS terminator the timings shift enough to expose it.
    //
    // A proxy that is usually true is worse than no check: it passes for
    // the wrong reason and fails for reasons that have nothing to do with
    // the dialog. Waiting for the event removes the proxy entirely, and
    // it needs no Bootstrap internals — `shown.bs.modal` is public API.
    await page.waitForFunction(
        (id) => /** @type {any} */ (window).__sectionEditorShown?.[id] === true,
        dialogId
    );

    return dialog;
}
