/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The enlarged SEPA QR of a rental booking (modules/rental/views/
// _payment_qr_dialog.html.twig): keeps the screen awake while it is open.
//
// The dialog itself is a plain Bootstrap modal, opened by its thumbnail
// and by « Agrandir le QR code » with no help from here. What a modal
// cannot do is stop the screen dimming — and the enlarged code is shown
// precisely to be held in front of a banking app for as long as it takes
// to scan, often longer than a laptop's or a phone's idle delay. So while
// a dialog holding `[data-qr-enlarged]` is shown, this asks the browser
// for a screen wake lock, and gives it back when the dialog closes.
//
// Everything here is best effort. A browser without the Wake Lock API, or
// one that refuses it (battery saver, a page not visible), simply dims as
// it always did: the code is still on screen and still scans.
//
// Delegated on the document, because the booking page re-renders its
// panels in place (rental-booking.js) and Bootstrap's own events bubble.
// The browser releases the lock by itself when the page is hidden, so a
// dialog still open when the page comes back asks for it again.
(function () {
    /** @type {{ release: () => Promise<void> } | null} */
    var lock = null;
    var wanted = false;

    /**
     * The Wake Lock API, when this browser has one.
     *
     * @returns {{ request: (type: string) => Promise<{ release: () => Promise<void> }> } | null}
     */
    function wakeLock() {
        var api = /** @type {any} */ (navigator).wakeLock;

        return api && typeof api.request === 'function' ? api : null;
    }

    /** @returns {Promise<void>} */
    function acquire() {
        var api = wakeLock();
        if (api === null || lock !== null) {
            return Promise.resolve();
        }

        return api.request('screen').then(function (sentinel) {
            lock = sentinel;
            // Closed while the request was on its way: give it straight back.
            if (!wanted) {
                release();
            }
        }).catch(function () {
            // Refused: the screen dims as it would have anyway.
        });
    }

    /** @returns {void} */
    function release() {
        var held = lock;
        lock = null;
        if (held !== null) {
            held.release().catch(function () {
                // Already released by the browser: nothing to give back.
            });
        }
    }

    /**
     * @param {Event} event
     * @returns {boolean}
     */
    function isQrDialog(event) {
        var target = event.target;

        return target instanceof Element && target.querySelector('[data-qr-enlarged]') !== null;
    }

    document.addEventListener('shown.bs.modal', function (event) {
        if (!isQrDialog(event)) return;
        wanted = true;
        void acquire();
    });

    document.addEventListener('hidden.bs.modal', function (event) {
        if (!isQrDialog(event)) return;
        wanted = false;
        release();
    });

    document.addEventListener('visibilitychange', function () {
        if (wanted && document.visibilityState === 'visible') {
            // The browser dropped the lock when the page was hidden.
            lock = null;
            void acquire();
        }
    });
})();
