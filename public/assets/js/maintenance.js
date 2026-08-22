/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Configuration > Maintenance — "Sauvegarde automatique" frequency select:
// auto-saves on change (same pattern as the banner module's per-item
// visibility select's JS).
(function () {
    var select = /** @type {HTMLSelectElement} */ (document.getElementById('auto-backup-frequency'));
    if (!select) return;

    select.addEventListener('change', function () {
        window.ScoutMagicApi.postJson('/config/maintenance/backup/auto-frequency', { frequency: select.value })
            .then(function (res) {
                if (res.data && res.data.success) {
                    window.ScoutMagicToast.show('Enregistré.', { variant: 'success' });
                }
            });
    });
})();

// Configuration > Maintenance — "Sauvegarde complète" form: submits, then
// polls GET /api/maintenance/backup-status/{id} via ScoutMagicApi.poll
// until the background generation finishes or fails.
(function () {
    var form = document.getElementById('full-backup-form');
    if (!form) return;

    var submitBtn = /** @type {HTMLButtonElement} */ (document.getElementById('full-backup-submit'));
    var progressEl = document.getElementById('full-backup-progress');
    var errorEl = document.getElementById('full-backup-error');
    /** @type {{stop: () => void}|null} */
    var pollHandle = null;

    function stopPolling() {
        if (pollHandle) {
            pollHandle.stop();
            pollHandle = null;
        }
    }

    /** @param {string} message */
    function showError(message) {
        stopPolling();
        submitBtn.disabled = false;
        progressEl.classList.add('d-none');
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
    }

    /** @param {string|number} backupId */
    function pollStatus(backupId) {
        pollHandle = window.ScoutMagicApi.poll(function () {
            return window.ScoutMagicApi.getJson('/api/maintenance/backup-status/' + backupId).then(function (res) {
                if (!res.data) {
                    // Transient network hiccup — keep polling, the next
                    // tick will likely succeed.
                    return undefined;
                }
                if (res.data.status === 'completed') {
                    window.location.reload();
                    return false;
                }
                if (res.data.status === 'failed') {
                    showError(res.data.error_message || 'La génération de la sauvegarde a échoué.');
                    return false;
                }
                // pending / in_progress: keep polling.
                return undefined;
            });
        }, { intervalMs: 3000 });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        errorEl.classList.add('d-none');

        var scope = /** @type {HTMLInputElement} */ (form.querySelector('input[name="scope"]:checked'));
        var password = /** @type {HTMLInputElement} */ (document.getElementById('full-backup-password')).value;
        if (!scope || password === '') return;

        submitBtn.disabled = true;
        progressEl.classList.remove('d-none');

        window.ScoutMagicApi.postJson('/config/maintenance/backup/full', { scope: scope.value, password: password })
            .then(function (res) {
                if (!res.data) {
                    showError('Erreur réseau.');
                    return;
                }
                if (!res.data.success) {
                    showError(res.data.error || 'Erreur lors du lancement de la sauvegarde.');
                    return;
                }
                pollStatus(res.data.backup_id);
            });
    });
})();

