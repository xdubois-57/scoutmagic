/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Mass-mail list page (modules/mass_mail/views/list.html.twig): the
// compose dialog (_compose_dialog.html.twig) and its draft → test →
// sending wizard. Extracted from the template's inline <script> verbatim
// so the Vitest suite can exercise the production code directly
// (tests/js/mass-mail-list.test.js). Server-side data (sections, lists,
// scout years…) is handed over by an inline nonce-tagged script setting
// window.massMailListData — the support-dashboard pattern.
//
// Fetches ride the site-wide ScoutMagicApi envelope ({ok, status, data})
// and success feedback goes through ScoutMagicToast; the two multipart
// uploads (audience import, attachments) keep a raw fetch(FormData) since
// the JSON toolbox deliberately owns JSON bodies only.
(function () {
    var mmModalEl = document.getElementById('mm-modal');
    if (!mmModalEl || !window.massMailListData) return;

    const MM_DATA = window.massMailListData;
    const api = window.ScoutMagicApi;
    const escapeHtml = api.escapeHtml;

    /**
     * @param {string} id
     * @returns {HTMLElement}
     */
    function el(id) { return /** @type {HTMLElement} */ (document.getElementById(id)); }
    /**
     * @param {string} id
     * @returns {HTMLInputElement}
     */
    function inputEl(id) { return /** @type {HTMLInputElement} */ (document.getElementById(id)); }
    /**
     * @param {string} id
     * @returns {HTMLSelectElement}
     */
    function selectEl(id) { return /** @type {HTMLSelectElement} */ (document.getElementById(id)); }

    const statusLabel = (s) => ({ draft: 'Brouillon', test: 'Test', sending: 'Envoi en cours', sent: 'Envoyé' })[s] || s;
    // Same tone mapping as partials/status_badge.html.twig (draft → info,
    // test/sending → warning, sent → success) — keep the two in step.
    const statusBadgeClass = (s) => ({ draft: 'text-bg-info', test: 'text-bg-warning', sending: 'text-bg-warning', sent: 'text-bg-success' })[s] || 'text-bg-secondary';

    const mmModal = window.bootstrap ? new window.bootstrap.Modal(mmModalEl) : null;
    let mmCurrentId = null;
    let mmCurrentStatus = null;
    /** @type {number[]} */
    let mmSelectedYearIds = [];
    // Mail-merge state: the imported audience ({id, filename, sheet_name,
    // columns, row_count} + sample values), the previewed row offset, and
    // where a picked variable should be inserted (subject vs body).
    let mmAudience = null;
    let mmAudienceSample = {};
    let mmMergeOffset = 0;
    let mmInsertTarget = 'body';

    // Except for a chef d'unité (or above), the sender section is locked to
    // the account's own (highest-role) section — not a free choice.
    function populateSectionSelect(currentSectionId) {
        const select = selectEl('mm-section');
        if (MM_DATA.unrestricted) {
            select.innerHTML = MM_DATA.sections.map(s => '<option value="' + s.id + '">' + escapeHtml(s.name) + '</option>').join('');
            select.disabled = false;
            return;
        }

        const forced = MM_DATA.forcedSectionId;
        const options = new Map();
        if (forced !== null) {
            const forcedSection = MM_DATA.sections.find(s => s.id === forced);
            options.set(forced, forcedSection ? forcedSection.name : ('Section #' + forced));
        }
        // A draft created (e.g. by a chef d'unité) targeting a different
        // section is still shown correctly when reopened — the select stays
        // fully locked either way, this only avoids silently switching the
        // section away from what's actually stored on save.
        if (currentSectionId != null && !options.has(currentSectionId)) {
            const currentSection = MM_DATA.sections.find(s => s.id === currentSectionId);
            options.set(currentSectionId, currentSection ? currentSection.name : ('Section #' + currentSectionId));
        }

        select.innerHTML = Array.from(options.entries())
            .map(([id, name]) => '<option value="' + id + '">' + escapeHtml(name) + '</option>')
            .join('');
        select.disabled = true;
    }

    /**
     * @param {string} listType
     * @param {number|null} listSectionId
     * @returns {boolean}
     */
    function isListAllowed(listType, listSectionId) {
        if (MM_DATA.unrestricted) return true;
        if (listType === 'default_chiefs') return true;
        if (listType === 'default_section') return MM_DATA.userSectionIds.includes(listSectionId);
        // Mail-merge is open to every chief — the uploaded file names the
        // recipients, not a section list (server-side rule mirrored here).
        if (listType === 'mail_merge') return true;
        return false; // "Membres actifs" and every custom list
    }

    function populateListSelect() {
        const select = selectEl('mm-list');
        let html = '';
        MM_DATA.defaultLists.forEach(l => {
            const disabled = !isListAllowed(l.list_type, l.list_section_id) ? 'disabled' : '';
            html += '<option value="' + l.list_type + ':' + (l.list_section_id ?? '') + '" data-type="' + l.list_type + '" '
                + 'data-list-section-id="' + (l.list_section_id ?? '') + '" title="' + escapeHtml(l.description) + '" ' + disabled + '>'
                + escapeHtml(l.label) + '</option>';
        });
        MM_DATA.customLists.forEach(l => {
            const disabled = !isListAllowed('custom', null) ? 'disabled' : '';
            html += '<option value="custom:' + l.id + '" data-type="custom" data-list-id="' + l.id + '" '
                + 'title="' + escapeHtml(l.description) + '" ' + disabled + '>' + escapeHtml(l.name) + '</option>';
        });
        html += '<option value="mail_merge:" data-type="mail_merge" '
            + 'title="Un fichier Excel importé définit les destinataires : un email par ligne, avec des variables par colonne.">'
            + 'Publipostage — fichier Excel</option>';
        select.innerHTML = html;
    }

    // Multi-select (module addendum) — several years can be checked at once;
    // their lists are merged/deduplicated server-side at send time
    // (Service\MailingListService::resolveMembersForYears()). Defaults to
    // "current" only.
    function populateScoutYearCheckboxes() {
        const group = el('mm-scout-year-group');
        const entries = [
            { key: 'previous', label: MM_DATA.scoutYears.previous.label, id: MM_DATA.scoutYears.previous.id, available: MM_DATA.scoutYears.previous.available },
            { key: 'current', label: MM_DATA.scoutYears.current.label, id: MM_DATA.scoutYears.current.id, available: MM_DATA.scoutYears.current.available },
            { key: 'next', label: MM_DATA.scoutYears.next.label, id: MM_DATA.scoutYears.next.id, available: MM_DATA.scoutYears.next.available },
        ];
        // "available: false" (module addendum — next year needs a Desk import
        // first) is a permanent lock, independent from setFieldsEditable()'s
        // temporary read-only lock — unlike a disabled *button*, a disabled
        // *checkbox* still shows its checked state clearly, so no extra
        // pointer-events workaround is needed for the read-only case below.
        group.innerHTML = entries.map(e => (
            '<div class="form-check form-check-inline">'
            + '<input class="form-check-input mm-year-checkbox" type="checkbox" value="' + e.id + '" id="mm-year-' + e.key + '" '
            + (e.available ? '' : 'disabled title="Desk n\'a pas encore été importé pour cette année scoute."') + '>'
            + '<label class="form-check-label" for="mm-year-' + e.key + '">' + escapeHtml(e.label) + '</label>'
            + '</div>'
        )).join('');
        selectScoutYears([MM_DATA.scoutYears.current.id]);

        const [cutoffMonth, cutoffDay] = MM_DATA.previousYearCutoff.split('-');
        el('mm-previous-year-note').textContent =
            'Année précédente (' + MM_DATA.scoutYears.previous.label + ') : les personnes actives lors du dernier import '
            + 'Desk de cette année scoute (généralement autour du ' + cutoffDay + '/' + cutoffMonth + ', ajustable dans '
            + 'Configuration > Réglages).';
    }

    /** @param {number[]} yearIds */
    function selectScoutYears(yearIds) {
        mmSelectedYearIds = yearIds;
        yearCheckboxes().forEach(cb => {
            cb.checked = yearIds.includes(parseInt(cb.value, 10));
        });
    }

    /** @returns {HTMLInputElement[]} */
    function yearCheckboxes() {
        return Array.from(/** @type {NodeListOf<HTMLInputElement>} */ (
            document.querySelectorAll('#mm-scout-year-group .mm-year-checkbox')
        ));
    }

    // Delegated (checkboxes are rebuilt on every populateScoutYearCheckboxes() call).
    el('mm-scout-year-group').addEventListener('change', (e) => {
        if (!(e.target instanceof Element) || !e.target.classList.contains('mm-year-checkbox')) return;
        mmSelectedYearIds = yearCheckboxes().filter(cb => cb.checked).map(cb => parseInt(cb.value, 10));
    });

    // Rich text toolbar — same execCommand engine as partials/rich_text_editor.html.twig.
    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('#mm-body-toolbar [data-command]')).forEach(btn => {
        btn.addEventListener('click', () => {
            const cmd = btn.dataset.command;
            const bodyContent = el('mm-body-content');
            if (cmd === 'createLink') {
                const url = prompt('URL du lien :');
                if (url) document.execCommand(cmd, false, url);
            } else if (cmd === 'formatBlock') {
                document.execCommand(cmd, false, '<' + btn.dataset.value + '>');
            } else {
                document.execCommand(cmd, false, null);
            }
            bodyContent.focus();
        });
    });

    function currentListType() {
        const option = selectEl('mm-list').selectedOptions[0];
        return option ? option.dataset.type : null;
    }

    // Shows/hides the mail-merge zone vs the scout-year block depending on
    // the selected list type — a mail-merge email's recipients come from the
    // file, never from a scout year.
    function updateListTypeUi() {
        const isMerge = currentListType() === 'mail_merge';
        el('mm-merge-zone').classList.toggle('d-none', !isMerge);
        el('mm-merge-list-note').classList.toggle('d-none', !isMerge);
        el('mm-scout-year-zone').classList.toggle('d-none', isMerge);
        updateVariableDropdown();
    }

    function renderMergeAudience() {
        const uploadState = el('mm-merge-upload-state');
        const infoState = el('mm-merge-info-state');
        if (!mmAudience) {
            uploadState.classList.remove('d-none');
            infoState.classList.add('d-none');
            updateVariableDropdown();
            return;
        }

        uploadState.classList.add('d-none');
        infoState.classList.remove('d-none');
        el('mm-merge-filename').textContent = mmAudience.filename;
        el('mm-merge-meta').textContent =
            mmAudience.row_count + ' ligne' + (mmAudience.row_count > 1 ? 's' : '') + ' · feuille « ' + mmAudience.sheet_name + ' »';
        el('mm-merge-columns').innerHTML =
            '<span class="form-text small mt-0 me-1">Colonnes :</span>'
            + mmAudience.columns.map(c => '<span class="badge text-bg-light border">' + escapeHtml(c) + '</span>').join(' ');
        el('mm-merge-replace-btn').classList.toggle('d-none', mmCurrentStatus !== null && mmCurrentStatus !== 'draft');
        updateVariableDropdown();
    }

    /** @param {string[]} warnings */
    function showMergeWarnings(warnings) {
        const box = el('mm-merge-warnings');
        if (!warnings || !warnings.length) {
            box.classList.add('d-none');
            return;
        }
        box.innerHTML = warnings.map(w => '<div>' + escapeHtml(w) + '</div>').join('');
        box.classList.remove('d-none');
    }

    // The "{ } Variable" toolbar dropdown — one entry per audience column,
    // with the first row's value as a sample hint. Visible only while a
    // mail-merge draft with an imported audience is being edited.
    function updateVariableDropdown() {
        const dropdown = el('mm-variable-dropdown');
        const editable = mmCurrentStatus === null || mmCurrentStatus === 'draft';
        const visible = currentListType() === 'mail_merge' && !!mmAudience && editable;
        dropdown.classList.toggle('d-none', !visible);
        if (!visible) return;

        el('mm-variable-menu').innerHTML = mmAudience.columns.map(c => {
            const sample = (mmAudienceSample[c] ?? '') !== '' ? mmAudienceSample[c] : '—';
            return '<li><button type="button" class="dropdown-item d-flex justify-content-between gap-3 mm-variable-item" data-column="' + escapeHtml(c) + '">'
                + '<span>' + escapeHtml(c) + '</span>'
                + '<span class="text-body-secondary small text-truncate" style="max-width: 10rem;">« ' + escapeHtml(sample) + ' »</span>'
                + '</button></li>';
        }).join('');

        /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.mm-variable-item')).forEach(item => {
            item.addEventListener('click', () => insertVariable(item.dataset.column));
        });
    }

    // Inserted where the chief last had their cursor — the subject input or
    // the rich-text body (default).
    /** @param {string} column */
    function insertVariable(column) {
        // The braces stay split ('{'+'{') from the inline-script days — the
        // template that used to render this code would otherwise have handed
        // Twig a literal {{ … }} to evaluate (caught by
        // tests/e2e/specs/mass-mail-merge.spec.js). Harmless in a static
        // file, and the intent stays obvious.
        const token = '{' + '{' + column + '}' + '}';
        if (mmInsertTarget === 'subject') {
            const input = inputEl('mm-subject');
            const start = input.selectionStart ?? input.value.length;
            const end = input.selectionEnd ?? input.value.length;
            input.value = input.value.slice(0, start) + token + input.value.slice(end);
            input.focus();
            input.setSelectionRange(start + token.length, start + token.length);
            return;
        }
        const body = el('mm-body-content');
        body.focus();
        document.execCommand('insertText', false, token);
    }

    el('mm-subject').addEventListener('focus', () => { mmInsertTarget = 'subject'; });
    el('mm-body-content').addEventListener('focus', () => { mmInsertTarget = 'body'; });

    el('mm-list').addEventListener('change', updateListTypeUi);

    el('mm-merge-import-btn').addEventListener('click', async () => {
        const input = inputEl('mm-merge-file');
        if (!input.files.length) {
            showError('Choisissez d\'abord un fichier Excel (.xlsx).');
            return;
        }

        const formData = new FormData();
        formData.append('file', input.files[0]);
        formData.append('_csrf_token', api.csrfToken());

        const res = await fetch('/mass-mail/audiences', { method: 'POST', body: formData });
        const data = await res.json().catch(() => null);
        if (!data || !data.success) {
            showErrorList('Le fichier n\'a pas été accepté :', (data && data.errors) || [(data && data.error) || 'Erreur.']);
            return;
        }
        el('mm-error').classList.add('d-none');
        input.value = '';
        mmAudience = data.audience;
        mmAudienceSample = {};
        fetchAudienceSample(mmAudience.id);
        renderMergeAudience();
        showMergeWarnings(data.warnings);
    });

    /** @param {number} audienceId */
    async function fetchAudienceSample(audienceId) {
        const res = await api.getJson('/mass-mail/audiences/' + audienceId);
        if (res.data && res.data.success) {
            mmAudience = res.data.audience;
            mmAudienceSample = res.data.sample || {};
            renderMergeAudience();
        }
    }

    el('mm-merge-replace-btn').addEventListener('click', () => {
        // Back to the upload state — the current audience stays attached
        // until a new import actually succeeds.
        el('mm-merge-upload-state').classList.remove('d-none');
        el('mm-merge-info-state').classList.add('d-none');
    });

    /** @param {number} offset */
    async function loadMergePreview(offset) {
        const res = await api.getJson('/mass-mail/' + mmCurrentId + '/merge-preview?offset=' + offset);
        const data = res.data;
        if (!data || !data.success) {
            showError((data && data.error) || 'Erreur.');
            return;
        }
        const p = data.preview;
        mmMergeOffset = p.offset;
        el('mm-merge-preview-position').textContent = 'Ligne ' + (p.offset + 1) + ' / ' + p.total;
        el('mm-merge-preview-recipient').textContent = 'Destinataire : ' + p.recipient_label + ' (ligne ' + p.row_index + ' du fichier)';
        el('mm-merge-preview-subject').textContent = p.subject;
        // Server-rendered: the body was sanitized at save time and every
        // substituted value is HTML-escaped server-side.
        el('mm-merge-preview-body').innerHTML = p.body_html;
        /** @type {HTMLButtonElement} */ (el('mm-merge-prev-btn')).disabled = p.offset <= 0;
        /** @type {HTMLButtonElement} */ (el('mm-merge-next-btn')).disabled = p.offset >= p.total - 1;

        const warnings = [];
        if (p.unknown_tokens.length) {
            // Braces split for the same historical reason as insertVariable().
            warnings.push('Variable(s) inconnue(s) dans le message : ' + p.unknown_tokens.map(t => '{' + '{' + t + '}' + '}').join(', ') + ' — vérifiez l\'orthographe des colonnes.');
        }
        if (p.missing_values.length) {
            warnings.push('Cette ligne n\'a pas de valeur pour : ' + p.missing_values.join(', ') + ' (remplacée par du vide).');
        }
        const box = el('mm-merge-preview-warnings');
        box.classList.toggle('d-none', !warnings.length);
        box.innerHTML = warnings.map(w => '<div>' + escapeHtml(w) + '</div>').join('');
    }

    el('mm-merge-prev-btn').addEventListener('click', () => loadMergePreview(Math.max(0, mmMergeOffset - 1)));
    el('mm-merge-next-btn').addEventListener('click', () => loadMergePreview(mmMergeOffset + 1));

    /** @param {boolean} editable */
    function setFieldsEditable(editable) {
        // The sender section select stays permanently disabled for a
        // restricted (non chef d'unité) account — populateSectionSelect()
        // already locked it; never re-enable it here.
        selectEl('mm-section').disabled = MM_DATA.unrestricted ? !editable : true;
        selectEl('mm-list').disabled = !editable;
        inputEl('mm-subject').disabled = !editable;
        el('mm-body-content').setAttribute('contenteditable', editable ? 'true' : 'false');
        // A checkbox's checked state stays clearly visible even when disabled
        // (unlike a button's ".active" highlight), so read-only mode can just
        // disable it directly — except a year already permanently unavailable
        // (no Desk import yet) stays disabled regardless of editable.
        yearCheckboxes().forEach(cb => {
            if (!editable) {
                cb.disabled = true;
            } else {
                const entry = [MM_DATA.scoutYears.previous, MM_DATA.scoutYears.current, MM_DATA.scoutYears.next]
                    .find(y => y.id === parseInt(cb.value, 10));
                cb.disabled = entry ? !entry.available : false;
            }
        });
        el('mm-body-toolbar').classList.toggle('d-none', !editable);
        inputEl('mm-merge-file').disabled = !editable;
        /** @type {HTMLButtonElement} */ (el('mm-merge-import-btn')).disabled = !editable;
        el('mm-merge-replace-btn').classList.toggle('d-none', !editable);
    }

    /** @param {Array<{id: number, file_id: number, name: string}>} attachments */
    function renderAttachments(attachments) {
        const list = el('mm-attachments-list');
        if (!attachments.length) {
            list.innerHTML = '<p class="text-body-secondary fst-italic small mb-0">Aucune pièce jointe.</p>';
            return;
        }
        list.innerHTML = attachments.map(a =>
            '<div class="d-flex justify-content-between align-items-center border rounded px-2 py-1">'
            + '<a href="/files/' + a.file_id + '" target="_blank" rel="noopener">' + escapeHtml(a.name) + '</a>'
            + '<button type="button" class="btn btn-sm btn-outline-danger mm-attachment-delete-btn" data-id="' + a.id + '"><i class="bi bi-trash"></i></button>'
            + '</div>'
        ).join('');
        /** @type {NodeListOf<HTMLElement>} */ (list.querySelectorAll('.mm-attachment-delete-btn')).forEach(btn => {
            btn.addEventListener('click', async () => {
                await api.postJson('/mass-mail/attachments/' + btn.dataset.id, {}, { method: 'DELETE' });
                openEmail(mmCurrentId);
            });
        });
    }

    /** @param {string|null} status */
    function applyStatusUi(status) {
        mmCurrentStatus = status;
        const badge = el('mm-status-badge');
        badge.textContent = statusLabel(status);
        badge.className = 'badge ms-2 ' + statusBadgeClass(status);
        badge.classList.toggle('d-none', status === null);

        const isDraft = status === null || status === 'draft';
        const isTest = status === 'test';
        const isSendingOrSent = status === 'sending' || status === 'sent';

        setFieldsEditable(isDraft);

        el('mm-save-btn').classList.toggle('d-none', !isDraft);
        el('mm-to-test-btn').classList.toggle('d-none', !isDraft || mmCurrentId === null);
        el('mm-to-draft-btn').classList.toggle('d-none', !isTest);
        el('mm-start-sending-btn').classList.toggle('d-none', !isTest);
        el('mm-test-send-zone').classList.toggle('d-none', !isTest);
        el('mm-progress-zone').classList.toggle('d-none', !isSendingOrSent);

        // Mail-merge: per-recipient preview in test mode, and the test send
        // note explaining which row's values the test carries.
        const isMerge = currentListType() === 'mail_merge';
        el('mm-merge-preview-zone').classList.toggle('d-none', !isTest || !isMerge);
        el('mm-test-send-merge-note').classList.toggle('d-none', !isTest || !isMerge);
        if (isTest && isMerge && mmCurrentId !== null) {
            loadMergePreview(0);
        }
        updateListTypeUi();
        el('mm-attachment-upload-zone').classList.toggle('d-none', !isDraft || mmCurrentId === null);
        el('mm-attachment-hint').classList.toggle('d-none', mmCurrentId !== null || !isDraft);

        if (isSendingOrSent) {
            /** @type {HTMLAnchorElement} */ (el('mm-tracking-link')).href = '/mass-mail/' + mmCurrentId + '/tracking';
        }
    }

    function renderProgress(counts) {
        if (!counts || !counts.total) {
            el('mm-progress-text').textContent = 'Aucun destinataire.';
            return;
        }
        const pct = (n) => (n / counts.total * 100).toFixed(1) + '%';
        el('mm-progress-sent').style.width = pct(counts.sent);
        el('mm-progress-error').style.width = pct(counts.error);
        el('mm-progress-pending').style.width = pct(counts.pending);
        el('mm-progress-text').textContent =
            counts.sent + ' envoyés · ' + counts.error + ' erreurs · ' + counts.pending + ' en attente — ' + counts.total + ' au total';
    }

    function resetModal() {
        mmCurrentId = null;
        mmAudience = null;
        mmAudienceSample = {};
        mmMergeOffset = 0;
        el('mm-modal-title').textContent = 'Nouvel email';
        el('mm-error').classList.add('d-none');
        inputEl('mm-subject').value = '';
        el('mm-body-content').innerHTML = '';
        inputEl('mm-test-send-email').value = MM_DATA.currentUserEmail;
        showMergeWarnings([]);
        renderMergeAudience();
        populateSectionSelect(null);
        populateListSelect();
        // A restricted account's first list option isn't necessarily an
        // allowed one (browsers don't reliably skip a disabled first option
        // for the implicit default selection) — force it explicitly.
        if (!MM_DATA.unrestricted) {
            const firstAllowed = /** @type {HTMLOptionElement|null} */ (document.querySelector('#mm-list option:not([disabled])'));
            if (firstAllowed) selectEl('mm-list').value = firstAllowed.value;
        }
        populateScoutYearCheckboxes();
        renderAttachments([]);
        applyStatusUi(null);
    }

    /** @param {number|string} id */
    async function openEmail(id) {
        const res = await api.getJson('/mass-mail/' + id);
        const data = res.data;
        if (!data || !data.success) {
            window.ScoutMagicToast.show((data && data.error) || 'Email introuvable.', { variant: 'error' });
            return;
        }
        mmCurrentId = data.email.id;
        mmAudience = null;
        mmAudienceSample = {};
        mmMergeOffset = 0;
        el('mm-modal-title').textContent = data.email.subject;
        el('mm-error').classList.add('d-none');
        showMergeWarnings([]);
        populateSectionSelect(data.email.section_id);
        populateListSelect();
        populateScoutYearCheckboxes();
        selectEl('mm-section').value = String(data.email.section_id);
        selectEl('mm-list').value = data.email.list_type + ':' + (data.email.list_type === 'custom' ? data.email.list_id : (data.email.list_section_id ?? ''));
        selectScoutYears(data.email.scout_year_ids);
        renderMergeAudience();
        if (data.email.list_type === 'mail_merge' && data.email.audience_id) {
            mmAudience = { id: data.email.audience_id, filename: '…', sheet_name: '', columns: [], row_count: 0 };
            await fetchAudienceSample(data.email.audience_id);
        }
        inputEl('mm-subject').value = data.email.subject;
        el('mm-body-content').innerHTML = data.email.body_html;
        inputEl('mm-test-send-email').value = MM_DATA.currentUserEmail;
        renderAttachments(data.attachments);
        renderProgress(data.counts);
        applyStatusUi(data.email.status);
        mmModal ? mmModal.show() : mmModalEl.classList.add('show');
    }

    el('mm-new-btn').addEventListener('click', () => {
        resetModal();
        mmModal ? mmModal.show() : mmModalEl.classList.add('show');
    });

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.mm-open-btn')).forEach(btn => {
        btn.addEventListener('click', () => openEmail(btn.dataset.id));
    });

    /** @param {string} message */
    function showError(message) {
        const box = el('mm-error');
        box.textContent = message;
        box.classList.remove('d-none');
    }

    // A refused mail-merge import reports every offending line at once —
    // rendered as a titled list rather than one flattened sentence.
    /**
     * @param {string} title
     * @param {string[]} messages
     */
    function showErrorList(title, messages) {
        const box = el('mm-error');
        box.innerHTML = '<div class="fw-semibold">' + escapeHtml(title) + '</div>'
            + '<ul class="mb-0 mt-1">' + messages.map(m => '<li>' + escapeHtml(m) + '</li>').join('') + '</ul>'
            + '<div class="small mt-1">Corrigez le fichier puis réimportez-le — aucune ligne n\'a été conservée.</div>';
        box.classList.remove('d-none');
    }

    function currentListSelection() {
        const option = selectEl('mm-list').selectedOptions[0];
        if (!option) return { list_type: null, list_id: null, list_section_id: null };
        return {
            list_type: option.dataset.type,
            list_id: option.dataset.listId ? parseInt(option.dataset.listId, 10) : null,
            list_section_id: option.dataset.listSectionId ? parseInt(option.dataset.listSectionId, 10) : null,
        };
    }

    el('mm-save-btn').addEventListener('click', async () => {
        const listSelection = currentListSelection();
        const isMerge = listSelection.list_type === 'mail_merge';
        const payload = {
            subject: inputEl('mm-subject').value,
            body_html: el('mm-body-content').innerHTML,
            section_id: parseInt(selectEl('mm-section').value, 10),
            list_type: listSelection.list_type,
            list_id: listSelection.list_id,
            list_section_id: listSelection.list_section_id,
            audience_id: isMerge && mmAudience ? mmAudience.id : null,
            scout_year_ids: isMerge ? [] : mmSelectedYearIds
        };

        if (isMerge && !mmAudience) {
            showError('Importez d\'abord un fichier Excel pour le publipostage.');
            return;
        }
        if (!isMerge && mmSelectedYearIds.length === 0) {
            showError('Au moins une année scoute doit être sélectionnée.');
            return;
        }

        const url = mmCurrentId ? '/mass-mail/' + mmCurrentId : '/mass-mail';
        const method = mmCurrentId ? 'PATCH' : 'POST';
        const res = await api.postJson(url, payload, { method: method });
        const data = res.data;
        if (!data || !data.success) {
            showError((data && data.error) || 'Erreur.');
            return;
        }
        window.location.reload();
    });

    // Refreshes the dialog in place (never a full page reload/close) — the
    // underlying list table catches up next time the page itself reloads.
    /**
     * @param {string} action
     * @param {string} [confirmMessage]
     */
    async function postStatusAction(action, confirmMessage) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        const res = await api.postJson('/mass-mail/' + mmCurrentId + '/status', { action: action });
        const data = res.data;
        if (!data || !data.success) {
            showError((data && data.error) || 'Erreur.');
            return;
        }
        await openEmail(mmCurrentId);
    }

    el('mm-to-test-btn').addEventListener('click', () => postStatusAction('to_test'));
    el('mm-to-draft-btn').addEventListener('click', () => postStatusAction('to_draft'));
    el('mm-start-sending-btn').addEventListener('click', () =>
        postStatusAction('start_sending', 'Lancer l\'envoi ? La liste des destinataires sera figée et l\'envoi ne pourra plus être annulé.')
    );

    el('mm-test-send-btn').addEventListener('click', async () => {
        const res = await api.postJson('/mass-mail/' + mmCurrentId + '/test-send', {
            to: inputEl('mm-test-send-email').value,
            merge_offset: mmMergeOffset
        });
        const data = res.data;
        if (!data || !data.success) {
            showError((data && data.error) || 'Erreur.');
            return;
        }
        window.ScoutMagicToast.show('Email de test envoyé.');
    });

    el('mm-attachment-upload-btn').addEventListener('click', async () => {
        const input = inputEl('mm-attachment-file');
        if (!input.files.length || !mmCurrentId) return;

        const formData = new FormData();
        formData.append('file', input.files[0]);
        formData.append('_csrf_token', api.csrfToken());

        const res = await fetch('/mass-mail/' + mmCurrentId + '/attachments', { method: 'POST', body: formData });
        const data = await res.json().catch(() => null);
        if (!data || !data.success) {
            showError((data && data.error) || 'Erreur.');
            return;
        }
        input.value = '';
        openEmail(mmCurrentId);
    });
})();
