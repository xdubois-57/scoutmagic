/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

(function () {
    // Image upload — navigate to upload page. data-context lets other core
    // components (e.g. member_photo()) reuse this same overlay/click wiring
    // with their own upload context, defaulting to 'editable_image'. Wired
    // unconditionally, before the richTextEditorModal check below: pages
    // like the member page render .editable-image outside configuration
    // mode (member_photo()'s $editable flag), where partials/
    // rich_text_editor.html.twig — and its modal — is never included at
    // all (base.html.twig only includes it when config_mode is true). That
    // modal has nothing to do with image upload, so image click handling
    // must not depend on it existing.
    function navigateToUpload(container) {
        var key = container.dataset.key;
        var context = container.dataset.context || 'editable_image';
        window.location.href = '/upload?context=' + encodeURIComponent(context) + '&key=' + encodeURIComponent(key) + '&return=' + encodeURIComponent(window.location.pathname);
    }

    document.querySelectorAll('.editable-image .editable-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            navigateToUpload(btn.closest('.editable-image'));
        });
    });

    // An empty editable-image (no file set yet) has no img/photo to look
    // at, only its "Cliquer pour ajouter une image" placeholder text — so
    // the whole box is clickable too, not just the hover-revealed button
    // above (that stopPropagation() keeps this from double-firing).
    document.querySelectorAll('.editable-image').forEach(function (container) {
        if (container.querySelector('img')) return;
        container.addEventListener('click', function () {
            navigateToUpload(container);
        });
    });

    // Everything below is rich-text (.editable-content) editing, which
    // needs the modal from partials/rich_text_editor.html.twig — only
    // rendered in configuration mode (see base.html.twig) — so it stays
    // gated on the modal actually being present.
    var modalEl = document.getElementById('richTextEditorModal');
    if (!modalEl) return;

    var modal = new bootstrap.Modal(modalEl);
    var editorContent = document.getElementById('richTextEditorContent');
    var currentKey = null;
    var currentElement = null;

    // Toolbar commands and paste cleaning — both shared, both for issue
    // #306. This script used to wire `[data-command]` itself, document-wide
    // and without `data-value`, which cost the H2/H3/« Paragraphe » buttons
    // their argument and double-wired every other button against
    // rich-text-field.js on the pages that load both. See rich-text-link.js.
    window.ScoutMagicRichText.wireToolbar(modalEl, editorContent);
    window.ScoutMagicRichText.wirePaste(editorContent);

    // Open editor on rich text edit click
    document.querySelectorAll('.editable-content .editable-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var container = /** @type {HTMLElement} */ (btn.closest('.editable-content'));
            currentKey = container.dataset.key;
            currentElement = container;
            var clone = /** @type {HTMLElement} */ (container.cloneNode(true));
            var overlay = clone.querySelector('.editable-overlay');
            if (overlay) overlay.remove();
            editorContent.innerHTML = clone.innerHTML;
            modal.show();
        });
    });

    // Save
    document.getElementById('richTextEditorSave').addEventListener('click', function () {
        // The shared modal is also driven by rich-text-field.js and by
        // pages with their own add flow (modules/banner) — outside
        // configuration mode this handler still gets attached (the modal
        // exists), so it must stand down unless an .editable-content
        // block was opened HERE. Same guard rich-text-field.js applies
        // for the same reason.
        if (currentKey === null) return;

        // Cleaned BEFORE it is sent, not only before it is shown: the
        // server sanitises what it stores whatever we send, so sending the
        // browser's own wrapper markup means storing something other than
        // what the page then displays. Cleaning once, here, makes the saved
        // value and the echoed one the same string — which is the « visible
        // une fois sauvé, disparaît au rechargement » half of issue #306.
        var html = window.ScoutMagicRichText.cleanHtml(editorContent.innerHTML);
        var csrfMeta = /** @type {HTMLMetaElement | null} */ (document.querySelector('meta[name="csrf-token"]'));
        var csrf = csrfMeta ? csrfMeta.content : '';

        fetch('/api/editable-content', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: currentKey, value: html, type: 'rich_text', _csrf_token: csrf })
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (json.success) {
                var overlay = currentElement.querySelector('.editable-overlay');
                currentElement.innerHTML = html;
                if (overlay) currentElement.prepend(overlay);
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