// "Mise à jour" section — install form(s): submit, then poll
// GET /api/maintenance/update-status/{id} (same ScoutMagicApi.poll shape as
// the full-backup polling above) until installation completes, fails, or is
// rolled back. Wires every matching form found (the always-rendered "Une
// nouvelle version est disponible" form when the webhook's last cached check
// found one, and/or the "Vérifier maintenant" dialog's own form below) since
// both POST to the same endpoint and need identical polling.
(function () {
    /** @param {HTMLElement|null} form */
    function wireInstallForm(form) {
        if (!form) return;

        var submitBtn = /** @type {HTMLButtonElement} */ (form.querySelector('button[type="submit"]'));
        var progressEl = form.querySelector('[id$="-progress"]') || document.getElementById('update-install-progress');
        var errorEl = form.querySelector('[id$="-error"]') || document.getElementById('update-install-error');
        /** @type {{stop: () => void}|null} */
        var pollHandle = null;

        function stopPolling() {
            if (pollHandle) {
                pollHandle.stop();
                pollHandle = null;
            }
        }

        /** @param {string} message */
        function showError(message) {
            stopPolling();
            if (submitBtn) submitBtn.disabled = false;
            if (progressEl) progressEl.classList.add('d-none');
            if (errorEl) {
                errorEl.textContent = message;
                errorEl.classList.remove('d-none');
            }
        }

        /** @param {string|number} historyId */
        function pollStatus(historyId) {
            pollHandle = window.ScoutMagicApi.poll(function () {
                return window.ScoutMagicApi.getJson('/api/maintenance/update-status/' + historyId).then(function (res) {
                    if (!res.data) {
                        // Transient network hiccup — keep polling.
                        return undefined;
                    }
                    if (res.data.status === 'completed') {
                        window.location.reload();
                        return false;
                    }
                    if (res.data.status === 'failed' || res.data.status === 'rolled_back') {
                        showError(res.data.error_message || 'L\'installation de la mise à jour a échoué.');
                        return false;
                    }
                    // pending / backing_up / downloading / installing / migrating: keep polling.
                    return undefined;
                });
            }, { intervalMs: 3000 });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (errorEl) errorEl.classList.add('d-none');
            if (submitBtn) submitBtn.disabled = true;
            if (progressEl) progressEl.classList.remove('d-none');

            window.ScoutMagicApi.postJson('/config/maintenance/update/install', {})
                .then(function (res) {
                    if (!res.data) {
                        showError('Erreur réseau.');
                        return;
                    }
                    if (!res.data.success) {
                        showError(res.data.error || 'Erreur lors du lancement de la mise à jour.');
                        return;
                    }
                    pollStatus(res.data.history_id);
                });
        });
    }

    wireInstallForm(document.getElementById('update-install-form'));
    wireInstallForm(document.getElementById('update-check-now-install-form'));
})();

// "Mise à jour" section — "Vérifier maintenant" button: on-demand check
// (POST /config/maintenance/update/check-now), since detection is
// otherwise purely webhook-driven with no polling in between. Populates
// #update-check-now-dialog with the result; its own install form is wired
// above alongside the always-rendered one.
(function () {
    var btn = /** @type {HTMLButtonElement} */ (document.getElementById('update-check-now'));
    if (!btn) return;

    var progressEl = document.getElementById('update-check-now-progress');
    var errorEl = document.getElementById('update-check-now-error');
    var dialog = document.getElementById('update-check-now-dialog');
    var messageEl = document.getElementById('update-check-now-message');
    var notesEl = document.getElementById('update-check-now-notes');
    var dismissBtn = document.getElementById('update-check-now-dismiss');

    if (dismissBtn) {
        dismissBtn.addEventListener('click', function () {
            dialog.classList.add('d-none');
        });
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        errorEl.classList.add('d-none');
        dialog.classList.add('d-none');
        progressEl.classList.remove('d-none');

        window.ScoutMagicApi.postJson('/config/maintenance/update/check-now', {})
            .then(function (res) {
                btn.disabled = false;
                progressEl.classList.add('d-none');

                if (!res.data) {
                    errorEl.textContent = 'Erreur réseau.';
                    errorEl.classList.remove('d-none');
                    return;
                }
                var data = res.data;
                if (!data.success) {
                    errorEl.textContent = data.error || 'Erreur lors de la vérification.';
                    errorEl.classList.remove('d-none');
                    return;
                }

                if (!data.update_available) {
                    errorEl.classList.add('d-none');
                    dialog.classList.add('d-none');
                    window.ScoutMagicToast.show('Le site est déjà à jour.', { variant: 'success' });
                    return;
                }

                messageEl.textContent = 'Nouvelle version disponible : ' + data.version;
                // notes_html is server-rendered from Markdown by
                // Core\View\MarkdownRenderer (already HTML-escaped there),
                // never built from data.notes client-side.
                notesEl.innerHTML = data.notes_html || '';
                dialog.classList.remove('d-none');
            });
    });
})();

