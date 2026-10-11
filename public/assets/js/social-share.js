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
// records nothing. **It leaves on the PRESS, not on the click**: see
// `pressed()` for why, and for what that costs. The second records that
// the share happened, and is sent only once the browser's own promise
// has resolved: a share sheet the chief dismissed is not a share, and
// recording it would freeze the communication and write a line in the
// history for something that never left. `navigator.share` rejects with
// an `AbortError` on dismissal, which is exactly the signal needed, and
// it is why the recording cannot ride along with the first request.
(function () {
    const buttons = Array.prototype.slice.call(
        document.querySelectorAll('[data-share-communication]')
    );
    if (buttons.length === 0) {
        return;
    }

    const api = window.ScoutMagicApi;
    const feedback = document.querySelector('[data-share-feedback]');
    /**
     * How long « Lien copié » stays on screen before the reload takes
     * the page away, in milliseconds. `registration-passage.js` waits
     * the same beat for the same reason.
     */
    const READ_DELAY_MS = 1200;
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
        if (!canShareSheet) {
            return copied(url);
        }

        return navigator.share(sheetPayload(url, title))
            .then(function () { return 'native'; })
            .catch(function (error) { return sheetRefused(url, error); });
    }

    /**
     * The clipboard path: the desktop's own, and the sheet's fallback.
     *
     * @param {string} url
     * @returns {Promise<?string>}
     */
    function copied(url) {
        return clipboard.writeText(url).then(function () { return 'copy'; }).catch(refused);
    }

    /**
     * **The sheet would not open — so the link goes to the clipboard
     * instead of nowhere.**
     *
     * `navigator.share()` needs transient user activation, and a network
     * round trip spends it. `pressed()` now sends that request on the
     * press so the sheet usually opens from the click handler itself —
     * but « usually » is the whole reason this stays: a slow network can
     * still have the click arrive before the address, and then the gap
     * is back and WebKit answers `NotAllowedError` on the very device
     * the sheet exists for (`Claude review` on PR #868). Not an iOS
     * special case either; any browser can lose it.
     *
     * So the chief gets the link anyway, and the history records what
     * truly happened — « Lien copié », not a sheet that never opened.
     *
     * @param {string} url
     * @param {?{name?: string}} error
     * @returns {null|Promise<?string>}
     */
    function sheetRefused(url, error) {
        if (error?.name === 'AbortError') {
            return null;
        }
        if (error?.name === 'NotAllowedError' && clipboard) {
            return copied(url);
        }

        return refused(error);
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
     * **And where there IS a message, the reload waits for it to be
     * read.** « Lien copié » is the only confirmation a copy gets — the
     * sheet is its own receipt, the clipboard is silent — and an
     * immediate reload wiped it with the rest of the DOM before anybody
     * could see it. The help page promises that sentence
     * (`modules/social/help/partager-soi-meme.md`: « elle copie le lien
     * et vous le dit »), so it has to be visible. `Claude review` found
     * it on PR #868, and noted that the failure branch above had already
     * been fixed for exactly this reason while the success one had not.
     * The delay is `registration-passage.js`'s, which solved the same
     * problem the same way.
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
            if (kind !== 'copy') {
                window.location.reload();

                return;
            }

            announce('Lien copié', true);
            window.setTimeout(function () { window.location.reload(); }, READ_DELAY_MS);
        });
    }

    /**
     * Everything one button does, so each communication keeps its own
     * address, title and request: one history page carries a button per
     * communication, and the sheet must never be handed another one's
     * title.
     *
     * @param {HTMLElement} button
     */
    function arm(button) {
        const linkUrl = button.dataset.shareLinkUrl || '';
        const recordUrl = button.dataset.shareRecordUrl || '';
        const title = button.dataset.shareTitle || '';
        /** The request this button's press sent, or null before any. */
        let minting = null;
        /** Its answer, once it has come back. */
        let address = null;

        /**
         * Asks for the public page, once per button.
         *
         * @returns {Promise<?string>}
         */
        function mint() {
            if (minting === null) {
                minting = api.postJson(linkUrl, {}).then(function (answer) {
                    address = minted(answer);
                    // **A refusal is not cached.** One request per button
                    // is enough because the address is permanent once it
                    // exists — but an answer that carried none must not
                    // freeze the button on it, so the next press asks
                    // again. `postJson` never rejects; it answers
                    // `{ok: false}`, which `minted()` turns into null
                    // after telling the chief why.
                    if (address === null) {
                        minting = null;
                    }

                    return address;
                });
            }

            return minting;
        }

        /**
         * **The press is what mints the page, and the click is what
         * shares it** (`Claude review` on PR #868, option B).
         *
         * `navigator.share()` needs transient user activation, and a
         * network round trip spends it: asking for the address first —
         * there being none before it exists — left WebKit answering
         * `NotAllowedError` on the very device the sheet exists for. So
         * the request leaves on `pointerdown`, and by the time the
         * `click` arrives the address is usually here, which lets the
         * sheet open from the handler itself with the activation the tap
         * gave it.
         *
         * **What it costs, deliberately.** IT-04 said the page is born
         * « au premier partage qui en a besoin, et à aucun autre
         * moment »; it is now born on the press, so a chief who touches
         * the button and changes their mind leaves a public page behind.
         * The maintainer took that trade on the pull request: the page
         * is reachable only by whoever holds its 32 random bytes,
         * nothing links to it, and the alternative was a sheet that
         * never opens on a telephone.
         *
         * **And the net stays.** A slow network can still have the click
         * arrive first; then this waits, the activation may be gone, and
         * `sheetRefused()` puts the link on the clipboard rather than
         * nowhere.
         *
         * @returns {?string|Promise<?string>}
         */
        function pressed() {
            return address === null
                ? mint().then(function (url) { return handed(url, title); })
                : handed(address, title);
        }

        button.hidden = false;

        // Both ways of pressing the button, so what it does does not
        // depend on the device: a pointer gives `pointerdown`, a
        // keyboard gives Enter or Space before its synthetic click.
        button.addEventListener('pointerdown', function () {
            void mint();
        });
        button.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                void mint();
            }
        });

        button.addEventListener('click', function () {
            // **The gesture starts HERE, not inside `withDisabled()`.**
            // That helper defers by a microtask by design
            // (`Promise.resolve().then(run)`), and the whole point of
            // minting on the press is to call `navigator.share()` while
            // this handler still holds the activation. So the sheet is
            // asked for first, and the helper is given what follows —
            // which is also what keeps the re-enable on the failure
            // path, the half hand-written copies forget.
            const gesture = pressed();

            // `void`: the chain reports to the chief itself, through the
            // live region, so there is nothing for a caller to await,
            // and an unhandled promise is a defect the quality gate
            // reads as one.
            void api.withDisabled(
                /** @type {HTMLButtonElement} */ (button),
                function () {
                    return Promise.resolve(gesture).then(function (kind) {
                        return kind === null ? undefined : record(recordUrl, kind);
                    });
                }
            );
        });
    }

    buttons.forEach(function (node) {
        // A cast and not an `instanceof` guard: the selector only ever
        // matches the history's own `<button>`, so the guard was a branch
        // no test could reach, written for the typechecker. The same
        // reasoning as the `.dataset` reads above.
        arm(/** @type {HTMLElement} */ (node));
    });
})();
