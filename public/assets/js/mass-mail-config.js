/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Mass-mail configuration page (modules/mass_mail/views/config.html.twig):
// the custom mailing lists (create / edit / activate / delete through the
// shared modal) and the site-wide sending speed form.
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/mass-mail-config.test.js).
//
// Same corrections as config-badges.js and finance-categories.js: the
// file-local csrf() reader is gone (ScoutMagicApi.postJson carries the
// token in both the body and the X-CSRF-Token header), HTTP success and
// business success are two separate checks again — a 500 error page is not
// JSON, so it now surfaces as a failure instead of throwing inside
// res.json() — and the four alert() calls are toasts. The confirm()
// guarding the permanent list deletion is ScoutMagicConfirm (design.md
// §7.5): a native box gives a permanent delete the same two buttons as a
// harmless question, in the browser's language rather than French.
//
// The current sending interval comes from the server through
// window.massMailConfigData, set by an inline nonce-tagged script in the
// template — the window.llmConfigData / window.supportDashboardCharts
// pattern.
(function () {
    var modalEl = document.getElementById('cfg-list-modal');
    var settingsForm = /** @type {HTMLFormElement|null} */ (document.getElementById('cfg-settings-form'));

    // A no-op on every other page of the site — this file used to
    // dereference #cfg-batch-interval unconditionally and threw wherever it
    // was absent.
    if (!modalEl && !settingsForm) {
        return;
    }

    var api = window.ScoutMagicApi;
    var pageData = window.massMailConfigData || {};

    /**
     * @param {string} id
     * @returns {HTMLElement}
     */
    function el(id) { return /** @type {HTMLElement} */ (document.getElementById(id)); }

    /**
     * @param {string} id
     * @returns {HTMLInputElement}
     */
    function inputEl(id) { return /** @type {HTMLInputElement} */ (document.getElementById(id)); }

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
        return !!(res.data && res.data.success);
    }

    /** @param {{ok: boolean, status: number, data: any}} res */
    function toastError(res) {
        window.ScoutMagicToast.show(errorMessage(res), { variant: 'error' });
    }

    // --- Custom mailing lists ---

    // Wired only when the modal is on the page; a named function rather
    // than a bare `if` block so every helper below keeps function scope.
    /** @param {HTMLElement} modalEl */
    function wireListModal(modalEl) {
        var modal = window.bootstrap ? new window.bootstrap.Modal(modalEl) : null;
        /** @type {string|null} */
        var currentListId = null;

        function showModal() {
            if (modal) {
                modal.show();
            } else {
                modalEl.classList.add('show');
            }
        }

        /** @param {string} message */
        function showModalError(message) {
            var box = el('cfg-list-error');
            box.textContent = message;
            box.classList.remove('d-none');
        }

        function resetModal() {
            currentListId = null;
            el('cfg-list-modal-title').textContent = 'Nouvelle liste';
            el('cfg-list-error').classList.add('d-none');
            inputEl('cfg-list-name').value = '';
            inputEl('cfg-list-description').value = '';
            /** @type {NodeListOf<HTMLInputElement>} */
            (document.querySelectorAll('.cfg-function-checkbox, .cfg-section-checkbox')).forEach(function (cb) {
                cb.checked = false;
            });
        }

        document.getElementById('cfg-new-list-btn')?.addEventListener('click', function () {
            resetModal();
            showModal();
        });

        /** @type {NodeListOf<HTMLElement>} */
        (document.querySelectorAll('.cfg-edit-list-btn')).forEach(function (btn) {
            btn.addEventListener('click', function () {
                resetModal();
                currentListId = btn.dataset.id;
                el('cfg-list-modal-title').textContent = 'Modifier la liste';
                inputEl('cfg-list-name').value = btn.dataset.name;
                inputEl('cfg-list-description').value = btn.dataset.description || '';
                var functionIds = btn.dataset.functionIds ? btn.dataset.functionIds.split(',') : [];
                var sectionIds = btn.dataset.sectionIds ? btn.dataset.sectionIds.split(',') : [];
                functionIds.forEach(function (id) {
                    var cb = inputEl('cfg-fn-' + id);
                    if (cb) cb.checked = true;
                });
                sectionIds.forEach(function (id) {
                    var cb = inputEl('cfg-sec-' + id);
                    if (cb) cb.checked = true;
                });
                showModal();
            });
        });

        /**
         * The checked values of one checkbox group, as integers.
         *
         * @param {string} selector
         * @returns {number[]}
         */
        function checkedIds(selector) {
            return Array.from(/** @type {NodeListOf<HTMLInputElement>} */ (document.querySelectorAll(selector)))
                .map(function (cb) { return parseInt(cb.value, 10); });
        }

        document.getElementById('cfg-list-save-btn')?.addEventListener('click', async function () {
            var payload = {
                name: inputEl('cfg-list-name').value,
                description: inputEl('cfg-list-description').value,
                function_ids: checkedIds('.cfg-function-checkbox:checked'),
                section_ids: checkedIds('.cfg-section-checkbox:checked')
            };

            var url = currentListId ? '/config/mass-mail/lists/' + currentListId : '/config/mass-mail/lists';
            var method = currentListId ? 'PATCH' : 'POST';
            var res = await api.postJson(url, payload, { method: method });
            if (!isSuccess(res)) {
                // An error about the form's own fields belongs inside the
                // form, next to what has to be corrected — not in a toast
                // that slides away while the modal is still open.
                showModalError(errorMessage(res));
                return;
            }
            window.location.reload();
        });
    }

    if (modalEl) {
        wireListModal(modalEl);
    }

    /** @type {NodeListOf<HTMLElement>} */
    (document.querySelectorAll('.cfg-toggle-list-btn')).forEach(function (btn) {
        btn.addEventListener('click', async function () {
            // Deactivating hides the list from the composer; it destroys
            // nothing and is undone by the same button — no confirmation.
            var active = btn.dataset.active === '1';
            var res = await api.postJson('/config/mass-mail/lists/' + btn.dataset.id + '/toggle', { active: !active });
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
            var res = await api.postJson('/config/mass-mail/lists/' + btn.dataset.id, {}, { method: 'DELETE' });
            if (!isSuccess(res)) {
                toastError(res);
                return;
            }
            window.location.reload();
        });
    });

    // --- Sending speed (global to the whole site) ---

    if (settingsForm) {
        var intervalInput = inputEl('cfg-batch-interval');
        if (intervalInput && pageData.batchIntervalMinutes != null) {
            intervalInput.value = String(pageData.batchIntervalMinutes);
        }

        settingsForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            var res = await api.postJson('/config/mass-mail/settings', {
                batch_size: parseInt(inputEl('cfg-batch-size').value, 10),
                batch_interval_minutes: parseInt(inputEl('cfg-batch-interval').value, 10)
            });
            if (!isSuccess(res)) {
                toastError(res);
                return;
            }
            window.ScoutMagicToast.show('Réglages enregistrés.', { variant: 'success' });
        });
    }
})();
