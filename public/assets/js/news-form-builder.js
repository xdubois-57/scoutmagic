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

    // Article content now lives in the form's "bloc de texte"/confirmation
    // fields rather than a separate body editor (usability review) — this
    // is what the AI summary/keyword generators use as their context.
    // `fieldState` is declared further down but `var` is function-scoped,
    // so it's already available (as an array, possibly still empty) by
    // the time these AI buttons can actually be clicked.
    function collectFieldsContentText() {
        var parts = [];
        fieldState.forEach(function (f) {
            if ((f.field_type === 'text' || f.field_type === 'confirmation') && f.confirmation_text) {
                parts.push(f.confirmation_text);
            }
        });
        return parts.join('\n');
    }

    // Client-side mirror of Core\Security\HtmlSanitizer's allowlist — the
    // server re-sanitizes on save regardless, but the rich-text editor
    // round-trips whatever the browser's contenteditable produces
    // (paste, drag-drop) straight back into innerHTML on every re-render
    // (see createRichTextEditor below), so this closes that DOM-to-DOM
    // loop instead of relying solely on the server pass.
    var HTML_SANITIZER_ALLOWED_TAGS = {
        p: [], br: [], strong: [], b: [], em: [], i: [], u: [],
        a: ['href', 'title', 'target', 'rel'],
        ul: [], ol: [], li: [], h2: [], h3: [], h4: [], blockquote: []
    };
    var HTML_SANITIZER_STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'textarea', 'select'];

    function sanitizeHtmlAttributes(el, tagName) {
        var allowed = HTML_SANITIZER_ALLOWED_TAGS[tagName] || [];
        Array.prototype.slice.call(el.attributes).forEach(function (attr) {
            var name = attr.name.toLowerCase();
            if (name.indexOf('on') === 0 || allowed.indexOf(name) === -1) {
                el.removeAttribute(attr.name);
                return;
            }
            if (name === 'href') {
                var value = attr.value.trim().toLowerCase();
                if (value.indexOf('javascript:') === 0 || value.indexOf('data:') === 0) {
                    el.removeAttribute(attr.name);
                }
            }
        });
        if (tagName === 'a' && el.getAttribute('target') === '_blank') {
            el.setAttribute('rel', 'noopener noreferrer');
        }
    }

    function sanitizeHtmlChildren(parent) {
        var child = parent.firstChild;
        while (child) {
            var next = child.nextSibling;
            if (child.nodeType === Node.ELEMENT_NODE) {
                var tagName = child.tagName.toLowerCase();
                if (HTML_SANITIZER_STRIP_WITH_CONTENT.indexOf(tagName) !== -1) {
                    parent.removeChild(child);
                } else if (!Object.prototype.hasOwnProperty.call(HTML_SANITIZER_ALLOWED_TAGS, tagName)) {
                    // Not in the allowlist: drop the tag but keep its text/inline content.
                    var firstMoved = child.firstChild;
                    while (child.firstChild) {
                        parent.insertBefore(child.firstChild, child);
                    }
                    parent.removeChild(child);
                    next = firstMoved || next;
                } else {
                    sanitizeHtmlAttributes(child, tagName);
                    sanitizeHtmlChildren(child);
                }
            } else if (child.nodeType === Node.COMMENT_NODE) {
                parent.removeChild(child);
            }
            child = next;
        }
    }

    // Parses into an inert <template> fragment (never attached to the
    // live document, so no image load / script execution can fire while
    // walking it) and returns the tag/attribute-allowlisted HTML.
    function sanitizeHtml(html) {
        var template = document.createElement('template');
        template.innerHTML = html || '';
        sanitizeHtmlChildren(template.content);
        return template.innerHTML;
    }

    // --- Shared rich-text editor (module spec §11.5 — no modal, unlike
    // partials/rich_text_editor.html.twig's editable() flow) — ONE
    // implementation (toolbar + contenteditable + image insertion) used
    // both for the main article body and for every "Bloc de texte" form
    // field (see buildRichTextEditor() further down), so the two never
    // drift apart. `onChange` receives the editable's innerHTML on every
    // edit; `initialHtml` seeds the starting content.
    var RICH_TEXT_TOOLBAR_COMMANDS = [
        { command: 'formatBlock', value: 'h2', label: 'H2', title: 'Titre 2' },
        { command: 'formatBlock', value: 'h3', label: 'H3', title: 'Titre 3' },
        { command: 'formatBlock', value: 'p', icon: 'bi-text-paragraph', title: 'Paragraphe' },
        { separator: true },
        { command: 'bold', icon: 'bi-type-bold', title: 'Gras' },
        { command: 'italic', icon: 'bi-type-italic', title: 'Italique' },
        { command: 'underline', icon: 'bi-type-underline', title: 'Souligné' },
        { separator: true },
        { command: 'insertUnorderedList', icon: 'bi-list-ul', title: 'Liste à puces' },
        { command: 'insertOrderedList', icon: 'bi-list-ol', title: 'Liste numérotée' },
        { separator: true },
        { command: 'createLink', icon: 'bi-link-45deg', title: 'Lien' },
        { command: 'insertImage', icon: 'bi-image', title: 'Insérer une image' },
        { command: 'removeFormat', icon: 'bi-eraser', title: 'Supprimer le formatage' }
    ];

    function createRichTextEditor(initialHtml, onChange) {
        var wrapper = document.createElement('div');

        var toolbar = document.createElement('div');
        toolbar.className = 'news-rich-text-toolbar mb-2 d-flex flex-wrap gap-1';

        var editable = document.createElement('div');
        editable.contentEditable = 'true';
        editable.className = 'form-control';
        editable.style.minHeight = '100px';
        editable.innerHTML = sanitizeHtml(initialHtml);
        editable.addEventListener('input', function () {
            if (onChange) onChange(sanitizeHtml(editable.innerHTML));
        });

        var imageInput = document.createElement('input');
        imageInput.type = 'file';
        imageInput.accept = 'image/jpeg,image/png,image/webp,image/gif';
        imageInput.className = 'd-none';

        var savedRange = null;
        function saveSelection() {
            var sel = window.getSelection();
            if (sel && sel.rangeCount > 0) savedRange = sel.getRangeAt(0);
        }
        editable.addEventListener('keyup', saveSelection);
        editable.addEventListener('mouseup', saveSelection);

        RICH_TEXT_TOOLBAR_COMMANDS.forEach(function (cmd) {
            if (cmd.separator) {
                var sep = document.createElement('span');
                sep.className = 'mx-1';
                toolbar.appendChild(sep);
                return;
            }

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary';
            btn.title = cmd.title;
            btn.innerHTML = cmd.label ? '<strong>' + cmd.label + '</strong>' : '<i class="bi ' + cmd.icon + '"></i>';
            btn.addEventListener('click', function () {
                editable.focus();
                if (cmd.command === 'createLink') {
                    var url = prompt('URL du lien :');
                    if (url) document.execCommand('createLink', false, url);
                } else if (cmd.command === 'insertImage') {
                    imageInput.click();
                } else {
                    document.execCommand(cmd.command, false, cmd.value || null);
                }
            });
            toolbar.appendChild(btn);
        });

        // Uploads via a dedicated endpoint (the article itself may not
        // exist yet, so this can't wait for the main form's own
        // multipart submit) then inserts the resulting <img> at the
        // last-known cursor position.
        imageInput.addEventListener('change', function () {
            var file = imageInput.files[0];
            if (!file) return;

            var formData = new FormData();
            formData.append('image', file);
            formData.append('_csrf_token', csrf());

            fetch('/news/images/upload', { method: 'POST', body: formData })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    imageInput.value = '';
                    if (!data.success) {
                        alert(data.error || "Erreur lors de l'envoi de l'image.");
                        return;
                    }

                    editable.focus();
                    var sel = window.getSelection();
                    if (savedRange && sel) {
                        sel.removeAllRanges();
                        sel.addRange(savedRange);
                    }
                    document.execCommand('insertImage', false, data.url);
                    if (onChange) onChange(sanitizeHtml(editable.innerHTML));
                });
        });

        wrapper.appendChild(toolbar);
        wrapper.appendChild(editable);
        wrapper.appendChild(imageInput);

        return { wrapper: wrapper, editable: editable };
    }

    // --- Featured image click-to-edit (usability review) — the visible
    // preview/placeholder + overlay live in editor.html.twig via the same
    // .editable-image/.editable-overlay classes as editable_image()
    // (core/View/TwigFactory.php); clicking is handled natively by the
    // wrapping <label for="image">, no JS needed for that part.
    //
    // Best resolution for social sharing (Facebook/Instagram), square or
    // landscape — never portrait: a portrait source is center-cropped to
    // a square; a square or landscape source keeps its natural ratio
    // untouched, only downscaled if it's larger than the cap below. Then
    // re-encoded as PNG in a canvas before it ever leaves the browser —
    // the original File in the <input> is replaced with the processed
    // one via DataTransfer, so the multipart POST always uploads the
    // processed version, never the original.
    var FEATURED_IMAGE_MAX_DIMENSION = 2048;

    function processFeaturedImage(file, maxDimension, callback) {
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
            URL.revokeObjectURL(url);

            var sourceWidth = img.naturalWidth;
            var sourceHeight = img.naturalHeight;

            var cropWidth = sourceWidth;
            var cropHeight = sourceHeight;
            var sx = 0;
            var sy = 0;
            if (sourceHeight > sourceWidth) {
                // Portrait — center-crop to a square.
                cropHeight = sourceWidth;
                sy = (sourceHeight - cropHeight) / 2;
            }

            var scale = Math.min(1, maxDimension / Math.max(cropWidth, cropHeight));
            var targetWidth = Math.round(cropWidth * scale);
            var targetHeight = Math.round(cropHeight * scale);

            var canvas = document.createElement('canvas');
            canvas.width = targetWidth;
            canvas.height = targetHeight;
            canvas.getContext('2d').drawImage(img, sx, sy, cropWidth, cropHeight, 0, 0, targetWidth, targetHeight);

            canvas.toBlob(function (blob) {
                callback(blob);
            }, 'image/png');
        };
        img.onerror = function () {
            URL.revokeObjectURL(url);
            callback(null);
        };
        img.src = url;
    }

    var featuredImageInput = document.getElementById('image');
    var featuredImagePreview = document.getElementById('news-image-preview');
    var featuredImagePlaceholder = document.getElementById('news-image-placeholder');
    var featuredImageLabel = document.getElementById('news-image-label');
    if (featuredImageInput) {
        featuredImageInput.addEventListener('change', function () {
            var file = featuredImageInput.files[0];
            if (!file) return;

            processFeaturedImage(file, FEATURED_IMAGE_MAX_DIMENSION, function (blob) {
                if (!blob) {
                    alert("Impossible de traiter cette image.");
                    featuredImageInput.value = '';
                    return;
                }

                var processedFile = new File([blob], 'image.png', { type: 'image/png' });
                var dataTransfer = new DataTransfer();
                dataTransfer.items.add(processedFile);
                featuredImageInput.files = dataTransfer.files;

                if (featuredImagePreview) {
                    featuredImagePreview.src = URL.createObjectURL(blob);
                    featuredImagePreview.classList.remove('d-none');
                    if (featuredImagePlaceholder) featuredImagePlaceholder.classList.add('d-none');
                    if (featuredImageLabel) featuredImageLabel.classList.add('d-none');
                }
            });
        });
    }

    // There is no standalone "Accès au formulaire" control anymore
    // (usability review) — whether the form requires login is derived
    // from the article's own Visibilité: Public/Lien direct pages are
    // reachable by anyone anyway, so the form needs no login either;
    // Chefs/Chefs d'Unité already require being logged in just to see the
    // page.
    function isPublicAccess() {
        var selected = document.querySelector('input[name="visibility"]:checked');
        var value = selected ? selected.value : 'public';
        return value === 'public' || value === 'direct_link';
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
            updateAccessUi();
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

    // --- AI buttons: disabled until there's a title or some article
    // content to work from (usability review) — re-checked on every title
    // keystroke and every field-content edit. ---
    var aiSummaryBtn = document.getElementById('news-ai-summary-btn');
    var aiKeywordsBtn = document.getElementById('news-ai-keywords-btn');

    function hasTitleOrContent() {
        var titleInput = document.querySelector('input[name="title"]');
        var title = titleInput ? titleInput.value.trim() : '';
        if (title !== '') return true;
        return collectFieldsContentText().trim() !== '';
    }

    function updateAiButtonsState() {
        var enabled = hasTitleOrContent();
        if (aiSummaryBtn && aiSummaryBtn.textContent !== 'Génération…') aiSummaryBtn.disabled = !enabled;
        if (aiKeywordsBtn && aiKeywordsBtn.textContent !== 'Génération…') aiKeywordsBtn.disabled = !enabled;
    }

    var titleInputEl = document.querySelector('input[name="title"]');
    if (titleInputEl) {
        titleInputEl.addEventListener('input', updateAiButtonsState);
    }

    // --- AI summary generation ---
    if (aiSummaryBtn) {
        aiSummaryBtn.addEventListener('click', function () {
            var title = document.querySelector('input[name="title"]').value;
            var bodyHtml = collectFieldsContentText();
            aiSummaryBtn.disabled = true;
            aiSummaryBtn.textContent = 'Génération…';

            postJson('/news/seo/generate-summary', { title: title, body_html: bodyHtml })
                .then(function (data) {
                    aiSummaryBtn.textContent = "Générer avec l'IA";
                    updateAiButtonsState();
                    if (data.success) {
                        document.getElementById('summary').value = data.summary;
                    } else {
                        alert(data.error || 'Erreur lors de la génération.');
                    }
                });
        });
    }

    // --- AI keyword generation ---
    if (aiKeywordsBtn) {
        aiKeywordsBtn.addEventListener('click', function () {
            var title = document.querySelector('input[name="title"]').value;
            var bodyHtml = collectFieldsContentText();
            aiKeywordsBtn.disabled = true;
            aiKeywordsBtn.textContent = 'Génération…';

            postJson('/news/seo/generate-keywords', { title: title, body_html: bodyHtml })
                .then(function (data) {
                    aiKeywordsBtn.textContent = "Générer avec l'IA";
                    updateAiButtonsState();
                    if (data.success) {
                        document.getElementById('seo_keywords').value = data.keywords;
                    } else {
                        alert(data.error || 'Erreur lors de la génération.');
                    }
                });
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
        var isPublic = isPublicAccess();
        var limitSelect = document.getElementById('form_response_limit');
        var publicHelp = document.getElementById('news-access-public-help');
        var membersWarning = document.getElementById('news-access-members-warning');

        if (limitSelect) {
            limitSelect.disabled = isPublic;
            if (isPublic) limitSelect.value = 'unlimited';
        }
        if (publicHelp) publicHelp.classList.toggle('d-none', !isPublic);

        if (membersWarning) {
            // updateAccessUi() can run (via updateVisibilityUi()) before
            // the field-builder section below has initialized fieldState.
            var usesMembers = (fieldState || []).some(function (f) {
                return f.options_source === 'members';
            });
            membersWarning.classList.toggle('d-none', !(isPublic && usesMembers));
        }
    }

    // --- Field builder ---
    var fieldsListEl = document.getElementById('news-fields-list');
    var fieldState = [];
    var expandedKey = null;
    var nextKey = 1;
    var draggedFieldKey = null;

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
        { type: 'confirmation', label: 'Bloc de confirmation', icon: 'bi-info-circle' },
        { type: 'text', label: 'Bloc de texte', icon: 'bi-card-text' }
    ];
    var NON_INPUT_TYPES = ['confirmation', 'text'];
    var TYPE_LABELS = {};
    FIELD_TYPES.forEach(function (t) { TYPE_LABELS[t.type] = t.label; });

    if (fieldsListEl && window.NEWS_EDITOR_DATA) {
        fieldState = (window.NEWS_EDITOR_DATA.fields || []).map(function (f) {
            f._key = nextKey++;
            return f;
        });

        // "Opened by default" (usability review) — the default/only
        // field (typically the "bloc de texte" seeded server-side) starts
        // expanded so the author can start typing immediately, no extra
        // click needed.
        if (fieldState.length === 1) {
            expandedKey = fieldState[0]._key;
        }

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
                // Mandatory by default (usability review) — the author
                // opts out per-field via the "Obligatoire" checkbox
                // rather than opting in every time.
                _key: nextKey++, id: null, field_type: type, label: TYPE_LABELS[type],
                is_required: true, options_source: type === 'dropdown' || type === 'radio' || type === 'checkbox' ? 'manual' : null,
                options_manual: null, capacity_max: null, price_per_unit: null, confirmation_text: null
            };
            fieldState.push(field);
            expandedKey = field._key;
            renderFieldList();
            var labelInput = fieldsListEl.querySelector('[data-key="' + field._key + '"] .news-field-label-input');
            if (labelInput) labelInput.focus();
        }

        // The first field is always a pinned "bloc de texte" (usability
        // review: "there must always be a bloc de texte on top, it cannot
        // be deleted or moved") — its own delete/drag/move controls are
        // never rendered (see buildFieldRow()), but every mutating
        // function guards against index 0 too, in case of a stray call.
        function removeField(key) {
            if (fieldState.length > 0 && fieldState[0]._key === key) return;
            fieldState = fieldState.filter(function (f) { return f._key !== key; });
            if (expandedKey === key) expandedKey = null;
            renderFieldList();
        }

        function moveField(key, direction) {
            var index = fieldState.findIndex(function (f) { return f._key === key; });
            if (index === 0) return;
            var target = index + direction;
            if (target < 0 || target >= fieldState.length || target === 0) return;
            var tmp = fieldState[index];
            fieldState[index] = fieldState[target];
            fieldState[target] = tmp;
            renderFieldList();
            persistReorderIfSaved();
        }

        // Desktop drag-and-drop reorder (same dragstart/dragover/dragend
        // pattern as public/assets/js/list-editor.js) — arbitrary
        // reordering by key, driving the same fieldState array the
        // up/down buttons (mobile/touch, see buildFieldRow) also mutate.
        function moveFieldToKey(draggedKey, targetKey) {
            var fromIndex = fieldState.findIndex(function (f) { return f._key === draggedKey; });
            var toIndex = fieldState.findIndex(function (f) { return f._key === targetKey; });
            if (fromIndex === -1 || toIndex === -1 || fromIndex === toIndex) return;
            if (fromIndex === 0 || toIndex === 0) return;

            var moved = fieldState.splice(fromIndex, 1)[0];
            fieldState.splice(toIndex, 0, moved);
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

        // Exactly the same toolbar/contenteditable/image-insertion code as
        // the article body editor (module usability review: "the bloc de
        // texte must have the same formatting menu as the article itself,
        // reuse the same code") — one independent instance per field,
        // since a form can have several "text" blocks at once.
        function buildRichTextEditor(container, field) {
            var rte = createRichTextEditor(field.confirmation_text || '', function (html) {
                field.confirmation_text = html;
            });
            container.appendChild(rte.wrapper);
        }

        // The scheduling/response-visibility/payment settings box only
        // makes sense once there's at least one real INPUT field — with
        // only "bloc de texte"/confirmation blocks there's nothing to
        // submit, so it stays hidden (usability review). The fields box's
        // own heading also reflects this: "Article" while it's just
        // content, "Formulaire" once there's something to fill in.
        function updateFormSettingsVisibility() {
            var hasRealInput = fieldState.some(function (f) {
                return NON_INPUT_TYPES.indexOf(f.field_type) === -1;
            });

            var settings = document.getElementById('news-form-settings');
            if (settings) settings.classList.toggle('d-none', !hasRealInput);

            var heading = document.getElementById('news-form-box-heading');
            if (heading) heading.textContent = hasRealInput ? 'Formulaire' : 'Article';
        }

        // The "Paiement" settings only make sense once at least one
        // number field actually has a price set (usability review) — not
        // just whenever the Finance module is available.
        function updatePaymentSettingsVisibility() {
            var paymentSettings = document.getElementById('news-payment-settings');
            if (!paymentSettings) return;
            var hasPricedField = fieldState.some(function (f) {
                return f.field_type === 'number' && f.price_per_unit !== null && f.price_per_unit !== '';
            });
            paymentSettings.classList.toggle('d-none', !hasPricedField);
        }

        function renderFieldList() {
            fieldsListEl.innerHTML = '';
            fieldState.forEach(function (field, index) {
                fieldsListEl.appendChild(buildFieldRow(field, index));
            });
            updateAccessUi();
            updateFormSettingsVisibility();
            updatePaymentSettingsVisibility();
            updateAiButtonsState();
        }

        function buildFieldRow(field, index) {
            var isPinned = index === 0;

            var wrapper = document.createElement('div');
            wrapper.className = 'border rounded-3 p-2';
            wrapper.dataset.key = field._key;

            // The pinned first "bloc de texte" can't be dragged, and
            // nothing can be dropped onto it either (moveFieldToKey()
            // rejects fromIndex/toIndex 0 regardless, this just avoids
            // the visual drag affordance for a no-op).
            if (!isPinned) {
                wrapper.draggable = true;
                wrapper.addEventListener('dragstart', function () {
                    draggedFieldKey = field._key;
                    wrapper.classList.add('opacity-50');
                });
                wrapper.addEventListener('dragend', function () {
                    wrapper.classList.remove('opacity-50');
                    draggedFieldKey = null;
                });
                wrapper.addEventListener('dragover', function (e) {
                    e.preventDefault();
                });
                wrapper.addEventListener('drop', function (e) {
                    e.preventDefault();
                    if (draggedFieldKey !== null && draggedFieldKey !== field._key) {
                        moveFieldToKey(draggedFieldKey, field._key);
                    }
                });
            }

            var row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2';
            row.style.cursor = 'pointer';

            if (isPinned) {
                var pinIcon = document.createElement('span');
                pinIcon.className = 'text-body-secondary';
                pinIcon.setAttribute('aria-label', 'Toujours en premier');
                pinIcon.title = 'Toujours en premier';
                pinIcon.innerHTML = '<i class="bi bi-pin-angle-fill"></i>';
                row.appendChild(pinIcon);
            } else {
                var dragHandle = document.createElement('span');
                dragHandle.className = 'text-body-secondary d-none d-lg-inline';
                dragHandle.style.cursor = 'grab';
                dragHandle.setAttribute('aria-label', 'Glisser pour réordonner');
                dragHandle.title = 'Glisser pour réordonner';
                dragHandle.innerHTML = '<i class="bi bi-grip-vertical"></i>';
                row.appendChild(dragHandle);

                var moveBtns = document.createElement('span');
                moveBtns.className = 'd-inline-flex flex-column d-lg-none';
                moveBtns.innerHTML =
                    '<button type="button" class="btn btn-sm btn-link text-body-secondary p-0 news-move-up" aria-label="Monter"' + (index === 1 ? ' disabled' : '') + '><i class="bi bi-chevron-up"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-link text-body-secondary p-0 news-move-down" aria-label="Descendre"' + (index === fieldState.length - 1 ? ' disabled' : '') + '><i class="bi bi-chevron-down"></i></button>';
                row.appendChild(moveBtns);
            }

            var icon = document.createElement('i');
            icon.className = 'bi ' + fieldIcon(field.field_type);
            row.appendChild(icon);

            var label = document.createElement('span');
            label.className = 'flex-grow-1';
            var isNonInput = NON_INPUT_TYPES.indexOf(field.field_type) !== -1;
            var labelText = field.field_type === 'confirmation' ? 'Bloc de confirmation' : (field.field_type === 'text' ? 'Bloc de texte' : (field.label || 'Sans libellé'));
            // field.label is admin-entered free text (not HTML-sanitized server-side,
            // since it's meant to stay plain text) — built with textContent/DOM nodes
            // rather than innerHTML so it can never be reinterpreted as markup.
            label.appendChild(document.createTextNode(labelText));
            if (field.is_required && !isNonInput) {
                var requiredMark = document.createElement('span');
                requiredMark.className = 'text-danger';
                requiredMark.textContent = ' *';
                label.appendChild(requiredMark);
            }
            if (field.price_per_unit) {
                var priceBadge = document.createElement('span');
                priceBadge.className = 'text-body-secondary small';
                priceBadge.textContent = ' · ' + field.price_per_unit + '€/unité';
                label.appendChild(priceBadge);
            }
            row.appendChild(label);

            var typeName = document.createElement('span');
            typeName.className = 'text-body-secondary small d-none d-md-inline';
            typeName.textContent = TYPE_LABELS[field.field_type] || field.field_type;
            row.appendChild(typeName);

            if (!isPinned) {
                var deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'btn btn-sm btn-outline-danger';
                deleteBtn.innerHTML = '<i class="bi bi-trash"></i>';
                deleteBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    removeField(field._key);
                });
                row.appendChild(deleteBtn);
            }

            row.addEventListener('click', function () {
                expandedKey = expandedKey === field._key ? null : field._key;
                renderFieldList();
            });
            if (!isPinned) {
                row.querySelector('.news-move-up').addEventListener('click', function (e) {
                    e.stopPropagation();
                    moveField(field._key, -1);
                });
                row.querySelector('.news-move-down').addEventListener('click', function (e) {
                    e.stopPropagation();
                    moveField(field._key, 1);
                });
            }

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

            if (NON_INPUT_TYPES.indexOf(field.field_type) === -1) {
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
                capInput.min = '1';
                capInput.step = '1';
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
                    priceInput.min = '0';
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
                if (isPublicAccess()) {
                    membersBtn.disabled = true;
                    membersBtn.title = 'Indisponible en visibilité Public/Lien direct — les répondants ne sont pas connectés.';
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

            if (field.field_type === 'text') {
                addRow('<label class="form-label small d-block">Contenu</label>');
                buildRichTextEditor(panel, field);
            }

            return panel;
        }
    }

    // --- Submit: serialize fields before native form POST ---
    var editorForm = document.getElementById('news-editor-form');
    if (editorForm) {
        editorForm.addEventListener('submit', function () {
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
