/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Generic reusable "rich text field" wiring — see
// core/View/templates/partials/rich_text_field.html.twig and
// partials/rich_text_edit_button.html.twig. Reuses the same shared
// modal/toolbar markup as editable.js (partials/rich_text_editor.html.twig)
// but is never gated behind configuration mode and saves to a caller-supplied
// URL (data-save-url on the edit button) instead of the fixed
// /api/editable-content endpoint.
//
// It is loaded alongside editable.js, and in configuration mode BOTH are
// live on the same modal — the two save handlers already guard on their own
// currentKey for that reason. The toolbar is shared rather than guarded:
// see wireToolbar() in rich-text-link.js.
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

    // Toolbar commands (bold/italic/lists/link/etc.) — editable.js drives
    // the exact same buttons of the same shared modal. The comment that
    // used to stand here said that could not collide because editable.js
    // "only runs when configuration mode is active"; configuration mode is
    // exactly when both scripts are active, and both wirings fired on one
    // click, un-applying every toggle as fast as it was applied (issue
    // #306). The shared wiring is idempotent per button: whichever of the
    // two gets there first wires them, the other finds them wired.
    window.ScoutMagicRichText.wireToolbar(modalEl, editorContent);
    window.ScoutMagicRichText.wirePaste(editorContent);

    function escapeAttr(value) {
        return value.replace(/["\\]/g, String.raw`\$&`);
    }

    document.querySelectorAll('.rich-text-field-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            currentKey = /** @type {HTMLElement} */ (btn).dataset.key;
            currentSaveUrl = /** @type {HTMLElement} */ (btn).dataset.saveUrl;
            currentPreview = document.querySelector('.rich-text-field-preview[data-key="' + escapeAttr(currentKey) + '"]');
            editorContent.innerHTML = currentPreview ? currentPreview.innerHTML : '';
            modal.show();
        });
    });

    document.getElementById('richTextEditorSave').addEventListener('click', function () {
        if (!currentKey) return;

        // Cleaned before it is sent, so that what is stored and what the
        // preview shows are the same string. See editable.js for the why.
        var html = window.ScoutMagicRichText.cleanHtml(editorContent.innerHTML);
        var csrfMeta = /** @type {HTMLMetaElement | null} */ (document.querySelector('meta[name="csrf-token"]'));
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
                window.ScoutMagicToast.show(json.error || 'Erreur lors de l\'enregistrement.', { variant: 'error' });
            }
        })
        .catch(function () {
            window.ScoutMagicToast.show('Erreur réseau.', { variant: 'error' });
        });
    });
})();
