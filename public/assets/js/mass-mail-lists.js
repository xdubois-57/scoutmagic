/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The mailing lists page (modules/mass_mail/views/mailing_lists.html.twig):
// the custom mailing lists, created / edited / activated / deleted through
// the shared modal.
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/mass-mail-lists.test.js).
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
// The site-wide sending speed used to be edited here too; it is not any
// more. `batch_size` and `batch_interval_minutes` are ordinary settings
// rows, so Configuration > Réglages already edits them, and a second
// editor for the same two values was a second thing to keep in step.
//
// The criteria are three select bars in mode:multi (design.md §1.4), and
// this file owns what they cannot say by themselves: the sentence the
// three of them add up to, and the number of members that sentence
// currently resolves to. The AND between axes is invisible in the
// controls — three separate pickers look like three independent filters —
// and it is exactly what turns « un badge » crossed with « une section
// d'animés » into zero recipients every time, badges being assignable
// only to the Staff d'U and to the chef/chef d'unité functions.
(function () {
    var modalEl = document.getElementById('cfg-list-modal');

    // A no-op on every other page of the site.
    if (!modalEl) {
        return;
    }

    var api = window.ScoutMagicApi;

    var FUNCTION_PICKER = 'cfg-function-picker';
    var SECTION_PICKER = 'cfg-section-picker';
    var BADGE_PICKER = 'cfg-badge-picker';

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
     * A `data-*-ids="2,3"` attribute as a list of ids. An absent or empty
     * attribute is no ids, never `['']` — which would select nothing and
     * look identical until somebody's badge happened to be id 0.
     *
     * @param {string|undefined} value
     * @returns {string[]}
     */
    function splitIds(value) {
        return value ? value.split(',').filter(function (id) { return id !== ''; }) : [];
    }

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

    /**
     * The list modal's own error line. Outside wireListModal() because it
     * reads the box by id and borrows nothing from that call.
     *
     * @param {string} message
     */
    function showModalError(message) {
        var box = el('cfg-list-error');
        box.textContent = message;
        box.classList.remove('d-none');
    }

    /** @param {{ok: boolean, status: number, data: any}} res */
    function toastError(res) {
        window.ScoutMagicToast.show(errorMessage(res), { variant: 'error' });
    }

    // --- The three criteria axes ---

    /**
     * The ids currently selected in one select bar, as integers.
     *
     * @param {string} pickerId
     * @returns {number[]}
     */
    function selectedIds(pickerId) {
        var container = document.getElementById(pickerId);
        if (!container) return [];
        return Array.from(container.querySelectorAll('.select-bar-item[data-selected="true"]'))
            .map(function (item) { return Number.parseInt(/** @type {HTMLElement} */ (item).dataset.id, 10); })
            .filter(function (id) { return !Number.isNaN(id); });
    }

    /**
     * @returns {{function_ids: number[], section_ids: number[], badge_ids: number[]}}
     */
    function currentCriteria() {
        return {
            function_ids: selectedIds(FUNCTION_PICKER),
            section_ids: selectedIds(SECTION_PICKER),
            badge_ids: selectedIds(BADGE_PICKER)
        };
    }

    /**
     * Sets one select bar's selection to exactly `ids`, through the
     * component's own escape hatch so the panel row, the check mark and
     * the trigger summary all stay in step.
     *
     * @param {string} pickerId
     * @param {(number|string)[]} ids
     */
    function setPickerSelection(pickerId, ids) {
        var container = document.getElementById(pickerId);
        if (!container || !window.SelectBar) return;
        var wanted = ids.map(String);
        container.querySelectorAll('.select-bar-item').forEach(function (item) {
            var id = /** @type {HTMLElement} */ (item).dataset.id || '';
            window.SelectBar.setSelected(pickerId, id, wanted.indexOf(id) !== -1);
        });
    }

    /**
     * One axis's clause, or null when the axis constrains nothing.
     *
     * Singular and plural are two different sentences rather than one with
     * an « (s) » in it: « une des 1 fonctions choisies » is what a
     * template produces and nobody writes.
     *
     * @param {number} count
     * @param {string} singular e.g. « exercent la fonction choisie »
     * @param {string} plural  with %d for the count
     * @returns {string|null}
     */
    function axisClause(count, singular, plural) {
        if (count === 0) return null;
        return count === 1 ? singular : plural.replace('%d', String(count));
    }

    /**
     * The sentence the three axes add up to. An axis that constrains
     * nothing disappears from it entirely — leaving it in is precisely
     * where the ET becomes ambiguous, because « et sont dans aucune
     * section » reads as a restriction rather than as its absence.
     *
     * @param {{function_ids: number[], section_ids: number[], badge_ids: number[]}} criteria
     * @returns {string}
     */
    function criteriaSentence(criteria) {
        var clauses = [
            axisClause(criteria.function_ids.length,
                'exercent la fonction choisie', 'exercent une des %d fonctions choisies'),
            axisClause(criteria.section_ids.length,
                'sont dans la section choisie', 'sont dans une des %d sections choisies'),
            axisClause(criteria.badge_ids.length,
                'portent le badge choisi', 'portent un des %d badges choisis')
        ].filter(function (clause) { return clause !== null; });

        if (clauses.length === 0) {
            return 'Aucun membre du site dans cette liste.';
        }
        return 'Membres qui ' + clauses.join(' ET ') + '.';
    }

    /**
     * The live count, asked of the server after every change.
     *
     * Sequenced rather than debounced: each request carries the token of
     * the call that started it, and an answer that is no longer the latest
     * is dropped. Toggling three rows quickly otherwise leaves whichever
     * response happens to come back last on screen, which is the one case
     * a counter must never get wrong.
     */
    var countToken = 0;

    /** @param {{function_ids: number[], section_ids: number[], badge_ids: number[]}} criteria */
    async function refreshCount(criteria) {
        var countEl = el('cfg-criteria-count');
        if (!countEl) return;

        var yearLabel = countEl.dataset.yearLabel || '';
        var token = ++countToken;

        if (criteria.function_ids.length === 0
            && criteria.section_ids.length === 0
            && criteria.badge_ids.length === 0) {
            countEl.textContent = '';
            countEl.className = 'small mb-0';
            return;
        }

        countEl.textContent = 'Comptage…';
        countEl.className = 'small mb-0 text-body-secondary';

        var res = await api.postJson('/admin/listes-de-diffusion/preview-count', criteria);
        if (token !== countToken) {
            return;
        }
        if (!isSuccess(res)) {
            countEl.textContent = 'Le nombre de destinataires n\'a pas pu être calculé.';
            countEl.className = 'small mb-0 text-body-secondary';
            return;
        }

        var count = res.data.count;
        var year = res.data.scout_year_label || yearLabel;
        if (count === 0) {
            countEl.textContent = '0 destinataire pour l\'année ' + year + ' — vérifiez le croisement des '
                + 'critères : un badge n\'est porté que par le Staff d\'U et les animateurs, jamais par un animé.';
            countEl.className = 'small mb-0 text-danger';
            return;
        }
        countEl.textContent = count + (count === 1 ? ' destinataire' : ' destinataires')
            + ' pour l\'année ' + year + '.';
        countEl.className = 'small mb-0 text-body-secondary';
    }

    function refreshCriteria() {
        var criteria = currentCriteria();
        var sentenceEl = el('cfg-criteria-sentence');
        if (sentenceEl) {
            sentenceEl.textContent = criteriaSentence(criteria);
        }
        void refreshCount(criteria);
    }

    [FUNCTION_PICKER, SECTION_PICKER, BADGE_PICKER].forEach(function (pickerId) {
        document.getElementById(pickerId)?.addEventListener('select-bar:change', refreshCriteria);
    });

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

        function resetModal() {
            currentListId = null;
            el('cfg-list-modal-title').textContent = 'Nouvelle liste';
            el('cfg-list-error').classList.add('d-none');
            inputEl('cfg-list-name').value = '';
            inputEl('cfg-list-description').value = '';
            setPickerSelection(FUNCTION_PICKER, []);
            setPickerSelection(SECTION_PICKER, []);
            setPickerSelection(BADGE_PICKER, []);
            refreshCriteria();
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
                setPickerSelection(FUNCTION_PICKER, splitIds(btn.dataset.functionIds));
                setPickerSelection(SECTION_PICKER, splitIds(btn.dataset.sectionIds));
                setPickerSelection(BADGE_PICKER, splitIds(btn.dataset.badgeIds));
                refreshCriteria();
                showModal();
            });
        });

        document.getElementById('cfg-list-save-btn')?.addEventListener('click', async function () {
            var criteria = currentCriteria();
            var payload = {
                name: inputEl('cfg-list-name').value,
                description: inputEl('cfg-list-description').value,
                function_ids: criteria.function_ids,
                section_ids: criteria.section_ids,
                badge_ids: criteria.badge_ids
            };

            var url = currentListId ? '/admin/listes-de-diffusion/lists/' + currentListId : '/admin/listes-de-diffusion/lists';
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
