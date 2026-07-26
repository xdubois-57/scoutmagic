// News module: article editor rich-text toolbar, visibility/SEO toggles,
// the interactive form builder (field list add/edit/reorder/delete), and
// the live payment total on the public submission form. One file since
// most of it only ever runs together on the editor page; the payment
// calculator section also runs alone on the article detail page.
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

    // --- Inline rich-text toolbar (module spec §11.5 — no modal, unlike
    // partials/rich_text_editor.html.twig's editable() flow) ---
    var bodyEditor = document.getElementById('news-body-editor');
    if (bodyEditor) {
        document.querySelectorAll('.news-editor-toolbar [data-command]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var cmd = btn.dataset.command;
                bodyEditor.focus();
                if (cmd === 'createLink') {
                    var url = prompt('URL du lien :');
                    if (url) document.execCommand(cmd, false, url);
                } else {
                    document.execCommand(cmd, false, btn.dataset.value || null);
                }
            });
        });
    }

    // --- Visibility segmented buttons ---
    var visibilityGroup = document.getElementById('news-visibility-group');
    if (visibilityGroup) {
        var directLinkHelp = document.getElementById('news-direct-link-help');
        var seoSection = document.getElementById('news-seo-section');

        function updateVisibilityUi() {
            var selected = visibilityGroup.querySelector('input:checked');
            var isDirectLink = selected && selected.value === 'direct_link';
            if (directLinkHelp) directLinkHelp.classList.toggle('d-none', !isDirectLink);
            if (seoSection) seoSection.classList.toggle('d-none', isDirectLink);
        }

        visibilityGroup.querySelectorAll('input').forEach(function (input) {
            input.addEventListener('change', updateVisibilityUi);
        });
        updateVisibilityUi();
    }

    // --- SEO indexing toggle ---
    var isIndexedCheckbox = document.getElementById('is_indexed');
    var seoFields = document.getElementById('news-seo-fields');
    if (isIndexedCheckbox && seoFields) {
        isIndexedCheckbox.addEventListener('change', function () {
            seoFields.classList.toggle('d-none', !isIndexedCheckbox.checked);
        });
    }

    // --- AI keyword generation ---
    var aiKeywordsBtn = document.getElementById('news-ai-keywords-btn');
    if (aiKeywordsBtn) {
        aiKeywordsBtn.addEventListener('click', function () {
            var title = document.querySelector('input[name="title"]').value;
            var bodyHtml = bodyEditor ? bodyEditor.innerHTML : '';
            aiKeywordsBtn.disabled = true;
            aiKeywordsBtn.textContent = 'Génération…';

            postJson('/news/seo/generate-keywords', { title: title, body_html: bodyHtml })
                .then(function (data) {
                    aiKeywordsBtn.disabled = false;
                    aiKeywordsBtn.textContent = "Générer avec l'IA";
                    if (data.success) {
                        document.getElementById('seo_keywords').value = data.keywords;
                    } else {
                        alert(data.error || 'Erreur lors de la génération.');
                    }
                });
        });
    }

    // --- "Ajouter un formulaire" toggle ---
    var hasFormCheckbox = document.getElementById('has_form');
    var formBuilder = document.getElementById('news-form-builder');
    if (hasFormCheckbox && formBuilder) {
        hasFormCheckbox.addEventListener('change', function () {
            formBuilder.classList.toggle('d-none', !hasFormCheckbox.checked);
        });
    }

    // --- Form state badge + access/response-limit interlock ---
    function updateFormStateBadge() {
        var badge = document.getElementById('news-form-state-badge');
        if (!badge) return;
        var forceClosed = document.getElementById('form_is_force_closed');
        var opensAt = document.getElementById('form_opens_at');
        var closesAt = document.getElementById('form_closes_at');
        var today = new Date().toISOString().slice(0, 10);
        var open = true;
        if (forceClosed && forceClosed.checked) open = false;
        if (opensAt && opensAt.value && today < opensAt.value) open = false;
        if (closesAt && closesAt.value && today > closesAt.value) open = false;
        badge.textContent = open ? 'Ouvert' : 'Fermé';
        badge.className = 'badge rounded-pill ' + (open ? 'text-bg-success' : 'text-bg-secondary');
    }
    ['form_is_force_closed', 'form_opens_at', 'form_closes_at'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', updateFormStateBadge);
    });
    updateFormStateBadge();

    function updateAccessUi() {
        var accessInput = document.querySelector('input[name="form_access"]:checked');
        var isPublic = accessInput && accessInput.value === 'public';
        var limitSelect = document.getElementById('form_response_limit');
        var publicHelp = document.getElementById('news-access-public-help');
        var membersWarning = document.getElementById('news-access-members-warning');

        if (limitSelect) {
            limitSelect.disabled = isPublic;
            if (isPublic) limitSelect.value = 'unlimited';
        }
        if (publicHelp) publicHelp.classList.toggle('d-none', !isPublic);

        if (membersWarning) {
            var usesMembers = fieldState.some(function (f) {
                return f.options_source === 'members';
            });
            membersWarning.classList.toggle('d-none', !(isPublic && usesMembers));
        }
    }
    document.querySelectorAll('input[name="form_access"]').forEach(function (input) {
        input.addEventListener('change', updateAccessUi);
    });

    // --- Field builder ---
    var fieldsListEl = document.getElementById('news-fields-list');
    var fieldState = [];
    var expandedKey = null;
    var nextKey = 1;

    var FIELD_TYPES = [
        { type: 'short_text', label: 'Texte court', icon: 'bi-fonts' },
        { type: 'long_text', label: 'Texte long', icon: 'bi-text-paragraph' },
        { type: 'number', label: 'Nombre', icon: 'bi-123' },
        { type: 'date', label: 'Date', icon: 'bi-calendar-date' },
        { type: 'phone', label: 'Téléphone', icon: 'bi-telephone' },
        { type: 'email', label: 'Email', icon: 'bi-envelope' },
        { type: 'dropdown', label: 'Liste déroulante', icon: 'bi-menu-button-wide' },
        { type: 'radio', label: 'Choix unique', icon: 'bi-ui-radios' },
        { type: 'checkbox', label: 'Choix multiple', icon: 'bi-ui-checks' },
        { type: 'switch', label: 'Interrupteur', icon: 'bi-toggle-on' },
        { type: 'confirmation', label: 'Bloc de confirmation', icon: 'bi-info-circle' }
    ];
    var TYPE_LABELS = {};
    FIELD_TYPES.forEach(function (t) { TYPE_LABELS[t.type] = t.label; });

    if (fieldsListEl && window.NEWS_EDITOR_DATA) {
        fieldState = (window.NEWS_EDITOR_DATA.fields || []).map(function (f) {
            f._key = nextKey++;
            return f;
        });

        renderFieldList();
        updateAccessUi();

        var addFieldBtn = document.getElementById('news-add-field-btn');
        var typePicker = document.getElementById('news-field-type-picker');
        var typeGrid = document.getElementById('news-field-type-grid');
        var pickerClose = document.getElementById('news-field-picker-close');

        FIELD_TYPES.forEach(function (t) {
            var col = document.createElement('div');
            col.className = 'col-6 col-sm-4 col-md-3';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-outline-secondary w-100 py-2';
            btn.innerHTML = '<i class="bi ' + t.icon + ' d-block mb-1"></i><span class="small">' + t.label + '</span>';
            btn.addEventListener('click', function () {
                addField(t.type);
                typePicker.classList.add('d-none');
            });
            col.appendChild(btn);
            typeGrid.appendChild(col);
        });

        addFieldBtn.addEventListener('click', function () {
            typePicker.classList.toggle('d-none');
        });
        pickerClose.addEventListener('click', function () {
            typePicker.classList.add('d-none');
        });

        function addField(type) {
            var field = {
                _key: nextKey++, id: null, field_type: type, label: TYPE_LABELS[type],
                is_required: false, options_source: type === 'dropdown' || type === 'radio' || type === 'checkbox' ? 'manual' : null,
                options_manual: null, capacity_max: null, price_per_unit: null, confirmation_text: null
            };
            fieldState.push(field);
            expandedKey = field._key;
            renderFieldList();
            var labelInput = fieldsListEl.querySelector('[data-key="' + field._key + '"] .news-field-label-input');
            if (labelInput) labelInput.focus();
        }

        function removeField(key) {
            fieldState = fieldState.filter(function (f) { return f._key !== key; });
            if (expandedKey === key) expandedKey = null;
            renderFieldList();
        }

        function moveField(key, direction) {
            var index = fieldState.findIndex(function (f) { return f._key === key; });
            var target = index + direction;
            if (target < 0 || target >= fieldState.length) return;
            var tmp = fieldState[index];
            fieldState[index] = fieldState[target];
            fieldState[target] = tmp;
            renderFieldList();
            persistReorderIfSaved();
        }

        function persistReorderIfSaved() {
            var articleId = window.NEWS_EDITOR_DATA.articleId;
            var ids = fieldState.filter(function (f) { return f.id; }).map(function (f) { return f.id; });
            if (!articleId || ids.length !== fieldState.length) return;
            postJson('/news/' + articleId + '/form/fields/reorder', { ids: ids });
        }

        function fieldIcon(type) {
            var found = FIELD_TYPES.filter(function (t) { return t.type === type; })[0];
            return found ? found.icon : 'bi-question';
        }

        function renderFieldList() {
            fieldsListEl.innerHTML = '';
            fieldState.forEach(function (field, index) {
                fieldsListEl.appendChild(buildFieldRow(field, index));
            });
            updateAccessUi();
        }

        function buildFieldRow(field, index) {
            var wrapper = document.createElement('div');
            wrapper.className = 'border rounded-3 p-2';
            wrapper.dataset.key = field._key;

            var row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2';
            row.style.cursor = 'pointer';

            var moveBtns = document.createElement('span');
            moveBtns.className = 'd-inline-flex flex-column';
            moveBtns.innerHTML =
                '<button type="button" class="btn btn-sm btn-link text-body-secondary p-0 news-move-up" aria-label="Monter"' + (index === 0 ? ' disabled' : '') + '><i class="bi bi-chevron-up"></i></button>' +
                '<button type="button" class="btn btn-sm btn-link text-body-secondary p-0 news-move-down" aria-label="Descendre"' + (index === fieldState.length - 1 ? ' disabled' : '') + '><i class="bi bi-chevron-down"></i></button>';
            row.appendChild(moveBtns);

            var icon = document.createElement('i');
            icon.className = 'bi ' + fieldIcon(field.field_type);
            row.appendChild(icon);

            var label = document.createElement('span');
            label.className = 'flex-grow-1';
            var labelText = field.field_type === 'confirmation' ? 'Bloc de confirmation' : (field.label || 'Sans libellé');
            label.innerHTML = labelText + (field.is_required && field.field_type !== 'confirmation' ? ' <span class="text-danger">*</span>' : '') +
                (field.price_per_unit ? ' <span class="text-body-secondary small">· ' + field.price_per_unit + '€/unité</span>' : '');
            row.appendChild(label);

            var typeName = document.createElement('span');
            typeName.className = 'text-body-secondary small d-none d-md-inline';
            typeName.textContent = TYPE_LABELS[field.field_type] || field.field_type;
            row.appendChild(typeName);

            var deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'btn btn-sm btn-outline-danger';
            deleteBtn.innerHTML = '<i class="bi bi-trash"></i>';
            deleteBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                removeField(field._key);
            });
            row.appendChild(deleteBtn);

            row.addEventListener('click', function () {
                expandedKey = expandedKey === field._key ? null : field._key;
                renderFieldList();
            });
            moveBtns.querySelector('.news-move-up').addEventListener('click', function (e) {
                e.stopPropagation();
                moveField(field._key, -1);
            });
            moveBtns.querySelector('.news-move-down').addEventListener('click', function (e) {
                e.stopPropagation();
                moveField(field._key, 1);
            });

            wrapper.appendChild(row);

            if (expandedKey === field._key) {
                wrapper.appendChild(buildFieldEditPanel(field));
            }

            return wrapper;
        }

        function buildFieldEditPanel(field) {
            var panel = document.createElement('div');
            panel.className = 'mt-2 pt-2 border-top';
            panel.addEventListener('click', function (e) { e.stopPropagation(); });

            function addRow(html) {
                var div = document.createElement('div');
                div.className = 'mb-2';
                div.innerHTML = html;
                panel.appendChild(div);
                return div;
            }

            if (field.field_type !== 'confirmation') {
                var labelRow = addRow('<label class="form-label small">Libellé du champ</label>');
                var labelInput = document.createElement('input');
                labelInput.type = 'text';
                labelInput.className = 'form-control news-field-label-input';
                labelInput.value = field.label || '';
                labelInput.addEventListener('input', function () {
                    field.label = labelInput.value;
                    var rowLabel = fieldsListEl.querySelector('[data-key="' + field._key + '"] .flex-grow-1');
                    if (rowLabel) rowLabel.textContent = labelInput.value || 'Sans libellé';
                });
                labelRow.appendChild(labelInput);

                var reqRow = addRow('');
                var reqCheck = document.createElement('div');
                reqCheck.className = 'form-check';
                reqCheck.innerHTML = '<input class="form-check-input" type="checkbox"' + (field.is_required ? ' checked' : '') + '><label class="form-check-label">Obligatoire</label>';
                reqCheck.querySelector('input').addEventListener('change', function (e) {
                    field.is_required = e.target.checked;
                });
                reqRow.appendChild(reqCheck);
            }

            if (field.field_type === 'number') {
                var capRow = addRow('');
                var capCheck = document.createElement('div');
                capCheck.className = 'form-check mb-1';
                capCheck.innerHTML = '<input class="form-check-input" type="checkbox"' + (field.capacity_max !== null ? ' checked' : '') + '><label class="form-check-label">Limiter la capacité</label>';
                var capInput = document.createElement('input');
                capInput.type = 'number';
                capInput.className = 'form-control form-control-sm mt-1' + (field.capacity_max === null ? ' d-none' : '');
                capInput.value = field.capacity_max || '';
                capCheck.querySelector('input').addEventListener('change', function (e) {
                    capInput.classList.toggle('d-none', !e.target.checked);
                    field.capacity_max = e.target.checked ? (parseInt(capInput.value, 10) || 0) : null;
                });
                capInput.addEventListener('input', function () {
                    field.capacity_max = parseInt(capInput.value, 10) || 0;
                });
                capRow.appendChild(capCheck);
                capRow.appendChild(capInput);
                addRow('<span class="form-text">Le nombre maximum est le cumul de toutes les réponses. Exemple : si la limite est 50 et que 48 ont déjà été réservés, le prochain répondant verra « Il reste 2 places ».</span>');

                if (window.NEWS_EDITOR_DATA.financeAvailable) {
                    var priceRow = addRow('<label class="form-label small">Prix unitaire (€)</label>');
                    var priceInput = document.createElement('input');
                    priceInput.type = 'number';
                    priceInput.step = '0.50';
                    priceInput.className = 'form-control';
                    priceInput.value = field.price_per_unit || '';
                    priceInput.addEventListener('input', function () {
                        field.price_per_unit = priceInput.value !== '' ? parseFloat(priceInput.value) : null;
                        renderFieldList();
                    });
                    priceRow.appendChild(priceInput);
                    addRow('<span class="form-text">Laisser vide si ce champ n\'est pas payant.</span>');
                }
            }

            if (['dropdown', 'radio', 'checkbox'].indexOf(field.field_type) !== -1) {
                var sourceRow = addRow('<label class="form-label small d-block">Source des options</label>');
                var sourceGroup = document.createElement('div');
                sourceGroup.className = 'btn-group';
                var manualBtn = document.createElement('button');
                manualBtn.type = 'button';
                manualBtn.className = 'btn btn-sm ' + (field.options_source !== 'members' ? 'btn-primary' : 'btn-outline-primary');
                manualBtn.textContent = 'Liste manuelle';
                var membersBtn = document.createElement('button');
                membersBtn.type = 'button';
                membersBtn.className = 'btn btn-sm ' + (field.options_source === 'members' ? 'btn-primary' : 'btn-outline-primary');
                membersBtn.textContent = 'Membres liés au compte';
                var accessInput = document.querySelector('input[name="form_access"]:checked');
                if (accessInput && accessInput.value === 'public') {
                    membersBtn.disabled = true;
                    membersBtn.title = 'Indisponible en accès public — les répondants ne sont pas connectés.';
                }
                sourceGroup.appendChild(manualBtn);
                sourceGroup.appendChild(membersBtn);
                sourceRow.appendChild(sourceGroup);

                var manualRow = addRow('');
                manualRow.classList.toggle('d-none', field.options_source === 'members');
                var manualTextarea = document.createElement('textarea');
                manualTextarea.className = 'form-control';
                manualTextarea.rows = 4;
                manualTextarea.placeholder = 'Une option par ligne';
                manualTextarea.value = field.options_manual || '';
                manualTextarea.addEventListener('input', function () {
                    field.options_manual = manualTextarea.value;
                });
                manualRow.appendChild(manualTextarea);

                var membersHelp = addRow('<span class="form-text">Les options seront les membres (enfants/animés) rattachés au compte de la personne qui remplit le formulaire. Résolu dynamiquement au moment du remplissage.</span>');
                membersHelp.classList.toggle('d-none', field.options_source !== 'members');

                manualBtn.addEventListener('click', function () {
                    field.options_source = 'manual';
                    manualBtn.className = 'btn btn-sm btn-primary';
                    membersBtn.className = 'btn btn-sm btn-outline-primary';
                    manualRow.classList.remove('d-none');
                    membersHelp.classList.add('d-none');
                    updateAccessUi();
                });
                membersBtn.addEventListener('click', function () {
                    if (membersBtn.disabled) return;
                    field.options_source = 'members';
                    membersBtn.className = 'btn btn-sm btn-primary';
                    manualBtn.className = 'btn btn-sm btn-outline-primary';
                    manualRow.classList.add('d-none');
                    membersHelp.classList.remove('d-none');
                    updateAccessUi();
                });
            }

            if (field.field_type === 'confirmation') {
                var textRow = addRow('<label class="form-label small">Texte affiché avant l\'envoi</label>');
                var textarea = document.createElement('textarea');
                textarea.className = 'form-control';
                textarea.rows = 5;
                textarea.value = field.confirmation_text || '';
                textarea.addEventListener('input', function () {
                    field.confirmation_text = textarea.value;
                });
                textRow.appendChild(textarea);
            }

            return panel;
        }
    }

    // --- Submit: serialize body + fields before native form POST ---
    var editorForm = document.getElementById('news-editor-form');
    if (editorForm) {
        editorForm.addEventListener('submit', function () {
            var bodyInput = document.getElementById('body_html_input');
            if (bodyInput && bodyEditor) bodyInput.value = bodyEditor.innerHTML;

            var fieldsInput = document.getElementById('fields_json_input');
            if (fieldsInput) {
                var serialized = fieldState.map(function (f) {
                    return {
                        id: f.id, field_type: f.field_type, label: f.label, is_required: f.is_required,
                        options_source: f.options_source, options_manual: f.options_manual,
                        capacity_max: f.capacity_max, price_per_unit: f.price_per_unit, confirmation_text: f.confirmation_text
                    };
                });
                fieldsInput.value = JSON.stringify(serialized);
            }
        });
    }

    // --- Delete confirmation ---
    var deleteConfirmBtn = document.getElementById('news-delete-confirm-btn');
    if (deleteConfirmBtn) {
        deleteConfirmBtn.addEventListener('click', function () {
            var id = deleteConfirmBtn.dataset.id;
            fetch('/news/' + id, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ _csrf_token: csrf() })
            }).then(function (res) { return res.json(); }).then(function (data) {
                if (data.success) {
                    window.location.href = '/news/manage';
                } else {
                    alert(data.error || 'Erreur lors de la suppression.');
                }
            });
        });
    }

    // --- Live payment total (public submission form, module spec §11.4) ---
    var paymentSummary = document.getElementById('news-payment-summary');
    if (paymentSummary) {
        var numberFields = document.querySelectorAll('.news-number-field[data-price]');
        var linesEl = document.getElementById('news-payment-lines');
        var totalEl = document.getElementById('news-payment-total');

        function recalcPayment() {
            var total = 0;
            var lines = [];
            numberFields.forEach(function (input) {
                var price = parseFloat(input.dataset.price);
                if (!price) return;
                var qty = parseFloat(input.value) || 0;
                var subtotal = qty * price;
                total += subtotal;
                lines.push('<p class="mb-1">' + input.dataset.label + ' : ' + qty + ' × ' + price.toFixed(2).replace('.', ',') + '€ = ' + subtotal.toFixed(2).replace('.', ',') + '€</p>');
            });
            linesEl.innerHTML = lines.join('');
            totalEl.textContent = total.toFixed(2).replace('.', ',');
        }

        numberFields.forEach(function (input) {
            input.addEventListener('input', recalcPayment);
        });
        recalcPayment();
    }
})();
