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
//   - « Dossiers lus » (#1343): shown only once the connection the form
//     holds has answered — the inbox ticked for good, the server's other
//     folders to tick (its special ones already filtered out server-side),
//     and the sent mail found from the server's mark or chosen in the
//     server's own list. A change of server, port, encryption, address,
//     password or usage hides it again until the new settings answer.
//
// The page works without any of it: the form posts as typed, a new address
// then reads its inbox alone and an edited one keeps its folders, and the
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
    var foldersSection = document.getElementById('mailbox-folders');
    var foldersCheck = /** @type {HTMLButtonElement|null} */ (document.getElementById('mailbox-folders-check'));
    var foldersList = document.getElementById('mailbox-folders-list');
    var foldersMissing = document.getElementById('mailbox-folders-missing');
    var foldersStatus = document.getElementById('mailbox-folders-status');
    var foldersVerified = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-folders-verified'));
    var sentSelect = /** @type {HTMLSelectElement|null} */ (document.getElementById('mailbox-sent-folder'));
    var sentHelp = document.getElementById('mailbox-sent-help');

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

    // ── Folders read (#1343) ───────────────────────────────────────────

    /** @param {string} message */
    function setFoldersStatus(message) {
        if (foldersStatus) {
            foldersStatus.textContent = message;
        }
    }

    /**
     * The other folders the box reads, as stored or as last posted — read
     * once from the page, then from the boxes ticked on screen.
     *
     * @type {string[]}
     */
    var chosenFolders = (function () {
        try {
            var parsed = JSON.parse(foldersSection?.dataset.chosen || '[]');
            return Array.isArray(parsed) ? parsed.filter(function (f) { return typeof f === 'string'; }) : [];
        } catch {
            // Ignored on purpose: the attribute is the server's own
            // json_encode, unreadable only on a page altered in the
            // browser — and then no folder counts as chosen, which the
            // server-side check on saving would refuse anyway.
            return [];
        }
    })();

    /** The sent folder the box names, or '' for the server's mark. */
    var chosenSent = foldersSection?.dataset.sent ?? '';

    /** The other folders ticked on screen, when a listing is shown. */
    function tickedFolders() {
        if (!foldersList) {
            return [];
        }
        /** @type {string[]} */
        var ticked = [];
        foldersList.querySelectorAll('input.folder-checkbox:checked:not(:disabled)').forEach(function (cb) {
            ticked.push(/** @type {HTMLInputElement} */ (cb).value);
        });
        return ticked;
    }

    /**
     * @param {string} folderName the path, exactly as the server spells it
     * @param {string} label
     * @param {number} index
     * @param {{checked: boolean, locked?: boolean}} state
     * @returns {HTMLDivElement}
     */
    function buildFolderCheckbox(folderName, label, index, state) {
        var id = 'folder-cb-' + index;

        var wrapper = document.createElement('div');
        wrapper.className = 'form-check';

        var input = document.createElement('input');
        input.type = 'checkbox';
        input.className = 'form-check-input folder-checkbox';
        input.id = id;
        input.value = folderName;
        input.checked = state.checked;
        if (state.locked) {
            // Always read: shown ticked, never posted, never untickable.
            input.disabled = true;
        } else {
            input.name = 'folders[]';
        }

        var text = document.createElement('label');
        text.className = 'form-check-label';
        text.htmlFor = id;
        text.textContent = label;

        wrapper.appendChild(input);
        wrapper.appendChild(text);

        return wrapper;
    }

    /**
     * The sent-mail choice: the server's mark when it has one, otherwise
     * none or one of its own folders.
     *
     * @param {string|null} marked
     * @param {string[]} candidates
     */
    function showSentChoice(marked, candidates) {
        if (!sentSelect) {
            return;
        }
        sentSelect.textContent = '';
        var automatic = document.createElement('option');
        automatic.value = '';
        automatic.textContent = marked !== null ? 'Trouvé automatiquement : ' + marked : 'Aucun';
        sentSelect.appendChild(automatic);
        candidates.forEach(function (folder) {
            var option = document.createElement('option');
            option.value = folder;
            option.textContent = folder;
            sentSelect.appendChild(option);
        });
        sentSelect.value = candidates.includes(chosenSent) ? chosenSent : '';
        sentSelect.disabled = false;
        if (sentHelp) {
            sentHelp.textContent = marked !== null
                ? 'Le serveur indique lui-même son dossier des messages envoyés.'
                : "Le serveur n'indique pas son dossier des messages envoyés : choisissez-le, ou laissez « Aucun ».";
        }
    }

    /**
     * Show a successful listing: the inbox ticked for good, the folders
     * offered, the sent-mail choice — and, when the box names folders the
     * server no longer has, say so rather than drop them unseen.
     *
     * @param {{folders?: unknown, sent_folder?: unknown, sent_candidates?: unknown}} data
     */
    function showFolderListing(data) {
        if (!foldersList || !foldersSection) {
            return;
        }
        /** @type {string[]} */
        var offered = Array.isArray(data.folders) ? data.folders : [];
        /** @type {string[]} */
        var candidates = Array.isArray(data.sent_candidates) ? data.sent_candidates : [];

        foldersList.textContent = '';
        foldersList.appendChild(buildFolderCheckbox('INBOX', 'Boîte de réception (INBOX) — toujours lue', 0, {
            checked: true,
            locked: true
        }));
        offered.forEach(function (folder, index) {
            foldersList.appendChild(buildFolderCheckbox(folder, folder, index + 1, {
                checked: chosenFolders.includes(folder)
            }));
        });

        var missing = chosenFolders.filter(function (f) { return !offered.includes(f); });
        /** @type {string[]} */
        var warnings = [];
        if (missing.length > 0) {
            warnings.push('Introuvable sur ce serveur, donc plus lu après l’enregistrement : ' + missing.join(', ') + '.');
        }
        // A sent folder the server no longer has is replaced by the choice
        // below on saving: said, rather than swapped unseen.
        if (sentSelect && chosenSent !== '' && !candidates.includes(chosenSent)) {
            warnings.push('Dossier des messages envoyés introuvable sur ce serveur : ' + chosenSent
                + '. L’enregistrement le remplace par le choix ci-dessous.');
        }
        if (foldersMissing) {
            foldersMissing.textContent = warnings.join(' ');
            foldersMissing.hidden = warnings.length === 0;
        }

        showSentChoice(typeof data.sent_folder === 'string' ? data.sent_folder : null, candidates);
        if (foldersVerified) {
            foldersVerified.disabled = false;
        }
        foldersSection.hidden = false;
    }

    if (foldersList) {
        foldersList.addEventListener('change', function () {
            chosenFolders = tickedFolders();
        });
    }
    if (sentSelect) {
        sentSelect.addEventListener('change', function () {
            chosenSent = sentSelect.value;
        });
    }

    /**
     * What the listing on screen was read for — the whole connection,
     * password included, and the usage, since a seed box is offered its
     * junk folder — or null. A listing is only good for those settings.
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
            // Another password is another connection: a listing read
            // with the old one proves nothing about the new.
            passwordInput ? passwordInput.value : '',
            reservedModule()
        ]);
    }

    /**
     * Hide the section until the settings on screen have answered: no
     * listing read for other settings stays visible, and nothing of it is
     * posted — the boxes leave with the list, the sent choice and the
     * « verified » flag are disabled.
     */
    function resetFolderListing() {
        loadedKey = null;
        if (foldersList) {
            foldersList.textContent = '';
        }
        if (foldersMissing) {
            foldersMissing.hidden = true;
        }
        if (sentSelect) {
            sentSelect.textContent = '';
            sentSelect.disabled = true;
        }
        if (foldersVerified) {
            foldersVerified.disabled = true;
        }
        if (foldersSection) {
            foldersSection.hidden = true;
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

    /** @param {boolean} [quiet] say nothing when a field is missing */
    async function listFolders(quiet) {
        var key = connectionKey();
        if (key === loadedKey || key === pendingKey) {
            return;
        }
        if (loadedKey !== null) {
            resetFolderListing();
        }
        if (!canListFolders()) {
            if (!quiet) {
                setFoldersStatus("Renseignez l'adresse, le mot de passe et le serveur pour vérifier la connexion.");
            }
            return;
        }

        setFoldersStatus('Vérification de la connexion…');
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
        setFoldersStatus(typeof res.data.message === 'string' ? res.data.message : '');
        showFolderListing(res.data);
    }

    if (foldersCheck) {
        foldersCheck.hidden = false;
        foldersCheck.addEventListener('click', function () {
            void listFolders(false);
        });
    }
    // An edited box can answer at once, with its stored password.
    void listFolders(true);

    // A change of server, port, encryption, address, password or usage
    // makes the listing on screen another box's: hide it, and verify again.
    function connectionChanged() {
        if (connectionKey() === loadedKey) {
            return;
        }
        pendingKey = null;
        resetFolderListing();
        void listFolders(true);
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
