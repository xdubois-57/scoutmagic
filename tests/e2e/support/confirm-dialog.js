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
 * @param {{ native?: boolean }} [options] native defaults to true and
 *        installs the Playwright dialog handler for the templates still on
 *        the allowlist. Pass false when the spec keeps a dialog handler of
 *        its own — a page whose remaining native box is an alert() the spec
 *        CAPTURES rather than accepts. Two handlers on one dialog is not a
 *        matter of taste: whichever runs second answers an already-answered
 *        dialog, and Playwright rejects that call.
 */
export async function autoConfirm(page, options = {}) {
    // Templates whose script has not been extracted yet.
    if (options.native !== false) {
        page.on('dialog', (dialog) => dialog.accept());
    }

    // The site's dialog: click its confirmation button as soon as it is on
    // screen. A MutationObserver rather than polling, so no spec has to
    // wait for a dialog it never asked to see.
    await page.addInitScript(() => {
        const answer = (modal) => {
            // « Annuler » is first in the footer and the confirmation
            // second — design.md §7.4's ordering, which the dialog builds
            // deliberately.
            const button = modal.querySelector('.modal-footer .btn:last-child');
            if (button instanceof HTMLElement) {
                button.click();
            }
        };

        /** @param {HTMLElement} modal */
        const answerWhenReady = (modal) => {
            // NOT on insertion: Bootstrap's Modal.hide() returns without
            // doing anything while the show transition is still running, so
            // a click landing in that window leaves the dialog AND its
            // backdrop on screen for the rest of the test, swallowing every
            // later click. Answering on 'shown.bs.modal' is the first
            // moment the dialog can actually be dismissed. Without the
            // Bootstrap bundle confirm.js shows the same markup by hand,
            // with no transition and no event — answer straight away.
            const bootstrapBundle = /** @type {{ Modal?: unknown }|undefined} */ (
                /** @type {?} */ (window).bootstrap
            );
            if (bootstrapBundle && bootstrapBundle.Modal) {
                modal.addEventListener('shown.bs.modal', () => answer(modal), { once: true });
            } else {
                answer(modal);
            }
        };

        const observer = new MutationObserver((records) => {
            records.forEach((record) => {
                record.addedNodes.forEach((node) => {
                    if (node instanceof HTMLElement && node.id === 'sm-confirm-modal') {
                        answerWhenReady(node);
                    }
                });
            });
        });
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
 * The messages accumulate across navigations, which is what the `alerts`
 * arrays this replaces did: they lived in the test's own process and a
 * page load meant nothing to them, while an init script runs again on
 * every load and would hand back only the last page's toasts. sessionStorage
 * is the one store with exactly the lifetime wanted here — the tab's, so a
 * toast survives the reload that follows the action that raised it, and
 * nothing survives the test.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {() => Promise<string[]>} the messages shown so far, in order
 */
export async function collectToasts(page) {
    await page.addInitScript(() => {
        const KEY = '__smToasts';
        /** @param {string} message */
        const remember = (message) => {
            const seen = JSON.parse(sessionStorage.getItem(KEY) || '[]');
            seen.push(message);
            sessionStorage.setItem(KEY, JSON.stringify(seen));
        };
        const record = () => {
            document.querySelectorAll('.toast-body:not([data-e2e-seen])').forEach((body) => {
                body.setAttribute('data-e2e-seen', '1');
                remember((body.textContent || '').trim());
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

    return async () => page.evaluate(
        () => JSON.parse(sessionStorage.getItem('__smToasts') || '[]'),
    );
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
