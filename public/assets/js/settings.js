/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

document.addEventListener('DOMContentLoaded', function() {
    var modal = new bootstrap.Modal(document.getElementById('settingEditModal'));
    var currentModuleId = null;
    var currentKey = null;

    document.querySelectorAll('.setting-row').forEach(function(rowEl) {
        var row = /** @type {HTMLElement} */ (rowEl);
        row.addEventListener('click', function() {
            currentModuleId = row.dataset.module === 'core' ? '' : row.dataset.module;
            currentKey = row.dataset.key;
            var type = row.dataset.type;
            var value = row.dataset.value;
            var label = row.dataset.label;
            var description = row.dataset.description;
            var regex = row.dataset.regex;
            var options = [];
            try { options = JSON.parse(row.dataset.options || '[]'); } catch(e) {}

            document.getElementById('settingEditTitle').textContent = label;
            document.getElementById('settingEditDescription').textContent = description;
            document.getElementById('settingEditLabel').textContent = 'Valeur';
            document.getElementById('settingEditType').textContent = 'Type : ' + type;
            document.getElementById('settingEditError').classList.add('d-none');

            var container = document.getElementById('settingEditInputContainer');
            container.innerHTML = buildInput(type, value, regex, options);

            modal.show();
        });
    });

    document.getElementById('settingEditSave').addEventListener('click', async function() {
        var input = /** @type {HTMLInputElement} */ (document.querySelector('#settingEditInputContainer input, #settingEditInputContainer select, #settingEditInputContainer textarea'));
        var value = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
        var csrf = /** @type {HTMLMetaElement} */ (document.querySelector('meta[name="csrf-token"]'));
        var csrfValue = csrf ? csrf.content : '';

        var res = await fetch('/config/settings/update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                module_id: currentModuleId || null,
                key: currentKey,
                value: value,
                _csrf_token: csrfValue
            })
        });
        var data = await res.json();

        if (data.success) {
            modal.hide();
            window.location.reload();
        } else {
            document.getElementById('settingEditError').textContent = data.error;
            document.getElementById('settingEditError').classList.remove('d-none');
        }
    });

    /**
     * @param {string} type
     * @param {string} value
     * @param {string} regex
     * @param {string[]} options
     * @returns {string}
     */
    function buildInput(type, value, regex, options) {
        var pattern = regex ? ' pattern="' + escapeAttr(regex) + '"' : '';
        switch (type) {
            case 'boolean':
                return '<div class="form-check form-switch">' +
                    '<input class="form-check-input" type="checkbox" ' + (value === '1' ? 'checked' : '') + '>' +
                    '</div>';
            case 'select':
                return '<select class="form-select">' +
                    options.map(function(o) { return '<option value="' + escapeAttr(o) + '"' + (o === value ? ' selected' : '') + '>' + escapeHtml(o) + '</option>'; }).join('') +
                    '</select>';
            case 'textarea':
                return '<textarea class="form-control" rows="4"' + pattern + '>' + escapeHtml(value) + '</textarea>';
            case 'color':
                return '<input type="color" class="form-control form-control-color" value="' + escapeAttr(value) + '">';
            default:
                return '<input type="' + type + '" class="form-control" value="' + escapeAttr(value) + '"' + pattern + '>';
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // escapeHtml alone doesn't escape quotes (not required in text-node
    // context), so any value interpolated inside a double-quoted HTML
    // attribute (value="...", pattern="...") needs this instead.
    function escapeAttr(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
});
