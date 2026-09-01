/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Banners configuration page (modules/banner/views/config.html.twig):
// the per-banner visibility select, and the custom « add » flow that
// collects the new banner's text in the shared rich-text dialog BEFORE
// it ever appears in the list, instead of adding an empty banner and
// editing it after. Extracted from the template's inline <script> so the
// Vitest suite can exercise the production code directly
// (tests/js/banner-config.test.js).
//
// The add flow's subtlety, and the reason it is worth testing: a banner
// needs an id before its content can be stored under
// `banner_content_{id}`, so the row is created first. If the dialog is
// then dismissed without saving, that row is deleted again — otherwise
// every abandoned « Ajouter » leaves an empty banner on the site's home
// page. The cleanup hangs off `hidden.bs.modal` because that is the one
// event Bootstrap fires for all three ways out (Enregistrer, Annuler,
// Échap), and both listeners unregister themselves there so a second
// « Ajouter » does not save into the first banner's key.
//
// Corrections that came with the move: the three `alert()` boxes are
// toasts (design.md §7.5), and the file-local csrf() reader is gone —
// ScoutMagicApi carries the token in the body and the header both.
(function () {
    var api = window.ScoutMagicApi;

    /** @type {NodeListOf<HTMLSelectElement>} */
    var roleSelects = document.querySelectorAll('.banner-role-min-select');
    var addBtn = /** @type {HTMLButtonElement|null} */ (
        document.querySelector('#banner-list .list-editor-add-btn')
    );

    // A no-op on every other page of the site.
    if (!roleSelects.length && !addBtn) {
        return;
    }

    /** @param {{ok: boolean, status: number, data: any}} res */
    function toastError(res, fallback) {
        var message = (res.data && res.data.error) || fallback;
        window.ScoutMagicToast.show(message, { variant: 'error' });
    }

    // Visibility select — auto-saves on change
    // (module.json: POST /config/banner/role-min).
    roleSelects.forEach(function (select) {
        select.addEventListener('change', function () {
            api.postJson('/config/banner/role-min', {
                id: Number.parseInt(select.dataset.id || '', 10),
                role_min: select.value
            }).then(function (res) {
                if (res.data && res.data.success) {
                    return;
                }
                toastError(res, 'Erreur.');
            });
        });
    });

    var modalEl = document.getElementById('richTextEditorModal');
    if (!addBtn || !modalEl) {
        return;
    }

    var Modal = window.bootstrap && window.bootstrap.Modal;
    var modal = Modal ? new Modal(modalEl) : null;
    var editorContent = document.getElementById('richTextEditorContent');
    var saveBtn = document.getElementById('richTextEditorSave');
    if (!modal || !editorContent || !saveBtn) {
        return;
    }

    addBtn.addEventListener('click', async function () {
        var created = await api.postJson(addBtn.dataset.url || '', {});
        if (!created.data || !created.data.success) {
            toastError(created, "Erreur lors de l'ajout.");
            return;
        }

        var bannerId = created.data.banner_id;
        var saved = false;
        editorContent.innerHTML = '';
        modal.show();

        function onSave() {
            api.postJson('/api/rich-text-content', {
                key: 'banner_content_' + bannerId,
                value: editorContent.innerHTML,
                type: 'rich_text'
            }).then(function (res) {
                if (!res.data || !res.data.success) {
                    toastError(res, 'Erreur.');
                    return;
                }
                saved = true;
                modal.hide();
                // A new banner changes the list, its order and its
                // preview — the page re-renders rather than being
                // patched by hand.
                window.location.reload();
            });
        }

        function onHidden() {
            saveBtn.removeEventListener('click', onSave);
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            if (!saved) {
                api.postJson('/config/banner/delete', { id: bannerId });
            }
        }

        saveBtn.addEventListener('click', onSave);
        modalEl.addEventListener('hidden.bs.modal', onHidden);
    });
})();
