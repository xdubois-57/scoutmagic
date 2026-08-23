/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Finance module receipts page (modules/finance/views/receipts/list.html.twig):
// live search over the receipt grid, association to movements, linked-movement
// management. Extracted from the template's inline <script> verbatim so the
// Vitest suite can exercise the production code directly (tests/js/
// finance-receipts.test.js) — the page passes its one server-side value
// (the selected account id) through data-account-id on #receipts-grid
// instead of a Twig interpolation.
//
// The grid is rendered TWICE by design: Twig draws the first page
// server-side (partials in receipts/_grid.html.twig), and every search /
// filter / pagination / mutation here re-draws the same cards client-side
// from /finance/receipts/search JSON (receiptCardHtml below). The two
// renderings must stay in step — tests/e2e/specs/finance-receipts.spec.js
// walks both over the same receipt.

(function () {
    const grid = document.getElementById('receipts-grid');
    if (!grid) return;

    const currentAccountId = parseInt(/** @type {HTMLElement} */ (grid).dataset.accountId || '0', 10);
    let currentAttachmentId = null;
    let currentAttachmentDate = '';
    const associateModalEl = document.getElementById('associate-modal');
    const associateModal = window.bootstrap ? new window.bootstrap.Modal(associateModalEl) : null;
    const movementsModalEl = document.getElementById('movements-modal');
    const movementsModal = window.bootstrap ? new window.bootstrap.Modal(movementsModalEl) : null;

    // The attribute-safe escaper (quotes escaped too, because the result is
    // interpolated into alt=/title=/data-* attributes below — audit M16) is
    // the shared site-wide one now; tests/js/escape-html-attribute-safety
    // .test.js exercises its definition in public/assets/js/api.js.
    const escapeHtml = window.ScoutMagicApi.escapeHtml;

    function formatAmount(amount) {
        return Number(amount).toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function receiptThumbHtml(r) {
        if (r.mime_type.startsWith('image/')) {
            return '<img src="/files/' + r.file_id + '" alt="' + escapeHtml(r.original_filename) + '" class="img-fluid rounded" style="max-height:160px;object-fit:cover;width:100%;">';
        }
        if (r.mime_type === 'application/pdf') {
            return '<img src="/files/' + r.file_id + '/thumbnail" alt="' + escapeHtml(r.original_filename) + '" class="img-fluid rounded pdf-thumbnail" style="max-height:160px;object-fit:cover;width:100%;">';
        }
        return '<div class="d-flex align-items-center justify-content-center bg-body-secondary rounded" style="height:160px;">'
            + '<i class="bi bi-file-earmark-word fs-1"></i></div>';
    }

    function receiptSuggestedBlockHtml(r) {
        if (!r.suggested_amount && !r.suggested_date && !r.suggested_label) {
            return '';
        }
        return '<div class="small">'
            + (r.suggested_amount ? formatAmount(r.suggested_amount) + ' €' : '')
            + (r.suggested_date ? ' — ' + escapeHtml(r.suggested_date) : '')
            + (r.suggested_source === 'ai' ? ' <span class="badge text-bg-info">IA</span>' : '')
            + (r.suggested_label ? '<div class="text-body-secondary">' + escapeHtml(r.suggested_label) + '</div>' : '')
            + (r.suggested_description ? '<div class="text-body-secondary fst-italic">' + escapeHtml(r.suggested_description) + '</div>' : '')
            + '</div>';
    }

    function receiptStatusBlockHtml(r) {
        if (r.is_pending) {
            return '<span class="badge text-bg-warning">En attente</span>'
                + (r.matching_ai_attempted
                    ? ' <span class="badge text-bg-secondary" title="Le rapprochement automatique par IA a déjà été tenté pour ce reçu, sans résultat certain.">IA : aucun mouvement trouvé</span>'
                    : '');
        }
        return '<button type="button" class="btn btn-sm btn-link p-0 movements-btn" data-id="' + r.id + '">'
            + '<span class="badge text-bg-success">' + r.movement_count + ' mouvement(s) lié(s)</span></button>';
    }

    function receiptCardHtml(r) {
        return '<div class="col-12 col-md-4" data-receipt-id="' + r.id + '">'
            + '<div class="border rounded-3 p-3 h-100 d-flex flex-column gap-2">'
            + '<a href="/files/' + r.file_id + '" target="_blank" rel="noopener">' + receiptThumbHtml(r) + '</a>'
            + '<div class="fw-semibold text-truncate" title="' + escapeHtml(r.original_filename) + '">' + escapeHtml(r.original_filename) + '</div>'
            + '<div class="text-body-secondary small">' + escapeHtml(r.uploaded_at) + '</div>'
            + receiptSuggestedBlockHtml(r)
            + '<div>' + receiptStatusBlockHtml(r) + '</div>'
            + '<div class="d-flex gap-2 mt-auto">'
            + '<button type="button" class="btn btn-sm btn-outline-primary associate-btn" data-id="' + r.id + '" data-suggested-date="' + escapeHtml(r.suggested_date || '') + '">Associer</button>'
            + '<a href="/finance/receipts/new?replace=' + r.id + '" class="btn btn-sm btn-outline-secondary">Remplacer</a>'
            + '<button type="button" class="btn btn-sm btn-outline-danger delete-btn" data-id="' + r.id + '">Supprimer</button>'
            + '</div></div></div>';
    }

    // Mirrors core/View/templates/partials/pagination.html.twig in AJAX
    // mode (data_attr: 'data-page') — the same markup Twig renders for the
    // first paint in receipts/_grid.html.twig: prev/next chevrons, a ±2
    // window around the current page, first/last always shown, ellipsis
    // for the gaps. Keep the two renderings in step.
    function paginationHtml(data) {
        if (data.total_pages <= 1) {
            return '';
        }
        const win = 2;
        const pageButton = (p, extra, label) => '<li class="page-item ' + (extra || '') + '">'
            + '<button type="button" class="page-link" data-page="' + p + '"'
            + (label ? ' aria-label="' + label.text + '"' : (p === data.page ? ' aria-current="page"' : ''))
            + '>' + (label ? '<i class="bi ' + label.icon + '" aria-hidden="true"></i>' : p) + '</button></li>';

        let items = pageButton(data.page - 1, data.page <= 1 ? 'disabled' : '', { text: 'Précédent', icon: 'bi-chevron-left' });
        for (let p = 1; p <= data.total_pages; p++) {
            if (p === 1 || p === data.total_pages || (p >= data.page - win && p <= data.page + win)) {
                items += pageButton(p, p === data.page ? 'active' : '');
            } else if (p === data.page - win - 1 || p === data.page + win + 1) {
                items += '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
        }
        items += pageButton(data.page + 1, data.page >= data.total_pages ? 'disabled' : '', { text: 'Suivant', icon: 'bi-chevron-right' });
        return '<nav aria-label="Pagination des reçus"><ul class="pagination pagination-sm">' + items + '</ul></nav>';
    }

    function bindPdfThumbnailFallbacks(root) {
        root.querySelectorAll('img.pdf-thumbnail').forEach(img => {
            img.addEventListener('error', () => {
                const fallback = document.createElement('div');
                fallback.className = 'd-flex align-items-center justify-content-center bg-body-secondary rounded';
                fallback.style.height = '160px';
                fallback.innerHTML = '<i class="bi bi-file-earmark-pdf fs-1"></i>';
                img.replaceWith(fallback);
            }, { once: true });
        });
    }

    function renderReceipts(data) {
        // Same markup as partials/empty_state.html.twig (compact) via
        // receipts/_grid.html.twig's own empty branch.
        const cards = data.receipts.length === 0
            ? '<div class="col-12"><p class="text-body-secondary fst-italic mb-0">Aucun reçu.</p></div>'
            : data.receipts.map(receiptCardHtml).join('');
        grid.innerHTML = '<div class="row g-3">' + cards + '</div>' + paginationHtml(data);
        bindGridEvents();
        bindPdfThumbnailFallbacks(grid);
    }

    let currentPage = 1;
    let searchTimeout = null;

    async function fetchReceipts(page) {
        currentPage = page;
        const q = /** @type {HTMLInputElement} */ (document.getElementById('receipts-search')).value;
        const pending = /** @type {HTMLInputElement} */ (document.getElementById('receipts-pending-only')).checked ? '1' : '0';
        const res = await window.ScoutMagicApi.getJson('/finance/receipts/search?account_id=' + currentAccountId
            + '&q=' + encodeURIComponent(q) + '&pending=' + pending + '&page=' + page);
        if (res.data && res.data.success) {
            renderReceipts(res.data);
        }
    }

    document.getElementById('receipts-search').addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => fetchReceipts(1), 300);
    });

    document.getElementById('receipts-pending-only').addEventListener('change', () => fetchReceipts(1));

    document.getElementById('receipts-account').addEventListener('change', (e) => {
        window.location.href = '/finance/receipts?account_id=' + /** @type {HTMLSelectElement} */ (e.target).value;
    });

    document.getElementById('receipts-filter-btn').addEventListener('click', () => {
        clearTimeout(searchTimeout);
        fetchReceipts(1);
    });

    document.getElementById('receipts-reset-btn').addEventListener('click', () => {
        clearTimeout(searchTimeout);
        /** @type {HTMLInputElement} */ (document.getElementById('receipts-search')).value = '';
        /** @type {HTMLInputElement} */ (document.getElementById('receipts-pending-only')).checked = false;
        fetchReceipts(1);
    });

    function bindGridEvents() {
        // [data-page] skips the ellipsis <span class="page-link">, which
        // carries no target page.
        document.querySelectorAll('#receipts-grid .page-link[data-page]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                fetchReceipts(parseInt(/** @type {HTMLElement} */ (link).dataset.page, 10));
            });
        });

        document.querySelectorAll('.associate-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                currentAttachmentId = /** @type {HTMLElement} */ (btn).dataset.id;
                currentAttachmentDate = /** @type {HTMLElement} */ (btn).dataset.suggestedDate || '';
                /** @type {HTMLInputElement} */ (document.getElementById('associate-search')).value = '';
                runAssociateSearch('');
                associateModal ? associateModal.show() : associateModalEl.classList.add('show');
            });
        });

        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const confirmed = await window.ScoutMagicConfirm.ask({
                    message: 'Supprimer ce reçu ?',
                    confirmLabel: 'Supprimer'
                });
                if (!confirmed) {
                    return;
                }
                const res = await window.ScoutMagicApi.postJson('/finance/receipts/' + /** @type {HTMLElement} */ (btn).dataset.id, {}, { method: 'DELETE' });
                if (res.data && res.data.success) {
                    fetchReceipts(currentPage);
                } else {
                    window.ScoutMagicToast.show((res.data && res.data.error) || 'Une erreur est survenue.', { variant: 'error' });
                }
            });
        });

        document.querySelectorAll('.movements-btn').forEach(btn => {
            btn.addEventListener('click', () => loadMovements(/** @type {HTMLElement} */ (btn).dataset.id));
        });
    }

    bindGridEvents();
    bindPdfThumbnailFallbacks(grid);

    let searchTimeoutAssociate = null;
    document.getElementById('associate-search').addEventListener('input', (e) => {
        clearTimeout(searchTimeoutAssociate);
        searchTimeoutAssociate = setTimeout(() => runAssociateSearch(/** @type {HTMLInputElement} */ (e.target).value), 250);
    });

    async function runAssociateSearch(query) {
        let url = '/finance/movements/search?q=' + encodeURIComponent(query) + '&account_id=' + currentAccountId;
        if (query === '' && currentAttachmentDate) {
            url += '&near_date=' + encodeURIComponent(currentAttachmentDate);
        }
        const res = await window.ScoutMagicApi.getJson(url);
        const data = res.data;
        const results = document.getElementById('associate-results');
        results.innerHTML = '';
        if (!data || !data.success || data.movements.length === 0) {
            results.innerHTML = '<p class="text-body-secondary fst-italic mb-0">Aucun résultat.</p>';
            return;
        }
        data.movements.forEach(m => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary text-start';
            btn.textContent = m.date + ' — ' + m.description + ' — ' + m.amount.toFixed(2) + ' €';
            btn.addEventListener('click', () => associateWithMovement(m.id));
            results.appendChild(btn);
        });
    }

    async function associateWithMovement(movementId) {
        const res = await window.ScoutMagicApi.postJson('/finance/receipts/' + currentAttachmentId + '/associate', { transaction_ids: [movementId] });
        if (res.data && res.data.success) {
            // A plain `if`, not the side-effect ternary this used to be:
            // main fixed that as a SonarQube reliability finding (S2201),
            // and the fix applies unchanged to the shared-toolbox version
            // of the call above it.
            if (associateModal) {
                associateModal.hide();
            }
            fetchReceipts(currentPage);
        } else {
            window.ScoutMagicToast.show((res.data && res.data.error) || 'Une erreur est survenue.', { variant: 'error' });
        }
    }

    let currentMovementsAttachmentId = null;

    async function loadMovements(attachmentId) {
        currentMovementsAttachmentId = attachmentId;
        const res = await window.ScoutMagicApi.getJson('/finance/receipts/' + attachmentId + '/movements');
        const data = res.data;
        const list = document.getElementById('movements-list');
        list.innerHTML = '';
        if (!data || !data.success || data.movements.length === 0) {
            list.innerHTML = '<p class="text-body-secondary fst-italic mb-0">Aucun mouvement lié.</p>';
        } else {
            data.movements.forEach(m => {
                const row = document.createElement('div');
                row.className = 'd-flex align-items-center gap-2 border rounded p-2';
                row.innerHTML = '<div class="flex-grow-1">' + escapeHtml(m.date) + ' — ' + escapeHtml(m.description)
                    + ' — ' + formatAmount(m.amount) + ' €</div>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger unlink-movement-btn" data-transaction-id="' + m.id + '">Délier</button>';
                list.appendChild(row);
            });
            list.querySelectorAll('.unlink-movement-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    await window.ScoutMagicApi.postJson('/finance/receipts/' + currentMovementsAttachmentId + '/dissociate', {
                        transaction_id: parseInt(/** @type {HTMLElement} */ (btn).dataset.transactionId, 10)
                    });
                    loadMovements(currentMovementsAttachmentId);
                    fetchReceipts(currentPage);
                });
            });
        }
        movementsModal ? movementsModal.show() : movementsModalEl.classList.add('show');
    }
})();
