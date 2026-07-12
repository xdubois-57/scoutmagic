document.addEventListener('DOMContentLoaded', function() {
    var modal = new bootstrap.Modal(document.getElementById('settingEditModal'));
    var currentModuleId = null;
    var currentKey = null;

    document.querySelectorAll('.setting-row').forEach(function(row) {
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
        var input = document.querySelector('#settingEditInputContainer input, #settingEditInputContainer select, #settingEditInputContainer textarea');
        var value = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
        var csrf = document.querySelector('meta[name="csrf-token"]');
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

    function buildInput(type, value, regex, options) {
        var pattern = regex ? ' pattern="' + regex + '"' : '';
        switch (type) {
            case 'boolean':
                return '<div class="form-check form-switch">' +
                    '<input class="form-check-input" type="checkbox" ' + (value === '1' ? 'checked' : '') + '>' +
                    '</div>';
            case 'select':
                return '<select class="form-select">' +
                    options.map(function(o) { return '<option value="' + o + '"' + (o === value ? ' selected' : '') + '>' + o + '</option>'; }).join('') +
                    '</select>';
            case 'textarea':
                return '<textarea class="form-control" rows="4"' + pattern + '>' + escapeHtml(value) + '</textarea>';
            case 'color':
                return '<input type="color" class="form-control form-control-color" value="' + value + '">';
            default:
                return '<input type="' + type + '" class="form-control" value="' + escapeHtml(value) + '"' + pattern + '>';
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
