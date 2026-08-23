/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Finance module categories configuration page
// (modules/finance/views/config/categories.html.twig): category CRUD, the
// categorization rules (CRUD, test, drag-and-drop + move-button
// reordering, AI rule toggle) and the background "run rules" tracking.
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/finance-categories.test.js).
//
// Fetches ride the site-wide ScoutMagicApi envelope; failure feedback that
// used to be alert() is a toast now, and the background run's completion
// summary lands in the persistent #run-rules-result block (it used to be a
// one-shot alert() nobody could re-read) plus a completion toast. The
// status polling loop rides ScoutMagicApi.poll. Every confirmation goes
// through window.ScoutMagicConfirm (design.md §7.5), each naming its own
// action on the confirm button rather than a generic « OK ».
(function () {
    const rulesList = document.getElementById('rules-list');
    if (!rulesList || !document.getElementById('category-form')) return;

    const api = window.ScoutMagicApi;

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

    /** @param {string} message */
    function toastError(message) {
        window.ScoutMagicToast.show(message || 'Erreur.', { variant: 'error' });
    }

    // --- Categories ---
    const categoryModalEl = el('category-modal');
    const categoryModal = window.bootstrap ? new window.bootstrap.Modal(categoryModalEl) : null;
    const categoryErrorBox = el('category-form-error');

    /** @param {string} title */
    function showCategoryModal(title) {
        el('category-modal-title').textContent = title;
        categoryErrorBox.classList.add('d-none');
        categoryModal ? categoryModal.show() : categoryModalEl.classList.add('show');
    }

    el('new-category-btn').addEventListener('click', () => {
        inputEl('category-id').value = '';
        inputEl('category-name').value = '';
        inputEl('category-description').value = '';
        showCategoryModal('Nouvelle catégorie');
    });

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.edit-category-btn')).forEach(btn => {
        btn.addEventListener('click', () => {
            inputEl('category-id').value = btn.dataset.id;
            inputEl('category-name').value = btn.dataset.name;
            inputEl('category-description').value = btn.dataset.description;
            showCategoryModal('Modifier la catégorie');
        });
    });

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.category-suggestion-btn')).forEach(btn => {
        btn.addEventListener('click', () => {
            inputEl('category-name').value = btn.dataset.name;
        });
    });

    el('category-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = inputEl('category-id').value;
        const payload = /** @type {{action: string, name: string, description: string, id?: number}} */ ({
            action: id ? 'update' : 'create',
            name: inputEl('category-name').value,
            description: inputEl('category-description').value
        });
        if (id) payload.id = parseInt(id, 10);

        const res = await api.postJson('/config/finance/categories', payload);
        if (res.data && res.data.success) {
            window.location.reload();
        } else {
            categoryErrorBox.textContent = (res.data && res.data.error) || 'Erreur.';
            categoryErrorBox.classList.remove('d-none');
        }
    });

    /**
     * @param {string} action
     * @param {number} id
     */
    async function postCategoryAction(action, id) {
        const res = await api.postJson('/config/finance/categories', { action: action, id: id });
        if (res.data && res.data.success) {
            window.location.reload();
        } else {
            toastError(res.data && res.data.error);
        }
    }

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.toggle-category-btn')).forEach(btn => {
        btn.addEventListener('click', () => postCategoryAction(btn.dataset.active === '1' ? 'deactivate' : 'activate', parseInt(btn.dataset.id, 10)));
    });

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.delete-category-btn')).forEach(btn => {
        btn.addEventListener('click', async () => {
            const confirmed = await window.ScoutMagicConfirm.ask({
                message: 'Supprimer cette catégorie ?',
                confirmLabel: 'Supprimer'
            });
            if (confirmed) {
                postCategoryAction('delete', parseInt(btn.dataset.id, 10));
            }
        });
    });

    el('reset-default-categories-btn').addEventListener('click', async () => {
        // Recreating a missing default touches nothing that exists — a
        // primary confirmation, not the danger one a delete gets.
        const confirmed = await window.ScoutMagicConfirm.ask({
            message: 'Recréer les catégories par défaut qui auraient été supprimées ? Les catégories existantes ne sont pas modifiées.',
            confirmLabel: 'Recréer',
            variant: 'primary'
        });
        if (!confirmed) {
            return undefined;
        }
        const res = await api.postJson('/config/finance/categories', { action: 'reset_defaults' });
        if (res.data && res.data.success) {
            window.location.reload();
        } else {
            toastError(res.data && res.data.error);
        }
    });

    // --- Categorization rules ---
    const ruleModalEl = el('rule-modal');
    const ruleModal = window.bootstrap ? new window.bootstrap.Modal(ruleModalEl) : null;
    const ruleErrorBox = el('rule-form-error');

    /** @param {string} title */
    function showRuleModal(title) {
        el('rule-modal-title').textContent = title;
        ruleErrorBox.classList.add('d-none');
        ruleModal ? ruleModal.show() : ruleModalEl.classList.add('show');
    }

    el('new-rule-btn').addEventListener('click', () => {
        /** @type {HTMLFormElement} */ (el('rule-form')).reset();
        inputEl('rule-id').value = '';
        el('test-rule-result').textContent = '';
        showRuleModal('Nouvelle règle');
    });

    el('reset-default-rules-btn').addEventListener('click', async () => {
        const confirmed = await window.ScoutMagicConfirm.ask({
            message: "Réinitialiser les règles par défaut ? Toute modification apportée aux règles marquées « Par défaut » sera perdue. Vos propres règles ne seront pas touchées.",
            confirmLabel: 'Réinitialiser'
        });
        if (!confirmed) {
            return undefined;
        }
        const res = await api.postJson('/config/finance/rules', { action: 'reset_defaults' });
        if (res.data && res.data.success) {
            window.location.reload();
        } else {
            toastError(res.data && res.data.error);
        }
    });

    const runRulesBtn = /** @type {HTMLButtonElement} */ (el('run-rules-btn'));
    const runRulesStatus = el('run-rules-status');
    /** @type {{stop: () => void}|null} */
    let runRulesPoll = null;

    /** @param {boolean} running */
    function setRunRulesRunning(running) {
        runRulesBtn.disabled = running;
        runRulesStatus.classList.toggle('d-none', !running);
    }

    // The completion summary is a persistent, re-readable block on the
    // page — the alert() it replaces vanished with one click and the
    // numbers with it. The toast only announces that the block appeared.
    function showRunRulesResult(lastResult) {
        if (!lastResult) return;
        const box = el('run-rules-result');
        box.textContent = 'Dernière exécution : '
            + lastResult.categorized_by_rules + ' mouvement(s) catégorisé(s) par les règles, '
            + lastResult.categorized_by_ai + ' par l\'IA, '
            + lastResult.still_uncategorized + ' toujours non catégorisé(s).';
        box.classList.remove('d-none');
        window.ScoutMagicToast.show('Catégorisation terminée.');
    }

    // Follows an in-progress background run: no overlapping ticks, catch-up
    // when the tab becomes visible again — ScoutMagicApi.poll's behaviours,
    // which the setTimeout loop this replaces only half had. A failed
    // status read keeps the rhythm instead of silently abandoning the run.
    function startRunRulesPolling() {
        if (runRulesPoll) return;
        runRulesPoll = api.poll(async () => {
            const res = await api.postJson('/config/finance/rules', { action: 'run_status' });
            const data = res.data;
            if (!data || !data.success) {
                return undefined; // transient failure — keep polling
            }
            setRunRulesRunning(data.running);
            if (data.running) {
                return undefined;
            }
            runRulesPoll = null;
            showRunRulesResult(data.last_result);
            return false;
        }, { intervalMs: 3000 });
    }

    runRulesBtn.addEventListener('click', async () => {
        // Categorising uncategorised movements adds information, it never
        // removes any — primary, not danger.
        const confirmed = await window.ScoutMagicConfirm.ask({
            message: "Appliquer les règles de catégorisation à tous les mouvements non catégorisés ? Cette opération se déroule en arrière-plan.",
            confirmLabel: 'Appliquer',
            variant: 'primary'
        });
        if (!confirmed) {
            return undefined;
        }

        const res = await api.postJson('/config/finance/rules', { action: 'run_on_uncategorized' });
        if (res.data && res.data.success) {
            setRunRulesRunning(true);
            startRunRulesPolling();
        } else {
            toastError(res.data && res.data.error);
        }
    });

    // Reflect an already-in-progress run on page load (e.g. the admin
    // navigated away and back while it was still running).
    (async () => {
        const res = await api.postJson('/config/finance/rules', { action: 'run_status' });
        if (res.data && res.data.success && res.data.running) {
            setRunRulesRunning(true);
            startRunRulesPolling();
        }
    })();

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.edit-rule-btn')).forEach(btn => {
        btn.addEventListener('click', () => {
            inputEl('rule-id').value = btn.dataset.id;
            inputEl('rule-keyword-pattern').value = btn.dataset.keywordPattern;
            inputEl('rule-counterparty-account-pattern').value = btn.dataset.counterpartyAccountPattern;
            inputEl('rule-amount-range').value = btn.dataset.amountRange;
            /** @type {HTMLSelectElement} */ (el('rule-category')).value = btn.dataset.categoryId;
            el('test-rule-result').textContent = '';
            showRuleModal('Modifier la règle');
        });
    });

    function ruleConditionsPayload() {
        return {
            keyword_pattern: inputEl('rule-keyword-pattern').value,
            counterparty_account_pattern: inputEl('rule-counterparty-account-pattern').value,
            amount_range: inputEl('rule-amount-range').value
        };
    }

    el('test-rule-btn').addEventListener('click', async () => {
        const res = await api.postJson('/config/finance/rules', Object.assign({ action: 'test' }, ruleConditionsPayload()));
        const resultBox = el('test-rule-result');
        if (res.data && res.data.success) {
            resultBox.textContent = res.data.count + ' mouvement(s) existant(s) correspondent.';
        } else {
            resultBox.textContent = (res.data && res.data.error) || 'Erreur.';
        }
    });

    el('rule-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = inputEl('rule-id').value;
        const payload = /** @type {Object.<string, any>} */ (Object.assign({
            action: id ? 'update' : 'create',
            category_id: parseInt(/** @type {HTMLSelectElement} */ (el('rule-category')).value, 10)
        }, ruleConditionsPayload()));
        if (id) payload.id = parseInt(id, 10);

        const res = await api.postJson('/config/finance/rules', payload);
        if (res.data && res.data.success) {
            window.location.reload();
        } else {
            ruleErrorBox.textContent = (res.data && res.data.error) || 'Erreur.';
            ruleErrorBox.classList.remove('d-none');
        }
    });

    /**
     * @param {string} action
     * @param {Object} extra
     */
    async function postRuleAction(action, extra) {
        const res = await api.postJson('/config/finance/rules', Object.assign({ action: action }, extra));
        if (res.data && res.data.success) {
            window.location.reload();
        } else {
            toastError(res.data && res.data.error);
        }
    }

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.toggle-rule-btn')).forEach(btn => {
        btn.addEventListener('click', () => postRuleAction(btn.dataset.active === '1' ? 'deactivate' : 'activate', { id: parseInt(btn.dataset.id, 10) }));
    });

    document.getElementById('toggle-ai-rule-btn')?.addEventListener('click', async (e) => {
        const btn = /** @type {HTMLElement} */ (e.currentTarget);
        const res = await api.postJson('/config/finance/rules', { action: 'set_ai_enabled', enabled: btn.dataset.active !== '1' });
        if (res.data && res.data.success) {
            window.location.reload();
        } else {
            toastError(res.data && res.data.error);
        }
    });

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.delete-rule-btn')).forEach(btn => {
        btn.addEventListener('click', async () => {
            const confirmed = await window.ScoutMagicConfirm.ask({
                message: 'Supprimer cette règle ?',
                confirmLabel: 'Supprimer'
            });
            if (confirmed) {
                postRuleAction('delete', { id: parseInt(btn.dataset.id, 10) });
            }
        });
    });

    // --- Drag-and-drop reordering ---
    let draggedRow = null;

    /** @type {NodeListOf<HTMLElement>} */ (rulesList.querySelectorAll('div[data-id]')).forEach(row => {
        row.addEventListener('dragstart', () => {
            draggedRow = row;
            row.classList.add('opacity-50');
        });
        row.addEventListener('dragend', () => {
            row.classList.remove('opacity-50');
            draggedRow = null;
        });
        row.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (!draggedRow || draggedRow === row) {
                return undefined;
            }
            const rect = row.getBoundingClientRect();
            const before = (e.clientY - rect.top) < rect.height / 2;
            row.parentNode.insertBefore(draggedRow, before ? row : row.nextSibling);
        });
        row.addEventListener('drop', async (e) => {
            e.preventDefault();
            const ids = orderedRuleIds();
            const res = await api.postJson('/config/finance/rules', { action: 'reorder', ordered_ids: ids });
            if (!res.data || !res.data.success) {
                toastError(res.data && res.data.error);
                window.location.reload();
            }
            // On success the list is already visually reordered by the
            // drag-and-drop handlers above — no reload needed.
        });
    });

    /** @returns {number[]} */
    function orderedRuleIds() {
        return Array.from(/** @type {NodeListOf<HTMLElement>} */ (rulesList.querySelectorAll('div[data-id]')))
            .map(r => parseInt(r.dataset.id, 10));
    }

    // --- Move up/down (touch-friendly alternative to drag-and-drop for rules) ---
    function persistRuleOrder() {
        api.postJson('/config/finance/rules', { action: 'reorder', ordered_ids: orderedRuleIds() })
            .then(res => { if (!res.data || !res.data.success) toastError(res.data && res.data.error); });
    }
    function updateRuleMoveButtons() {
        const items = Array.from(rulesList.querySelectorAll('.rule-item'));
        items.forEach((item, i) => {
            const up = /** @type {HTMLButtonElement|null} */ (item.querySelector('.rule-move-up'));
            const down = /** @type {HTMLButtonElement|null} */ (item.querySelector('.rule-move-down'));
            if (up) up.disabled = (i === 0);
            if (down) down.disabled = (i === items.length - 1);
        });
    }
    rulesList.querySelectorAll('.rule-move-up').forEach(btn => {
        btn.addEventListener('click', () => {
            const item = btn.closest('.rule-item');
            const prev = item.previousElementSibling;
            if (prev && prev.classList.contains('rule-item')) {
                prev.before(item);
                persistRuleOrder();
                updateRuleMoveButtons();
            }
        });
    });
    rulesList.querySelectorAll('.rule-move-down').forEach(btn => {
        btn.addEventListener('click', () => {
            const item = btn.closest('.rule-item');
            const next = item.nextElementSibling;
            if (next && next.classList.contains('rule-item')) {
                item.before(next);
                persistRuleOrder();
                updateRuleMoveButtons();
            }
        });
    });
})();
