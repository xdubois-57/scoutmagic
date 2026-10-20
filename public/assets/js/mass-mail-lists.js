/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The mailing lists page (modules/mass_mail/views/mailing_lists.html.twig):
// activating / deactivating and deleting a custom list. What a list is
// made of — its member groups and contact groups — is summarised by the
// page and edited nowhere on it.
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/mass-mail-lists.test.js).
//
// Same corrections as config-badges.js and finance-categories.js: the
// file-local csrf() reader is gone (ScoutMagicApi.postJson carries the
// token in both the body and the X-CSRF-Token header), HTTP success and
// business success are two separate checks — a 500 error page is not
// JSON, so it surfaces as a failure instead of throwing inside
// res.json() — and failures are toasts. The confirm() guarding the
// permanent list deletion is ScoutMagicConfirm (design.md §7.5): a native
// box gives a permanent delete the same two buttons as a harmless
// question, in the browser's language rather than French.

(function () {
    // A no-op on every other page of the site.
    if (!document.getElementById('cfg-custom-lists')) {
        return;
    }

    var api = window.ScoutMagicApi;

    /**
     * The failure line for one envelope: the server's own message when it
     * answered JSON, the generic « Erreur. » when it answered JSON without
     * one, and a distinct line when it did not answer JSON at all
     * (postJson folds a network error or an HTML error page into
     * data:null — reading that as a success is the bug this replaces).
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
     * Business success — never `res.ok`, which only says the server
     * answered properly.
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {boolean}
     */
    function isSuccess(res) {
        return !!(res.data?.success);
    }

    /** @param {{ok: boolean, status: number, data: any}} res */
    function toastError(res) {
        window.ScoutMagicToast.show(errorMessage(res), { variant: 'error' });
    }

    /** @type {NodeListOf<HTMLElement>} */
    (document.querySelectorAll('.cfg-toggle-list-btn')).forEach(function (btn) {
        btn.addEventListener('click', async function () {
            // Deactivating hides the list from the composer; it destroys
            // nothing and is undone by the same button — no confirmation.
            var active = btn.dataset.active === '1';
            var res = await api.postJson('/admin/listes-de-diffusion/lists/' + btn.dataset.id + '/toggle', { active: !active });
            if (!isSuccess(res)) {
                toastError(res);
                return;
            }
            window.location.reload();
        });
    });

    /** @type {NodeListOf<HTMLElement>} */
    (document.querySelectorAll('.cfg-delete-list-btn')).forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var confirmed = await window.ScoutMagicConfirm.ask({
                message: 'Supprimer cette liste ?',
                confirmLabel: 'Supprimer'
            });
            if (!confirmed) {
                return;
            }
            var res = await api.postJson('/admin/listes-de-diffusion/lists/' + btn.dataset.id, {}, { method: 'DELETE' });
            if (!isSuccess(res)) {
                toastError(res);
                return;
            }
            window.location.reload();
        });
    });
})();
