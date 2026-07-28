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
