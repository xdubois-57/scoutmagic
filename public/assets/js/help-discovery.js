/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Le saviez-vous ? » — the discovery dialog
// (partials/help_discovery_dialog.html.twig, ARCHITECTURE.md §8.95).
//
// Every card is already in the page, server-rendered: this file walks
// between them, remembers which ones were actually gone past, and makes
// exactly ONE network call — at the close. A call per card would be four
// requests to say what one says, and each of them a chance to record half
// a passage.
//
// Offline, that call fails and nothing is recorded, so the same tips come
// back next time. That is the right behaviour rather than a bug to queue
// around: nothing was stored, so nothing was consumed.
//
// No inline on*= handlers (dead under the CSP — design.md §7.5), no
// alert()/confirm(), and no card is ever built from a string.
(function () {
    var ENDPOINT = '/api/aide/decouverte';

    var modal = document.getElementById('help-discovery-modal');
    if (!modal) {
        return;
    }

    var cards = /** @type {HTMLElement[]} */ (Array.prototype.slice.call(
        modal.querySelectorAll('[data-discovery-card]')
    ));
    if (cards.length === 0) {
        return;
    }

    var position = /** @type {HTMLElement|null} */ (modal.querySelector('[data-discovery-position]'));
    var nextButton = /** @type {HTMLElement|null} */ (modal.querySelector('[data-discovery-next]'));
    var doneButton = /** @type {HTMLElement|null} */ (modal.querySelector('[data-discovery-done]'));
    var moreButton = /** @type {HTMLElement|null} */ (modal.querySelector('[data-discovery-more]'));
    var snoozeButton = /** @type {HTMLElement|null} */ (modal.querySelector('[data-discovery-snooze]'));
    var neverButton = /** @type {HTMLElement|null} */ (modal.querySelector('[data-discovery-never]'));

    var index = 0;
    /** @type {string[]} the ids actually gone past, in the order they were shown */
    var seen = [];
    // One call and one only: whatever closes the dialog first decides
    // what is written, and the `hidden.bs.modal` that follows must not
    // send a second, contradicting one.
    var settled = false;

    /**
     * Remembers a card as read. Shown IS read here — somebody who sees a
     * tip has seen it, whether or not they then press « Suivant ».
     *
     * @param {number} at
     */
    function remember(at) {
        var id = cards[at].dataset.discoveryId;
        if (id && !seen.includes(id)) {
            seen.push(id);
        }
    }

    /** @param {number} at */
    function show(at) {
        index = at;
        cards.forEach(function (card, i) {
            card.classList.toggle('d-none', i !== at);
        });
        remember(at);

        if (position) {
            position.textContent = (at + 1) + ' / ' + cards.length;
        }

        var last = at === cards.length - 1;
        if (nextButton) {
            nextButton.classList.toggle('d-none', last);
        }
        if (doneButton) {
            doneButton.classList.toggle('d-none', !last);
        }
        if (moreButton) {
            moreButton.classList.toggle('d-none', !last);
        }
    }

    /**
     * The one call. Resolves whatever the server answered — the caller
     * decides what to do next, and « offline » is simply a resolution
     * like any other.
     *
     * @param {string} action one of close|snooze|never|more
     * @returns {Promise<void>}
     */
    function settle(action) {
        if (settled) {
            return Promise.resolve();
        }
        settled = true;

        var api = window.ScoutMagicApi;
        if (!api) {
            return Promise.resolve();
        }

        return api.postJson(ENDPOINT, { ids: seen, action: action }).then(function () {});
    }

    function hide() {
        var instance = window.bootstrap ? window.bootstrap.Modal.getInstance(modal) : null;
        if (instance) {
            instance.hide();
        }
    }

    /**
     * @param {HTMLElement|null} button
     * @param {() => void} handler
     */
    function on(button, handler) {
        if (button) {
            button.addEventListener('click', handler);
        }
    }

    on(nextButton, function () {
        if (index < cards.length - 1) {
            show(index + 1);
        }
    });

    on(snoozeButton, function () {
        settle('snooze').then(hide);
    });

    on(neverButton, function () {
        settle('never').then(hide);
    });

    on(moreButton, function () {
        // The next batch comes from the server, re-rendered by the same
        // template — never assembled here from a JSON payload, which
        // would be a second implementation of the card and a DOM sink
        // fed by server strings.
        settle('more').then(function () {
            window.location.reload();
        });
    });

    // « Terminé », the close cross, Escape and a click on the backdrop
    // all arrive here, and all mean the same thing.
    modal.addEventListener('hidden.bs.modal', function () {
        settle('close');
    });

    show(0);

    if (window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }
}());