// "Mises à jour automatiques" section — toggle/level/schedule are all
// saved together on one "Enregistrer" click (not autosaved per-field, unlike
// the backup frequency select above), plus the webhook secret's
// generate/regenerate (shown in cleartext exactly once) and the semver
// explainer/major-version-warning show/hide.
(function () {
    var enabledCheckbox = /** @type {HTMLInputElement} */ (document.getElementById('auto-update-enabled'));
    if (!enabledCheckbox) return;

    var detailsEl = document.getElementById('auto-update-details');
    enabledCheckbox.addEventListener('change', function () {
        detailsEl.classList.toggle('d-none', !enabledCheckbox.checked);
    });

    var majorWarning = document.getElementById('auto-update-major-warning');
    var devWarning = document.getElementById('auto-update-dev-warning');
    var scheduleSection = document.getElementById('auto-update-schedule-section');
    var branchSection = document.getElementById('auto-update-branch-section');
    var webhookSection = document.getElementById('auto-update-webhook-section');
    var levelRadios = /** @type {NodeListOf<HTMLInputElement>} */ (document.querySelectorAll('input[name="auto-update-level"]'));
    levelRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (!radio.checked) return;
            majorWarning.classList.toggle('d-none', radio.value !== 'major');
            devWarning.classList.toggle('d-none', radio.value !== 'dev');
            scheduleSection.classList.toggle('d-none', radio.value === 'dev');
            branchSection.classList.toggle('d-none', radio.value !== 'dev');
            webhookSection.classList.toggle('d-none', radio.value !== 'dev');
        });
    });

    var semverToggle = document.getElementById('auto-update-semver-toggle');
    var semverExplainer = document.getElementById('auto-update-semver-explainer');
    if (semverToggle && semverExplainer) {
        semverToggle.addEventListener('click', function (e) {
            e.preventDefault();
            semverExplainer.classList.toggle('d-none');
        });
    }

    var saveBtn = document.getElementById('auto-update-save');
    var errorEl = document.getElementById('auto-update-error');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var levelRadio = /** @type {HTMLInputElement} */ (document.querySelector('input[name="auto-update-level"]:checked'));
            errorEl.classList.add('d-none');

            window.ScoutMagicApi.postJson('/config/maintenance/auto-update/save', {
                enabled: enabledCheckbox.checked,
                level: levelRadio ? levelRadio.value : 'patch',
                day: /** @type {HTMLSelectElement} */ (document.getElementById('auto-update-day')).value,
                time: /** @type {HTMLInputElement} */ (document.getElementById('auto-update-time')).value,
                branch: /** @type {HTMLInputElement} */ (document.getElementById('auto-update-branch')).value
            })
                .then(function (res) {
                    if (!res.data) {
                        errorEl.textContent = 'Erreur réseau.';
                        errorEl.classList.remove('d-none');
                        return;
                    }
                    if (!res.data.success) {
                        errorEl.textContent = res.data.error || 'Erreur lors de l\'enregistrement.';
                        errorEl.classList.remove('d-none');
                        return;
                    }
                    window.ScoutMagicToast.show('Enregistré.', { variant: 'success' });
                });
        });
    }

    var webhookBtn = /** @type {HTMLButtonElement} */ (document.getElementById('webhook-generate-secret'));
    if (webhookBtn) {
        webhookBtn.addEventListener('click', function () {
            webhookBtn.disabled = true;
            window.ScoutMagicApi.postJson('/api/maintenance/webhook-secret', {})
                .then(function (res) {
                    webhookBtn.disabled = false;
                    if (!res.data) {
                        window.ScoutMagicToast.show('Erreur réseau.', { variant: 'error' });
                        return;
                    }
                    if (!res.data.success) {
                        window.ScoutMagicToast.show(res.data.error || 'Erreur lors de la génération du secret.', { variant: 'error' });
                        return;
                    }
                    document.getElementById('webhook-secret-value').textContent = res.data.secret;
                    document.getElementById('webhook-secret-display').classList.remove('d-none');
                    document.getElementById('webhook-setup-instructions').classList.add('d-none');

                    var badge = document.getElementById('webhook-status-badge');
                    badge.textContent = '✓ Configuré';
                    badge.classList.remove('text-bg-secondary');
                    badge.classList.add('text-bg-success');

                    document.getElementById('webhook-generate-secret-label').textContent = 'Régénérer le secret';
                    webhookBtn.classList.remove('btn-outline-primary');
                    webhookBtn.classList.add('btn-outline-secondary');
                    var icon = webhookBtn.querySelector('i');
                    if (icon) {
                        icon.classList.remove('bi-key');
                        icon.classList.add('bi-arrow-repeat');
                    }
                });
        });
    }
})();

