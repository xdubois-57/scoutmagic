/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Badges configuration page (core/View/templates/config/badges.html.twig):
// renaming a custom badge, activating/deactivating any badge, deleting an
// unassigned custom one, and adding a new one.
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/config-badges.test.js).
//
// Same three corrections as config-functions.js: the file-local badgeCsrf()
// reader is gone (ScoutMagicApi.postJson carries the token), HTTP success
// and business success are two separate checks again, and the four alert()
// failures are toasts. The confirm() guarding the permanent deletion is
// ScoutMagicConfirm (design.md §7.5) — a native box gives a permanent
// delete the same two buttons as a harmless question, in the browser's
// language rather than French.
//
// The « Actif » control is a role="switch" checkbox whose aria-checked
// nav.js syncs on `change`. A failed toggle reverts `checked` in code,
// which fires no event, so this file calls
// ScoutMagicNav.syncSwitchAriaChecked() itself — otherwise a screen reader
// keeps announcing the state the server refused.
(function () {
    var api = window.ScoutMagicApi;

    /** @type {NodeListOf<HTMLElement>} */
    var badgeRows = document.querySelectorAll('.badge-row');
    var addBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('badge-add-btn'));
    var newNameInput = /** @type {HTMLInputElement|null} */ (document.getElementById('new-badge-name'));

    // A no-op on every other page of the site — #badge-add-btn used to be
    // dereferenced unconditionally and threw wherever it was absent.
    if (!badgeRows.length && !addBtn) {
        return;
    }

    /**
     * The failure line for one envelope: the server's own message when it
     * answered JSON, a generic one when it did not (postJson folds a
     * network error or an HTML error page into data:null).
     *
     * @param {{ok: boolean, status: number, data: any}} res
     */
    function toastError(res) {
        var message = (res.data && res.data.error) || 'Erreur : réponse serveur invalide.';
        window.ScoutMagicToast.show(message, { variant: 'error' });
    }

    /** @param {{ok: boolean, status: number, data: any}} res */
    function isSuccess(res) {
        return !!(res.data && res.data.success);
    }

    badgeRows.forEach(function (row) {
        var badgeId = parseInt(row.dataset.id, 10);
        var isDefault = row.dataset.default === '1';
        var nameInput = /** @type {HTMLInputElement|null} */ (row.querySelector('.badge-name-input'));
        var activeInput = /** @type {HTMLInputElement|null} */ (row.querySelector('.badge-active-input'));
        var deleteBtn = /** @type {HTMLButtonElement|null} */ (row.querySelector('.badge-delete-btn'));

        // A default badge's name is server-owned: the input is readonly and
        // saving it is not offered at all.
        if (!isDefault && nameInput) {
            nameInput.addEventListener('blur', function () {
                api.postJson('/config/badges/update', { badge_id: badgeId, name: nameInput.value })
                    .then(function (res) {
                        if (isSuccess(res)) {
                            // A rename can rewrite the badge everywhere it
                            // appears — the page re-renders rather than
                            // patching each occurrence by hand.
                            window.location.reload();
                        } else {
                            toastError(res);
                        }
                    });
            });
        }

        if (activeInput) {
            activeInput.addEventListener('change', function () {
                api.withDisabled(activeInput, function () {
                    return api.postJson('/config/badges/toggle-active', {
                        badge_id: badgeId,
                        active: activeInput.checked
                    });
                }).then(function (res) {
                    if (isSuccess(res)) {
                        return;
                    }
                    toastError(res);
                    // The server refused: put the switch back where it was,
                    // and tell assistive technology about it (no `change`
                    // event fires for a programmatic revert).
                    activeInput.checked = !activeInput.checked;
                    if (window.ScoutMagicNav && window.ScoutMagicNav.syncSwitchAriaChecked) {
                        window.ScoutMagicNav.syncSwitchAriaChecked(activeInput);
                    }
                });
            });
        }

        if (deleteBtn) {
            deleteBtn.addEventListener('click', async function () {
                var confirmed = await window.ScoutMagicConfirm.ask({
                    message: 'Supprimer définitivement ce badge ?',
                    confirmLabel: 'Supprimer'
                });
                if (!confirmed) {
                    return;
                }
                var res = await api.postJson('/config/badges/delete', { badge_id: badgeId });
                if (isSuccess(res)) {
                    row.remove();
                } else {
                    toastError(res);
                }
            });
        }
    });

    if (addBtn && newNameInput) {
        addBtn.addEventListener('click', function () {
            api.withDisabled(addBtn, function () {
                return api.postJson('/config/badges/add', { name: newNameInput.value });
            }).then(function (res) {
                if (isSuccess(res)) {
                    window.location.reload();
                } else {
                    toastError(res);
                }
            });
        });
    }
})();
