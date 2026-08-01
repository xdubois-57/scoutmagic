// Configuration > Maintenance — "Sauvegarde automatique" frequency select:
// auto-saves on change (same pattern as the banner module's per-item
// visibility select's JS).
(function () {
    var select = document.getElementById('auto-backup-frequency');
    if (!select) return;

    var saved = document.getElementById('auto-backup-frequency-saved');
    var csrfInput = document.querySelector('input[name="_csrf_token"]');

    select.addEventListener('change', function () {
        fetch('/config/maintenance/backup/auto-frequency', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ frequency: select.value, _csrf_token: csrfInput ? csrfInput.value : '' })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success && saved) {
                    saved.classList.remove('d-none');
                    setTimeout(function () { saved.classList.add('d-none'); }, 1500);
                }
            });
    });
})();

// Configuration > Maintenance — "Sauvegarde complète" form: submits, then
// polls GET /api/maintenance/backup-status/{id} (same setInterval/
// clearInterval pattern as public/assets/js/auth.js's magic-link polling)
// until the background generation finishes or fails.
(function () {
    var form = document.getElementById('full-backup-form');
    if (!form) return;

    var submitBtn = document.getElementById('full-backup-submit');
    var progressEl = document.getElementById('full-backup-progress');
    var errorEl = document.getElementById('full-backup-error');
    var pollTimer = null;

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function showError(message) {
        stopPolling();
        submitBtn.disabled = false;
        progressEl.classList.add('d-none');
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
    }

    function pollStatus(backupId) {
        pollTimer = setInterval(function () {
            fetch('/api/maintenance/backup-status/' + backupId)
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status === 'completed') {
                        stopPolling();
                        window.location.reload();
                    } else if (data.status === 'failed') {
                        showError(data.error_message || 'La génération de la sauvegarde a échoué.');
                    }
                    // pending / in_progress: keep polling.
                })
                .catch(function () {
                    // Transient network hiccup — keep polling, the next
                    // tick will likely succeed.
                });
        }, 3000);
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        errorEl.classList.add('d-none');

        var scope = form.querySelector('input[name="scope"]:checked');
        var password = document.getElementById('full-backup-password').value;
        if (!scope || password === '') return;

        submitBtn.disabled = true;
        progressEl.classList.remove('d-none');

        fetch('/config/maintenance/backup/full', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ scope: scope.value, password: password, _csrf_token: csrf() })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) {
                    showError(data.error || 'Erreur lors du lancement de la sauvegarde.');
                    return;
                }
                pollStatus(data.backup_id);
            })
            .catch(function () {
                showError('Erreur réseau.');
            });
    });
})();

// "Mise à jour" section — install button: submits, then polls
// GET /api/maintenance/update-status/{id} (same pattern as the full-backup
// polling above) until installation completes, fails, or is rolled back.
(function () {
    var form = document.getElementById('update-install-form');
    if (!form) return;

    var submitBtn = document.getElementById('update-install-submit');
    var progressEl = document.getElementById('update-install-progress');
    var errorEl = document.getElementById('update-install-error');
    var pollTimer = null;

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function showError(message) {
        stopPolling();
        submitBtn.disabled = false;
        progressEl.classList.add('d-none');
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
    }

    function pollStatus(historyId) {
        pollTimer = setInterval(function () {
            fetch('/api/maintenance/update-status/' + historyId)
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status === 'completed') {
                        stopPolling();
                        window.location.reload();
                    } else if (data.status === 'failed' || data.status === 'rolled_back') {
                        showError(data.error_message || 'L\'installation de la mise à jour a échoué.');
                    }
                    // pending / backing_up / downloading / installing / migrating: keep polling.
                })
                .catch(function () {
                    // Transient network hiccup — keep polling.
                });
        }, 3000);
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        errorEl.classList.add('d-none');
        submitBtn.disabled = true;
        progressEl.classList.remove('d-none');

        var csrfInput = form.querySelector('input[name="_csrf_token"]');

        fetch('/config/maintenance/update/install', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ _csrf_token: csrfInput ? csrfInput.value : '' })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) {
                    showError(data.error || 'Erreur lors du lancement de la mise à jour.');
                    return;
                }
                pollStatus(data.history_id);
            })
            .catch(function () {
                showError('Erreur réseau.');
            });
    });
})();

