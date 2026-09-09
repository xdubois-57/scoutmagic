/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The « Adresses de cette liste » panel of each custom list
// (modules/mass_mail/views/mailing_lists.html.twig).
//
// The whole set arrives in ONE call, decrypted and sorted server-side, the
// first time the panel is opened — and everything afterwards happens here,
// without a reload and without another round trip: searching, the
// « désinscrites seulement » filter, and rendering in slices.
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
// The Excel round trip lives here too, and takes two steps on purpose:
// replacing a list wholesale is the only operation of this screen with no
// way back, so the upload only ever ANALYSES — the server writes nothing
// and answers with the counts — and a second, explicit click applies them.
// The uploaded file is deleted server-side the moment the analysis
// returns, which is also why the confirmation carries the rows back: there
// is no file left to re-read.
(function () {
    var panels = document.querySelectorAll('.mml-addresses');
    if (panels.length === 0) {
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
     * @param {HTMLElement} panel
     * @param {string} label
     * @returns {HTMLElement}
     */
    function part(panel, label) {
        return /** @type {HTMLElement} */ (panel.querySelector('[data-address-' + label + ']'));
    }

    /** @param {HTMLElement} panel */
    function wirePanel(panel) {
        var listId = panel.dataset.listId;
        /** @type {{id: number, name: string|null, email: string, unsubscribed_at: string|null}[]} */
        var addresses = [];
        var loaded = false;
        var shown = SLICE;

        var rowsEl = part(panel, 'rows');
        var emptyEl = part(panel, 'empty');
        var moreEl = part(panel, 'more');
        var errorEl = part(panel, 'error');
        var countEl = part(panel, 'count');
        var searchEl = /** @type {HTMLInputElement} */ (part(panel, 'search'));
        var unsubOnlyEl = /** @type {HTMLInputElement} */ (part(panel, 'unsubscribed-only'));
        var addForm = /** @type {HTMLFormElement} */ (part(panel, 'add-form'));
        var newNameEl = /** @type {HTMLInputElement} */ (part(panel, 'new-name'));
        var newEmailEl = /** @type {HTMLInputElement} */ (part(panel, 'new-email'));
        var importInput = /** @type {HTMLInputElement} */ (part(panel, 'import-input'));
        var importPreview = part(panel, 'import-preview');
        var importSummary = part(panel, 'import-summary');
        var importErrors = part(panel, 'import-errors');
        var importConfirm = part(panel, 'import-confirm');
        var importCancel = part(panel, 'import-cancel');

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

        /** @param {{total: number, unsubscribed: number}} counts */
        function renderCount(counts) {
            var text = counts.total + ' adresse' + (counts.total > 1 ? 's' : '');
            if (counts.unsubscribed > 0) {
                text += ', dont ' + counts.unsubscribed + ' désinscrite' + (counts.unsubscribed > 1 ? 's' : '');
            }
            countEl.textContent = text;
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
         * One row. An unsubscribed one is greyed, says so, and carries
         * NEITHER « Modifier » NOR « Supprimer »: the row survives
         * everything, and « 312 contacts » followed by « 304 envoyés »
         * reads as a breakdown unless the screen says which eight are out.
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

            row.appendChild(buildAction('Modifier', 'btn-outline-secondary', function () {
                startEditing(row, address);
            }));
            row.appendChild(buildAction('Supprimer', 'btn-outline-danger', function () {
                void removeAddress(address);
            }));

            return row;
        }

        /**
         * @param {string} label
         * @param {string} variant
         * @param {() => void} onClick
         * @returns {HTMLButtonElement}
         */
        function buildAction(label, variant, onClick) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm ' + variant;
            button.textContent = label;
            button.addEventListener('click', onClick);
            return button;
        }

        /**
         * Editing happens IN the row — no dialog, no reload. Cancelling
         * puts the row back exactly as it was.
         *
         * @param {HTMLLIElement} row
         * @param {{id: number, name: string|null, email: string, unsubscribed_at: string|null}} address
         */
        function startEditing(row, address) {
            row.textContent = '';

            var nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.className = 'form-control form-control-sm';
            nameInput.style.maxWidth = '12rem';
            nameInput.value = address.name || '';
            nameInput.setAttribute('aria-label', 'Nom');

            var emailInput = document.createElement('input');
            emailInput.type = 'email';
            emailInput.className = 'form-control form-control-sm flex-grow-1';
            emailInput.value = address.email;
            emailInput.setAttribute('aria-label', 'Adresse email');

            row.appendChild(nameInput);
            row.appendChild(emailInput);
            row.appendChild(buildAction('Enregistrer', 'btn-primary', function () {
                void saveEdit(address, nameInput.value, emailInput.value);
            }));
            row.appendChild(buildAction('Annuler', 'btn-outline-secondary', function () {
                render();
            }));
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
            if (loaded) {
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

        /**
         * @param {{id: number, name: string|null, email: string, unsubscribed_at: string|null}} address
         * @param {string} name
         * @param {string} email
         */
        async function saveEdit(address, name, email) {
            var res = await api.postJson(
                '/admin/listes-de-diffusion/addresses/' + address.id,
                { name: name, email: email },
                { method: 'PATCH' }
            );
            if (!isSuccess(res) || !res.data.address) {
                showError(errorMessage(res));
                render();
                return;
            }
            showError(null);
            addresses = addresses.map(function (a) { return a.id === address.id ? res.data.address : a; });
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
            renderCount(res.data.counts);
            render();
        }

        /**
         * The sentence the chief confirms — the four counts, in the order
         * that matters to somebody about to lose rows: what arrives, what
         * stays, what goes, and what is kept because it asked to be left
         * alone.
         *
         * @param {{added: number, unchanged: number, removed: number, kept_unsubscribed: number}} summary
         * @returns {string}
         */
        function summarySentence(summary) {
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
            if (!file) {
                return;
            }

            // The one raw fetch() here: a multipart body is outside the
            // JSON toolbox's remit, so the CSRF token rides the form data
            // by hand — the finance-movements.js precedent.
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
            importSummary.textContent = summarySentence(data.summary);
            renderImportErrors(data.errors || []);
            importPreview.classList.remove('d-none');
            importInput.value = '';
        });

        importCancel.addEventListener('click', function () {
            hideImportPreview();
        });

        importConfirm.addEventListener('click', async function () {
            if (pendingImport === null) {
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
            renderCount(res.data.counts);
            // The set on screen is no longer the set in the database —
            // fetch it again rather than guess what the replacement did.
            loaded = false;
            addresses = [];
            shown = SLICE;
            await load();
        });

        panel.addEventListener('toggle', function () {
            if (panel.hasAttribute('open')) {
                void load();
            }
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
            var res = await api.postJson('/admin/listes-de-diffusion/lists/' + listId + '/addresses', {
                name: newNameEl.value,
                email: newEmailEl.value
            });
            // A success envelope without the row it claims to have created
            // is not a success — reading it as one crashed the panel on
            // the next line rather than saying anything.
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
            renderCount(res.data.counts);
            render();
        });
    }

    panels.forEach(function (panel) {
        wirePanel(/** @type {HTMLElement} */ (panel));
    });
})();
