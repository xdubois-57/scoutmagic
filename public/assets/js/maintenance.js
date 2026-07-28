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
