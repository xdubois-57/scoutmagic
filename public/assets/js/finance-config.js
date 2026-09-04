/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Finance module configuration overview
// (modules/finance/views/config/index.html.twig): the « Zone de danger »
// alone — permanently deleting one account's movements, and archiving every
// receipt. Extracted from the template's inline <script> so the Vitest suite
// can exercise the production code directly
// (tests/js/finance-config.test.js).
//
// Both actions are irreversible, and both keep the two guards the page has
// always had: a yes/no question naming the action, then a typed
// « SUPPRIMER » the server re-checks (confirmation_text). Neither is a
// native dialog any more — window.ScoutMagicConfirm.ask/.prompt render the
// site's own modal in French with the danger styling design.md §7.5
// requires, where confirm()/prompt() showed the origin above the sentence
// and labelled their buttons in the browser's language.
//
// The fetch rides the site-wide ScoutMagicApi envelope, which also carries
// the CSRF token the file-local csrf() reader used to fetch. HTTP success
// (`res.ok`) and business success (`data.success`) stay strictly apart: a
// 500 error page is not JSON, and reading it as a completed deletion is
// exactly the class of bug that envelope exists to stop.
(function () {
    var movementsBtn = document.getElementById('danger-movements-btn');
    var receiptsBtn = document.getElementById('danger-receipts-btn');

    // A no-op on every other page of the site — both buttons used to be
    // dereferenced unconditionally and threw wherever they were absent.
    if (!movementsBtn && !receiptsBtn) {
        return;
    }

    var api = window.ScoutMagicApi;

    /**
     * The failure line for one envelope: the server's own message when it
     * answered JSON, a distinct one when it did not answer JSON at all
     * (postJson folds a network error or an HTML error page into
     * data:null).
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {string}
     */
    function errorMessage(res) {
        if (res.data) {
            return res.data.error || 'Erreur.';
        }
        return 'Erreur : réponse serveur invalide.';
    }

    /**
     * Runs one danger-zone action, behind the typed confirmation the server
     * re-checks. An answer that is not exactly « SUPPRIMER » — including a
     * dismissed dialog, which resolves to null — sends nothing at all.
     *
     * @param {string} action
     * @param {Object} extra
     * @returns {Promise<void>}
     */
    async function executeDangerAction(action, extra) {
        var confirmationText = await window.ScoutMagicConfirm.prompt({
            message: 'Cette action est irréversible. Tapez SUPPRIMER pour confirmer :',
            confirmLabel: 'Supprimer',
            variant: 'danger'
        });
        if (confirmationText !== 'SUPPRIMER') {
            return;
        }

        var res = await api.postJson('/config/finance/danger', {
            action: action,
            confirmation_text: confirmationText,
            ...extra
        });

        if (res.data?.success) {
            window.location.reload();
        } else {
            window.ScoutMagicToast.show(errorMessage(res), { variant: 'error' });
        }
    }

    movementsBtn?.addEventListener('click', async function () {
        var accountId = Number.parseInt(/** @type {HTMLSelectElement} */ (document.getElementById('danger-movements-account')).value, 10);
        var confirmed = await window.ScoutMagicConfirm.ask({
            message: 'Supprimer tous les mouvements de ce compte ? Cette action est irréversible.',
            confirmLabel: 'Supprimer'
        });
        if (!confirmed) {
            return;
        }
        await executeDangerAction('delete_movements', { account_id: accountId });
    });

    receiptsBtn?.addEventListener('click', async function () {
        var confirmed = await window.ScoutMagicConfirm.ask({
            message: 'Supprimer (archiver) tous les reçus ? Cette action est irréversible.',
            confirmLabel: 'Supprimer'
        });
        if (!confirmed) {
            return;
        }
        await executeDangerAction('delete_receipts', {});
    });
})();
