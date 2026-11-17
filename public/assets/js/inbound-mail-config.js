/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Inbound-mail address form
// (modules/inbound_mail/views/config/mailbox_form.html.twig):
//   - the provider an address belongs to, asked of the server once the
//     address is typed, which prefills an EMPTY server, port and
//     encryption and shows the app-password help that applies (D8);
//   - the module field, shown only for an address reserved to a module;
//   - « Dossiers lus (facultatif) »: opened, it lists the server's folders
//     — the special ones already filtered out server-side — as checkboxes
//     backed by the no-script textarea, and says which folder holds the
//     sent mail when the server marks one (D9).
//
// The page works without any of it: the form posts as typed, and the
// server verifies the connection before saving (D10).
//
// Same conventions as sos-config.js: fetches ride ScoutMagicApi, every
// server-supplied string reaches the screen via textContent (never
// innerHTML), and no credential is ever logged or put into a URL.
(function () {
    var form = /** @type {HTMLFormElement|null} */ (document.getElementById('mailbox-form'));
    if (!form) {
        return;
    }

    var api = window.ScoutMagicApi;

    // ── DOM handles ────────────────────────────────────────────────────

    var idInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-id'));
    var usernameInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-username'));
    var hostInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-host'));
    var portInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-port'));
    var encryptionSelect = /** @type {HTMLSelectElement|null} */ (document.getElementById('mailbox-encryption'));
    var passwordInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-password'));
    var providerKnown = document.getElementById('mailbox-provider-known');
    var providerUnknown = document.getElementById('mailbox-provider-unknown');
    var moduleField = document.getElementById('mailbox-module-field');
    var foldersDetails = /** @type {HTMLDetailsElement|null} */ (document.getElementById('mailbox-folders-details'));
    var foldersTextarea = /** @type {HTMLTextAreaElement|null} */ (document.getElementById('mailbox-folders'));
    var foldersWrapper = document.getElementById('mailbox-folders-wrapper');
    var foldersPicker = document.getElementById('mailbox-folders-picker');
    var foldersList = document.getElementById('mailbox-folders-list');
    var foldersStatus = document.getElementById('mailbox-folders-status');
    var sentInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-sent-folder'));
    var sentField = document.getElementById('mailbox-sent-field');
    var sentFound = document.getElementById('mailbox-sent-found');
    var sentFoundName = document.getElementById('mailbox-sent-found-name');

    // ── Provider recognition (D8) ──────────────────────────────────────

    /**
     * The value this script last wrote in each connection field, so a later
     * recognition may replace it — and never replaces what the operator
     * typed. Decided field by field, like the server's own prefill: a port
     * chosen with the server still empty is the operator's.
     *
     * @type {{host?: string, port?: string, encryption?: string}}
     */
    var prefilled = {};

    /** @returns {{host: (HTMLInputElement|null), port: (HTMLInputElement|null), encryption: (HTMLSelectElement|null)}} */
    function connectionFields() {
        return { host: hostInput, port: portInput, encryption: encryptionSelect };
    }

    /**
     * Whether this script may write the field: empty, or still holding what
     * the script itself wrote there.
     *
     * @param {'host'|'port'|'encryption'} name
     */
    function isScriptOwned(name) {
        var field = connectionFields()[name];
        if (!field) {
            return false;
        }
        return field.value.trim() === '' || (prefilled[name] !== undefined && field.value === prefilled[name]);
    }

    /** @param {string|null} key */
    function showPasswordHelp(key) {
        form.querySelectorAll('[data-password-help]').forEach(function (node) {
            var help = /** @type {HTMLElement} */ (node);
            help.hidden = help.dataset.passwordHelp !== key;
        });
    }

    /**
     * The sentences under the address and the password: « X reconnu » and
     * that provider's password help, or « Fournisseur non reconnu ».
     *
     * @param {{label: string, password_help: (string|null)}|null} provider
     */
    function showProviderHints(provider) {
        if (providerKnown) {
            var badge = providerKnown.querySelector('.badge');
            if (badge && provider) {
                badge.textContent = provider.label + ' reconnu';
            }
            providerKnown.hidden = !provider;
        }
        if (providerUnknown) {
            providerUnknown.hidden = !!provider || (usernameInput?.value.trim() ?? '') === '';
        }
        showPasswordHelp(provider?.password_help || null);
    }

    /**
     * Fill, among the three settings, those this script may write.
     *
     * @param {{host: string, port: string, encryption: string}} settings
     */
    function prefillConnection(settings) {
        /** @type {Array<'host'|'port'|'encryption'>} */
        var names = ['host', 'port', 'encryption'];
        names.forEach(function (name) {
            var field = connectionFields()[name];
            if (field && isScriptOwned(name)) {
                field.value = settings[name];
                prefilled[name] = settings[name];
            }
        });
    }

    /**
     * Empty what this script prefilled and nobody changed since; what the
     * operator typed stays.
     */
    function clearOwnPrefill() {
        /** @type {Array<'host'|'port'|'encryption'>} */
        var names = ['host', 'port', 'encryption'];
        names.forEach(function (name) {
            var field = connectionFields()[name];
            if (field && prefilled[name] !== undefined && field.value === prefilled[name]) {
                field.value = '';
            }
            delete prefilled[name];
        });
    }

    /**
     * @param {{label: string, host: string, port: number, encryption: string, password_help: (string|null)}|null} provider
     */
    function applyProvider(provider) {
        showProviderHints(provider);

        if (provider) {
            prefillConnection({
                host: String(provider.host),
                port: String(provider.port),
                encryption: String(provider.encryption)
            });
        } else {
            // An unknown provider leaves the three settings empty: what this
            // script prefilled for the previous address goes.
            clearOwnPrefill();
        }
    }

    // Nothing asked yet: the page's own prefill reads the domain alone, so
    // an address already there is asked once on load, MX included.
    var lastAsked = '';

    async function recognize() {
        if (!usernameInput) {
            return;
        }
        var address = usernameInput.value.trim();
        if (address === lastAsked) {
            return;
        }
        lastAsked = address;

        if (address.indexOf('@') < 1) {
            applyProvider(null);
            return;
        }

        var res = await api.postJson('/config/courrier-entrant/fournisseur', { address: address });
        // An answer for an address the operator has since replaced is not
        // this address's answer.
        if (usernameInput.value.trim() !== address) {
            return;
        }
        applyProvider(res.data?.recognized ? res.data.provider : null);
    }

    if (usernameInput) {
        usernameInput.addEventListener('change', recognize);
        if (usernameInput.value.trim() !== '') {
            void recognize();
        }
    }

    // ── Usage → module field ───────────────────────────────────────────

    function syncModuleField() {
        if (!moduleField) {
            return;
        }
        var dedicated = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-purpose-dedicated'));
        moduleField.hidden = !dedicated?.checked;
    }

    form.querySelectorAll('input[name="purpose"]').forEach(function (radio) {
        radio.addEventListener('change', syncModuleField);
    });
    syncModuleField();

    // ── Folders read (D9) ──────────────────────────────────────────────

    /** @param {string} message */
    function setFoldersStatus(message) {
        if (foldersStatus) {
            foldersStatus.textContent = message;
        }
    }

    /** The other folders the operator had chosen before the listing. */
    function currentSelectedFolders() {
        if (!foldersTextarea) {
            return [];
        }
        return foldersTextarea.value
            .split('\n')
            .map(function (f) { return f.trim(); })
            .filter(function (f) { return f !== ''; });
    }

    /**
     * @param {string} folderName
     * @param {number} index
     * @param {boolean} checked
     * @returns {HTMLDivElement}
     */
    function buildFolderCheckbox(folderName, index, checked) {
        var id = 'folder-cb-' + index;

        var wrapper = document.createElement('div');
        wrapper.className = 'form-check';

        var input = document.createElement('input');
        input.type = 'checkbox';
        input.className = 'form-check-input folder-checkbox';
        input.id = id;
        input.value = folderName;
        input.checked = checked;

        var label = document.createElement('label');
        label.className = 'form-check-label';
        label.htmlFor = id;
        label.textContent = folderName;

        wrapper.appendChild(input);
        wrapper.appendChild(label);

        return wrapper;
    }

    function syncFoldersToTextarea() {
        if (!foldersTextarea || !foldersList) {
            return;
        }
        /** @type {string[]} */
        var checked = [];
        foldersList.querySelectorAll('.folder-checkbox:checked').forEach(function (cb) {
            checked.push(/** @type {HTMLInputElement} */ (cb).value);
        });
        foldersTextarea.value = checked.join('\n');
    }

    /**
     * @param {string[]} folders
     * @param {string[]} selected
     */
    function showFolderPicker(folders, selected) {
        if (!foldersList || !foldersPicker || !foldersWrapper) {
            return;
        }

        foldersList.textContent = '';
        folders.forEach(function (folder, index) {
            foldersList.appendChild(buildFolderCheckbox(folder, index, selected.includes(folder)));
        });

        syncFoldersToTextarea();

        foldersWrapper.classList.add('d-none');
        foldersPicker.classList.remove('d-none');
    }

    /** @param {string|null} sentFolder */
    function showSentFolder(sentFolder) {
        // Found by the server and not overridden: said, not asked. An
        // operator's own entry stays editable.
        if (!sentFolder || (sentInput?.value.trim() ?? '') !== '') {
            return;
        }
        if (sentFoundName) {
            sentFoundName.textContent = sentFolder;
        }
        if (sentFound) {
            sentFound.hidden = false;
        }
        if (sentField) {
            sentField.hidden = true;
        }
    }

    if (foldersList) {
        foldersList.addEventListener('change', syncFoldersToTextarea);
    }

    /**
     * What the folder listing on screen was read for — the connection and
     * the usage, since a seed box is offered its junk folder — or null.
     * A listing is only good for the settings it was asked with.
     *
     * @type {string|null}
     */
    var loadedKey = null;

    /** The settings of the request in flight, so a late answer for older ones is dropped. */
    var pendingKey = /** @type {string|null} */ (null);

    /** The module an address is reserved to, or '' for the general mail. */
    function reservedModule() {
        var dedicated = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-purpose-dedicated'));
        var select = /** @type {HTMLSelectElement|null} */ (document.getElementById('mailbox-dedicated-to'));
        return dedicated?.checked && select ? select.value : '';
    }

    function connectionKey() {
        return JSON.stringify([
            hostInput ? hostInput.value.trim() : '',
            portInput ? portInput.value.trim() : '',
            encryptionSelect ? encryptionSelect.value : '',
            usernameInput ? usernameInput.value.trim() : '',
            reservedModule()
        ]);
    }

    /**
     * Put the folder section back as the page rendered it: the textarea
     * (which holds the current choice) instead of a picker read for other
     * settings, and the sent-folder field instead of a « trouvé
     * automatiquement » that may name another server's folder.
     */
    function resetFolderListing() {
        loadedKey = null;
        if (foldersList) {
            foldersList.textContent = '';
        }
        if (foldersPicker) {
            foldersPicker.classList.add('d-none');
        }
        if (foldersWrapper) {
            foldersWrapper.classList.remove('d-none');
        }
        if (sentFound) {
            sentFound.hidden = true;
        }
        if (sentField) {
            sentField.hidden = false;
        }
        setFoldersStatus('');
    }

    /** Whether the form holds enough to open a connection. */
    function canListFolders() {
        var editing = idInput ? Number.parseInt(idInput.value, 10) > 0 : false;
        return (usernameInput?.value.trim() ?? '') !== ''
            && (hostInput?.value.trim() ?? '') !== ''
            && (editing || (passwordInput?.value ?? '') !== '');
    }

    /** What the test endpoint is asked, from the form as it stands. */
    function folderRequest() {
        return {
            host: hostInput?.value ?? '',
            port: portInput ? Number.parseInt(portInput.value, 10) : 993,
            encryption: encryptionSelect?.value ?? 'ssl',
            username: usernameInput?.value ?? '',
            password: passwordInput?.value ?? '',
            mailbox_id: idInput ? Number.parseInt(idInput.value, 10) : 0,
            dedicated_to: reservedModule()
        };
    }

    /**
     * Show a successful listing.
     *
     * @param {{folders?: unknown, sent_folder?: unknown}} data
     */
    function showFolderListing(data) {
        // The choice as it stands NOW: a folder typed while the server was
        // answering is kept, not the snapshot taken before the request.
        var selected = currentSelectedFolders();
        /** @type {string[]} */
        var offered = Array.isArray(data.folders) ? data.folders : [];
        // A folder already chosen stays listed, checked, even when the
        // server no longer offers it: a listing never unticks it silently.
        var folders = offered.concat(selected.filter(function (f) { return !offered.includes(f); }));
        setFoldersStatus(folders.length > 0 ? '' : "Le serveur n'a pas d'autre dossier à proposer.");
        if (folders.length > 0) {
            showFolderPicker(folders, selected);
        }
        showSentFolder(typeof data.sent_folder === 'string' ? data.sent_folder : null);
    }

    async function listFolders() {
        if (!foldersDetails?.open) {
            return;
        }
        var key = connectionKey();
        if (key === loadedKey || key === pendingKey) {
            return;
        }
        if (loadedKey !== null) {
            resetFolderListing();
        }
        if (!canListFolders()) {
            setFoldersStatus("Renseignez l'adresse, le mot de passe et le serveur pour voir les dossiers de la boîte.");
            return;
        }

        setFoldersStatus('Lecture des dossiers du serveur…');
        pendingKey = key;
        var res = await api.postJson('/config/courrier-entrant/test-connexion', folderRequest());

        // An answer for settings changed since, or overtaken by a newer
        // request, describes some other box: it is dropped.
        if (pendingKey !== key || connectionKey() !== key) {
            return;
        }
        pendingKey = null;

        if (!res.data?.success) {
            setFoldersStatus(res.data?.error || 'Erreur : réponse serveur invalide.');
            return;
        }

        loadedKey = key;
        showFolderListing(res.data);
    }

    if (foldersDetails) {
        foldersDetails.addEventListener('toggle', listFolders);
        // Rendered already open (an edited box with folders chosen), the
        // section fires no toggle: list its folders now.
        void listFolders();
    }

    // A change of server, port, encryption, address or usage makes the
    // listing on screen another box's: drop it, and read again if open.
    function connectionChanged() {
        // Nothing listed yet, or the last attempt failed or lacked a
        // field: try again, since the change may be the missing piece.
        if (loadedKey === null && pendingKey === null) {
            void listFolders();
            return;
        }
        if (connectionKey() === loadedKey) {
            return;
        }
        pendingKey = null;
        resetFolderListing();
        void listFolders();
    }

    [hostInput, portInput, encryptionSelect, usernameInput, passwordInput,
        document.getElementById('mailbox-dedicated-to')].forEach(function (control) {
        if (control) {
            control.addEventListener('change', connectionChanged);
        }
    });
    form.querySelectorAll('input[name="purpose"]').forEach(function (radio) {
        radio.addEventListener('change', connectionChanged);
    });
})();
