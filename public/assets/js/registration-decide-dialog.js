/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Formulaire d'inscription › Demandes — the decision dialog of the
// « Action rapide » column (modules/registration/views/config/
// _decide_dialogs.html.twig, issue #843 IT-04).
//
// The dialogs are rendered by the server, one per pending request, and
// opened by Bootstrap's own data API. This file only does the one thing
// they cannot do alone: « Refuser » does not refuse. It shows the reason
// (« Pas de place » / « Autre ») and gives way to « Confirmer le refus »,
// which stays disabled until a reason is chosen. Closing the dialog puts
// it back as it was opened — the note as stored, the reason as preset —
// since each opening is a new decision.
//
// It also sends a decision once: a double click would post it twice, and
// the second post — the request no longer pending — would replace the
// confirmation of the first with « n'est plus en attente ».
//
// Nothing here writes markup or an address: it toggles `hidden` and
// `disabled` on elements the server rendered. The server refuses a refusal
// without a valid reason anyway (RequestStatusService::refuse()).
//
// The pure part is exposed as window.ScoutMagicDecideDialog for
// tests/js/registration-decide-dialog.test.js.
(function () {
    // The two reasons a refusal may carry, as the server names them
    // (RegistrationRequest::REFUSAL_*).
    var REFUSAL_REASONS = ['no_room', 'other'];

    /**
     * Whether « Confirmer le refus » may be pressed: only with one of the
     * two reasons chosen — never the empty « Choisir… ».
     *
     * @param {string} reason
     * @returns {boolean}
     */
    function canConfirmRefusal(reason) {
        return REFUSAL_REASONS.indexOf(reason) !== -1;
    }

    /**
     * Puts one dialog in its refusing state (the reason shown, « Confirmer
     * le refus » in place of « Refuser ») or back out of it.
     *
     * @param {HTMLElement} dialog
     * @param {boolean} refusing
     */
    function setRefusing(dialog, refusing) {
        var reasonBlock = /** @type {HTMLElement|null} */ (dialog.querySelector('[data-decide-reason]'));
        var trigger = /** @type {HTMLButtonElement|null} */ (dialog.querySelector('[data-decide-refuse]'));
        var confirm = /** @type {HTMLButtonElement|null} */ (dialog.querySelector('[data-decide-refuse-confirm]'));
        var select = /** @type {HTMLSelectElement|null} */ (dialog.querySelector('[data-decide-reason-select]'));
        if (!reasonBlock || !trigger || !confirm || !select) {
            return;
        }
        reasonBlock.hidden = !refusing;
        trigger.hidden = refusing;
        confirm.hidden = !refusing;
        confirm.disabled = !refusing || !canConfirmRefusal(select.value);
    }

    /**
     * The dialog's two submit buttons (« Confirmer le refus », « Accepter »),
     * which sit in its footer, outside the form they post.
     *
     * @param {HTMLElement} dialog
     * @returns {HTMLButtonElement[]}
     */
    function submitButtons(dialog) {
        return Array.prototype.slice.call(dialog.querySelectorAll('button[type="submit"]'));
    }

    /**
     * Puts one dialog back as it was opened: the note and the reason as the
     * server rendered them, « Refuser » in place, and free to be sent.
     *
     * @param {HTMLElement} dialog
     * @param {HTMLFormElement} form
     */
    function reset(dialog, form) {
        form.reset();
        form.removeAttribute('data-decide-sent');
        submitButtons(dialog).forEach(function (button) { button.disabled = false; });
        setRefusing(dialog, false);
    }

    /**
     * @param {HTMLElement} dialog
     */
    function bind(dialog) {
        var trigger = dialog.querySelector('[data-decide-refuse]');
        var select = /** @type {HTMLSelectElement|null} */ (dialog.querySelector('[data-decide-reason-select]'));
        var form = /** @type {HTMLFormElement|null} */ (dialog.querySelector('[data-decide-form]'));
        if (!trigger || !select || !form) {
            return;
        }

        trigger.addEventListener('click', function () {
            setRefusing(dialog, true);
            select.focus();
        });
        select.addEventListener('change', function () {
            setRefusing(dialog, true);
        });
        form.addEventListener('submit', function (event) {
            if (form.hasAttribute('data-decide-sent')) {
                event.preventDefault();
                return;
            }
            form.setAttribute('data-decide-sent', '');
            // One turn later: a button disabled now would drop its own name
            // and value — the decision itself — from the request being built
            // (design.md §7.10, the same reason form-submit-lock.js waits).
            window.setTimeout(function () {
                submitButtons(dialog).forEach(function (button) { button.disabled = true; });
            }, 0);
        });
        // Bootstrap's own event, once the dialog has finished closing.
        dialog.addEventListener('hidden.bs.modal', function () { reset(dialog, form); });
        // Back to a page the history cache kept as it was left: sent.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                reset(dialog, form);
            }
        });
    }

    function init() {
        document.querySelectorAll('.modal').forEach(
            /** @param {Element} dialog */
            function (dialog) {
                if (dialog instanceof HTMLElement && dialog.querySelector('[data-decide-form]')) {
                    bind(dialog);
                }
            }
        );
    }

    window.ScoutMagicDecideDialog = {
        canConfirmRefusal: canConfirmRefusal,
        setRefusing: setRefusing,
        init: init,
    };

    init();
})();
