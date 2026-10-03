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
//
// **Connected lists are opt-in** (issue #752): lists rendered with the
// same `data-sortable-group` accept each other's items. The list that
// receives a drop posts its whole content with its own `data-group-key`
// as `group`; the server moves whatever arrived from elsewhere and closes
// the gap it left. A list without the attribute behaves exactly as before.
(function () {
    // Requests go through the shared window.ScoutMagicApi.postJson envelope
    // ({ok, status, data} — never a rejection); each call site below reads
    // `res.data || {}` and branches on data.success as before.
    document.querySelectorAll('.list-editor').forEach(
        /** @param {HTMLElement} container */
        function (container) {
        var itemsEl = /** @type {HTMLElement|null} */ (container.querySelector('.list-editor-items'));
        var reorderUrl = container.dataset.reorderUrl;
        var activeUrl = container.dataset.activeUrl;
        var deleteUrl = container.dataset.deleteUrl;
        var addBtn = /** @type {HTMLButtonElement} */ (container.querySelector('.list-editor-add-btn'));

        // --- Drag-and-drop reorder ---
        // The shared toolbox (public/assets/js/sortable.js), delegated on
        // the list, so an item added or removed after load needs no
        // re-wiring.
        var group = container.dataset.sortableGroup || '';
        window.ScoutMagicSortable.bind(itemsEl, {
            itemSelector: '.list-editor-item',
            draggingClass: 'list-editor-item--dragging',
            onReorder: persistOrder,
            group: group || undefined,
        });

        /**
         * @param {{item: HTMLElement, from: HTMLElement, to: HTMLElement}} [move]
         */
        function persistOrder(move) {
            if (!reorderUrl) return;
            var crossed = !!move && move.from !== move.to;
            // Sent as-is (not parseInt'd) — an item's id isn't always
            // numeric (e.g. the general configuration page's module list
            // uses each module's string id).
            var ids = Array.from(itemsEl.querySelectorAll('.list-editor-item')).map(
                /** @param {HTMLElement} el */
                function (el) {
                return el.dataset.id;
            });
            /** @type {Record<string, any>} */
            var body = { ids: ids };
            if (group) {
                body.group = container.dataset.groupKey || '';
            }
            refreshList(itemsEl);
            if (crossed) {
                refreshList(move.from);
            }
            window.ScoutMagicApi.postJson(reorderUrl, body).then(function (res) {
                var data = res.data || {};
                if (!data.success) {
                    window.ScoutMagicToast.show(data.error || 'Erreur lors de la réorganisation.', { variant: 'error' });
                    // An item shown in a section it was never saved in
                    // would be a screen that lies about who can read a
                    // page: start again from what the server holds.
                    if (crossed) {
                        window.location.reload();
                    }
                    return;
                }
                applyItemFields(data.items);
            });
        }

        /**
         * What the server says changed on a row that moved (its column,
         * for a text page), written into the row's `data-item-field`
         * elements — so the screen is right without a reload.
         *
         * @param {Record<string, Record<string, string>>|undefined} items
         */
        function applyItemFields(items) {
            if (!items) return;
            Object.keys(items).forEach(function (id) {
                var row = itemsEl.querySelector('.list-editor-item[data-id="' + id + '"]');
                if (!row) return;
                Object.keys(items[id]).forEach(function (field) {
                    row.querySelectorAll('[data-item-field="' + field + '"]').forEach(function (node) {
                        var value = items[id][field];
                        node.textContent = value ? value + ' · ' : '';
                    });
                });
            });
        }

        /**
         * The chrome that depends on a list's content: the « empty »
         * sentence, and which move buttons are disabled.
         *
         * @param {HTMLElement} list
         */
        function refreshList(list) {
            var items = Array.from(list.querySelectorAll('.list-editor-item'));
            var empty = /** @type {HTMLElement|null} */ (list.querySelector('.list-editor-empty'));
            if (empty) {
                empty.classList.toggle('d-none', items.length > 0);
            }
            items.forEach(function (item, index) {
                var upBtn = /** @type {HTMLButtonElement} */ (item.querySelector('.list-editor-move-up'));
                var downBtn = /** @type {HTMLButtonElement} */ (item.querySelector('.list-editor-move-down'));
                if (upBtn) upBtn.disabled = (index === 0);
                if (downBtn) downBtn.disabled = (index === items.length - 1);
            });
        }

        // --- Move up/down (touch-friendly alternative to drag-and-drop) ---
        // Delegated on the list, so a row that arrived from a connected
        // list answers to the list it is in now.
        itemsEl.addEventListener('click', function (e) {
            var target = /** @type {HTMLElement|null} */ (e.target);
            var up = target?.closest('.list-editor-move-up');
            var down = target?.closest('.list-editor-move-down');
            if (!up && !down) return;
            var item = /** @type {HTMLElement} */ ((up || down).closest('.list-editor-item'));
            if (up) {
                var prev = item.previousElementSibling;
                if (prev?.classList.contains('list-editor-item')) {
                    prev.before(item);
                    persistOrder();
                }
            } else {
                var next = item.nextElementSibling;
                if (next?.classList.contains('list-editor-item')) {
                    item.before(next);
                    persistOrder();
                }
            }
        });

        // --- Active toggle (icon button, not a checkbox) ---
        itemsEl.querySelectorAll('.list-editor-active-toggle').forEach(
            /** @param {HTMLButtonElement} toggle */
            function (toggle) {
            toggle.addEventListener('click', function () {
                if (!activeUrl) return;
                var nextActive = toggle.dataset.active !== '1';
                toggle.disabled = true;
                window.ScoutMagicApi.postJson(activeUrl, { id: Number.parseInt(toggle.dataset.id, 10), active: nextActive })
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
                window.ScoutMagicApi.postJson(deleteUrl, { id: Number.parseInt(btn.dataset.id, 10) }).then(function (res) {
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
