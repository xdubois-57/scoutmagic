/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// News module: article editor rich-text toolbar, visibility/SEO toggles,
// the interactive form builder (field list add/edit/reorder/delete), and
// the live payment total on the public submission form. One file since
// most of it only ever runs together on the editor page; the payment
// calculator section also runs alone on the article detail page.
(function () {
    // CSRF token and JSON requests go through the site-wide toolbox
    // (window.ScoutMagicApi, loaded by base.html.twig before this file).
    // postJson resolves to the {ok, status, data} envelope — never a
    // rejection — so each call site reads `res.data || {}` and branches on
    // data.success as before.

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
        ul: [], ol: [], li: [], h2: [], h3: [], h4: [], blockquote: [],
        // Mirrors the PHP allowlist (Core\Security\HtmlSanitizer) so the
        // "Insérer une image" button's <img> survives the client pass too;
        // src is scheme-validated below.
        img: ['src', 'alt', 'width', 'height']
    };
    var HTML_SANITIZER_STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'textarea', 'select'];

    // Allowlist, not a denylist: a denylist of "dangerous" schemes
    // (javascript:, data:, ...) always misses one (vbscript:, and
    // whatever comes next) — only a fixed set of schemes we actually
    // need for a link is ever accepted. No scheme at all (a relative
    // URL, fragment, or query string) is safe and always allowed.
    var URL_SCHEME_ALLOWLIST = ['http', 'https', 'mailto', 'tel'];

    function isSafeUrlScheme(value) {
        // Strip tabs/newlines/carriage-returns and leading/trailing
        // whitespace the way a browser's URL parser does before reading
        // the scheme, so an injected tab/newline (e.g. "java\tscript:")
        // can't slip past a naive prefix check.
        var normalized = String(value).replace(/[\t\r\n]+/g, '').trim().toLowerCase();
        var schemeMatch = /^([a-z][a-z0-9+.-]*):/.exec(normalized);
        if (!schemeMatch) {
            return true;
        }
        return URL_SCHEME_ALLOWLIST.includes(schemeMatch[1]);
    }

    /**
     * @param {Element} el
     * @param {string} tagName
     */
    function sanitizeHtmlAttributes(el, tagName) {
        var allowed = HTML_SANITIZER_ALLOWED_TAGS[tagName] || [];
        Array.prototype.slice.call(el.attributes).forEach(function (attr) {
            var name = attr.name.toLowerCase();
            if (name.startsWith('on') || !allowed.includes(name)) {
                el.removeAttribute(attr.name);
                return;
            }
            if ((name === 'href' || name === 'src') && !isSafeUrlScheme(attr.value)) {
                el.removeAttribute(attr.name);
            }
        });
        if (tagName === 'img' && !el.hasAttribute('src')) {
            el.remove();
            return;
        }
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
                if (HTML_SANITIZER_STRIP_WITH_CONTENT.includes(tagName)) {
                    child.remove();
                } else if (!Object.hasOwn(HTML_SANITIZER_ALLOWED_TAGS, tagName)) {
                    // Not in the allowlist: drop the tag but keep its text/inline content.
                    var firstMoved = child.firstChild;
                    while (child.firstChild) {
                        parent.insertBefore(child.firstChild, child);
                    }
                    child.remove();
                    next = firstMoved || next;
                } else {
                    sanitizeHtmlAttributes(child, tagName);
                    sanitizeHtmlChildren(child);
                }
            } else if (child.nodeType === Node.COMMENT_NODE) {
                child.remove();
            }
            child = next;
        }
    }

    // Parses via DOMParser rather than assigning to some element's
    // .innerHTML: the resulting Document has no browsing context, so
    // (unlike a live element) it never loads images or runs scripts
    // while we walk and strip it below. Also avoids ever writing
    // unsanitized input into a live DOM property, even a detached one.
    var HTML_SANITIZER_PARSER = new DOMParser();

    function sanitizeHtml(html) {
        var doc = HTML_SANITIZER_PARSER.parseFromString(
            '<!DOCTYPE html><html><body>' + (html || '') + '</body></html>',
            'text/html'
        );
        sanitizeHtmlChildren(doc.body);
        return doc.body.innerHTML;
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

    /**
     * `labelledBy` is the id of the element whose text names this editor.
     * A contenteditable is not a form control, so no <label for> can point
     * at it — aria-labelledby is what gives it a name, and pointing at the
     * visible caption rather than repeating the wording in an aria-label
     * keeps one source of truth for it.
     *
     * @param {string} initialHtml
     * @param {((html: string) => void)|null} [onChange]
     * @param {string|null} [labelledBy]
     * @returns {{wrapper: HTMLDivElement, editable: HTMLDivElement}}
     */
    function createRichTextEditor(initialHtml, onChange, labelledBy) {
        var wrapper = document.createElement('div');

        var toolbar = document.createElement('div');
        toolbar.className = 'news-rich-text-toolbar mb-2 d-flex flex-wrap gap-1';

        var editable = document.createElement('div');
        editable.contentEditable = 'true';
        editable.className = 'form-control';
        editable.style.minHeight = '100px';
        // role: Chromium already maps a contenteditable to `textbox`, but
        // not every assistive technology does — and the name below is only
        // announced on something that has a role to announce it for.
        editable.setAttribute('role', 'textbox');
        editable.setAttribute('aria-multiline', 'true');
        if (labelledBy) {
            editable.setAttribute('aria-labelledby', labelledBy);
        }
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
            if (sel?.rangeCount > 0) savedRange = sel.getRangeAt(0);
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
                    // Shared: captures the selection, asks, normalizes the
                    // URL and gives focus back. See rich-text-link.js.
                    window.ScoutMagicRichText.insertLink(editable);
                    return;
                }
                if (cmd.command === 'insertImage') {
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
            formData.append('_csrf_token', window.ScoutMagicApi.csrfToken());

            fetch('/news/images/upload', { method: 'POST', body: formData })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    imageInput.value = '';
                    if (!data.success) {
                        window.ScoutMagicToast.show(data.error || "Erreur lors de l'envoi de l'image.", { variant: 'error' });
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

    /**
     * @param {File} file
     * @param {number} maxDimension
     * @param {(blob: Blob|null) => void} callback
     */
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

    var featuredImageInput = /** @type {HTMLInputElement} */ (document.getElementById('image'));
    var featuredImagePreview = /** @type {HTMLImageElement} */ (document.getElementById('news-image-preview'));
    var featuredImagePlaceholder = document.getElementById('news-image-placeholder');
    var featuredImageLabel = document.getElementById('news-image-label');
    if (featuredImageInput) {
        featuredImageInput.addEventListener('change', function () {
            var file = featuredImageInput.files[0];
            if (!file) return;

            processFeaturedImage(file, FEATURED_IMAGE_MAX_DIMENSION, function (blob) {
                if (!blob) {
                    window.ScoutMagicToast.show("Impossible de traiter cette image.", { variant: 'error' });
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

    // Everything the editor's Visibilité control decides, as a pure
    // function of the chosen value — deliberately separated from the DOM
    // that applies it, so the rules can be tested without a page.
    //
    // - Public and Lien direct pages are reachable by anyone anyway, so
    //   their form needs no login either; the other three already require
    //   being signed in just to see the page. There is no standalone
    //   "Accès au formulaire" control anymore (usability review).
    // - Lien direct and Membres connectés both refuse indexing on the
    //   server (Service\ArticleService::enforceSeoRules) — the first
    //   because it is in no list, the second because a crawler never
    //   signs in and would publish the preview of a members-only article.
    //   Hiding the SEO panel only spares the author a section the server
    //   is going to discard; it is not the protection.
    //
    // @param {string} value
    // @returns {{formAccessIsPublic: boolean, showDirectLinkHelp: boolean, showIdentifiedHelp: boolean, showSeoSection: boolean}}
    function visibilityUiState(value) {
        return {
            formAccessIsPublic: value === 'public' || value === 'direct_link',
            showDirectLinkHelp: value === 'direct_link',
            showIdentifiedHelp: value === 'identified',
            showSeoSection: value !== 'direct_link' && value !== 'identified',
        };
    }

    function selectedVisibility() {
        var selected = /** @type {HTMLInputElement} */ (document.querySelector('input[name="visibility"]:checked'));
        return selected ? selected.value : 'public';
    }

    function isPublicAccess() {
        return visibilityUiState(selectedVisibility()).formAccessIsPublic;
    }

    // --- Visibility segmented buttons ---
    var visibilityGroup = document.getElementById('news-visibility-group');
    if (visibilityGroup) {
        var directLinkHelp = document.getElementById('news-direct-link-help');
        var identifiedHelp = document.getElementById('news-identified-help');
        var seoSection = document.getElementById('news-seo-section');

        function updateVisibilityUi() {
            var selected = /** @type {HTMLInputElement} */ (visibilityGroup.querySelector('input:checked'));
            var state = visibilityUiState(selected ? selected.value : 'public');
            if (directLinkHelp) directLinkHelp.classList.toggle('d-none', !state.showDirectLinkHelp);
            if (identifiedHelp) identifiedHelp.classList.toggle('d-none', !state.showIdentifiedHelp);
            if (seoSection) seoSection.classList.toggle('d-none', !state.showSeoSection);
            updateAccessUi();
        }

        visibilityGroup.querySelectorAll('input').forEach(function (input) {
            input.addEventListener('change', updateVisibilityUi);
        });
        updateVisibilityUi();
    }

    // --- SEO indexing toggle ---
    var isIndexedCheckbox = /** @type {HTMLInputElement} */ (document.getElementById('is_indexed'));
    var seoFields = document.getElementById('news-seo-fields');
    if (isIndexedCheckbox && seoFields) {
        isIndexedCheckbox.addEventListener('change', function () {
            seoFields.classList.toggle('d-none', !isIndexedCheckbox.checked);
        });
    }

    // --- AI buttons: disabled until there's a title or some article
    // content to work from (usability review) — re-checked on every title
    // keystroke and every field-content edit. ---
    var aiSummaryBtn = /** @type {HTMLButtonElement} */ (document.getElementById('news-ai-summary-btn'));
    var aiKeywordsBtn = /** @type {HTMLButtonElement} */ (document.getElementById('news-ai-keywords-btn'));

    function hasTitleOrContent() {
        var titleInput = /** @type {HTMLInputElement} */ (document.querySelector('input[name="title"]'));
        var title = titleInput ? titleInput.value.trim() : '';
        if (title !== '') return true;
        return collectFieldsContentText().trim() !== '';
    }

    /**
     * Busy state of one AI button. These are icon-only since the labels
     * stopped fitting a phone next to their field (partials/
     * ai_button.html.twig), so "generating" is the wand swapped for a
     * spinner and a data-busy flag — never a textContent swap, which would
     * delete the icon itself and leave an empty square.
     *
     * @param {HTMLButtonElement|null} btn
     * @param {boolean} busy
     * @returns {void}
     */
    function setAiButtonBusy(btn, busy) {
        if (!btn) return;
        btn.dataset.busy = busy ? '1' : '';
        btn.disabled = busy;
        var icon = btn.querySelector('i');
        if (icon) icon.className = busy ? 'spinner-border spinner-border-sm' : 'bi bi-magic';
    }

    function updateAiButtonsState() {
        var enabled = hasTitleOrContent();
        if (aiSummaryBtn?.dataset.busy !== '1') aiSummaryBtn.disabled = !enabled;
        if (aiKeywordsBtn?.dataset.busy !== '1') aiKeywordsBtn.disabled = !enabled;
    }

    var titleInputEl = document.querySelector('input[name="title"]');
    if (titleInputEl) {
        titleInputEl.addEventListener('input', updateAiButtonsState);
    }

    // --- AI summary generation ---
    if (aiSummaryBtn) {
        aiSummaryBtn.addEventListener('click', function () {
            var title = /** @type {HTMLInputElement} */ (document.querySelector('input[name="title"]')).value;
            var bodyHtml = collectFieldsContentText();
            setAiButtonBusy(aiSummaryBtn, true);

            window.ScoutMagicApi.postJson('/news/seo/generate-summary', { title: title, body_html: bodyHtml })
                .then(function (res) {
                    var data = res.data || {};
                    setAiButtonBusy(aiSummaryBtn, false);
                    updateAiButtonsState();
                    if (data.success) {
                        /** @type {HTMLInputElement} */ (document.getElementById('summary')).value = data.summary;
                    } else {
                        window.ScoutMagicToast.show(data.error || 'Erreur lors de la génération.', { variant: 'error' });
                    }
                });
        });
    }

    // --- AI keyword generation ---
    if (aiKeywordsBtn) {
        aiKeywordsBtn.addEventListener('click', function () {
            var title = /** @type {HTMLInputElement} */ (document.querySelector('input[name="title"]')).value;
            var bodyHtml = collectFieldsContentText();
            setAiButtonBusy(aiKeywordsBtn, true);

            window.ScoutMagicApi.postJson('/news/seo/generate-keywords', { title: title, body_html: bodyHtml })
                .then(function (res) {
                    var data = res.data || {};
                    setAiButtonBusy(aiKeywordsBtn, false);
                    updateAiButtonsState();
                    if (data.success) {
                        /** @type {HTMLInputElement} */ (document.getElementById('seo_keywords')).value = data.keywords;
                    } else {
                        window.ScoutMagicToast.show(data.error || 'Erreur lors de la génération.', { variant: 'error' });
                    }
                });
        });
    }

    // --- Form state badge + access/response-limit interlock ---
    function updateFormStateBadge() {
        var badge = document.getElementById('news-form-state-badge');
        if (!badge) return;
        var forceClosed = /** @type {HTMLInputElement} */ (document.getElementById('form_is_force_closed'));
        var opensAt = /** @type {HTMLInputElement} */ (document.getElementById('form_opens_at'));
        var closesAt = /** @type {HTMLInputElement} */ (document.getElementById('form_closes_at'));
        var today = new Date().toISOString().slice(0, 10);
        var open = true;
        if (forceClosed?.checked) open = false;
        if (opensAt?.value && today < opensAt.value) open = false;
        if (closesAt?.value && today > closesAt.value) open = false;
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
        var limitSelect = /** @type {HTMLSelectElement} */ (document.getElementById('form_response_limit'));
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
    // The editor's server data — the article, its form fields, the
    // finance accounts — arrives as a `news-editor-data` JSON island
    // (ScoutMagicApi.pageData()): data to the parser rather than code, so
    // a field label containing a tag-closing sequence cannot end the
    // block in the middle of an object literal.
    var NEWS_EDITOR_DATA = window.ScoutMagicApi.pageData('news-editor-data');
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

    if (fieldsListEl && NEWS_EDITOR_DATA) {
        fieldState = (NEWS_EDITOR_DATA.fields || []).map(function (f) {
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
            var labelInput = /** @type {HTMLElement} */ (fieldsListEl.querySelector('[data-key="' + field._key + '"] .news-field-label-input'));
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

        /**
         * @param {string} key
         * @param {number} direction
         */
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
        /**
         * @param {string} draggedKey
         * @param {string} targetKey
         */
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
            var articleId = NEWS_EDITOR_DATA.articleId;
            var ids = fieldState.filter(function (f) { return f.id; }).map(function (f) { return f.id; });
            if (!articleId || ids.length !== fieldState.length) return;
            window.ScoutMagicApi.postJson('/news/' + articleId + '/form/fields/reorder', { ids: ids });
        }

        function fieldIcon(type) {
            var found = FIELD_TYPES.find(function (t) { return t.type === type; });
            return found ? found.icon : 'bi-question';
        }

        // Exactly the same toolbar/contenteditable/image-insertion code as
        // the article body editor (module usability review: "the bloc de
        // texte must have the same formatting menu as the article itself,
        // reuse the same code") — one independent instance per field,
        // since a form can have several "text" blocks at once.
        /**
         * @param {HTMLElement} container
         * @param {{confirmation_text: ?string}} field
         * @param {string} labelledBy id of the caption that names the editor
         */
        function buildRichTextEditor(container, field, labelledBy) {
            var rte = createRichTextEditor(field.confirmation_text || '', function (html) {
                field.confirmation_text = html;
            }, labelledBy);
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
                return !NON_INPUT_TYPES.includes(f.field_type);
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

        // Extracted out of buildFieldRow() to keep its own cognitive
        // complexity down — same drag-and-drop wiring either way.
        function attachDragHandlers(wrapper, field) {
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

        // Pin icon (first, non-draggable field) or drag handle + mobile
        // move buttons (every other field) — extracted out of
        // buildFieldRow() itself, same reasoning as buildLabelAndRequiredRow()
        // and its siblings further down: keeps that function's own
        // cognitive complexity under the linter's threshold. Side-effects
        // onto `row` rather than returning anything, matching
        // attachDragHandlers()'s convention just above.
        function buildFieldLeadingControls(row, index, isPinned) {
            if (isPinned) {
                var pinIcon = document.createElement('span');
                pinIcon.className = 'text-body-secondary';
                pinIcon.setAttribute('aria-label', 'Toujours en premier');
                pinIcon.title = 'Toujours en premier';
                pinIcon.innerHTML = '<i class="bi bi-pin-angle-fill"></i>';
                row.appendChild(pinIcon);
                return;
            }

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
                attachDragHandlers(wrapper, field);
            }

            var row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2';
            row.style.cursor = 'pointer';

            buildFieldLeadingControls(row, index, isPinned);

            var icon = document.createElement('i');
            icon.className = 'bi ' + fieldIcon(field.field_type);
            row.appendChild(icon);

            var label = document.createElement('span');
            label.className = 'flex-grow-1';
            var isNonInput = NON_INPUT_TYPES.includes(field.field_type);
            var BLOCK_LABELS = { confirmation: 'Bloc de confirmation', text: 'Bloc de texte' };
            var labelText = BLOCK_LABELS[field.field_type] || field.label || 'Sans libellé';
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

        function addFieldEditRow(panel, html) {
            var div = document.createElement('div');
            div.className = 'mb-2';
            div.innerHTML = html;
            panel.appendChild(div);
            return div;
        }

        /**
         * One edit-panel row holding a CAPTION — a <span> that names a group
         * of controls where a <label for> cannot reach (a button group, a
         * contenteditable). Built node by node rather than through
         * addFieldEditRow()'s innerHTML: the id is derived from the field
         * and the text is a sentence, and neither has any business being
         * parsed as markup.
         *
         * @param {HTMLElement} panel
         * @param {string} captionId
         * @param {string} text
         * @returns {HTMLElement} the row, for a caller appending to it
         */
        function addFieldCaptionRow(panel, captionId, text) {
            var div = document.createElement('div');
            div.className = 'mb-2';
            var caption = document.createElement('span');
            caption.className = 'form-label small d-block';
            caption.id = captionId;
            caption.textContent = text;
            div.appendChild(caption);
            panel.appendChild(div);
            return div;
        }

        /**
         * A DOM id for one control of one field's edit panel.
         *
         * Every control below needs one, because a <label> only names a
         * control it is actually associated with — a label element sitting
         * next to an input names nothing at all, to a screen reader as much
         * as to any other reader of the accessibility tree. The panel's
         * controls used to be built that way and were therefore unreachable
         * by name; tests/e2e/specs/news-form-payment.spec.js drives them by
         * their accessible names now, which is what keeps this honest.
         *
         * Keyed on field._key rather than on a global counter: it is the
         * identity this script already gives each field in fieldState, so
         * two panels (or a panel re-rendered mid-edit) can never mint the
         * same id.
         *
         * @param {{_key: number}} field
         * @param {string} control
         * @returns {string}
         */
        function fieldControlId(field, control) {
            return 'news-field-' + field._key + '-' + control;
        }

        /**
         * One edit-panel row carrying a real <label for> the caller's
         * control, appended next.
         *
         * `hidden` puts the label in the accessibility tree only
         * (.visually-hidden, the same class the rest of this codebase uses
         * for exactly this) — for the controls whose purpose the
         * surrounding text or placeholder already makes obvious to a
         * sighted user, where adding a visible caption would change the
         * interface rather than fix it.
         *
         * @param {HTMLElement} panel
         * @param {string} text
         * @param {string} controlId
         * @param {{hidden?: boolean}} [options]
         * @returns {HTMLDivElement}
         */
        function addLabelledFieldEditRow(panel, text, controlId, options) {
            var row = addFieldEditRow(panel, '');
            var label = document.createElement('label');
            label.className = (options?.hidden) ? 'visually-hidden' : 'form-label small';
            label.htmlFor = controlId;
            label.textContent = text;
            row.appendChild(label);

            return row;
        }

        /**
         * A checkbox with its own label, associated — the form-check markup
         * used to be assembled as an innerHTML string whose <label> carried
         * no `for`, which named nothing.
         *
         * @param {string} controlId
         * @param {string} text
         * @param {boolean} checked
         * @returns {{wrapper: HTMLDivElement, input: HTMLInputElement}}
         */
        function buildLabelledCheckbox(controlId, text, checked) {
            var wrapper = document.createElement('div');
            wrapper.className = 'form-check';

            var input = document.createElement('input');
            input.className = 'form-check-input';
            input.type = 'checkbox';
            input.id = controlId;
            input.checked = checked;

            var label = document.createElement('label');
            label.className = 'form-check-label';
            label.htmlFor = controlId;
            label.textContent = text;

            wrapper.appendChild(input);
            wrapper.appendChild(label);

            return { wrapper: wrapper, input: input };
        }

        // Each of the following builds one self-contained, field-type-specific
        // section of the edit panel — extracted out of buildFieldEditPanel()
        // itself (rather than left as inline `if` blocks there) purely to keep
        // its own cognitive complexity down. Same DOM/behavior either way.
        function buildLabelAndRequiredRow(panel, field) {
            var labelId = fieldControlId(field, 'label');
            var labelRow = addLabelledFieldEditRow(panel, 'Libellé du champ', labelId);
            var labelInput = document.createElement('input');
            labelInput.type = 'text';
            labelInput.id = labelId;
            // The class stays: it is this script's own hook for focusing the
            // input right after a field is added (see addField()).
            labelInput.className = 'form-control news-field-label-input';
            labelInput.value = field.label || '';
            labelInput.addEventListener('input', function () {
                field.label = labelInput.value;
                var rowLabel = fieldsListEl.querySelector('[data-key="' + field._key + '"] .flex-grow-1');
                if (rowLabel) rowLabel.textContent = labelInput.value || 'Sans libellé';
            });
            labelRow.appendChild(labelInput);

            var reqRow = addFieldEditRow(panel, '');
            var required = buildLabelledCheckbox(fieldControlId(field, 'required'), 'Obligatoire', !!field.is_required);
            required.input.addEventListener('change', function () {
                field.is_required = required.input.checked;
            });
            reqRow.appendChild(required.wrapper);
        }

        function buildNumberCapacityRow(panel, field) {
            var capacityId = fieldControlId(field, 'capacity');
            var capRow = addFieldEditRow(panel, '');
            var limit = buildLabelledCheckbox(
                fieldControlId(field, 'capacity-toggle'),
                'Limiter la capacité',
                field.capacity_max !== null
            );
            limit.wrapper.className = 'form-check mb-1';

            var capInput = document.createElement('input');
            capInput.type = 'number';
            capInput.id = capacityId;
            capInput.min = '1';
            capInput.step = '1';
            capInput.className = 'form-control form-control-sm mt-1' + (field.capacity_max === null ? ' d-none' : '');
            capInput.value = field.capacity_max || '';
            limit.input.addEventListener('change', function () {
                capInput.classList.toggle('d-none', !limit.input.checked);
                field.capacity_max = limit.input.checked ? (Number.parseInt(capInput.value, 10) || 0) : null;
            });
            capInput.addEventListener('input', function () {
                field.capacity_max = Number.parseInt(capInput.value, 10) || 0;
            });
            capRow.appendChild(limit.wrapper);
            // Hidden label, inside this same row and right before the box it
            // names: the checkbox above already tells a sighted reader what
            // the number is for, so a second visible caption would only
            // repeat it — but the box still has to have a name of its own.
            var capLabel = document.createElement('label');
            capLabel.className = 'visually-hidden';
            capLabel.htmlFor = capacityId;
            capLabel.textContent = 'Capacité maximale';
            capRow.appendChild(capLabel);
            capRow.appendChild(capInput);
            addFieldEditRow(panel, '<span class="form-text">Le nombre maximum est le cumul de toutes les réponses. Exemple : si la limite est 50 et que 48 ont déjà été réservés, le prochain répondant verra « Il reste 2 places ».</span>');

            if (NEWS_EDITOR_DATA.financeAvailable) {
                var priceId = fieldControlId(field, 'price');
                var priceRow = addLabelledFieldEditRow(panel, 'Prix unitaire (€)', priceId);
                var priceInput = document.createElement('input');
                priceInput.type = 'number';
                priceInput.id = priceId;
                priceInput.step = '0.50';
                priceInput.min = '0';
                priceInput.className = 'form-control';
                priceInput.value = field.price_per_unit || '';
                priceInput.addEventListener('input', function () {
                    field.price_per_unit = priceInput.value !== '' ? Number.parseFloat(priceInput.value) : null;
                    renderFieldList();
                });
                priceRow.appendChild(priceInput);
                addFieldEditRow(panel, '<span class="form-text">Laisser vide si ce champ n\'est pas payant.</span>');
            }
        }

        function buildOptionsSourceRow(panel, field) {
            // A caption, not a <label>: what it names is a group of buttons,
            // and a <label> may only be associated with a form control. The
            // group carries role="group" + aria-labelledby instead, which is
            // how a set of controls gets one shared name.
            var sourceCaptionId = fieldControlId(field, 'options-source');
            var sourceRow = addFieldCaptionRow(panel, sourceCaptionId, 'Source des options');
            var sourceGroup = document.createElement('div');
            sourceGroup.className = 'btn-group';
            sourceGroup.setAttribute('role', 'group');
            sourceGroup.setAttribute('aria-labelledby', sourceCaptionId);
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

            // Hidden label: the placeholder already tells a sighted reader
            // what goes in the box, and a placeholder is not a name.
            var manualId = fieldControlId(field, 'options');
            var manualRow = addLabelledFieldEditRow(panel, 'Options, une par ligne', manualId, { hidden: true });
            manualRow.classList.toggle('d-none', field.options_source === 'members');
            var manualTextarea = document.createElement('textarea');
            manualTextarea.id = manualId;
            manualTextarea.className = 'form-control';
            manualTextarea.rows = 4;
            manualTextarea.placeholder = 'Une option par ligne';
            manualTextarea.value = field.options_manual || '';
            manualTextarea.addEventListener('input', function () {
                field.options_manual = manualTextarea.value;
            });
            manualRow.appendChild(manualTextarea);

            var membersHelp = addFieldEditRow(panel, '<span class="form-text">Les options seront les membres (enfants/animés) rattachés au compte de la personne qui remplit le formulaire. Résolu dynamiquement au moment du remplissage.</span>');
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

        function buildFieldEditPanel(field) {
            var panel = document.createElement('div');
            panel.className = 'mt-2 pt-2 border-top';
            panel.addEventListener('click', function (e) { e.stopPropagation(); });

            if (!NON_INPUT_TYPES.includes(field.field_type)) {
                buildLabelAndRequiredRow(panel, field);
            }

            if (field.field_type === 'number') {
                buildNumberCapacityRow(panel, field);
            }

            if (['dropdown', 'radio', 'checkbox'].includes(field.field_type)) {
                buildOptionsSourceRow(panel, field);
            }

            if (field.field_type === 'confirmation') {
                var confirmationId = fieldControlId(field, 'confirmation');
                var textRow = addLabelledFieldEditRow(panel, "Texte affiché avant l'envoi", confirmationId);
                var textarea = document.createElement('textarea');
                textarea.id = confirmationId;
                textarea.className = 'form-control';
                textarea.rows = 5;
                textarea.value = field.confirmation_text || '';
                textarea.addEventListener('input', function () {
                    field.confirmation_text = textarea.value;
                });
                textRow.appendChild(textarea);
            }

            if (field.field_type === 'text') {
                // A caption rather than a <label>, for the same reason as
                // "Source des options" above: what it names is a
                // contenteditable, which no <label for> can reach.
                var contentCaptionId = fieldControlId(field, 'content');
                addFieldCaptionRow(panel, contentCaptionId, 'Contenu');
                buildRichTextEditor(panel, field, contentCaptionId);
            }

            return panel;
        }
    }

    // --- Submit: serialize fields before native form POST ---
    var editorForm = document.getElementById('news-editor-form');
    if (editorForm) {
        editorForm.addEventListener('submit', function () {
            var fieldsInput = /** @type {HTMLInputElement} */ (document.getElementById('fields_json_input'));
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
            window.ScoutMagicApi.postJson('/news/' + id, {}, { method: 'DELETE' }).then(function (res) {
                var data = res.data || {};
                if (data.success) {
                    window.location.href = '/news/manage';
                } else {
                    window.ScoutMagicToast.show(data.error || 'Erreur lors de la suppression.', { variant: 'error' });
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
            numberFields.forEach(function (inputEl) {
                var input = /** @type {HTMLInputElement} */ (inputEl);
                var price = Number.parseFloat(input.dataset.price);
                if (!price) return;
                var qty = Number.parseFloat(input.value) || 0;
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

    // --- Test seam (no behavioural effect) ---------------------------------
    // Unlike public/sw.js, whose
    // own globalThis lines are true no-ops (their declarations are top-level
    // in a classic script, so already global), everything above lives inside
    // this IIFE and is therefore genuinely module-private. This block adds
    // ONE namespaced global so tests/js/news-form-builder.test.js can reach
    // the HTML sanitizer directly and exercise the real implementation rather
    // than reimplementing its logic in a test-only copy — the same reason
    // window.SelectBar and window.ScoutMagicNav already exist.
    //
    // Test-only: nothing in production reads this, and nothing should. The
    // sanitizer in particular must never be called from a page as a
    // substitute for Core\Security\HtmlSanitizer's server-side pass — this
    // is the client half of a DOM-to-DOM round trip, not a replacement.
    globalThis.ScoutMagicNewsFormBuilderInternals = {
        sanitizeHtml: sanitizeHtml,
        sanitizeHtmlChildren: sanitizeHtmlChildren,
        sanitizeHtmlAttributes: sanitizeHtmlAttributes,
        isSafeUrlScheme: isSafeUrlScheme,
        isPublicAccess: isPublicAccess,
        visibilityUiState: visibilityUiState,
        hasTitleOrContent: hasTitleOrContent,
        setAiButtonBusy: setAiButtonBusy,
        HTML_SANITIZER_ALLOWED_TAGS: HTML_SANITIZER_ALLOWED_TAGS,
        HTML_SANITIZER_STRIP_WITH_CONTENT: HTML_SANITIZER_STRIP_WITH_CONTENT,
        URL_SCHEME_ALLOWLIST: URL_SCHEME_ALLOWLIST,
    };
})();
