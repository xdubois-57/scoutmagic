// Generic reusable "rich text field" wiring — see
// core/View/templates/partials/rich_text_field.html.twig and
// partials/rich_text_edit_button.html.twig. Reuses the same shared
// modal/toolbar markup as editable.js (partials/rich_text_editor.html.twig)
// but is never gated behind configuration mode and saves to a caller-supplied
// URL (data-save-url on the edit button) instead of the fixed
// /api/editable-content endpoint — safe to load alongside editable.js since
// that script only ever runs when configuration mode is active, and this one
// never is.
//
// The preview and the edit button are deliberately decoupled (matched by
// data-key, not by DOM nesting/proximity) so a caller can place the edit
// button anywhere in its own layout (e.g. an icon in a row of item actions)
// independently of where the content preview itself renders.
(function () {
    var modalEl = document.getElementById('richTextEditorModal');
    if (!modalEl) return;

    var modal = new bootstrap.Modal(modalEl);
    var editorContent = document.getElementById('richTextEditorContent');
    var currentKey = null;
    var currentSaveUrl = null;
    var currentPreview = null;

    // Toolbar commands (bold/italic/lists/link/etc.) — editable.js wires
    // the exact same [data-command] buttons, but only when configuration
    // mode is active, so this is never a double-wiring in practice.
    document.querySelectorAll('#richTextEditorModal [data-command]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var cmd = btn.dataset.command;
            if (cmd === 'createLink') {
                var url = prompt('URL du lien :');
                if (url) document.execCommand(cmd, false, url);
            } else {
                document.execCommand(cmd, false, null);
            }
            editorContent.focus();
        });
    });

    function escapeAttr(value) {
        return value.replace(/["\\]/g, '\\$&');
    }

    document.querySelectorAll('.rich-text-field-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            currentKey = btn.dataset.key;
            currentSaveUrl = btn.dataset.saveUrl;
            currentPreview = document.querySelector('.rich-text-field-preview[data-key="' + escapeAttr(currentKey) + '"]');
            editorContent.innerHTML = currentPreview ? currentPreview.innerHTML : '';
            modal.show();
        });
    });

    document.getElementById('richTextEditorSave').addEventListener('click', function () {
        if (!currentKey) return;

        var html = editorContent.innerHTML;
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrf = csrfMeta ? csrfMeta.content : '';

        fetch(currentSaveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: currentKey, value: html, type: 'rich_text', _csrf_token: csrf })
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (json.success) {
                if (currentPreview) currentPreview.innerHTML = html;
                modal.hide();
            } else {
                alert(json.error || 'Erreur lors de l\'enregistrement.');
            }
        })
        .catch(function () {
            alert('Erreur réseau.');
        });
    });
})();
