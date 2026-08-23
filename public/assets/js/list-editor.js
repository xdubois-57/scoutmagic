/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Generic reusable list editor — see
// core/View/templates/partials/list_editor.html.twig. Knows nothing about
// what an item "is"; only handles the list chrome: native HTML5
// drag-and-drop reordering, the active toggle, delete (with confirm), and
// add. Every action posts to a caller-supplied URL read from the
// container's data-* attributes. Add and delete both reload the page
// (simplest way to stay correct when the set of items — and each item's
// caller-defined content — changes); reordering updates the DOM in place
// and persists silently in the background, since that's the whole point
// of drag-and-drop feeling instant.
(function () {
    // Requests go through the shared window.ScoutMagicApi.postJson envelope
    // ({ok, status, data} — never a rejection); each call site below reads
    // `res.data || {}` and branches on data.success as before.
    document.querySelectorAll('.list-editor').forEach(
        /** @param {HTMLElement} container */
        function (container) {
        var itemsEl = container.querySelector('.list-editor-items');
        var reorderUrl = container.dataset.reorderUrl;
        var activeUrl = container.dataset.activeUrl;
        var deleteUrl = container.dataset.deleteUrl;
        var addBtn = /** @type {HTMLButtonElement} */ (container.querySelector('.list-editor-add-btn'));
        var draggedItem = null;

        // --- Drag-and-drop reorder ---
        itemsEl.querySelectorAll('.list-editor-item').forEach(function (item) {
            item.addEventListener('dragstart', function () {
                draggedItem = item;
                item.classList.add('list-editor-item--dragging');
            });
            item.addEventListener('dragend', function () {
                item.classList.remove('list-editor-item--dragging');
                draggedItem = null;
                persistOrder();
            });
            item.addEventListener('dragover',
                /** @param {DragEvent} e */
                function (e) {
                e.preventDefault();
                if (!draggedItem || draggedItem === item) return;
                var rect = item.getBoundingClientRect();
                var after = (e.clientY - rect.top) > rect.height / 2;
                itemsEl.insertBefore(draggedItem, after ? item.nextSibling : item);
            });
        });

        function persistOrder() {
            if (!reorderUrl) return;
            // Sent as-is (not parseInt'd) — an item's id isn't always
            // numeric (e.g. the general configuration page's module list
            // uses each module's string id).
            var ids = Array.from(itemsEl.querySelectorAll('.list-editor-item')).map(
                /** @param {HTMLElement} el */
                function (el) {
                return el.dataset.id;
            });
            window.ScoutMagicApi.postJson(reorderUrl, { ids: ids }).then(function (res) {
                var data = res.data || {};
                if (!data.success) {
                    window.ScoutMagicToast.show(data.error || 'Erreur lors de la réorganisation.', { variant: 'error' });
                }
            });
        }

        // --- Move up/down (touch-friendly alternative to drag-and-drop) ---
        itemsEl.querySelectorAll('.list-editor-move-up').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var item = btn.closest('.list-editor-item');
                var prev = item.previousElementSibling;
                if (prev && prev.classList.contains('list-editor-item')) {
                    prev.before(item);
                    persistOrder();
                    updateMoveButtons();
                }
            });
        });
        itemsEl.querySelectorAll('.list-editor-move-down').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var item = btn.closest('.list-editor-item');
                var next = item.nextElementSibling;
                if (next && next.classList.contains('list-editor-item')) {
                    item.before(next);
                    persistOrder();
                    updateMoveButtons();
                }
            });
        });

        function updateMoveButtons() {
            var items = Array.from(itemsEl.querySelectorAll('.list-editor-item'));
            items.forEach(function (item, index) {
                var upBtn = /** @type {HTMLButtonElement} */ (item.querySelector('.list-editor-move-up'));
                var downBtn = /** @type {HTMLButtonElement} */ (item.querySelector('.list-editor-move-down'));
                if (upBtn) upBtn.disabled = (index === 0);
                if (downBtn) downBtn.disabled = (index === items.length - 1);
            });
        }

        // --- Active toggle (icon button, not a checkbox) ---
        itemsEl.querySelectorAll('.list-editor-active-toggle').forEach(
            /** @param {HTMLButtonElement} toggle */
            function (toggle) {
            toggle.addEventListener('click', function () {
                if (!activeUrl) return;
                var nextActive = toggle.dataset.active !== '1';
                toggle.disabled = true;
                window.ScoutMagicApi.postJson(activeUrl, { id: parseInt(toggle.dataset.id, 10), active: nextActive })
                    .then(function (res) {
                        var data = res.data || {};
                        toggle.disabled = false;
                        if (data.success) {
                            toggle.dataset.active = nextActive ? '1' : '0';
                            toggle.classList.toggle('btn-outline-success', nextActive);
                            toggle.classList.toggle('btn-outline-secondary', !nextActive);
                            toggle.title = nextActive ? 'Actif — cliquer pour désactiver' : 'Inactif — cliquer pour activer';
                            var icon = toggle.querySelector('i');
                            icon.classList.toggle('bi-toggle-on', nextActive);
                            icon.classList.toggle('bi-toggle-off', !nextActive);
                        } else {
                            window.ScoutMagicToast.show(data.error || 'Erreur.', { variant: 'error' });
                        }
                    });
            });
        });

        // --- Delete ---
        itemsEl.querySelectorAll('.list-editor-delete-btn').forEach(
            /** @param {HTMLButtonElement} btn */
            function (btn) {
            btn.addEventListener('click', async function () {
                if (btn.disabled) return;
                var confirmed = await window.ScoutMagicConfirm.ask({
                    message: 'Supprimer définitivement cet élément ?',
                    confirmLabel: 'Supprimer'
                });
                if (!confirmed) return;
                window.ScoutMagicApi.postJson(deleteUrl, { id: parseInt(btn.dataset.id, 10) }).then(function (res) {
                    var data = res.data || {};
                    if (data.success) {
                        window.location.reload();
                    } else {
                        window.ScoutMagicToast.show(data.error || 'Erreur lors de la suppression.', { variant: 'error' });
                    }
                });
            });
        });

        // --- Add --- (skipped when data-add-mode="custom" — the caller
        // wires its own .list-editor-add-btn handler instead, e.g. to
        // collect content in a dialog before anything is created)
        if (addBtn && container.dataset.addMode !== 'custom') {
            addBtn.addEventListener('click', function () {
                window.ScoutMagicApi.postJson(addBtn.dataset.url, {}).then(function (res) {
                    var data = res.data || {};
                    if (data.success) {
                        window.location.reload();
                    } else {
                        window.ScoutMagicToast.show(data.error || "Erreur lors de l'ajout.", { variant: 'error' });
                    }
                });
            });
        }
    });
})();
