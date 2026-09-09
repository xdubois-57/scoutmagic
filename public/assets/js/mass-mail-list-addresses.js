/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The « Adresses propres à cette liste » panel, inside the list's own edit
// dialog (modules/mass_mail/views/mailing_lists.html.twig).
//
// **One panel, re-pointed at whichever list the dialog was opened for** —
// not one panel per row. A list's own addresses are the second half of
// what it resolves to, so they belong where the criteria are decided; the
// page that lists the lists only summarises them, and summarising twelve
// lists must not build twelve working panels nobody opened. `attach()` is
// what mass-mail-lists.js calls when it opens the dialog on a list.
//
// The whole set arrives in ONE call, decrypted and sorted server-side, the
// first time the panel is shown for a given list — and everything
// afterwards happens here, without a reload and without another round
// trip: searching, the « désinscrites seulement » filter, and rendering in
// slices.
//
// That shape is not a preference. The addresses are encrypted at rest, so
// there is no ORDER BY and no LIKE for the server to page or filter with;
// filtering in SQL would mean decrypting everything and throwing most of
// it away. The same reasoning, at length, is in
// Core\Member\Service\MemberSearchService. What bounds the cost is the
// `mass_mail_list_addresses_max` setting, not pagination.
//
// No library and no virtualisation: this project has no build step, and a
// bounded list rendered fifty rows at a time needs neither.
//
// **An address is added and removed, never corrected in place.** A row
// carries a name and an address and nothing else, so a typo is one delete
// and one add. Anything at scale goes through the Excel round trip, which
// takes two steps on purpose: replacing a list wholesale is the only
// operation of this dialog with no way back, so the upload only ever
// ANALYSES — the server writes nothing and answers with the counts — and a
// second, explicit click applies them. The uploaded file is deleted
// server-side the moment the analysis returns, which is also why the
// confirmation carries the rows back: there is no file left to re-read.
(function () {
    var panel = document.getElementById('cfg-list-addresses');
    if (!panel) {
        return;
    }

    var api = window.ScoutMagicApi;
    var SLICE = 50;

    /**
     * The same normalisation both sides of a search go through in PHP
     * (Core\Service\TextNormalizerService::fold): lowercase, accents
     * folded, every run of non-alphanumerics collapsed to one space. A
     * second implementation of a generic ALGORITHM, never a second copy of
     * data — there is no shared runtime between PHP and the browser, the
     * same reason OfflineWhitelist's matcher exists twice.
     *
     * @param {string|null|undefined} value
     * @returns {string}
     */
    function fold(value) {
        if (!value) {
            return '';
        }
        return value
            .toLowerCase()
            .normalize('NFD')
            .replace(/\p{Mn}/gu, '')
            .replace(/[^a-z0-9]+/gu, ' ')
            .trim();
    }

    /**
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {string}
     */
    function errorMessage(res) {
        if (res.data) {
            return res.data.error || 'Erreur.';
        }
        return 'Erreur : réponse serveur invalide.';
    }

    /**
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {boolean}
     */
    function isSuccess(res) {
        return !!(res.data?.success);
    }

    /**
     * @param {string} label
     * @returns {HTMLElement}
     */
    function part(label) {
        return /** @type {HTMLElement} */ (panel.querySelector('[data-address-' + label + ']'));
    }

    var unsavedNote = document.getElementById('cfg-addresses-unsaved');
    var rowsEl = part('rows');
    var emptyEl = part('empty');
    var moreEl = part('more');
    var errorEl = part('error');
    var searchEl = /** @type {HTMLInputElement} */ (part('search'));
    var unsubOnlyEl = /** @type {HTMLInputElement} */ (part('unsubscribed-only'));
    var addForm = /** @type {HTMLFormElement} */ (part('add-form'));
    var newNameEl = /** @type {HTMLInputElement} */ (part('new-name'));
    var newEmailEl = /** @type {HTMLInputElement} */ (part('new-email'));
    var exportEl = /** @type {HTMLAnchorElement} */ (part('export'));
    var importInput = /** @type {HTMLInputElement} */ (part('import-input'));
    var importPreview = part('import-preview');
    var importSummary = part('import-summary');
    var importErrors = part('import-errors');
    var importConfirm = part('import-confirm');
    var importCancel = part('import-cancel');

    /** @type {string|null} the list the panel currently stands for */
    var listId = null;
    /** @type {{id: number, name: string|null, email: string, unsubscribed_at: string|null}[]} */
    var addresses = [];
    var loaded = false;
    var shown = SLICE;
    /** @type {{name: string|null, email: string}[]|null} */
    var pendingImport = null;

    /** @param {string|null} message */
    function showError(message) {
        if (message === null) {
            errorEl.classList.add('d-none');
            return;
        }
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
    }

    /**
     * The count on the page BEHIND the dialog, rewritten in place.
     *
     * Adding an address is immediate — it is a request of its own, not
     * part of saving the list — so « Annuler » on the dialog leaves the
     * addresses written and the page's sentence stale. Rewriting it here
     * is what keeps the summary true whichever way the dialog is closed.
     *
     * @param {{total: number, unsubscribed: number}} counts
     */
    function renderPageCount(counts) {
        var target = document.querySelector('[data-address-count][data-list-id="' + listId + '"]');
        if (!target) {
            return;
        }
        if (counts.total === 0) {
            target.textContent = 'Aucune adresse propre à cette liste.';
            return;
        }
        var text = counts.total + ' adresse' + (counts.total > 1 ? 's' : '')
            + ' propre' + (counts.total > 1 ? 's' : '') + ' à cette liste';
        if (counts.unsubscribed > 0) {
            text += ', dont ' + counts.unsubscribed + ' désinscrite' + (counts.unsubscribed > 1 ? 's' : '');
        }
        target.textContent = text + '.';
    }

    /**
     * @returns {{id: number, name: string|null, email: string, unsubscribed_at: string|null}[]}
     */
    function visible() {
        var needle = fold(searchEl.value);
        var unsubscribedOnly = unsubOnlyEl.checked;

        return addresses.filter(function (address) {
            if (unsubscribedOnly && address.unsubscribed_at === null) {
                return false;
            }
            if (needle === '') {
                return true;
            }
            return (fold(address.name) + ' ' + fold(address.email)).indexOf(needle) !== -1;
        });
    }

    /**
     * One row. An unsubscribed one is greyed, says so, and carries no
     * « Supprimer »: the row survives everything, and « 312 adresses »
     * followed by « 304 envoyés » reads as a breakdown unless the screen
     * says which eight are out.
     *
     * @param {{id: number, name: string|null, email: string, unsubscribed_at: string|null}} address
     * @returns {HTMLLIElement}
     */
    function buildRow(address) {
        var row = document.createElement('li');
        row.className = 'list-group-item px-0 py-1 d-flex flex-wrap gap-2 align-items-center';
        row.dataset.addressId = String(address.id);

        var text = document.createElement('span');
        text.className = 'flex-grow-1 small'
            + (address.unsubscribed_at !== null ? ' text-body-secondary' : '');
        // textContent throughout: a name and an address are values
        // somebody typed, and this is the DOM sink CodeQL flags.
        text.textContent = address.name ? address.name + ' — ' + address.email : address.email;
        row.appendChild(text);

        if (address.unsubscribed_at !== null) {
            var badge = document.createElement('span');
            badge.className = 'badge bg-secondary-subtle text-secondary-emphasis';
            badge.textContent = 'Désinscrite';
            row.appendChild(badge);
            return row;
        }

        row.appendChild(buildRemoveButton(address));

        return row;
    }

    /**
     * The one action a row carries, as the trash icon every other
     * destructive row button on this page already uses — the word next to
     * fifty rows is fifty times the same word, and the icon is the same
     * 44px target either way (`.tap-target`). The accessible name stays
     * the sentence: the icon is `aria-hidden`, and the button is named
     * after the address it removes, so a screen reader announces which
     * one rather than fifty identical « Supprimer ».
     *
     * @param {{id: number, name: string|null, email: string}} address
     * @returns {HTMLButtonElement}
     */
    function buildRemoveButton(address) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-danger tap-target';
        button.setAttribute('aria-label', 'Retirer ' + address.email);
        button.title = 'Retirer de la liste';

        var icon = document.createElement('i');
        icon.className = 'bi bi-trash';
        icon.setAttribute('aria-hidden', 'true');
        button.appendChild(icon);

        button.addEventListener('click', function () {
            void removeAddress(address);
        });

        return button;
    }

    function render() {
        var matching = visible();
        rowsEl.textContent = '';

        matching.slice(0, shown).forEach(function (address) {
            rowsEl.appendChild(buildRow(address));
        });

        emptyEl.classList.toggle('d-none', matching.length !== 0);
        moreEl.classList.toggle('d-none', matching.length <= shown);
        moreEl.textContent = 'Afficher plus (' + (matching.length - shown) + ' restantes)';
    }

    async function load() {
        if (loaded || listId === null) {
            return;
        }
        var res = await api.getJson('/admin/listes-de-diffusion/lists/' + listId + '/addresses');
        if (!isSuccess(res) || !Array.isArray(res.data.addresses)) {
            showError(errorMessage(res));
            return;
        }
        loaded = true;
        addresses = res.data.addresses;
        showError(null);
        render();
    }

    /** @param {{id: number, name: string|null, email: string}} address */
    async function removeAddress(address) {
        var confirmed = await window.ScoutMagicConfirm.ask({
            message: 'Retirer ' + address.email + ' de cette liste ?',
            confirmLabel: 'Retirer'
        });
        if (!confirmed) {
            return;
        }

        var res = await api.postJson(
            '/admin/listes-de-diffusion/addresses/' + address.id,
            {},
            { method: 'DELETE' }
        );
        if (!isSuccess(res) || !res.data.counts) {
            showError(errorMessage(res));
            return;
        }
        showError(null);
        addresses = addresses.filter(function (a) { return a.id !== address.id; });
        renderPageCount(res.data.counts);
        render();
    }

    /**
     * The sentence the chief confirms — the counts, in the order that
     * matters to somebody about to lose rows: what arrives, what stays,
     * what goes, and what is kept because it asked to be left alone.
     *
     * @param {{added: number, unchanged: number, removed: number, kept_unsubscribed: number}} summary
     * @param {number} duplicates lines of the file that collapsed onto an address already named
     */
    function summarySentence(summary, duplicates) {
        var parts = [
            summary.added + ' adresse' + (summary.added > 1 ? 's' : '') + ' ajoutée'
                + (summary.added > 1 ? 's' : ''),
            summary.unchanged + ' inchangée' + (summary.unchanged > 1 ? 's' : ''),
            summary.removed + ' supprimée' + (summary.removed > 1 ? 's' : '')
        ];
        if (summary.kept_unsubscribed > 0) {
            parts.push(
                summary.kept_unsubscribed + ' désinscrite' + (summary.kept_unsubscribed > 1 ? 's' : '')
                    + ' — conservée' + (summary.kept_unsubscribed > 1 ? 's' : '')
                    + ', toujours exclue' + (summary.kept_unsubscribed > 1 ? 's' : '') + ' des envois'
            );
        }

        if (duplicates > 0) {
            // Lines that vanished into another line rather than into the
            // list. Saying nothing would let a file of 300 rows report
            // « 280 ajoutées » with no explanation of the twenty missing,
            // which reads as a loss.
            parts.push(
                duplicates + ' ligne' + (duplicates > 1 ? 's' : '')
                    + ' en double dans le fichier, fondue' + (duplicates > 1 ? 's' : '')
                    + ' dans la précédente'
            );
        }

        return parts.join(' · ');
    }

    function hideImportPreview() {
        pendingImport = null;
        importPreview.classList.add('d-none');
        importErrors.textContent = '';
        importErrors.classList.add('d-none');
        importInput.value = '';
    }

    /** @param {string[]} messages */
    function renderImportErrors(messages) {
        importErrors.textContent = '';
        if (messages.length === 0) {
            importErrors.classList.add('d-none');
            return;
        }
        messages.forEach(function (message) {
            var item = document.createElement('li');
            // Server text, and it quotes the chief's own spreadsheet:
            // textContent, never innerHTML.
            item.textContent = message;
            importErrors.appendChild(item);
        });
        importErrors.classList.remove('d-none');
    }

    importInput.addEventListener('change', async function () {
        var file = importInput.files && importInput.files[0];
        if (!file || listId === null) {
            return;
        }

        // The one raw fetch() here: a multipart body is outside the JSON
        // toolbox's remit, so the CSRF token rides the form data by hand —
        // the finance-movements.js precedent.
        var formData = new FormData();
        formData.append('file', file);
        formData.append('_csrf_token', api.csrfToken());

        var response = await fetch(
            '/admin/listes-de-diffusion/lists/' + listId + '/addresses/import',
            { method: 'POST', body: formData }
        );
        var data = await response.json().catch(function () { return null; });

        if (!data || !data.success) {
            hideImportPreview();
            showError((data && data.errors ? data.errors.join(' ') : null)
                || 'Erreur : réponse serveur invalide.');
            return;
        }

        showError(null);
        pendingImport = data.addresses;
        importSummary.textContent = summarySentence(data.summary, data.duplicates || 0);
        renderImportErrors(data.errors || []);
        importPreview.classList.remove('d-none');
        importInput.value = '';
    });

    importCancel.addEventListener('click', function () {
        hideImportPreview();
    });

    importConfirm.addEventListener('click', async function () {
        if (pendingImport === null || listId === null) {
            return;
        }

        var res = await api.postJson(
            '/admin/listes-de-diffusion/lists/' + listId + '/addresses/import/confirm',
            { addresses: pendingImport }
        );
        if (!isSuccess(res) || !res.data.counts) {
            showError(errorMessage(res));
            return;
        }

        showError(null);
        hideImportPreview();
        renderPageCount(res.data.counts);
        // The set on screen is no longer the set in the database — fetch
        // it again rather than guess what the replacement did.
        loaded = false;
        addresses = [];
        shown = SLICE;
        await load();
    });

    searchEl.addEventListener('input', function () {
        shown = SLICE;
        render();
    });
    unsubOnlyEl.addEventListener('change', function () {
        shown = SLICE;
        render();
    });
    moreEl.addEventListener('click', function () {
        shown += SLICE;
        render();
    });

    addForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        if (listId === null) {
            return;
        }
        var res = await api.postJson('/admin/listes-de-diffusion/lists/' + listId + '/addresses', {
            name: newNameEl.value,
            email: newEmailEl.value
        });
        // A success envelope without the row it claims to have created is
        // not a success — reading it as one crashed the panel on the next
        // line rather than saying anything.
        if (!isSuccess(res) || !res.data.address || !res.data.counts) {
            showError(errorMessage(res));
            return;
        }
        showError(null);
        addresses.push(res.data.address);
        addresses.sort(function (a, b) {
            return (a.name || '').toLowerCase().localeCompare((b.name || '').toLowerCase())
                || a.email.toLowerCase().localeCompare(b.email.toLowerCase());
        });
        newNameEl.value = '';
        newEmailEl.value = '';
        renderPageCount(res.data.counts);
        render();
    });

    /** Everything the panel holds about one list, forgotten. */
    function reset() {
        addresses = [];
        loaded = false;
        shown = SLICE;
        searchEl.value = '';
        unsubOnlyEl.checked = false;
        rowsEl.textContent = '';
        showError(null);
        hideImportPreview();
    }

    window.MassMailListAddresses = {
        /**
         * Point the panel at one list and fill it. Called by
         * mass-mail-lists.js when the dialog opens on an existing list.
         *
         * @param {string} id
         */
        attach: function (id) {
            reset();
            listId = id;
            panel.dataset.listId = id;
            exportEl.href = '/admin/listes-de-diffusion/lists/' + id + '/addresses/export';
            panel.classList.remove('d-none');
            unsavedNote?.classList.add('d-none');
            void load();
        },

        /**
         * A list that does not exist yet: the panel is hidden and the note
         * takes its place, rather than a panel that refuses every click.
         */
        detach: function () {
            reset();
            listId = null;
            panel.dataset.listId = '';
            exportEl.removeAttribute('href');
            panel.classList.add('d-none');
            unsavedNote?.classList.remove('d-none');
        }
    };
})();
