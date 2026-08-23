// End-to-end support: answering the site's confirmation dialog.
//
// Destructive actions used to be confirmed by window.confirm(), so every
// spec that deleted something wrote `page.on('dialog', d => d.accept())`
// and Playwright's dialog handler did the rest. design.md §7.5 replaced
// the native box with the site's own modal (public/assets/js/confirm.js),
// which Playwright cannot see as a dialog at all: the handler simply never
// fires, the click resolves nothing, and the spec fails on a timeout that
// blames the assertion rather than the dialog.
//
// autoConfirm() covers both, on purpose. A handful of templates still
// carry their confirmation inside an inline <script> that has not been
// extracted yet (they are enumerated in
// tests/Core/View/UxConventionsTest::NATIVE_DIALOG_ALLOWLIST, and the list
// only shrinks), so during the migration a spec can legitimately meet
// either kind. Once that allowlist is empty, the native branch here can go
// with it.

/**
 * Answers every confirmation on the page with "yes", for the rest of the
 * test — the equivalent of the old `page.on('dialog', d => d.accept())`.
 *
 * Must be called BEFORE the navigation that renders the page, because the
 * observer is installed as an init script.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function autoConfirm(page) {
    // Templates whose script has not been extracted yet.
    page.on('dialog', (dialog) => dialog.accept());

    // The site's dialog: click its confirmation button the moment it is
    // inserted. A MutationObserver rather than polling, so the click lands
    // in the same task the dialog was built in and no spec has to wait.
    await page.addInitScript(() => {
        const clickConfirmation = () => {
            const button = document.querySelector(
                '#sm-confirm-modal .modal-footer .btn:last-child',
            );
            if (button instanceof HTMLElement) {
                button.click();
            }
        };
        const observer = new MutationObserver(clickConfirmation);
        const start = () => observer.observe(document.body, { childList: true });
        if (document.body) {
            start();
        } else {
            document.addEventListener('DOMContentLoaded', start);
        }
    });
}

/**
 * Records every toast the page shows, for the rest of the test.
 *
 * The counterpart of autoConfirm() for the other half of design.md §7.5:
 * failures that used to arrive as window.alert() — which Playwright
 * dismisses silently, so "the button did nothing" and "the button said why
 * it did nothing" looked identical — now arrive as a toast. A toast
 * disappears on a timer, so it is captured as it is inserted rather than
 * read at assertion time.
 *
 * Must be called BEFORE the navigation that renders the page.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {() => Promise<string[]>} the messages shown so far, in order
 */
export async function collectToasts(page) {
    await page.addInitScript(() => {
        window.__smToasts = [];
        const record = () => {
            document.querySelectorAll('.toast-body:not([data-e2e-seen])').forEach((body) => {
                body.setAttribute('data-e2e-seen', '1');
                window.__smToasts.push(body.textContent.trim());
            });
        };
        const observer = new MutationObserver(record);
        const start = () => observer.observe(document.body, { childList: true, subtree: true });
        if (document.body) {
            start();
        } else {
            document.addEventListener('DOMContentLoaded', start);
        }
    });

    return async () => page.evaluate(() => window.__smToasts || []);
}

/**
 * Waits for the site's confirmation dialog and answers it, once.
 *
 * Use this instead of autoConfirm() when the test needs to SEE the
 * confirmation — to assert its wording, or when only one of several
 * actions in the test should be confirmed.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ accept?: boolean }} [options] accept defaults to true; false
 *        presses « Annuler », which is how a spec proves that declining
 *        really does cancel.
 * @returns {Promise<string>} the dialog's message, for asserting on
 */
export async function answerConfirmation(page, options = {}) {
    const dialog = page.locator('#sm-confirm-modal');
    await dialog.waitFor({ state: 'visible' });

    const message = (await dialog.locator('#sm-confirm-modal-body').innerText()).trim();

    // « Annuler » is first in the footer and the confirmation second —
    // design.md §7.4's ordering, which the dialog builds deliberately.
    const button = options.accept === false
        ? dialog.locator('.modal-footer .btn').first()
        : dialog.locator('.modal-footer .btn').last();
    await button.click();
    await dialog.waitFor({ state: 'detached' });

    return message;
}
