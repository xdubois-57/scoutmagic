/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The composition page of one email (modules/mass_mail/views/compose
// .html.twig).
//
// The page itself is ordinary forms: saving, adding an attachment,
// changing status and sending a test are POSTs that redirect, and they
// work with this file absent. What is left here is the four things a
// server round-trip cannot do without losing what the chief is typing:
//
//   1. showing/hiding the mail-merge zone and the scout-year block as the
//      list type changes;
//   2. importing the .xlsx (a multipart POST answering JSON, the same
//      endpoint the list page's dialog uses) without leaving the page and
//      dropping the half-written body;
//   3. inserting a {{Colonne}} token where the caret is — in the subject
//      or in the rich-text surface;
//   4. the per-recipient preview in test mode, and the recipient count in
//      the send confirmation, both asked at the moment of the click
//      because both are live.
//
// Fetches ride the site-wide ScoutMagicApi envelope ({ok, status, data});
// the audience upload keeps a raw fetch(FormData) since the JSON toolbox
// deliberately owns JSON bodies only.
(function () {
    const DATA = window.ScoutMagicApi ? window.ScoutMagicApi.pageData('mass-mail-compose-data') : null;
    const form = document.getElementById('mm-compose-form');
    if (!DATA || !form) return;

    const api = window.ScoutMagicApi;
    const escapeHtml = api.escapeHtml;

    /**
     * @param {string} id
     * @returns {HTMLElement|null}
     */
    function el(id) { return document.getElementById(id); }

    /** @type {number} the audience row the preview is showing */
    let mergeOffset = 0;
    /** @type {'subject'|'body'} where an inserted variable lands */
    let insertTarget = 'body';

    // ---------------------------------------------------------------
    // 1. The list type reshapes the form.
    // ---------------------------------------------------------------
    const listSelect = /** @type {HTMLSelectElement|null} */ (el('mm-list'));

    /** @returns {string} */
    function currentListType() {
        if (!listSelect) return '';
        const option = listSelect.selectedOptions[0];
        return option ? (option.dataset.listType || '') : '';
    }

    /**
     * Two list types answer the "which year?" question themselves, so the
     * year checkboxes make no sense for either — and each puts a note in
     * their place, since a block that comes and goes with nothing said
     * reads as a bug rather than as a rule.
     */
    function updateListTypeUi() {
        const listType = currentListType();
        const isMerge = listType === 'mail_merge';
        const isExternal = listType === 'external';
        toggle('mm-merge-zone', !isMerge);
        toggle('mm-merge-list-note', !isMerge);
        toggle('mm-external-list-note', !isExternal);
        toggle('mm-scout-year-zone', isMerge || isExternal);
    }

    /**
     * @param {string} id
     * @param {boolean} hidden
     */
    function toggle(id, hidden) {
        const node = el(id);
        if (node) node.classList.toggle('d-none', hidden);
    }

    if (listSelect) listSelect.addEventListener('change', updateListTypeUi);

    // ---------------------------------------------------------------
    // The future-year warning. Its text comes from the server, because
    // which of the two wordings applies depends on whether the
    // registration module is enabled — a browser guessing at that would
    // be a second answer to a question the server already answered.
    // ---------------------------------------------------------------
    /** @returns {HTMLInputElement[]} */
    function yearCheckboxes() {
        return Array.from(/** @type {NodeListOf<HTMLInputElement>} */ (
            document.querySelectorAll('#mm-scout-year-group .mm-year-checkbox')
        ));
    }

    function updateFutureYearWarning() {
        const box = el('mm-future-year-warning');
        if (!box) return;
        const warnings = yearCheckboxes()
            .filter((cb) => cb.checked && (cb.dataset.warning || '') !== '')
            .map((cb) => cb.dataset.warning);
        box.textContent = warnings.join(' ');
        box.classList.toggle('d-none', warnings.length === 0);
    }

    const yearGroup = el('mm-scout-year-group');
    if (yearGroup) {
        yearGroup.addEventListener('change', updateFutureYearWarning);
    }

    // ---------------------------------------------------------------
    // 2. The .xlsx import — in place, so a half-written body survives it.
    // ---------------------------------------------------------------
    const importBtn = el('mm-merge-import-btn');
    if (importBtn) {
        importBtn.addEventListener('click', async () => {
            const input = /** @type {HTMLInputElement} */ (el('mm-merge-file'));
            if (!input || !input.files || !input.files.length) {
                showMergeError(["Choisissez d'abord un fichier Excel (.xlsx)."]);
                return;
            }

            const formData = new FormData();
            formData.append('file', input.files[0]);
            formData.append('_csrf_token', api.csrfToken());

            const res = await fetch('/mass-mail/audiences', { method: 'POST', body: formData });
            const data = await res.json().catch(() => null);
            if (!data || !data.success) {
                showMergeError((data && data.errors) || [(data && data.error) || 'Erreur.']);
                return;
            }
            showMergeError([]);
            input.value = '';
            renderAudience(data.audience);
            showMergeWarnings(data.warnings || []);
            await loadAudienceSample(data.audience.id);
        });
    }

    const replaceBtn = el('mm-merge-replace-btn');
    if (replaceBtn) {
        replaceBtn.addEventListener('click', () => {
            // Back to the upload state — the current audience stays
            // attached until a new import actually succeeds.
            toggle('mm-merge-upload-state', false);
            toggle('mm-merge-info-state', true);
        });
    }

    /**
     * @param {{id: number, filename: string, sheet_name: string, columns: string[], row_count: number}} audience
     */
    function renderAudience(audience) {
        const hidden = /** @type {HTMLInputElement|null} */ (el('mm-audience-id'));
        if (hidden) hidden.value = String(audience.id);
        toggle('mm-merge-upload-state', true);
        toggle('mm-merge-info-state', false);
        setText('mm-merge-filename', audience.filename);
        setText(
            'mm-merge-meta',
            audience.row_count + ' ligne' + (audience.row_count > 1 ? 's' : '')
                + ' · feuille « ' + audience.sheet_name + ' »'
        );
        const columns = el('mm-merge-columns');
        if (columns) {
            columns.innerHTML = '<span class="form-text small mt-0 me-1">Colonnes :</span>'
                + audience.columns.map((c) => '<span class="badge text-bg-light border">' + escapeHtml(c) + '</span>').join(' ');
        }
    }

    /**
     * The variable menu is rebuilt from the freshly imported file, with
     * the first row's value as each column's sample hint — the same shape
     * the server rendered for an audience already attached.
     *
     * @param {number} audienceId
     */
    async function loadAudienceSample(audienceId) {
        const res = await api.getJson('/mass-mail/audiences/' + audienceId);
        if (!res.data || !res.data.success) return;
        renderVariableMenu(res.data.audience.columns, res.data.sample || {});
    }

    /**
     * @param {string[]} columns
     * @param {Record<string, string>} sample
     */
    function renderVariableMenu(columns, sample) {
        const dropdown = el('mm-variable-dropdown');
        const menu = el('mm-variable-menu');
        if (!dropdown || !menu) return;
        dropdown.classList.remove('d-none');
        menu.innerHTML = columns.map((column) => {
            const value = (sample[column] ?? '') !== '' ? sample[column] : '—';
            return '<li><button type="button" class="dropdown-item d-flex justify-content-between gap-3 mm-variable-item"'
                + ' data-column="' + escapeHtml(column) + '">'
                + '<span>' + escapeHtml(column) + '</span>'
                + '<span class="text-body-secondary small text-truncate" style="max-width: 10rem;">« '
                + escapeHtml(value) + ' »</span>'
                + '</button></li>';
        }).join('');
    }

    /** @param {string[]} warnings */
    function showMergeWarnings(warnings) {
        const box = el('mm-merge-warnings');
        if (!box) return;
        box.innerHTML = warnings.map((w) => '<div>' + escapeHtml(w) + '</div>').join('');
        box.classList.toggle('d-none', warnings.length === 0);
    }

    /**
     * A refused import reports every offending line at once — a titled
     * list, never one flattened sentence.
     *
     * @param {string[]} messages
     */
    function showMergeError(messages) {
        const box = el('mm-merge-error');
        if (!box) return;
        if (!messages.length) {
            box.classList.add('d-none');
            return;
        }
        box.innerHTML = '<div class="fw-semibold">Le fichier n\'a pas été accepté :</div>'
            + '<ul class="mb-0 mt-1">' + messages.map((m) => '<li>' + escapeHtml(m) + '</li>').join('') + '</ul>'
            + '<div class="small mt-1">Corrigez le fichier puis réimportez-le — aucune ligne n\'a été conservée.</div>';
        box.classList.remove('d-none');
    }

    /**
     * @param {string} id
     * @param {string} text
     */
    function setText(id, text) {
        const node = el(id);
        if (node) node.textContent = text;
    }

    // ---------------------------------------------------------------
    // 3. Variable insertion, where the caret is.
    // ---------------------------------------------------------------
    const subjectInput = /** @type {HTMLInputElement|null} */ (el('mm-subject'));
    const bodySurface = el('mm-body-content');
    if (subjectInput) subjectInput.addEventListener('focus', () => { insertTarget = 'subject'; });
    if (bodySurface) bodySurface.addEventListener('focus', () => { insertTarget = 'body'; });

    document.addEventListener('click', (event) => {
        const target = /** @type {HTMLElement|null} */ (event.target);
        const item = target === null ? null : target.closest('.mm-variable-item');
        if (item === null) return;
        insertVariable(/** @type {HTMLElement} */ (item).dataset.column || '');
    });

    /**
     * The braces are split ('{'+'{') so this file can never hand a Twig
     * template a literal token to evaluate, should it ever be inlined —
     * the same precaution the list page's script carries, and the intent
     * stays obvious.
     *
     * @param {string} column
     */
    function insertVariable(column) {
        const token = '{' + '{' + column + '}' + '}';
        if (insertTarget === 'subject' && subjectInput) {
            const start = subjectInput.selectionStart ?? subjectInput.value.length;
            const end = subjectInput.selectionEnd ?? subjectInput.value.length;
            subjectInput.value = subjectInput.value.slice(0, start) + token + subjectInput.value.slice(end);
            subjectInput.focus();
            subjectInput.setSelectionRange(start + token.length, start + token.length);
            return;
        }
        if (!bodySurface) return;
        bodySurface.focus();
        document.execCommand('insertText', false, token);
        // rich-text-form-field.js keeps the hidden input in step from the
        // surface's own `input` event; execCommand fires one, but a
        // browser that does not is one saved body lost.
        bodySurface.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // ---------------------------------------------------------------
    // 4a. The per-recipient preview (test mode, mail merge).
    // ---------------------------------------------------------------
    /** @param {number} offset */
    async function loadMergePreview(offset) {
        const res = await api.getJson('/mass-mail/' + DATA.emailId + '/merge-preview?offset=' + offset);
        const data = res.data;
        if (!data || !data.success) return;

        const preview = data.preview;
        mergeOffset = preview.offset;
        const hidden = /** @type {HTMLInputElement|null} */ (el('mm-merge-offset'));
        if (hidden) hidden.value = String(mergeOffset);

        setText('mm-merge-preview-position', 'Ligne ' + (preview.offset + 1) + ' / ' + preview.total);
        setText(
            'mm-merge-preview-recipient',
            'Destinataire : ' + preview.recipient_label + ' (ligne ' + preview.row_index + ' du fichier)'
        );
        setText('mm-merge-preview-subject', preview.subject);
        const body = el('mm-merge-preview-body');
        // Server-rendered: the body was sanitized at save time and every
        // substituted value is HTML-escaped server-side.
        if (body) body.innerHTML = preview.body_html;

        const prev = /** @type {HTMLButtonElement|null} */ (el('mm-merge-prev-btn'));
        const next = /** @type {HTMLButtonElement|null} */ (el('mm-merge-next-btn'));
        if (prev) prev.disabled = preview.offset <= 0;
        if (next) next.disabled = preview.offset >= preview.total - 1;

        const warnings = [];
        if (preview.unknown_tokens.length) {
            warnings.push('Variable(s) inconnue(s) dans le message : '
                + preview.unknown_tokens.map((t) => '{' + '{' + t + '}' + '}').join(', ')
                + " — vérifiez l'orthographe des colonnes.");
        }
        if (preview.missing_values.length) {
            warnings.push("Cette ligne n'a pas de valeur pour : " + preview.missing_values.join(', ') + ' (remplacée par du vide).');
        }
        const box = el('mm-merge-preview-warnings');
        if (box) {
            box.innerHTML = warnings.map((w) => '<div>' + escapeHtml(w) + '</div>').join('');
            box.classList.toggle('d-none', warnings.length === 0);
        }
    }

    const prevBtn = el('mm-merge-prev-btn');
    const nextBtn = el('mm-merge-next-btn');
    if (prevBtn) prevBtn.addEventListener('click', () => loadMergePreview(Math.max(0, mergeOffset - 1)));
    if (nextBtn) nextBtn.addEventListener('click', () => loadMergePreview(mergeOffset + 1));

    // ---------------------------------------------------------------
    // 4b. The send confirmation, with the number that makes it a real
    //     question.
    // ---------------------------------------------------------------
    /**
     * « Lancer l'envoi ? » on its own is a question about an unknown
     * quantity. The difference between 42 and 400 is the difference
     * between one section and the whole unit, and a wrong list looks
     * exactly like a right one until the mail is out.
     *
     * Asked fresh at the click, never read from what the page rendered:
     * the list behind the email is live. If the count cannot be had, the
     * send still asks — without a number, rather than not at all.
     *
     * @returns {Promise<string>}
     */
    async function recipientSentence() {
        const res = await api.getJson('/mass-mail/' + DATA.emailId + '/recipient-count');
        const data = res.data;
        if (!data || !data.success) return '';
        if (data.count === 0) return 'Cette liste ne désigne actuellement personne. ';

        const noun = data.kind === 'rows'
            ? (data.count > 1 ? 'lignes du fichier' : 'ligne du fichier')
            : (data.count > 1 ? 'personnes' : 'personne');

        return 'Cet email partira à ' + data.count + ' ' + noun + '. ';
    }

    const sendForm = /** @type {HTMLFormElement|null} */ (el('mm-start-sending-form'));
    if (sendForm) {
        sendForm.addEventListener('submit', async (event) => {
            if (sendForm.dataset.confirmed === '1') return;
            event.preventDefault();
            const count = await recipientSentence();
            const agreed = await window.ScoutMagicConfirm.ask({
                message: count + "Lancer l'envoi ? La liste des destinataires sera figée et l'envoi ne pourra plus être annulé.",
                confirmLabel: 'Envoyer',
                // Irreversible, but it destroys nothing — sending is the
                // whole point of the screen, so it gets the primary button.
                variant: 'primary',
            });
            if (!agreed) return;
            sendForm.dataset.confirmed = '1';
            sendForm.submit();
        });
    }

    updateListTypeUi();
    updateFutureYearWarning();
    if (DATA.status === 'test' && el('mm-merge-preview-zone')) {
        loadMergePreview(0);
    }
})();