// "Mises à jour automatiques" section — toggle/level/schedule are all
// saved together on one "Enregistrer" click (not autosaved per-field, unlike
// the backup frequency select above), plus the webhook secret's
// generate/regenerate (shown in cleartext exactly once) and the semver
// explainer/major-version-warning show/hide.
(function () {
    var enabledCheckbox = document.getElementById('auto-update-enabled');
    if (!enabledCheckbox) return;

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    var detailsEl = document.getElementById('auto-update-details');
    enabledCheckbox.addEventListener('change', function () {
        detailsEl.classList.toggle('d-none', !enabledCheckbox.checked);
    });

    var majorWarning = document.getElementById('auto-update-major-warning');
    document.querySelectorAll('input[name="auto-update-level"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            majorWarning.classList.toggle('d-none', radio.value !== 'major' || !radio.checked);
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
    var savedEl = document.getElementById('auto-update-saved');
    var errorEl = document.getElementById('auto-update-error');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var levelRadio = document.querySelector('input[name="auto-update-level"]:checked');
            errorEl.classList.add('d-none');

            fetch('/config/maintenance/auto-update/save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    enabled: enabledCheckbox.checked,
                    level: levelRadio ? levelRadio.value : 'patch',
                    day: document.getElementById('auto-update-day').value,
                    time: document.getElementById('auto-update-time').value,
                    _csrf_token: csrf()
                })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success) {
                        errorEl.textContent = data.error || 'Erreur lors de l\'enregistrement.';
                        errorEl.classList.remove('d-none');
                        return;
                    }
                    savedEl.classList.remove('d-none');
                    setTimeout(function () { savedEl.classList.add('d-none'); }, 1500);
                })
                .catch(function () {
                    errorEl.textContent = 'Erreur réseau.';
                    errorEl.classList.remove('d-none');
                });
        });
    }

    var webhookBtn = document.getElementById('webhook-generate-secret');
    if (webhookBtn) {
        webhookBtn.addEventListener('click', function () {
            webhookBtn.disabled = true;
            fetch('/api/maintenance/webhook-secret', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ _csrf_token: csrf() })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    webhookBtn.disabled = false;
                    if (!data.success) {
                        window.alert(data.error || 'Erreur lors de la génération du secret.');
                        return;
                    }
                    document.getElementById('webhook-secret-value').textContent = data.secret;
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
                })
                .catch(function () {
                    webhookBtn.disabled = false;
                    window.alert('Erreur réseau.');
                });
        });
    }
})();

