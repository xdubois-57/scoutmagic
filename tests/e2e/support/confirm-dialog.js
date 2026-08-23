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
// autoConfirm() covers both. The site's own templates are now free of
// native boxes (tests/Core/View/UxConventionsTest::NATIVE_DIALOG_ALLOWLIST
// is empty and stays so), so the native branch is a safety net rather
// than a migration crutch: the browser can still raise a dialog of its
// own — a beforeunload prompt, a basic-auth challenge — and Playwright's
// default for one is to dismiss it silently.

/**
 * Answers every confirmation on the page with "yes", for the rest of the
 * test — the equivalent of the old `page.on('dialog', d => d.accept())`.
 *
 * Must be called BEFORE the navigation that renders the page, because the
 * answering loop is installed as an init script.
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
    // Any dialog the BROWSER raises (beforeunload, basic auth): the site
    // itself no longer opens one.
    if (options.native !== false) {
        page.on('dialog', (dialog) => dialog.accept());
    }

    // The site's dialog: answer it when Bootstrap says it has finished
    // opening.
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

        // DELEGATED ON THE DOCUMENT, and installed by an init script — so
        // it is in place long before any dialog exists. Bootstrap's events
        // bubble, so this catches `shown.bs.modal` for a dialog created
        // much later.
        //
        // The previous version attached the listener to the dialog itself,
        // from a MutationObserver watching for its insertion. That loses a
        // race it cannot win: confirm.js appends the dialog and calls
        // Modal.show() in the SAME task, while the observer's callback is a
        // microtask the insertion queues — so the listener could only ever
        // attach after show() had returned. That is in time whenever the
        // fade really animates, and silently too late whenever it does not
        // (Bootstrap runs its callback synchronously when it measures a
        // transition duration of zero). The listener then waited for an
        // event already fired: nothing clicked, the dialog stayed on screen
        // for the rest of the test, and the failure blamed whatever
        // assertion came next. CI caught exactly that, twice, on a run that
        // was green locally both times.
        //
        // Answering ON `shown.bs.modal` — rather than as soon as the dialog
        // appears — is not a detail either: Bootstrap's Modal.hide() returns
        // without doing anything while the opening transition runs, so a
        // click landing in that window leaves the dialog AND its backdrop on
        // screen, swallowing every later click. `.modal.fade` reaches full
        // opacity the moment `.show` lands (what the fade animates is the
        // `.modal-dialog` transform), so "it looks opaque" is not the same
        // question and cannot stand in for this event.
        document.addEventListener('shown.bs.modal', (event) => {
            const modal = event.target;
            if (modal instanceof HTMLElement && modal.id === 'sm-confirm-modal') {
                answer(modal);
            }
        });

        // Without the Bootstrap bundle (a stripped page, or a spec that
        // blocks it) confirm.js shows the same markup by hand: no
        // transition, and no event to wait for. Poll for that case alone.
        setInterval(() => {
            if (window.bootstrap && window.bootstrap.Modal) {
                return;
            }
            const modal = document.getElementById('sm-confirm-modal');
            if (modal && modal.classList.contains('show') && modal.dataset.e2eAnswered !== '1') {
                modal.dataset.e2eAnswered = '1';
                answer(modal);
            }
        }, 50);
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
 * @param {{ accept?: boolean, note?: string }} [options] accept defaults to
 *        true; false presses « Annuler », which is how a spec proves that
 *        declining really does cancel. `note` types into the dialog's
 *        free-text field before answering — the confirm-with-a-word case
 *        (data-confirm-note), where the field the manager writes in only
 *        exists inside the dialog.
 * @returns {Promise<string>} the dialog's message, for asserting on
 */
export async function answerConfirmation(page, options = {}) {
    const dialog = page.locator('#sm-confirm-modal');
    await dialog.waitFor({ state: 'visible' });

    const message = (await dialog.locator('#sm-confirm-modal-body').innerText()).trim();

    if (options.note !== undefined) {
        await dialog.locator('#sm-confirm-modal-input').fill(options.note);
    }

    // « Annuler » is first in the footer and the confirmation second —
    // design.md §7.4's ordering, which the dialog builds deliberately.
    const button = options.accept === false
        ? dialog.locator('.modal-footer .btn').first()
        : dialog.locator('.modal-footer .btn').last();
    await button.click();
    await dialog.waitFor({ state: 'detached' });

    return message;
}
