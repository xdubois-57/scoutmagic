(function () {
    var modalEl = document.getElementById('richTextEditorModal');
    if (!modalEl) return;

    var modal = new bootstrap.Modal(modalEl);
    var editorContent = document.getElementById('richTextEditorContent');
    var currentKey = null;
    var currentElement = null;

    // Toolbar commands
    document.querySelectorAll('[data-command]').forEach(function (btn) {
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

    // Open editor on rich text edit click
    document.querySelectorAll('.editable-content .editable-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var container = btn.closest('.editable-content');
            currentKey = container.dataset.key;
            currentElement = container;
            var clone = container.cloneNode(true);
            var overlay = clone.querySelector('.editable-overlay');
            if (overlay) overlay.remove();
            editorContent.innerHTML = clone.innerHTML;
            modal.show();
        });
    });

    // Save
    document.getElementById('richTextEditorSave').addEventListener('click', function () {
        var html = editorContent.innerHTML;
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
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
                alert(json.error || 'Erreur lors de l\'enregistrement.');
            }
        })
        .catch(function () {
            alert('Erreur réseau.');
        });
    });

    // Image upload — navigate to upload page. data-context lets other core
    // components (e.g. member_photo()) reuse this same overlay/click wiring
    // with their own upload context, defaulting to 'editable_image'.
    document.querySelectorAll('.editable-image .editable-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var container = btn.closest('.editable-image');
            var key = container.dataset.key;
            var context = container.dataset.context || 'editable_image';
            window.location.href = '/upload?context=' + encodeURIComponent(context) + '&key=' + encodeURIComponent(key) + '&return=' + encodeURIComponent(window.location.pathname);
        });
    });
})();
