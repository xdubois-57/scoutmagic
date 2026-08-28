/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Inbound-mail configuration page
// (modules/inbound_mail/views/config/index.html.twig):
// the « Tester la connexion » button, the folder-checkbox picker it
// populates, and the edit-mode toggle that fills the form from an
// existing mailbox row.
//
// Same conventions as sos-config.js: fetches ride ScoutMagicApi, every
// server-supplied string reaches the screen via textContent or a DOM
// node (never innerHTML), and no credential is ever logged or
// interpolated into a URL.
(function () {
    var form = /** @type {HTMLFormElement|null} */ (document.getElementById('mailbox-form'));
    if (!form) {
        return;
    }

    var api = window.ScoutMagicApi;

    // ── DOM handles ────────────────────────────────────────────────────

    var formTitle = document.getElementById('mailbox-form-title');
    var idInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-id'));
    var nameInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-name'));
    var usernameInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-username'));
    var hostInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-host'));
    var portInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-port'));
    var encryptionSelect = /** @type {HTMLSelectElement|null} */ (document.getElementById('mailbox-encryption'));
    var passwordInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-password'));
    var foldersTextarea = /** @type {HTMLTextAreaElement|null} */ (document.getElementById('mailbox-folders'));
    var foldersWrapper = document.getElementById('mailbox-folders-wrapper');
    var foldersPicker = document.getElementById('mailbox-folders-picker');
    var foldersList = document.getElementById('mailbox-folders-list');
    var enabledCheckbox = /** @type {HTMLInputElement|null} */ (document.getElementById('mailbox-enabled'));
    var submitBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('mailbox-submit-btn'));
    var cancelBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('mailbox-cancel-btn'));
    var testBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('test-connection-btn'));
    var testResult = document.getElementById('test-connection-result');

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {boolean}
     */
    function succeeded(res) {
        return !!(res.data && res.data.success);
    }

    /**
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {string}
     */
    function failureMessage(res) {
        return (res.data && res.data.error) || 'Erreur : réponse serveur invalide.';
    }

    /**
     * @param {boolean} ok
     * @param {string} message
     */
    function renderTestResult(ok, message) {
        if (!testResult) {
            return;
        }
        testResult.textContent = '';

        var wrapper = document.createElement('span');
        wrapper.className = ok ? 'text-success' : 'text-danger';

        var icon = document.createElement('i');
        icon.className = ok ? 'bi bi-check-circle' : 'bi bi-x-circle';
        icon.setAttribute('aria-hidden', 'true');
        wrapper.appendChild(icon);
        wrapper.appendChild(document.createTextNode(' ' + message));

        testResult.appendChild(wrapper);
    }

    /** The folder names the operator had selected before the last test. */
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
     * Build one checkbox row for the folder picker.
     *
     * @param {string} folderName
     * @param {boolean} checked
     * @returns {HTMLDivElement}
     */
    function buildFolderCheckbox(folderName, checked) {
        var id = 'folder-cb-' + folderName.replace(/[^a-zA-Z0-9_-]/g, '_');

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

    /**
     * Populate the folder picker with checkboxes and sync the hidden
     * textarea whenever a checkbox changes.
     *
     * @param {string[]} folders
     * @param {string[]} selected
     */
    function showFolderPicker(folders, selected) {
        if (!foldersList || !foldersPicker || !foldersWrapper) {
            return;
        }

        foldersList.textContent = '';

        folders.forEach(function (folder) {
            var isSelected = selected.indexOf(folder) !== -1;
            var row = buildFolderCheckbox(folder, isSelected);
            foldersList.appendChild(row);
        });

        // Sync checkboxes → textarea on every change.
        foldersList.addEventListener('change', syncFoldersToTextarea);

        syncFoldersToTextarea();

        foldersWrapper.classList.add('d-none');
        foldersPicker.classList.remove('d-none');
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

    /** Reset the folder picker back to the textarea fallback. */
    function hideFolderPicker() {
        if (foldersPicker) {
            foldersPicker.classList.add('d-none');
        }
        if (foldersList) {
            foldersList.textContent = '';
        }
        if (foldersWrapper) {
            foldersWrapper.classList.remove('d-none');
        }
    }

    // ── Test connection ────────────────────────────────────────────────

    if (testBtn) {
        testBtn.addEventListener('click', async function () {
            if (testResult) {
                testResult.textContent = '…';
            }

            var selectedBefore = currentSelectedFolders();

            var res = await api.withDisabled(testBtn, function () {
                return api.postJson('/config/courrier-entrant/test-connexion', {
                    host: hostInput ? hostInput.value : '',
                    port: portInput ? parseInt(portInput.value, 10) : 993,
                    encryption: encryptionSelect ? encryptionSelect.value : 'ssl',
                    username: usernameInput ? usernameInput.value : '',
                    password: passwordInput ? passwordInput.value : '',
                    mailbox_id: idInput ? parseInt(idInput.value, 10) : 0
                });
            });

            if (succeeded(res)) {
                renderTestResult(true, res.data.message || 'Connexion réussie');

                var folders = Array.isArray(res.data.folders) ? res.data.folders : [];
                if (folders.length > 0) {
                    showFolderPicker(folders, selectedBefore);
                }
            } else {
                renderTestResult(false, failureMessage(res));
            }
        });
    }

    // ── Edit mode ──────────────────────────────────────────────────────

    /**
     * @param {string} val
     * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement|null} el
     */
    function setVal(el, val) {
        if (el) {
            el.value = val;
        }
    }

    function enterEditMode(dataset) {
        setVal(idInput, dataset.id || '0');
        setVal(nameInput, dataset.name || '');
        setVal(usernameInput, dataset.username || '');
        setVal(hostInput, dataset.host || '');
        setVal(portInput, dataset.port || '993');
        setVal(passwordInput, '');
        setVal(foldersTextarea, dataset.folders || '');

        if (encryptionSelect) {
            encryptionSelect.value = dataset.encryption || 'ssl';
        }
        if (enabledCheckbox) {
            enabledCheckbox.checked = dataset.isEnabled === '1';
        }
        if (passwordInput) {
            passwordInput.removeAttribute('required');
        }

        hideFolderPicker();

        if (testResult) {
            testResult.textContent = '';
        }

        if (formTitle) {
            formTitle.textContent = 'Modifier la boîte';
        }
        if (submitBtn) {
            submitBtn.textContent = 'Enregistrer';
        }
        if (cancelBtn) {
            cancelBtn.classList.remove('d-none');
        }

        if (form.scrollIntoView) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function leaveEditMode() {
        form.reset();
        setVal(idInput, '0');

        if (passwordInput) {
            passwordInput.setAttribute('required', '');
        }

        hideFolderPicker();

        if (testResult) {
            testResult.textContent = '';
        }

        if (formTitle) {
            formTitle.textContent = 'Ajouter une boîte';
        }
        if (submitBtn) {
            submitBtn.textContent = 'Ajouter la boîte';
        }
        if (cancelBtn) {
            cancelBtn.classList.add('d-none');
        }
    }

    /** @type {NodeListOf<HTMLButtonElement>} */
    var editButtons = document.querySelectorAll('.js-edit-mailbox');
    editButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            enterEditMode(btn.dataset);
        });
    });

    if (cancelBtn) {
        cancelBtn.addEventListener('click', leaveEditMode);
    }
})();
