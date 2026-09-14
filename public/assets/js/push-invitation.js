/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Activer les notifications ? » — the invitation the installed
// application offers once (partials/push_invitation_dialog.html.twig,
// ARCHITECTURE.md §8.111).
//
// The server has already decided that this ACCOUNT may be asked (signed
// in, VAPID configured, never refused, not on one of the excluded pages).
// What is left is the half only a browser knows, and it is the whole
// point of the feature: is this page being read inside the installed
// application, on a device that has never been asked?
//
// **That decision is synchronous, and it has to be.** This file runs
// immediately before /assets/js/help-discovery.js, which opens the tips
// dialog on load — so the flag below has to be set before that script's
// first statement, or two modals stack. Which is why the last test is
// `Notification.permission === 'default'` rather than "does this device
// have a subscription": the second is a promise, and the first implies it
// — a subscription cannot exist without granted permission. The cost is
// stated and small: a device that granted permission and then
// unsubscribed is not invited again, and « Mon compte » is where it goes.
//
// One network call, at the close, like the tips dialog: whatever closes
// the dialog first decides what is written.
(function () {
    var ENDPOINT = '/api/notifications/invitation';

    // Set before anything else can return: help-discovery.js reads this
    // to know whether the page already has a dialog in it.
    window.ScoutMagicPushInvitation = { showing: false };

    var modal = document.getElementById('push-invitation-modal');
    if (!modal) {
        return;
    }

    var body = modal.querySelector('#push-invitation-body');
    var vapidPublicKey = (body instanceof HTMLElement ? body.dataset.vapidPublicKey : '') || '';
    var push = window.ScoutMagicPush;
    if (!push?.isSupported(vapidPublicKey)) {
        return;
    }

    // Same two-sided test as offline-cache.js and file-viewer.js: the
    // media query everywhere, and iOS Safari's own flag, which is the only
    // signal there.
    var installed = window.matchMedia?.('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
    if (!installed || Notification.permission !== 'default') {
        return;
    }

    window.ScoutMagicPushInvitation.showing = true;

    var enableButton = /** @type {HTMLButtonElement|null} */ (modal.querySelector('[data-push-invitation-enable]'));
    var laterButton = /** @type {HTMLElement|null} */ (modal.querySelector('[data-push-invitation-later]'));
    var doneButton = /** @type {HTMLElement|null} */ (modal.querySelector('[data-push-invitation-done]'));
    var deniedNotice = /** @type {HTMLElement|null} */ (modal.querySelector('[data-push-invitation-denied]'));
    var errorNotice = /** @type {HTMLElement|null} */ (modal.querySelector('[data-push-invitation-error]'));
    var successNotice = /** @type {HTMLElement|null} */ (modal.querySelector('[data-push-invitation-success]'));

    // One call and one only: « Plus tard » and the close cross both arrive
    // here, and the `hidden.bs.modal` that follows an accepted invitation
    // must not send a second, contradicting answer.
    var settled = false;

    /**
     * @param {'later' | 'enabled'} action
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

        return api.postJson(ENDPOINT, { action: action }).then(function () {});
    }

    /** @param {HTMLElement|null} notice */
    function show(notice) {
        [deniedNotice, errorNotice, successNotice].forEach(function (el) {
            if (el) el.classList.add('d-none');
        });
        if (notice) notice.classList.remove('d-none');
    }

    // Accepted: the dialog has nothing left to ask, so the two answers go
    // away and only « Terminé » remains. Its dismissal is a no-op on the
    // server, settle() having already been spent on 'enabled'.
    function markEnabled() {
        show(successNotice);
        if (enableButton) enableButton.classList.add('d-none');
        if (laterButton) laterButton.classList.add('d-none');
        if (doneButton) doneButton.classList.remove('d-none');
    }

    /**
     * « Activer les notifications » — ask the device, subscribe it, and
     * record the answer. Declared here rather than inside the click
     * handler: it closes over nothing the handler owns, so rebuilding it
     * on every click would be a new function for the same work.
     *
     * @returns {Promise<void>}
     */
    function answerByEnabling() {
        return push.enable(vapidPublicKey).then(function (status) {
            if (status === 'enabled') {
                return settle('enabled').then(markEnabled);
            }
            // Denied is final for this device — the browser will not ask
            // again — so the dialog stays open on its explanation and the
            // dismissal that follows records « Plus tard », which is what
            // it now means.
            show(status === 'denied' ? deniedNotice : errorNotice);
            return Promise.resolve();
        });
    }

    if (enableButton) {
        enableButton.addEventListener('click', function () {
            var api = window.ScoutMagicApi;
            if (api) {
                api.withDisabled(enableButton, answerByEnabling);
            } else {
                answerByEnabling();
            }
        });
    }

    // « Plus tard », the close cross, Escape and a click on the backdrop
    // all arrive here, and all mean the same thing.
    modal.addEventListener('hidden.bs.modal', function () {
        settle('later');
    });

    if (window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }
}());
