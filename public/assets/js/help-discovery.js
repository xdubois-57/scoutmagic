/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Le saviez-vous ? » — the discovery dialog
// (partials/help_discovery_dialog.html.twig, ARCHITECTURE.md §8.95).
//
// Every card is already in the page, server-rendered: this file walks
// between them, remembers which ones were actually gone past, and makes
// one action at a time — at the close. A call per card would be four
// requests to say what one says. Only an acknowledged write settles the
// choice; an error stays visible with a keyboard-accessible retry.
//
// No inline on*= handlers (dead under the CSP — design.md §7.5), no
// alert()/confirm(), and no card is ever built from a string.
(function () {
    var ENDPOINT = '/api/aide/decouverte';

    // One dialog per visit. The installed application's « Activer les
    // notifications ? » invitation (/assets/js/push-invitation.js,
    // ARCHITECTURE.md §8.111) runs immediately before this file and says
    // synchronously whether it is opening; when it is, the tips stand
    // down and nothing here is consumed — the server holds them back for
    // the rest of the day when that dialog is answered. Two modals in a
    // row is what makes people close both without reading either.
    if (window.ScoutMagicPushInvitation?.showing) {
        return;
    }

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
    var errorNotice = /** @type {HTMLElement|null} */ (modal.querySelector('[data-discovery-error]'));
    var errorMessage = /** @type {HTMLElement|null} */ (modal.querySelector('[data-discovery-error-message]'));
    var retryButton = /** @type {HTMLElement|null} */ (modal.querySelector('[data-discovery-retry]'));
    var failedAction = '';
    var failedMessage = '';

    var index = 0;
    /** @type {string[]} the ids actually gone past, in the order they were shown */
    var seen = [];
    // One acknowledged choice: a successful action's hidden.bs.modal
    // must never write a second, contradicting one. Failed writes can retry.
    var settled = false;
    var pending = false;

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
     * Only HTTP and application success acknowledge the choice. A failed
     * request leaves the same cards available and never locks out a retry.
     *
     * @param {string} action one of close|snooze|never|more
     * @returns {Promise<boolean>}
     */
    function settle(action) {
        if (settled || pending) {
            return Promise.resolve(false);
        }
        pending = true;
        errorNotice?.classList.add('d-none');
        var controls = Array.from(modal.querySelectorAll('button')).filter(function (button) {
            return !button.disabled && !Object.hasOwn(button.dataset, 'bsDismiss');
        });
        controls.forEach(function (button) { button.disabled = true; });
        modal.setAttribute('aria-busy', 'true');

        var api = window.ScoutMagicApi;
        var ids = seen.slice();
        return Promise.resolve().then(function () {
            return api
                ? api.postJson(ENDPOINT, { ids: ids, action: action })
                : { ok: false, status: 0, data: null };
        }).then(function (response) {
            if (response?.ok && response.data?.success === true) {
                settled = true;
                return true;
            }
            reportFailure(action, response);
            return false;
        }).catch(function () {
            reportFailure(action, null);
            return false;
        }).finally(function () {
            pending = false;
            controls.forEach(function (button) { button.disabled = false; });
            modal.setAttribute('aria-busy', 'false');
        });
    }

    /**
     * Keep the error and retry within the open modal's focus trap. After
     * a manual dismissal, the common toast offers the same action instead.
     *
     * @param {string} action
     * @param {{data: any}|null} response
     */
    function reportFailure(action, response) {
        var message = typeof response?.data?.error === 'string' && response.data.error
            ? response.data.error
            : "Votre choix n'a pas pu être enregistré. Réessayez.";
        failedAction = action;
        failedMessage = message;
        if (modal.classList.contains('show') && errorNotice && errorMessage) {
            errorMessage.textContent = message;
            errorNotice.classList.remove('d-none');
            errorNotice.scrollIntoView?.({ block: 'nearest' });
            return;
        }
        showFailureToast(action, message);
    }

    /**
     * Preserve the failed choice outside the dismissed dialog.
     *
     * @param {string} action
     * @param {string} message
     */
    function showFailureToast(action, message) {
        window.ScoutMagicToast?.show(message, {
            variant: 'error',
            action: { label: 'Réessayer', onClick: function () { finish(action); } },
        });
    }

    /** @param {string} action */
    function finish(action) {
        void settle(action).then(function (saved) {
            if (!saved) {
                return;
            }
            if (action === 'more') {
                window.location.reload();
            } else if (action !== 'close') {
                hide();
            }
        });
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
        if (!pending && index < cards.length - 1) {
            show(index + 1);
        }
    });

    on(snoozeButton, function () {
        finish('snooze');
    });

    on(retryButton, function () {
        if (failedAction) {
            finish(failedAction);
        }
    });

    on(neverButton, function () {
        finish('never');
    });

    on(moreButton, function () {
        // The next batch comes from the server, re-rendered by the same
        // template — never assembled here from a JSON payload, which
        // would be a second implementation of the card and a DOM sink
        // fed by server strings.
        finish('more');
    });

    // Manual dismissal must preserve an unacknowledged choice for retry.
    // Otherwise « Terminé », Escape, the cross and the backdrop record close.
    modal.addEventListener('hidden.bs.modal', function () {
        if (failedAction && !settled) {
            if (!pending) {
                showFailureToast(failedAction, failedMessage);
            }
            return;
        }
        finish('close');
    });

    show(0);

    if (window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }
}());
