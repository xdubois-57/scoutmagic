/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Finance module movements list page
// (modules/finance/views/movements/list.html.twig): the movement details
// dialog (category, comment, exercice), the receipts dialog behind the 📎
// button (list the associated receipts, associate a pending one, upload a
// new one, dissociate) and the "Associer ce reçu ?" suggestion buttons.
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/finance-movements.test.js)
// — the page hands every server-side value over through the rows' data-*
// attributes, so there is no data global to read here.
//
// JSON calls ride the site-wide ScoutMagicApi envelope ({ok, status,
// data}): `ok` only says the server answered JSON at all, `data.success`
// is the business answer — the two are never conflated here (see api.js's
// header for the bug that convention exists to prevent). Failure feedback
// that used to be alert() is a toast, and the dissociate confirmation is
// the site's own dialog rather than window.confirm(). The receipt upload
// is the one call that keeps a raw fetch(): its body is multipart
// FormData, which the JSON toolbox deliberately does not own (the
// mass-mail-list.js precedent).
(function () {
    const detailsModalEl = document.getElementById('movement-details-modal');
    const attachmentsModalEl = document.getElementById('attachments-modal');
    // Every handler below reaches into one of the two dialogs. On a page
    // that does not render them — the "Aucun compte visible pour votre
    // rôle." empty state, or any page loading this file by accident — the
    // script is a no-op instead of a TypeError on the first
    // getElementById().value.
    if (!detailsModalEl || !attachmentsModalEl) return;

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

    /** @param {string} [message] */
    function toastError(message) {
        window.ScoutMagicToast.show(message || 'Une erreur est survenue.', { variant: 'error' });
    }

    /**
     * The same money rendering the |money Twig filter produces server-side
     * for the very same amounts (fr-BE, two decimals, ' €').
     *
     * @param {string|number} amount
     * @returns {string}
     */
    function formatAmountEur(amount) {
        return Number(amount).toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    // --- Movement details dialog ---
    const movementDetailsModal = window.bootstrap ? new window.bootstrap.Modal(detailsModalEl) : null;
    let currentDetailsMovementId = null;

    /**
     * Fills one <dt>/<dd> pair of the details list, hiding both when the
     * movement carries no value for it — an empty "Compte contrepartie"
     * line is noise, not information.
     *
     * @param {string} ddId
     * @param {string|undefined} value
     */
    function setDetailRow(ddId, value) {
        const dd = el(ddId);
        const dt = dd.previousElementSibling;
        const hasValue = value !== undefined && value !== null && value !== '';
        dd.textContent = hasValue ? value : '';
        dd.classList.toggle('d-none', !hasValue);
        dt.classList.toggle('d-none', !hasValue);
    }

    /** @param {HTMLElement} row */
    function openMovementDetails(row) {
        currentDetailsMovementId = row.dataset.id;
        el('md-date').textContent = row.dataset.date;
        el('md-label').textContent = row.dataset.label;
        el('md-bank-reference').textContent = row.dataset.bankReference || '—';
        el('md-amount').textContent = formatAmountEur(row.dataset.amount);
        el('md-source').textContent = row.dataset.source === 'import' ? 'Import bancaire' : 'Manuel';
        setDetailRow('md-counterparty-name', row.dataset.counterpartyName);
        setDetailRow('md-counterparty-account', row.dataset.counterpartyAccount);
        setDetailRow('md-extra-details', row.dataset.extraDetails);
        selectEl('md-category').value = row.dataset.categoryId || '';
        el('md-category-auto').classList.toggle('d-none', row.dataset.categorySource !== 'auto');
        inputEl('md-comment').value = row.dataset.comment || '';
        selectEl('md-fiscal-year').value = row.dataset.fiscalYearId;
        movementDetailsModal ? movementDetailsModal.show() : detailsModalEl.classList.add('show');
    }

    // A single delegated listener covers BOTH movement views — the desktop
    // <tr class="movement-row"> and the mobile card <div class="movement-row">.
    // A previous binding selected the table rows only (tr elements), leaving
    // the mobile cards inert. Clicks on controls
    // inside a row (attachment/suggestion buttons, links) keep their own
    // behavior.
    /**
     * @param {Event} event
     * @returns {HTMLElement|null}
     */
    function movementRowFromEvent(event) {
        if (!(event.target instanceof Element) || event.target.closest('a, button')) {
            return null;
        }
        return /** @type {HTMLElement|null} */ (event.target.closest('.movement-row'));
    }

    document.addEventListener('click', (e) => {
        const row = movementRowFromEvent(e);
        if (row) {
            openMovementDetails(row);
        }
    });

    // Keyboard activation for the mobile card (role="button" tabindex="0"):
    // Enter/Space open the same details modal as a click.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') {
            return;
        }
        const row = movementRowFromEvent(e);
        if (row) {
            e.preventDefault();
            openMovementDetails(row);
        }
    });

    el('md-save-btn').addEventListener('click', async () => {
        const categoryVal = selectEl('md-category').value;
        // PATCH is a JSON call like any other: postJson() takes the method
        // (the mass-mail-list.js precedent) and adds the CSRF token to the
        // body and the X-CSRF-Token header itself.
        const res = await api.postJson('/finance/movements/' + currentDetailsMovementId, {
            category_id: categoryVal !== '' ? parseInt(categoryVal, 10) : null,
            comment: inputEl('md-comment').value,
            fiscal_year_id: parseInt(selectEl('md-fiscal-year').value, 10)
        }, { method: 'PATCH' });
        if (res.data && res.data.success) {
            window.location.reload();
        } else {
            toastError(res.data && res.data.error);
        }
    });

    // --- Receipts dialog ---
    let currentMovementId = null;
    const attachmentsModal = window.bootstrap ? new window.bootstrap.Modal(attachmentsModalEl) : null;

    /**
     * @param {{mime_type: string, file_id: number}} a
     * @returns {string}
     */
    function attachmentThumbnailHtml(a) {
        if (a.mime_type.startsWith('image/')) {
            return '<img src="/files/' + a.file_id + '" alt="" class="rounded pdf-thumbnail" style="width:64px;height:64px;object-fit:cover;">';
        }
        if (a.mime_type === 'application/pdf') {
            return '<img src="/files/' + a.file_id + '/thumbnail" alt="" class="rounded pdf-thumbnail" style="width:64px;height:64px;object-fit:cover;">';
        }
        return '<div class="d-flex align-items-center justify-content-center bg-body-secondary rounded" style="width:64px;height:64px;"><i class="bi bi-file-earmark fs-4"></i></div>';
    }

    /** @param {string|number} movementId */
    async function loadAttachments(movementId) {
        currentMovementId = movementId;
        const res = await api.getJson('/finance/movements/' + movementId + '/attachments');
        const data = res.data;
        const list = el('attachments-list');
        list.innerHTML = '';
        if (!data || !data.success || !Array.isArray(data.attachments) || data.attachments.length === 0) {
            list.innerHTML = '<p class="text-body-secondary fst-italic mb-0">Aucun reçu associé.</p>';
            return;
        }

        data.attachments.forEach(a => {
            const row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2 border rounded p-2';
            let info = '<a href="/files/' + a.file_id + '" target="_blank" rel="noopener" class="fw-semibold">' + escapeHtml(a.original_filename) + '</a>';
            if (a.suggested_amount || a.suggested_date || a.suggested_label) {
                info += '<div class="small">'
                    + (a.suggested_amount ? formatAmountEur(a.suggested_amount) : '')
                    + (a.suggested_date ? ((a.suggested_amount ? ' — ' : '') + escapeHtml(a.suggested_date)) : '')
                    + (a.suggested_label ? ((a.suggested_amount || a.suggested_date ? ' — ' : '') + escapeHtml(a.suggested_label)) : '')
                    + '</div>';
            }
            if (a.suggested_description) {
                info += '<div class="small text-body-secondary fst-italic">' + escapeHtml(a.suggested_description) + '</div>';
            }
            row.innerHTML = attachmentThumbnailHtml(a) + '<div class="flex-grow-1">' + info + '</div>'
                + '<button type="button" class="btn btn-sm btn-outline-danger dissociate-btn" data-attachment-id="' + a.id + '">Dissocier</button>';
            list.appendChild(row);
        });

        // A PDF whose thumbnail was never generated (or failed) would show
        // the browser's broken-image glyph — the shared toolbox swaps in
        // the file icon instead, at this list's own 64px size.
        if (window.ScoutMagicPdfThumbnail) {
            window.ScoutMagicPdfThumbnail.bind(list, {
                width: '64px', height: '64px', iconClass: 'fs-4'
            });
        }

        /** @type {NodeListOf<HTMLElement>} */ (list.querySelectorAll('.dissociate-btn')).forEach(btn => {
            btn.addEventListener('click', async () => {
                const confirmed = await window.ScoutMagicConfirm.ask({
                    message: 'Délier ce reçu de ce mouvement ?',
                    confirmLabel: 'Délier'
                });
                if (!confirmed) {
                    return;
                }
                await api.postJson('/finance/receipts/' + btn.dataset.attachmentId + '/dissociate', {
                    transaction_id: currentMovementId
                });
                loadAttachments(currentMovementId);
                window.location.reload();
            });
        });
    }

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.attachment-btn')).forEach(btn => {
        btn.addEventListener('click', () => {
            loadAttachments(btn.dataset.id);
            attachmentsModal ? attachmentsModal.show() : attachmentsModalEl.classList.add('show');
        });
    });

    el('attachments-add-btn').addEventListener('click', async () => {
        const select = selectEl('attachments-add-select');
        if (!select.value || !currentMovementId) {
            return;
        }
        await api.postJson('/finance/receipts/' + select.value + '/associate', {
            transaction_ids: [currentMovementId]
        });
        window.location.reload();
    });

    el('attachments-upload-btn').addEventListener('click', async () => {
        const input = inputEl('attachments-upload-input');
        const errorBox = el('attachments-upload-error');
        errorBox.classList.add('d-none');
        if (!input.files.length || !currentMovementId) {
            return;
        }

        // The only raw fetch() left here: a multipart body is outside the
        // JSON toolbox's remit, so the CSRF token rides the form data by
        // hand. withDisabled() still owns the button's disabled state —
        // including the failure path the hand-written try/finally had to
        // remember.
        const formData = new FormData();
        formData.append('receipt', input.files[0]);
        formData.append('_csrf_token', api.csrfToken());

        await api.withDisabled(/** @type {HTMLButtonElement} */ (el('attachments-upload-btn')), async () => {
            const res = await fetch('/finance/movements/' + currentMovementId + '/attachments', {
                method: 'POST',
                body: formData
            });
            const data = await res.json().catch(() => null);
            if (data && data.success) {
                window.location.reload();
            } else {
                errorBox.textContent = (data && data.error) || 'Une erreur est survenue.';
                errorBox.classList.remove('d-none');
            }
        });
    });

    /**
     * @param {string} movementId
     * @param {string} attachmentId
     */
    async function associateSuggestion(movementId, attachmentId) {
        const res = await api.postJson('/finance/receipts/' + attachmentId + '/associate', {
            transaction_ids: [movementId]
        });
        if (res.data && res.data.success) {
            window.location.reload();
        } else {
            toastError(res.data && res.data.error);
        }
    }

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.suggest-associate-btn')).forEach(btn => {
        btn.addEventListener('click', () => associateSuggestion(btn.dataset.movementId, btn.dataset.attachmentId));
    });
})();
