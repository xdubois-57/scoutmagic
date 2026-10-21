/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Rapprocher un virement » on a booking's « Finances » page
// (modules/rental/views/management/_finance_transfer.html.twig, #980,
// chantier §2.6): the search of the dialog, and nothing else.
//
// The manager types the amount received and, perhaps, the payer's name.
// Both are somebody's money, so they are POSTed — never put in a URL, a
// history entry or a referrer — with the same `X-Requested-With` header as
// rental-booking.js, which is what makes RentalManagementController::
// bookingAction() answer JSON. The answer carries the list already rendered
// by Twig (`html`, from _finance_transfer_candidates.html.twig): this file
// places it, and builds no markup of its own out of a bank's text.
//
// The search form carries `data-async="off"`, so rental-booking.js stands
// aside and this file takes the submit. Each candidate's own form then
// asks first through confirm.js and posts normally: the page comes back
// whole, the dialog closed, the movement in « Mouvements du compte ».
//
// Delegated on `document`: the dialog sits in a `[data-booking-panel]`
// that rental-booking.js re-renders after any action, and a listener bound
// to the form at load would be lost with it.
(function () {
    var DIALOG_ID = 'rapprocher-virement';

    /**
     * @param {HTMLElement} results
     * @param {string} message
     * @returns {void}
     */
    function sayError(results, message) {
        var alert = document.createElement('p');
        alert.className = 'alert alert-danger small mb-0';
        alert.setAttribute('role', 'alert');
        alert.textContent = message;
        results.replaceChildren(alert);
    }

    /**
     * @param {HTMLFormElement} form
     * @returns {Promise<void>}
     */
    function search(form) {
        var dialog = form.closest('#' + DIALOG_ID) || document;
        var results = /** @type {HTMLElement|null} */ (dialog.querySelector('[data-transfer-results]'));
        if (results === null) {
            return Promise.resolve();
        }
        var target = results;
        var button = /** @type {HTMLButtonElement|null} */ (form.querySelector('button[type="submit"]'));
        if (button) {
            button.disabled = true;
        }
        target.setAttribute('aria-busy', 'true');

        return fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        }).then(function (response) {
            return response.json().catch(function () { return null; });
        }).then(function (data) {
            if (data === null) {
                sayError(target, 'La recherche n\'a pas abouti. Rechargez la page et réessayez.');
            } else if (data.success && typeof data.html === 'string') {
                target.innerHTML = data.html;
            } else {
                sayError(target, data.message || data.error || 'La recherche n\'a pas abouti.');
            }
        }).catch(function () {
            sayError(target, 'Erreur réseau : la recherche n\'a pas pu être faite.');
        }).finally(function () {
            target.removeAttribute('aria-busy');
            if (button) {
                button.disabled = false;
            }
        });
    }

    document.addEventListener('submit', function (e) {
        var target = /** @type {HTMLElement|null} */ (e.target);
        var form = /** @type {HTMLFormElement|null} */ (
            target === null ? null : target.closest('form[data-transfer-search]')
        );
        if (form === null) return;

        e.preventDefault();
        void search(form);
    });

    // A list of the account's credits is not left behind for whoever opens
    // the dialog next: closing it forgets the answer.
    document.addEventListener('hidden.bs.modal', function (e) {
        var dialog = /** @type {HTMLElement|null} */ (e.target);
        if (dialog === null || dialog.id !== DIALOG_ID) return;

        var results = dialog.querySelector('[data-transfer-results]');
        if (results) {
            results.replaceChildren();
        }
    });
})();
