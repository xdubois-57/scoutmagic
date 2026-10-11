/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Sharing a communication by hand, from the history (issue #706, IT-05):
// the phone's share sheet where there is one, the clipboard everywhere
// else.
//
// **One icon, two behaviours, decided by the device** — the chantier's
// own wording. `navigator.share` exists on a phone and almost nowhere on
// a desktop, so the same button opens the sheet there and copies the link
// here, saying « Lien copié » when it does.
//
// **The button starts hidden and this file unhides it.** Nothing here
// degrades to a working link: the address does not exist until a POST
// creates it, so a page whose JavaScript did not load has nothing to
// offer and says nothing rather than offering a dead control.
//
// **Two requests, and the order is the point.** The first asks for the
// link — which CREATES the public page, so it cannot be a GET — and
// records nothing. The second records that the share happened, and is
// sent only once the browser's own promise has resolved: a share sheet
// the chief dismissed is not a share, and recording it would freeze the
// communication and write a line in the history for something that never
// left. `navigator.share` rejects with an `AbortError` on dismissal,
// which is exactly the signal needed, and it is why the recording cannot
// ride along with the first request.
(function () {
    const buttons = Array.prototype.slice.call(
        document.querySelectorAll('[data-share-communication]')
    );
    if (buttons.length === 0) {
        return;
    }

    const api = window.ScoutMagicApi;
    const feedback = document.querySelector('[data-share-feedback]');
    const canShareSheet = typeof navigator.share === 'function';
    const clipboard = navigator.clipboard;

    // Neither way of handing the link over is available — an old browser,
    // or a page served over plain http, where `navigator.clipboard` is
    // not defined at all. The button stays hidden: there is nothing it
    // could do, and a button that does nothing is worse than none.
    if (!api || (!canShareSheet && !clipboard)) {
        return;
    }

    /**
     * Tells the chief what happened, in a live region so a screen reader
     * says it without the page moving.
     *
     * @param {string} message
     * @param {boolean} ok
     */
    function announce(message, ok) {
        if (!(feedback instanceof HTMLElement)) {
            return;
        }
        feedback.textContent = message;
        feedback.hidden = false;
        feedback.classList.toggle('text-success', ok);
        feedback.classList.toggle('text-danger', !ok);
    }

    /**
     * The public page's address — or null, once the chief has been told
     * why there is none to hand over.
     *
     * @param {{ok: boolean, status: number, data: any}} answer
     * @returns {?string}
     */
    function minted(answer) {
        const url = answer.ok ? answer.data?.url : null;
        if (!url) {
            announce(answer.data?.error || 'Le lien n’a pas pu être préparé.', false);

            return null;
        }

        return url;
    }

    /**
     * Hands the link to the device, and answers which gesture happened —
     * or null when none did.
     *
     * **The sheet carries the title as well as the address**, which is
     * what `specifications.md` § 47.10 promises and what makes the sheet
     * legible: a bare URL tells the chief's own contacts nothing about
     * what they are being sent. It was the address alone until
     * `Claude review` read the spec against this file on PR #868. The
     * clipboard takes the address only — a title pasted into WhatsApp
     * beside the link would be text nobody asked for.
     *
     * @param {?string} url
     * @param {string} title the communication's own, for the sheet
     * @returns {?string|Promise<?string>}
     */
    function handed(url, title) {
        if (url === null) {
            return null;
        }

        const given = canShareSheet
            ? navigator.share(sheetPayload(url, title)).then(function () { return 'native'; })
            : clipboard.writeText(url).then(function () { return 'copy'; });

        return given.catch(refused);
    }

    /**
     * What the share sheet is handed. The title is left out rather than
     * sent empty: an empty `title` is still a title, and some sheets
     * show it as a blank line.
     *
     * @param {string} url
     * @param {string} title
     * @returns {{url: string, title?: string}}
     */
    function sheetPayload(url, title) {
        return title === '' ? { url: url } : { title: title, url: url };
    }

    /**
     * **A dismissed sheet is not a failure and not a share.**
     * `AbortError` is what every browser raises when the chief closes the
     * sheet, and the only right answer is to say nothing at all: no
     * history line, no freeze, no error message for something they chose.
     *
     * @param {?{name?: string}} error
     * @returns {null}
     */
    function refused(error) {
        if (error?.name !== 'AbortError') {
            announce('Le partage n’a pas abouti.', false);
        }

        return null;
    }

    /**
     * Records the share that happened. The reload is what puts the new
     * line in « Ce qui est parti » — and the freeze it brings is what the
     * composer must show, so a stale page would offer buttons the server
     * has started refusing. It happens only if the recording did: a
     * reload over a refusal would wipe the message saying so.
     *
     * @param {string} recordUrl
     * @param {string} kind
     * @returns {Promise<void>}
     */
    function record(recordUrl, kind) {
        return api.postJson(recordUrl, { kind: kind }).then(function (answer) {
            // **The reload would hide its own failure.** The link is
            // already in the chief's hands, but an expired session or a
            // 404 means nothing was recorded and nothing frozen — and a
            // reloaded page says none of that, because it clears the live
            // region on the way out. CodeRabbit on PR #868.
            if (!answer?.ok) {
                announce('Le lien est parti, mais le partage n’a pas pu être enregistré.', false);

                return;
            }
            if (kind === 'copy') {
                announce('Lien copié', true);
            }
            window.location.reload();
        });
    }

    /**
     * One flat chain rather than promises nested in one another: mint the
     * page, hand the link over, record what happened.
     *
     * @param {HTMLElement} button
     * @returns {Promise<void>}
     */
    function run(button) {
        const linkUrl = button.dataset.shareLinkUrl || '';
        const recordUrl = button.dataset.shareRecordUrl || '';
        // Carried through the chain rather than held in the file: one
        // history page has a button per communication, and a second
        // click before the first mint resolved would otherwise hand the
        // sheet the other communication's title.
        const title = button.dataset.shareTitle || '';

        return api.postJson(linkUrl, {})
            .then(minted)
            .then(function (url) {
                return handed(url, title);
            })
            .then(function (kind) {
                return kind === null ? undefined : record(recordUrl, kind);
            });
    }

    buttons.forEach(function (node) {
        // A cast and not an `instanceof` guard: the selector only ever
        // matches the history's own `<button>`, so the guard was a branch
        // no test could reach, written for the typechecker. The same
        // reasoning as the `.dataset` reads above.
        const button = /** @type {HTMLElement} */ (node);
        button.hidden = false;
        button.addEventListener('click', function () {
            // `void`: the chain reports to the chief itself — through the
            // live region — so there is nothing for a caller to await,
            // and an unhandled promise is a defect the quality gate reads
            // as one.
            void api.withDisabled(/** @type {HTMLButtonElement} */ (button), function () {
                return run(button);
            });
        });
    });
})();
