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
    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({}, body, { _csrf_token: csrf() }))
        }).then(function (res) { return res.json(); });
    }

    document.querySelectorAll('.list-editor').forEach(function (container) {
        var itemsEl = container.querySelector('.list-editor-items');
        var reorderUrl = container.dataset.reorderUrl;
        var activeUrl = container.dataset.activeUrl;
        var deleteUrl = container.dataset.deleteUrl;
        var addBtn = container.querySelector('.list-editor-add-btn');
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
            item.addEventListener('dragover', function (e) {
                e.preventDefault();
                if (!draggedItem || draggedItem === item) return;
                var rect = item.getBoundingClientRect();
                var after = (e.clientY - rect.top) > rect.height / 2;
                itemsEl.insertBefore(draggedItem, after ? item.nextSibling : item);
            });
        });

        function persistOrder() {
            if (!reorderUrl) return;
            var ids = Array.from(itemsEl.querySelectorAll('.list-editor-item')).map(function (el) {
                return parseInt(el.dataset.id, 10);
            });
            postJson(reorderUrl, { ids: ids }).then(function (data) {
                if (!data.success) alert(data.error || 'Erreur lors de la réorganisation.');
            });
        }

        // --- Active toggle (icon button, not a checkbox) ---
        itemsEl.querySelectorAll('.list-editor-active-toggle').forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                if (!activeUrl) return;
                var nextActive = toggle.dataset.active !== '1';
                toggle.disabled = true;
                postJson(activeUrl, { id: parseInt(toggle.dataset.id, 10), active: nextActive })
                    .then(function (data) {
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
                            alert(data.error || 'Erreur.');
                        }
                    });
            });
        });

        // --- Delete ---
        itemsEl.querySelectorAll('.list-editor-delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (btn.disabled) return;
                if (!confirm('Supprimer définitivement cet élément ?')) return;
                postJson(deleteUrl, { id: parseInt(btn.dataset.id, 10) }).then(function (data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.error || 'Erreur lors de la suppression.');
                    }
                });
            });
        });

        // --- Add --- (skipped when data-add-mode="custom" — the caller
        // wires its own .list-editor-add-btn handler instead, e.g. to
        // collect content in a dialog before anything is created)
        if (addBtn && container.dataset.addMode !== 'custom') {
            addBtn.addEventListener('click', function () {
                postJson(addBtn.dataset.url, {}).then(function (data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.error || "Erreur lors de l'ajout.");
                    }
                });
            });
        }
    });
})();