// "Réinitialisation" section — three danger actions. The typed keyword is
// only a client-side UX gate (enabling the submit button); the real check
// always happens server-side (Core\Http\Controller\MaintenanceController).
(function () {
    function wireKeywordGate(inputId, expected, extraCondition) {
        var input = document.getElementById(inputId);
        if (!input) return null;
        var submitBtn = input.closest('form').querySelector('button[type="submit"]');
        function update() {
            var ok = input.value === expected && (!extraCondition || extraCondition());
            submitBtn.disabled = !ok;
        }
        input.addEventListener('input', update);
        return update;
    }

    // Generic poll helper for GET /api/maintenance/reset-status/{id}.
    // onDone/onFailed receive the error message (may be null); onNotFound
    // is only meaningfully different for full reset (see below), where a
    // 404 means "the operation wiped its own tracking row — that's success".
    function pollResetStatus(actionId, onDone, onFailed, onNotFound) {
        var timer = setInterval(function () {
            fetch('/api/maintenance/reset-status/' + actionId)
                .then(function (res) {
                    if (res.status === 404) {
                        clearInterval(timer);
                        onNotFound();
                        return null;
                    }
                    return res.json();
                })
                .then(function (data) {
                    if (!data) return;
                    if (data.status === 'done') {
                        clearInterval(timer);
                        onDone();
                    } else if (data.status === 'failed' || data.status === 'canceled') {
                        clearInterval(timer);
                        onFailed(data.error_message);
                    }
                    // pending / processing: keep polling.
                })
                .catch(function () {
                    // Transient network hiccup — keep polling.
                });
        }, 3000);
        return timer;
    }

    // --- Paramètres par défaut ---
    var resetSettingsUpdate = wireKeywordGate('reset-settings-keyword', 'REINITIALISER');
    var resetSettingsForm = document.getElementById('reset-settings-form');
    if (resetSettingsForm) {
        resetSettingsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = document.getElementById('reset-settings-submit');
            var progressEl = document.getElementById('reset-settings-progress');
            var errorEl = document.getElementById('reset-settings-error');
            var csrfInput = resetSettingsForm.querySelector('input[name="_csrf_token"]');
            errorEl.classList.add('d-none');
            submitBtn.disabled = true;
            progressEl.classList.remove('d-none');

            fetch('/config/maintenance/reset/settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    confirm_keyword: document.getElementById('reset-settings-keyword').value,
                    _csrf_token: csrfInput ? csrfInput.value : ''
                })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success) {
                        progressEl.classList.add('d-none');
                        errorEl.textContent = data.error || 'Erreur lors du lancement de la réinitialisation.';
                        errorEl.classList.remove('d-none');
                        if (resetSettingsUpdate) resetSettingsUpdate();
                        return;
                    }
                    pollResetStatus(
                        data.action_id,
                        function () { window.location.reload(); },
                        function (message) {
                            progressEl.classList.add('d-none');
                            errorEl.textContent = message || 'La réinitialisation a échoué.';
                            errorEl.classList.remove('d-none');
                        },
                        function () { window.location.reload(); }
                    );
                })
                .catch(function () {
                    progressEl.classList.add('d-none');
                    errorEl.textContent = 'Erreur réseau.';
                    errorEl.classList.remove('d-none');
                });
        });
    }

    // --- Réinitialisation complète ---
    var fullResetCheckbox = document.getElementById('full-reset-checkbox');
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
            var submitBtn = document.getElementById('full-reset-submit');
            var progressEl = document.getElementById('full-reset-progress');
            var errorEl = document.getElementById('full-reset-error');
            var csrfInput = fullResetForm.querySelector('input[name="_csrf_token"]');
            errorEl.classList.add('d-none');
            submitBtn.disabled = true;
            progressEl.classList.remove('d-none');

            fetch('/config/maintenance/reset/full', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    confirm_keyword: document.getElementById('full-reset-keyword').value,
                    confirm_checkbox: true,
                    _csrf_token: csrfInput ? csrfInput.value : ''
                })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success) {
                        progressEl.classList.add('d-none');
                        errorEl.textContent = data.error || 'Erreur lors du lancement de la réinitialisation.';
                        errorEl.classList.remove('d-none');
                        if (fullResetUpdate) fullResetUpdate();
                        return;
                    }
                    // A 404 here means the operation wiped scheduled_actions
                    // along with everything else — that IS success for a
                    // full reset, not an error, so onNotFound also redirects.
                    pollResetStatus(
                        data.action_id,
                        function () { window.location.href = '/'; },
                        function (message) {
                            progressEl.classList.add('d-none');
                            errorEl.textContent = message || 'La réinitialisation a échoué.';
                            errorEl.classList.remove('d-none');
                        },
                        function () { window.location.href = '/'; }
                    );
                })
                .catch(function () {
                    progressEl.classList.add('d-none');
                    errorEl.textContent = 'Erreur réseau.';
                    errorEl.classList.remove('d-none');
                });
        });
    }

    // --- Restaurer un backup ---
    var restoreUpdate = wireKeywordGate('restore-backup-keyword', 'RESTAURER');
    var sourceServerRadio = document.getElementById('restore-source-server');
    var sourceUploadRadio = document.getElementById('restore-source-upload');
    var serverPicker = document.getElementById('restore-server-picker');
    var uploadPicker = document.getElementById('restore-upload-picker');
    function toggleRestoreSource() {
        var isUpload = sourceUploadRadio && sourceUploadRadio.checked;
        if (serverPicker) serverPicker.classList.toggle('d-none', !!isUpload);
        if (uploadPicker) uploadPicker.classList.toggle('d-none', !isUpload);
    }
    if (sourceServerRadio) sourceServerRadio.addEventListener('change', toggleRestoreSource);
    if (sourceUploadRadio) sourceUploadRadio.addEventListener('change', toggleRestoreSource);

    var restoreForm = document.getElementById('restore-backup-form');
    if (restoreForm) {
        restoreForm.addEventListener('submit', function (e) {
            if (!window.confirm('Cette action va remplacer les données actuelles par celles de la sauvegarde sélectionnée. Continuer ?')) {
                e.preventDefault();
                return;
            }
            // Classic multipart submit — the server redirects back with
            // ?restore_id={id}, picked up by the polling block below.
            var submitBtn = document.getElementById('restore-backup-submit');
            submitBtn.disabled = true;
        });
    }

    // --- Mode développement (danger zone) ---
    var devModeUpdate = wireKeywordGate('dev-mode-keyword', 'DÉVELOPPEMENT');
    var devEnableForm = document.getElementById('dev-mode-enable-form');
    if (devEnableForm) {
        devEnableForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = document.getElementById('dev-mode-enable-submit');
            var errorEl = document.getElementById('dev-mode-enable-error');
            var csrfInput = devEnableForm.querySelector('input[name="_csrf_token"]');
            errorEl.classList.add('d-none');
            submitBtn.disabled = true;

            fetch('/config/maintenance/dev-mode/enable', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    branch: document.getElementById('dev-mode-branch').value,
                    confirm_keyword: document.getElementById('dev-mode-keyword').value,
                    _csrf_token: csrfInput ? csrfInput.value : ''
                })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success) {
                        errorEl.textContent = data.error || 'Erreur lors de l\'activation.';
                        errorEl.classList.remove('d-none');
                        if (devModeUpdate) devModeUpdate();
                        return;
                    }
                    window.location.reload();
                })
                .catch(function () {
                    errorEl.textContent = 'Erreur réseau.';
                    errorEl.classList.remove('d-none');
                    if (devModeUpdate) devModeUpdate();
                });
        });
    }

    var devDisableForm = document.getElementById('dev-mode-disable-form');
    if (devDisableForm) {
        devDisableForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = document.getElementById('dev-mode-disable-submit');
            var errorEl = document.getElementById('dev-mode-disable-error');
            var csrfInput = devDisableForm.querySelector('input[name="_csrf_token"]');
            errorEl.classList.add('d-none');
            submitBtn.disabled = true;

            fetch('/config/maintenance/dev-mode/disable', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ _csrf_token: csrfInput ? csrfInput.value : '' })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success) {
                        submitBtn.disabled = false;
                        errorEl.textContent = data.error || 'Erreur lors de la désactivation.';
                        errorEl.classList.remove('d-none');
                        return;
                    }
                    window.location.reload();
                })
                .catch(function () {
                    submitBtn.disabled = false;
                    errorEl.textContent = 'Erreur réseau.';
                    errorEl.classList.remove('d-none');
                });
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
