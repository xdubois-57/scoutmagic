/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Generic reusable list editor — see
// core/View/templates/partials/list_editor.html.twig. Knows nothing about
// what an item "is"; only handles the list chrome: native HTML5
// drag-and-drop reordering, the active toggle, delete (with confirm), and
// add. Every action posts to a caller-supplied URL read from the
// container's data-* attributes. Add and delete reload the page by default
// (simplest way to stay correct when the set of items — and each item's
// caller-defined content — changes); a list rendered with `in_place`
// instead inserts the row the server renders and removes a deleted one,
// without reloading. Reordering updates the DOM in place and persists
// silently in the background, since that's the whole point of
// drag-and-drop feeling instant.
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
        // re-wiring. Skipped for a list rendered with `sortable: false`,
        // whose rows are not draggable and whose page may not load the
        // toolbox at all.
        if (container.dataset.sortable !== 'false') {
            window.ScoutMagicSortable.bind(itemsEl, {
                itemSelector: '.list-editor-item',
                draggingClass: 'list-editor-item--dragging',
                onReorder: persistOrder,
            });
        }

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

        // Every row control below is wired by wireRow(), once per row: at
        // load for the rows the server rendered, and again for each row a
        // list rendered with `in_place` inserts afterwards.
        var inPlace = container.dataset.inPlace === 'true';

        /** @param {HTMLElement} item */
        function wireRow(item) {
            // --- Move up/down (touch-friendly alternative to drag-and-drop) ---
            item.querySelector('.list-editor-move-up')?.addEventListener('click', function () {
                var prev = item.previousElementSibling;
                if (prev?.classList.contains('list-editor-item')) {
                    prev.before(item);
                    persistOrder();
                    updateMoveButtons();
                }
            });
            item.querySelector('.list-editor-move-down')?.addEventListener('click', function () {
                var next = item.nextElementSibling;
                if (next?.classList.contains('list-editor-item')) {
                    item.before(next);
                    persistOrder();
                    updateMoveButtons();
                }
            });

            // --- Active toggle (icon button, not a checkbox) ---
            var toggle = /** @type {HTMLButtonElement|null} */ (item.querySelector('.list-editor-active-toggle'));
            toggle?.addEventListener('click', function () {
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

            // --- Delete ---
            var btn = /** @type {HTMLButtonElement|null} */ (item.querySelector('.list-editor-delete-btn'));
            btn?.addEventListener('click', async function () {
                if (btn.disabled) return;
                var confirmed = await window.ScoutMagicConfirm.ask({
                    message: item.dataset.deleteConfirm || 'Supprimer définitivement cet élément ?',
                    confirmLabel: 'Supprimer'
                });
                if (!confirmed) return;
                window.ScoutMagicApi.postJson(deleteUrl, { id: Number.parseInt(btn.dataset.id, 10) }).then(function (res) {
                    var data = res.data || {};
                    if (!data.success) {
                        window.ScoutMagicToast.show(data.error || 'Erreur lors de la suppression.', { variant: 'error' });
                    } else if (inPlace) {
                        item.remove();
                        updateMoveButtons();
                        showEmptyLabelIfEmpty();
                        if (data.message) window.ScoutMagicToast.show(data.message, { variant: 'success' });
                    } else {
                        window.location.reload();
                    }
                });
            });
        }

        itemsEl.querySelectorAll('.list-editor-item').forEach(
            /** @param {HTMLElement} item */
            function (item) { wireRow(item); }
        );

        function updateMoveButtons() {
            var items = Array.from(itemsEl.querySelectorAll('.list-editor-item'));
            items.forEach(function (item, index) {
                var upBtn = /** @type {HTMLButtonElement} */ (item.querySelector('.list-editor-move-up'));
                var downBtn = /** @type {HTMLButtonElement} */ (item.querySelector('.list-editor-move-down'));
                if (upBtn) upBtn.disabled = (index === 0);
                if (downBtn) downBtn.disabled = (index === items.length - 1);
            });
        }

        function showEmptyLabelIfEmpty() {
            if (itemsEl.querySelector('.list-editor-item, .list-editor-empty')) return;
            var empty = document.createElement('p');
            empty.className = 'list-editor-empty text-body-secondary fst-italic mb-0';
            empty.textContent = container.dataset.emptyLabel || 'Aucun élément.';
            itemsEl.appendChild(empty);
        }

        // --- Add in place --- (data-in-place="true": the caller's form,
        // posted as JSON, answered with the new row's HTML)
        var addForm = /** @type {HTMLFormElement|null} */ (
            inPlace ? container.querySelector('.list-editor-add-form-slot form') : null
        );
        if (addForm) {
            addForm.addEventListener('submit', function (event) {
                event.preventDefault();
                /** @type {Record<string, string>} */
                var payload = {};
                new FormData(addForm).forEach(function (value, key) {
                    if (typeof value === 'string') payload[key] = value;
                });
                var submit = /** @type {HTMLButtonElement|null} */ (addForm.querySelector('[type="submit"]'));
                if (submit) submit.disabled = true;
                window.ScoutMagicApi.postJson(container.dataset.addUrl || '', payload).then(function (res) {
                    var data = res.data || {};
                    if (submit) submit.disabled = false;
                    if (!data.success) {
                        window.ScoutMagicToast.show(data.error || "Erreur lors de l'ajout.", { variant: 'error' });
                        return;
                    }
                    var holder = document.createElement('div');
                    holder.innerHTML = data.html || '';
                    var row = /** @type {HTMLElement|null} */ (holder.querySelector('.list-editor-item'));
                    if (row) {
                        itemsEl.querySelector('.list-editor-empty')?.remove();
                        itemsEl.appendChild(row);
                        wireRow(row);
                        updateMoveButtons();
                    }
                    addForm.reset();
                    addForm.dispatchEvent(new Event('list-editor:added'));
                    /** @type {HTMLElement|null} */ (addForm.querySelector('input:not([type="hidden"]), select'))?.focus();
                    if (data.message) window.ScoutMagicToast.show(data.message, { variant: 'success' });
                }).catch(function () {
                    // Nothing reached the server: the form stays as typed.
                    if (submit) submit.disabled = false;
                    window.ScoutMagicToast.show("Erreur réseau : rien n'a été ajouté.", { variant: 'error' });
                });
            });
        }

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
