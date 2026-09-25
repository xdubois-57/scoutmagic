// Shared end-to-end helper: opening and closing a Bootstrap modal, and
// knowing when either is really over.
//
// Visibility is not the answer to "is it open?". A modal is visible from
// the first frame of its fade, and Bootstrap's Modal.hide() does nothing at
// all while the opening transition is still running — silently: the dialog
// stays on screen with its backdrop, and whatever the spec asserts next
// waits out its ceiling against a dialog that "will not close". Under
// `reducedMotion: 'reduce'` (playwright.config.js) the window shrinks to
// two 5 ms timers, and it is still there: a probe that calls hide() 10 ms
// after show() leaves the modal stuck 20 times out of 20, and only from
// 20 ms on does it close every time.
//
// Focus is not the answer either — support/section-editor.js records two
// versions that waited on it and lost. What Bootstrap says is: it fires
// `shown.bs.modal` when the opening is over and `hidden.bs.modal` when the
// closing is, backdrop included. This helper waits for exactly those.
//
// It records them rather than listening for one at a time, because a
// dialog can open without any click to hang a listener in front of:
// help-discovery.js offers its tip on page load, groups.js opens its
// dialog after a fetch. The recorder is an init script, so it is in place
// before the page's own scripts run, on every document the page loads
// after the first call — and it is also run once on the current document,
// for the common case of a dialog opened from a page already there.
//
// tests/Core/System/E2eOverlayGestureRatchetTest holds every scenario that
// names a modal to going through here (#520).
import { expect } from '@playwright/test';

/** Pages whose future documents already carry the recorder. */
const armed = new WeakSet();

/**
 * Writes Bootstrap's own verdict on each modal onto the element itself, as
 * `data-e2e-modal` — opening, shown, closing, hidden.
 *
 * Delegated on the document: Bootstrap's events bubble, so one listener
 * sees every modal, including one built after this ran (the site's
 * confirmation dialog is created on demand).
 */
function recordModalStates() {
    const w = /** @type {any} */ (window);
    if (w.__e2eModalRecorder) {
        return;
    }
    w.__e2eModalRecorder = true;

    /** @type {Array<[string, string]>} */
    const states = [
        ['show.bs.modal', 'opening'],
        ['shown.bs.modal', 'shown'],
        ['hide.bs.modal', 'closing'],
        ['hidden.bs.modal', 'hidden'],
    ];
    for (const [event, state] of states) {
        document.addEventListener(event, (e) => {
            if (e.target instanceof HTMLElement) {
                e.target.dataset.e2eModal = state;
            }
        });
    }
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function arm(page) {
    if (!armed.has(page)) {
        await page.addInitScript(recordModalStates);
        armed.add(page);
    }
    await page.evaluate(recordModalStates);
}

/**
 * Performs the gesture that opens a modal, and returns once Bootstrap says
 * the opening is over — never earlier.
 *
 * A click that lands before bootstrap.bundle.min.js has run does nothing
 * on a `data-bs-toggle` trigger, and a page script that builds its modal
 * does so only once the bundle is there too; either way the gesture waits
 * for the library first. It must therefore start from a page of the site
 * that has loaded, which every caller does.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} modalId the modal's id, without its `#`
 * @param {() => Promise<unknown>} gesture what opens it: a click, a
 *        submit, a navigation to a page that offers it by itself
 * @returns {Promise<import('@playwright/test').Locator>} the modal, open
 */
export async function openModal(page, modalId, gesture) {
    await page.waitForFunction(() => typeof (/** @type {any} */ (window)).bootstrap !== 'undefined');
    await arm(page);

    // A verdict left over from an earlier opening of the same dialog must
    // not answer for this one.
    await page.evaluate((id) => {
        const element = document.getElementById(id);
        if (element !== null) {
            delete element.dataset.e2eModal;
        }
    }, modalId);

    await gesture();

    const modal = page.locator(`#${modalId}`);
    await expect(modal).toHaveAttribute('data-e2e-modal', 'shown');

    return modal;
}

/**
 * Performs the gesture that closes a modal — its close button, or a choice
 * inside it that the page answers by hiding it — and returns once
 * Bootstrap says the closing is over, backdrop gone.
 *
 * `toBeHidden()` alone passes a step too early: the dialog is hidden
 * before its backdrop is removed, and a click aimed at the page in that
 * gap lands on the backdrop.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} modalId the modal's id, without its `#`
 * @param {() => Promise<unknown>} gesture what closes it
 */
export async function closeModal(page, modalId, gesture) {
    await arm(page);

    const modal = page.locator(`#${modalId}`);
    // Closing a dialog that has not finished opening is the one thing
    // Bootstrap refuses without a word.
    await expect(modal).toHaveAttribute('data-e2e-modal', 'shown');

    await gesture();

    await expect(modal).toHaveAttribute('data-e2e-modal', 'hidden');
    await expect(modal).toBeHidden();
}