// "Réinitialisation" section — three danger actions. The typed keyword is
// only a client-side UX gate (enabling the submit button); the real check
// always happens server-side (Core\Http\Controller\MaintenanceController).
(function () {
    /**
     * @param {string} inputId
     * @param {string} expected
     * @param {(() => boolean)} [extraCondition]
     * @returns {(() => void)|null}
     */
    function wireKeywordGate(inputId, expected, extraCondition) {
        var input = /** @type {HTMLInputElement} */ (document.getElementById(inputId));
        if (!input) return null;
        var submitBtn = /** @type {HTMLButtonElement} */ (input.closest('form').querySelector('button[type="submit"]'));
        function update() {
            var ok = input.value === expected && (!extraCondition || extraCondition());
            submitBtn.disabled = !ok;
        }
        input.addEventListener('input', update);
        return update;
    }

    // Generic ScoutMagicApi.poll wrapper for
    // GET /api/maintenance/reset-status/{id}. onDone/onFailed receive the
    // error message (may be null); onNotFound is only meaningfully different
    // for full reset (see below), where a 404 means "the operation wiped its
    // own tracking row — that's success".
    /**
     * @param {string|number} actionId
     * @param {() => void} onDone
     * @param {(message: string|null) => void} onFailed
     * @param {() => void} onNotFound
     * @returns {void}
     */
    function pollResetStatus(actionId, onDone, onFailed, onNotFound) {
        window.ScoutMagicApi.poll(function () {
            return window.ScoutMagicApi.getJson('/api/maintenance/reset-status/' + actionId).then(function (res) {
                if (res.status === 404) {
                    onNotFound();
                    return false;
                }
                if (!res.data) {
                    // Transient network hiccup — keep polling.
                    return undefined;
                }
                if (res.data.status === 'done') {
                    onDone();
                    return false;
                }
                if (res.data.status === 'failed' || res.data.status === 'canceled') {
                    onFailed(res.data.error_message);
                    return false;
                }
                // pending / processing: keep polling.
                return undefined;
            });
        }, { intervalMs: 3000 });
    }

    // --- Paramètres par défaut ---
    var resetSettingsUpdate = wireKeywordGate('reset-settings-keyword', 'REINITIALISER');
    var resetSettingsForm = document.getElementById('reset-settings-form');
    if (resetSettingsForm) {
        resetSettingsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = /** @type {HTMLButtonElement} */ (document.getElementById('reset-settings-submit'));
            var progressEl = document.getElementById('reset-settings-progress');
            var errorEl = document.getElementById('reset-settings-error');
            errorEl.classList.add('d-none');
            submitBtn.disabled = true;
            progressEl.classList.remove('d-none');

            window.ScoutMagicApi.postJson('/config/maintenance/reset/settings', {
                confirm_keyword: /** @type {HTMLInputElement} */ (document.getElementById('reset-settings-keyword')).value
            })
                .then(function (res) {
                    if (!res.data) {
                        progressEl.classList.add('d-none');
                        errorEl.textContent = 'Erreur réseau.';
                        errorEl.classList.remove('d-none');
                        return;
                    }
                    if (!res.data.success) {
                        progressEl.classList.add('d-none');
                        errorEl.textContent = res.data.error || 'Erreur lors du lancement de la réinitialisation.';
                        errorEl.classList.remove('d-none');
                        if (resetSettingsUpdate) resetSettingsUpdate();
                        return;
                    }
                    pollResetStatus(
                        res.data.action_id,
                        function () { window.location.reload(); },
                        function (message) {
                            progressEl.classList.add('d-none');
                            errorEl.textContent = message || 'La réinitialisation a échoué.';
                            errorEl.classList.remove('d-none');
                        },
                        function () { window.location.reload(); }
                    );
                });
        });
    }

    // --- Réinitialisation complète ---
    var fullResetCheckbox = /** @type {HTMLInputElement} */ (document.getElementById('full-reset-checkbox'));
    var fullResetUpdate = wireKeywordGate('full-reset-keyword', 'EFFACER', function () {
        return fullResetCheckbox && fullResetCheckbox.checked;
    });
    if (fullResetCheckbox && fullResetUpdate) {
        fullResetCheckbox.addEventListener('change', fullResetUpdate);
    }
    var fullResetForm = document.getElementById('full-reset-form');
    if (fullResetForm) {
        fullResetForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!window.confirm('Cette action est irréversible : toutes les données du site seront définitivement supprimées. Continuer ?')) {
                return;
            }
            var submitBtn = /** @type {HTMLButtonElement} */ (document.getElementById('full-reset-submit'));
            var progressEl = document.getElementById('full-reset-progress');
            var errorEl = document.getElementById('full-reset-error');
            errorEl.classList.add('d-none');
            submitBtn.disabled = true;
            progressEl.classList.remove('d-none');

            window.ScoutMagicApi.postJson('/config/maintenance/reset/full', {
                confirm_keyword: /** @type {HTMLInputElement} */ (document.getElementById('full-reset-keyword')).value,
                confirm_checkbox: true
            })
                .then(function (res) {
                    if (!res.data) {
                        progressEl.classList.add('d-none');
                        errorEl.textContent = 'Erreur réseau.';
                        errorEl.classList.remove('d-none');
                        return;
                    }
                    if (!res.data.success) {
                        progressEl.classList.add('d-none');
                        errorEl.textContent = res.data.error || 'Erreur lors du lancement de la réinitialisation.';
                        errorEl.classList.remove('d-none');
                        if (fullResetUpdate) fullResetUpdate();
                        return;
                    }
                    // A 404 here means the operation wiped scheduled_actions
                    // along with everything else — that IS success for a
                    // full reset, not an error, so onNotFound also redirects.
                    pollResetStatus(
                        res.data.action_id,
                        function () { window.location.href = '/'; },
                        function (message) {
                            progressEl.classList.add('d-none');
                            errorEl.textContent = message || 'La réinitialisation a échoué.';
                            errorEl.classList.remove('d-none');
                        },
                        function () { window.location.href = '/'; }
                    );
                });
        });
    }

    // --- Restaurer un backup ---
    var restoreUpdate = wireKeywordGate('restore-backup-keyword', 'RESTAURER');
    var sourceServerRadio = document.getElementById('restore-source-server');
    var sourceUploadRadio = /** @type {HTMLInputElement} */ (document.getElementById('restore-source-upload'));
    var serverPicker = document.getElementById('restore-server-picker');
    var uploadPicker = document.getElementById('restore-upload-picker');
    function toggleRestoreSource() {
        var isUpload = sourceUploadRadio && sourceUploadRadio.checked;
        if (serverPicker) serverPicker.classList.toggle('d-none', !!isUpload);
        if (uploadPicker) uploadPicker.classList.toggle('d-none', !isUpload);
    }
    if (sourceServerRadio) sourceServerRadio.addEventListener('change', toggleRestoreSource);
    if (sourceUploadRadio) sourceUploadRadio.addEventListener('change', toggleRestoreSource);

    var restoreForm = /** @type {HTMLFormElement | null} */ (document.getElementById('restore-backup-form'));
    if (restoreForm) {
        restoreForm.addEventListener('submit', function (e) {
            if (!window.confirm('Cette action va remplacer les données actuelles par celles de la sauvegarde sélectionnée. Continuer ?')) {
                e.preventDefault();
                return;
            }
            var submitBtn = /** @type {HTMLButtonElement} */ (document.getElementById('restore-backup-submit'));

            // A large uploaded archive can't ride the classic multipart POST
            // (the document-root-wide post_max_size is small now — audit M2):
            // send it first as ~8 MB chunks to the dedicated superadmin
            // route, then submit the form referencing the assembled file by
            // upload_id, with the file input cleared. Small archives keep
            // the plain multipart POST.
            var chunker = window.ScoutMagicChunkedUpload;
            var fileInput = /** @type {HTMLInputElement} */ (document.getElementById('restore-backup-file'));
            var uploadIdInput = /** @type {HTMLInputElement} */ (document.getElementById('restore-upload-id'));
            var chunkFile = sourceUploadRadio && sourceUploadRadio.checked
                && fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
            if (chunker && uploadIdInput && chunkFile && chunkFile.size > chunker.CHUNK_THRESHOLD) {
                e.preventDefault();
                submitBtn.disabled = true;
                var chunkErrorEl = document.getElementById('restore-backup-error');
                var chunkProgressEl = document.getElementById('restore-backup-progress');
                if (chunkErrorEl) chunkErrorEl.classList.add('d-none');
                if (chunkProgressEl) chunkProgressEl.classList.remove('d-none');
                chunker.uploadInChunks(chunkFile, '/config/maintenance/restore-upload-chunk', {
                    csrfToken: window.ScoutMagicApi.csrfToken()
                }).then(function (result) {
                    uploadIdInput.value = result.uploadId;
                    fileInput.value = '';
                    // .submit() bypasses this handler — no second confirm,
                    // no second chunk pass.
                    restoreForm.submit();
                }).catch(function (err) {
                    submitBtn.disabled = false;
                    if (chunkProgressEl) chunkProgressEl.classList.add('d-none');
                    if (chunkErrorEl) {
                        chunkErrorEl.textContent = (err && err.message) || 'Le téléversement a échoué.';
                        chunkErrorEl.classList.remove('d-none');
                    }
                });
                return;
            }

            // Classic multipart submit — the server redirects back with
            // ?restore_id={id}, picked up by the polling block below.
            submitBtn.disabled = true;
        });
    }

    // Resume polling after the classic-form restore redirect.
    var restoreIdMatch = /[?&]restore_id=(\d+)/.exec(window.location.search);
    if (restoreIdMatch) {
        var restoreProgressEl = document.getElementById('restore-backup-progress');
        var restoreErrorEl = document.getElementById('restore-backup-error');
        if (restoreProgressEl) restoreProgressEl.classList.remove('d-none');
        pollResetStatus(
            parseInt(restoreIdMatch[1], 10),
            function () { window.location.href = '/config/maintenance'; },
            function (message) {
                if (restoreProgressEl) restoreProgressEl.classList.add('d-none');
                if (restoreErrorEl) {
                    restoreErrorEl.textContent = message || 'La restauration a échoué.';
                    restoreErrorEl.classList.remove('d-none');
                }
            },
            function () { window.location.href = '/config/maintenance'; }
        );
    }
})();
