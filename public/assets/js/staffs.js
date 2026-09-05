/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Staffs page (core/View/templates/chefs/staffs.html.twig), the three
// things a section chief can change there without a submit button: the
// per-document title/description auto-save on blur, the advisory
// oversize-upload warning, and the per-member badge picker.
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/staffs.test.js).
//
// Four things changed on the way out of the template:
//   - both hand-written meta[name="csrf-token"] readers are gone; every
//     request rides ScoutMagicApi.postJson, which carries the token in the
//     body AND the header;
//   - HTTP success (res.ok) and business success (res.data.success) are two
//     separate facts again — the raw fetch + res.json() this replaces read
//     an HTML 500 page as a parse exception, never as a failed save;
//   - the two alert() failures are toasts and the confirm() is
//     window.ScoutMagicConfirm (design.md §7.5): a native box shows the
//     origin above the message and labels its buttons in the browser's
//     language, not French;
//   - the oversize threshold, which Twig used to interpolate straight into
//     the script body, arrives through the `staffs-data` JSON island
//     (ScoutMagicApi.pageData()) — the site-wide pattern.
//
// The oversize warning's message keeps its three paragraphs word for word.
// Its closing line (« Cliquez sur OK pour ajouter ce fichier tel quel, ou
// sur Annuler pour choisir un autre fichier. ») is gone because it
// described the native box's two buttons: the dialog now names both actions
// on the buttons themselves, which is where §7.5 wants that sentence.
//
// The badge picker is partials/select_bar.html.twig in mode:multi.
// select-bar.js already flips the row's visual state optimistically on
// click and dispatches select-bar:change with the picker's full new
// selection (docs/module-development.md); this only translates that into
// the single-badge-toggle endpoint (one member_year_id + the one badge_id
// that just changed) and reverts the optimistic state when the server
// rejects the toggle.
(function () {
    var api = window.ScoutMagicApi;

    /** @type {NodeListOf<HTMLInputElement|HTMLTextAreaElement>} */
    var documentFields = document.querySelectorAll('.section-document-title-input, .section-document-description-input');
    var fileInput = /** @type {HTMLInputElement|null} */ (document.getElementById('section-document-file-input'));
    /** @type {NodeListOf<HTMLElement>} */
    var badgePickers = document.querySelectorAll('.badge-picker');

    // A no-op on every other page of the site, and on this one whenever the
    // chief cannot edit the section (the template renders none of these
    // controls then).
    if (!documentFields.length && !fileInput && !badgePickers.length) {
        return;
    }

    /**
     * The failure line for one envelope: the server's own message when it
     * answered JSON, a generic one when it did not (postJson folds a
     * network error or an HTML error page into data:null).
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @param {string} fallback
     * @returns {void}
     */
    function toastError(res, fallback) {
        var message = res.data?.error || fallback;
        window.ScoutMagicToast.show(message, { variant: 'error' });
    }

    // --- Section documents: title / description auto-save on blur ---
    // No save button on these two fields (module addendum), and separate
    // from list-editor.js's own reorder/delete wiring, which has no concept
    // of per-item editable fields.
    documentFields.forEach(function (field) {
        field.addEventListener('blur', function () {
            var row = /** @type {HTMLElement|null} */ (field.closest('.section-document-row'));
            if (!row) {
                return;
            }
            var titleInput = /** @type {HTMLInputElement|null} */ (row.querySelector('.section-document-title-input'));
            var descriptionInput = /** @type {HTMLTextAreaElement|null} */ (row.querySelector('.section-document-description-input'));
            if (!titleInput || !descriptionInput) {
                return;
            }

            api.postJson('/chefs/staffs/documents/' + encodeURIComponent(row.dataset.id), {
                title: titleInput.value,
                description: descriptionInput.value
            }).then(function (res) {
                if (!res.data?.success) {
                    toastError(res, 'Erreur.');
                }
            });
        });
    });

    // --- Oversize warning on the add-document upload ---
    // Shown only when no compression backend is available server-side
    // (module addendum: never when one is, whatever the file size, since
    // the server shrinks the file itself in that case — the template only
    // sets oversizeWarningEnabled in that configuration). Purely advisory:
    // it never blocks the upload, it only offers to shrink the file first
    // via iLovePDF or an offline alternative.
    var data = window.ScoutMagicApi.pageData('staffs-data');
    if (fileInput && data?.oversizeWarningEnabled) {
        var thresholdBytes = (data.oversizeWarningMb || 5) * 1024 * 1024;

        fileInput.addEventListener('change', async function () {
            var file = fileInput.files?.[0];
            if (!file || file.size <= thresholdBytes) {
                return;
            }

            var sizeMb = (file.size / 1024 / 1024).toFixed(1);
            // Adding the file as it is destroys nothing — a primary
            // confirmation, not the danger one a delete gets.
            var proceed = await window.ScoutMagicConfirm.ask({
                message: 'Ce fichier fait ' + sizeMb + ' Mo. Aucun outil de compression n\'est disponible sur ce serveur pour le réduire automatiquement.\n\n'
                    + 'Vous pouvez le réduire vous-même avant de l\'ajouter : en ligne via iLovePDF (ilovepdf.com), ou hors ligne avec Aperçu (macOS) '
                    + 'ou un outil équivalent sous Windows/Linux.\n\n'
                    + 'Attention si ce document contient des données personnelles avant de le transmettre à un service en ligne tiers.',
                confirmLabel: 'Ajouter quand même',
                cancelLabel: 'Choisir un autre fichier',
                variant: 'primary'
            });
            if (!proceed) {
                fileInput.value = '';
            }
        });
    }

    // --- Badge picker, one per staff member ---
    badgePickers.forEach(function (wrapper) {
        var picker = /** @type {HTMLElement|null} */ (wrapper.querySelector('.select-bar'));
        if (!picker) {
            return;
        }

        var memberYearId = Number.parseInt(wrapper.dataset.memberYearId, 10);
        if (!Number.isInteger(memberYearId) || memberYearId <= 0) {
            return;
        }

        var previousSelected = new Set(
            Array.prototype.slice
                .call(picker.querySelectorAll('.select-bar-item[data-selected="true"]'))
                .map(function (el) { return el.dataset.id; })
        );

        picker.addEventListener('select-bar:change', async function (e) {
            var nextSelected = new Set(/** @type {CustomEvent} */ (e).detail.selectedIds);
            /** @type {string|null} */
            var badgeId = null;
            nextSelected.forEach(function (id) { if (!previousSelected.has(id)) badgeId = id; });
            if (badgeId === null) {
                previousSelected.forEach(function (id) { if (!nextSelected.has(id)) badgeId = id; });
            }
            if (badgeId === null) {
                return;
            }

            var assigned = nextSelected.has(badgeId);
            var res = await api.postJson('/chefs/staffs/badge-toggle', {
                member_year_id: memberYearId,
                badge_id: Number.parseInt(badgeId, 10)
            });

            if (res.data?.success) {
                previousSelected = nextSelected;
                return;
            }
            // Revert the optimistic state select-bar.js already applied,
            // then say why. setSelected deliberately does not re-dispatch
            // select-bar:change (see select-bar.js), so this cannot loop.
            if (window.SelectBar?.setSelected) {
                window.SelectBar.setSelected(picker.id, badgeId, !assigned);
            }
            toastError(res, 'Erreur.');
        });
    });
})();
