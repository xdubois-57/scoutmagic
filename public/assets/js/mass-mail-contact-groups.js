/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The contact group dialog (modules/mass_mail/views/partials/
// _contact_group_dialog.html.twig), on the page of a list.
//
// **Opened from the page, by contract** (CHANTIER-listes-de-diffusion-
// blocs.md §3): any `[data-contact-group-open]` opens it — without
// `data-block-id` to create a group, with it to open that group. The
// listener is delegated on `document`, so rows the page redraws after a
// save keep opening it. After a save the dialog emits
// `mass-mail:blocks-changed` on `document`, and the page reloads its blocks
// and its total; this script never touches the page's own markup.
//
// **A contact group changes only by file, and nothing is written before
// « Enregistrer ».** Choosing a file only ANALYSES it — the server writes
// nothing, deletes the upload, and answers the counts and the rows — and
// « Enregistrer » carries the name and those rows back together.
// « Annuler » abandons both, without a single request.
//
// **An existing group's contacts arrive in ONE call**, decrypted and
// sorted server-side, and everything afterwards happens here: searching,
// the « Désinscrites seulement » filter and rendering in slices of fifty.
// There is no ORDER BY and no LIKE on ciphertext, so the server has
// nothing to page with; what bounds the cost is the per-group cap.
(function () {
    var dialog = /** @type {HTMLElement|null} */ (document.querySelector('[data-contact-group-dialog]'));
    if (!dialog) {
        return;
    }

    var api = window.ScoutMagicApi;
    var SLICE = 50;
    var BASE = '/admin/listes-de-diffusion';
    var modalEl = dialog.closest('.modal');

    /**
     * The same normalisation both sides of a search go through in PHP
     * (Core\Service\TextNormalizerService::fold): lowercase, accents
     * folded, every run of non-alphanumerics collapsed to one space. A
     * second implementation of a generic ALGORITHM, never a second copy of
     * data — there is no shared runtime between PHP and the browser.
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
     * An identifier read from a data- attribute, accepted only as digits:
     * it ends up in a URL, and a value is not safe for having come from a
     * template.
     *
     * @param {string|undefined} value
     * @returns {string|null}
     */
    function identifier(value) {
        return value && /^\d+$/.test(value) ? value : null;
    }

    /** @param {number} count @returns {string} */
    function plural(count) {
        return count > 1 ? 's' : '';
    }

    /**
     * @param {string} name
     * @returns {HTMLElement}
     */
    function part(name) {
        return /** @type {HTMLElement} */ (dialog.querySelector('[data-cg-' + name + ']'));
    }

    var errorEl = part('error');
    var nameEl = /** @type {HTMLInputElement} */ (part('name'));
    var newEl = part('new');
    var fileNewEl = /** @type {HTMLInputElement} */ (part('file-new'));
    var fileNameEl = part('file-name');
    var existingEl = part('existing');
    var countEl = part('count');
    var searchEl = /** @type {HTMLInputElement} */ (part('search'));
    var unsubOnlyEl = /** @type {HTMLInputElement} */ (part('unsubscribed-only'));
    var rowsEl = part('rows');
    var emptyEl = part('empty');
    var moreEl = part('more');
    var exportEl = /** @type {HTMLAnchorElement} */ (part('export'));
    var fileExistingEl = /** @type {HTMLInputElement} */ (part('file-existing'));
    var previewEl = part('preview');
    var summaryEl = part('summary');
    var lineErrorsEl = part('line-errors');
    var saveEl = /** @type {HTMLButtonElement} */ (
        (modalEl || dialog).querySelector('[data-cg-save]')
    );

    /** @type {string|null} the list a new group is created in */
    var listId = null;
    /** @type {string|null} the group the dialog stands for, null for a creation */
    var blockId = null;
    /** @type {{id: number, name: string|null, email: string, unsubscribed_at: string|null}[]} */
    var contacts = [];
    var loaded = false;
    var shown = SLICE;
    /** @type {{name: string|null, email: string}[]|null} the rows of the analysed file, until saved */
    var pending = null;
    /**
     * A file still being analysed. « Enregistrer » waits for it: saving
     * meanwhile would send the name alone and drop the chosen file.
     */
    var analysing = false;

    /**
     * **One dialog, reopened on another group, so every answer has to
     * prove it is still wanted before it writes anything on screen.** A
     * group of three hundred contacts answers slowly (decryption is the
     * cost); close it, open a group of two, and the first answer may land
     * last. `reset()` bumps this, and a continuation whose token has moved
     * on drops its answer — the same sequencing as the member group's live
     * counter, for the same reason: what a request answers is only true
     * for the state that asked it.
     */
    var generation = 0;

    /** @param {number} token @returns {boolean} */
    function stale(token) {
        return token !== generation;
    }

    /** @param {string|null} message */
    function showError(message) {
        if (message === null) {
            errorEl.classList.add('d-none');
            errorEl.textContent = '';
            return;
        }
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
    }

    function updateSaveState() {
        var ready = !analysing
            && nameEl.value.trim() !== ''
            && (blockId === null ? pending !== null : loaded);
        saveEl.disabled = !ready;
    }

    // --- An existing group's contacts -----------------------------------

    /**
     * « 37 contacts, dont 2 désinscrits · fichier remplacé le 12 septembre
     * 2026 » — the line the list's page prints for the same group.
     *
     * @param {{count: number, unsubscribed: number, file_replaced_label: string}} group
     * @returns {string}
     */
    function countSentence(group) {
        var text = group.count + ' contact' + plural(group.count);
        if (group.unsubscribed > 0) {
            text += ', dont ' + group.unsubscribed + ' désinscrit' + plural(group.unsubscribed);
        }
        if (group.file_replaced_label) {
            text += ' · fichier remplacé le ' + group.file_replaced_label;
        }
        return text;
    }

    function visible() {
        var needle = fold(searchEl.value);
        var unsubscribedOnly = unsubOnlyEl.checked;

        return contacts.filter(function (contact) {
            if (unsubscribedOnly && contact.unsubscribed_at === null) {
                return false;
            }
            if (needle === '') {
                return true;
            }
            return (fold(contact.name) + ' ' + fold(contact.email)).includes(needle);
        });
    }

    /**
     * One row, read-only. An unsubscribed contact is greyed and says so:
     * « 312 contacts » followed by « 304 envoyés » reads as a breakdown
     * unless the screen says which eight are out.
     *
     * @param {{id: number, name: string|null, email: string, unsubscribed_at: string|null}} contact
     * @returns {HTMLLIElement}
     */
    function buildRow(contact) {
        var unsubscribed = contact.unsubscribed_at !== null;
        var row = document.createElement('li');
        row.className = 'list-group-item px-0 py-1 small' + (unsubscribed ? ' text-body-secondary' : '');
        row.dataset.contactId = String(contact.id);
        if (unsubscribed) {
            row.dataset.unsubscribed = '1';
        }

        // textContent throughout: a name and an address are values
        // somebody typed in a spreadsheet, and this is the DOM sink CodeQL
        // flags.
        if (contact.name) {
            var name = document.createElement('div');
            name.textContent = contact.name;
            row.appendChild(name);
        }
        var email = document.createElement('div');
        email.className = unsubscribed ? '' : 'text-body-secondary';
        email.textContent = contact.email + (unsubscribed ? ' · désinscrite' : '');
        row.appendChild(email);

        return row;
    }

    function render() {
        var matching = visible();
        rowsEl.textContent = '';

        matching.slice(0, shown).forEach(function (contact) {
            rowsEl.appendChild(buildRow(contact));
        });

        emptyEl.classList.toggle('d-none', matching.length !== 0);
        moreEl.classList.toggle('d-none', matching.length <= shown);
        moreEl.textContent = 'Afficher plus (' + Math.max(0, matching.length - shown) + ' restants)';
    }

    async function load() {
        if (blockId === null) {
            return;
        }
        var token = generation;
        var res = await api.getJson(BASE + '/contact-groups/' + blockId + '/contacts');
        // Before the error line as well as before the rows: a refusal for
        // a group nobody is looking at any more must not appear over the
        // one that is.
        if (stale(token)) {
            return;
        }
        if (!res.data?.success || !Array.isArray(res.data.contacts) || !res.data.group) {
            showError(res.data?.error || 'Erreur : réponse serveur invalide.');
            return;
        }
        loaded = true;
        contacts = res.data.contacts;
        nameEl.value = res.data.group.name || '';
        nameEl.disabled = false;
        countEl.textContent = countSentence(res.data.group);
        showError(null);
        render();
        updateSaveState();
    }

    // --- The file, analysed -----------------------------------------------

    /**
     * What « Enregistrer » would do, in the order that matters to somebody
     * about to lose rows. A new group only gains, so it says what was
     * found; an existing one says what arrives, what goes and what stays.
     *
     * @param {{added: number, unchanged: number, removed: number, kept_unsubscribed: number}} summary
     * @param {number} duplicates lines of the file that collapsed onto an address already named
     * @param {number} refused lines left out, listed below the sentence
     * @param {string} fileName
     * @returns {string}
     */
    function summarySentence(summary, duplicates, refused, fileName) {
        var parts = [];
        if (blockId === null) {
            parts.push(summary.added + ' contact' + plural(summary.added) + ' trouvé' + plural(summary.added));
        } else {
            parts.push(
                summary.added + ' ajouté' + plural(summary.added),
                summary.removed + ' retiré' + plural(summary.removed),
                summary.unchanged + ' inchangé' + plural(summary.unchanged)
            );
            if (summary.kept_unsubscribed > 0) {
                parts.push(summary.kept_unsubscribed + ' désinscrit' + plural(summary.kept_unsubscribed)
                    + ' conservé' + plural(summary.kept_unsubscribed));
            }
        }
        // Lines that vanished into another line rather than into the group:
        // without this, a file of 300 rows announcing 280 reads as a loss.
        if (duplicates > 0) {
            parts.push(duplicates + ' doublon' + plural(duplicates) + ' fusionné' + plural(duplicates));
        }
        if (refused > 0) {
            parts.push(refused + ' ligne' + plural(refused) + ' ignorée' + plural(refused));
        }

        var sentence = parts.join(', ') + '.';
        return blockId === null ? sentence : fileName + ' — à l\'enregistrement : ' + sentence;
    }

    /** @param {string[]} messages */
    function renderLineErrors(messages) {
        lineErrorsEl.textContent = '';
        messages.forEach(function (message) {
            var item = document.createElement('li');
            // Server text quoting the chief's own spreadsheet: textContent,
            // never innerHTML.
            item.textContent = message;
            lineErrorsEl.appendChild(item);
        });
        lineErrorsEl.classList.toggle('d-none', messages.length === 0);
    }

    function clearPreview() {
        pending = null;
        previewEl.classList.add('d-none');
        summaryEl.textContent = '';
        renderLineErrors([]);
        fileNameEl.textContent = '';
    }

    /** @param {HTMLInputElement} input */
    async function analyse(input) {
        var file = input.files?.[0];
        input.value = '';
        if (!file) {
            return;
        }
        if (blockId === null && listId === null) {
            return;
        }
        var url = blockId === null
            ? BASE + '/lists/' + listId + '/contact-groups/analyse'
            : BASE + '/contact-groups/' + blockId + '/analyse';

        // A multipart body is outside the JSON toolbox's remit, so the
        // CSRF token rides the form data by hand (finance-movements.js).
        var formData = new FormData();
        formData.append('file', file);
        formData.append('_csrf_token', api.csrfToken());

        var token = generation;
        clearPreview();
        analysing = true;
        updateSaveState();
        var data = null;
        try {
            var response = await fetch(url, { method: 'POST', body: formData });
            data = await response.json().catch(function () { return null; });
        } catch {
            data = null;
        }

        if (stale(token)) {
            return;
        }
        analysing = false;
        if (!data?.success || !Array.isArray(data.contacts)) {
            showError((Array.isArray(data?.errors) ? data.errors.join(' ') : '')
                || 'Erreur : réponse serveur invalide.');
            updateSaveState();
            return;
        }

        showError(null);
        pending = data.contacts;
        var lineErrors = Array.isArray(data.errors) ? data.errors : [];
        if (blockId === null) {
            fileNameEl.textContent = file.name;
        }
        summaryEl.textContent = summarySentence(data.summary, data.duplicates || 0, lineErrors.length, file.name);
        renderLineErrors(lineErrors);
        previewEl.classList.remove('d-none');
        updateSaveState();
    }

    // --- Enregistrer --------------------------------------------------------

    async function save() {
        var token = generation;
        /** @type {{name: string, contacts?: {name: string|null, email: string}[]}} */
        var body = { name: nameEl.value };
        if (pending !== null) {
            body.contacts = pending;
        }

        var res = blockId === null
            ? await api.postJson(BASE + '/lists/' + listId + '/contact-groups', body)
            : await api.postJson(BASE + '/contact-groups/' + blockId, body, { method: 'PATCH' });
        if (stale(token)) {
            return;
        }
        if (!res.data?.success || !res.data.group) {
            showError(res.data?.error || 'Erreur : réponse serveur invalide.');
            return;
        }

        var group = res.data.group;
        close();
        window.ScoutMagicToast?.show('Groupe de contacts enregistré.', { variant: 'success' });
        document.dispatchEvent(new CustomEvent('mass-mail:blocks-changed', {
            detail: { listId: String(group.list_id), blockId: String(group.id), kind: 'contact_group' }
        }));
    }

    // --- Opening and closing ----------------------------------------------

    /**
     * Everything the dialog holds about one group, forgotten — including
     * whatever request is still in flight for it, and the file analysed.
     */
    function reset() {
        generation++;
        analysing = false;
        contacts = [];
        loaded = false;
        shown = SLICE;
        searchEl.value = '';
        unsubOnlyEl.checked = false;
        rowsEl.textContent = '';
        countEl.textContent = '';
        nameEl.value = '';
        nameEl.disabled = false;
        exportEl.removeAttribute('href');
        showError(null);
        clearPreview();
        updateSaveState();
    }

    function close() {
        var modal = modalEl && window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modalEl) : null;
        if (modal) {
            // `hidden.bs.modal` resets the dialog.
            modal.hide();
            return;
        }
        reset();
    }

    /**
     * @param {string|null} list the list a new group goes in
     * @param {string|null} block the group to open, null to create one
     */
    function open(list, block) {
        reset();
        listId = list;
        blockId = block;

        newEl.classList.toggle('d-none', blockId !== null);
        existingEl.classList.toggle('d-none', blockId === null);
        if (blockId !== null) {
            exportEl.href = BASE + '/contact-groups/' + blockId + '/export';
            // Filled by the answer, so a name typed meanwhile is never
            // overwritten by it.
            nameEl.disabled = true;
            void load();
        }

        if (modalEl && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    document.addEventListener('click', function (event) {
        var target = /** @type {Element|null} */ (event.target instanceof Element ? event.target : null);
        var trigger = /** @type {HTMLElement|null} */ (target?.closest('[data-contact-group-open]') ?? null);
        // A dialog taken off the page answers no opener any more.
        if (!trigger || !dialog.isConnected) {
            return;
        }
        event.preventDefault();
        var block = identifier(trigger.dataset.blockId);
        var list = identifier(trigger.dataset.listId) || identifier(dialog.dataset.listId);
        if (block === null && list === null) {
            return;
        }
        open(list, block);
    });

    modalEl?.addEventListener('hidden.bs.modal', reset);

    fileNewEl.addEventListener('change', function () {
        void analyse(fileNewEl);
    });
    fileExistingEl.addEventListener('change', function () {
        void analyse(fileExistingEl);
    });
    nameEl.addEventListener('input', updateSaveState);
    saveEl.addEventListener('click', function () {
        // The state is re-read afterwards: withDisabled() re-enables the
        // button whatever happened, including on a dialog just reset.
        void api.withDisabled(saveEl, save).then(updateSaveState);
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
})();
