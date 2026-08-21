// Configuration > Support — "Générer un paquet de support".
//
// Posts to /config/support/package, then polls
// GET /api/support/package-status/{id} until the background generation
// finishes — the same setInterval/clearInterval shape as maintenance.js's
// backup polling and auth.js's magic-link polling.
//
// Nothing here decides anything: the archive is built, stored encrypted and
// access-controlled entirely server-side. This script only reflects progress
// and reveals the download link, which points at /files/{id} like every
// other download in the application.
(function () {
    var button = /** @type {HTMLButtonElement|null} */ (document.getElementById('support-package-generate'));
    if (!button) return;

    var progressEl = document.getElementById('support-package-progress');
    var errorEl = document.getElementById('support-package-error');
    var downloadEl = /** @type {HTMLAnchorElement|null} */ (document.getElementById('support-package-download'));
    var generatedAtEl = document.getElementById('support-package-generated-at');
    var pollTimer = null;

    function csrf() {
        var meta = /** @type {HTMLMetaElement|null} */ (document.querySelector('meta[name="csrf-token"]'));
        return meta ? meta.content : '';
    }

    function stopPolling() {
        if (pollTimer) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    /**
     * @param {string} message
     * @returns {void}
     */
    function showError(message) {
        stopPolling();
        button.disabled = false;
        if (progressEl) progressEl.classList.add('d-none');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.classList.remove('d-none');
        }
    }

    /**
     * @param {string} downloadUrl
     * @returns {void}
     */
    function showDownload(downloadUrl) {
        stopPolling();
        button.disabled = false;
        if (progressEl) progressEl.classList.add('d-none');
        if (downloadEl) {
            downloadEl.href = downloadUrl;
            downloadEl.classList.remove('d-none');
        }
        if (generatedAtEl) {
            generatedAtEl.textContent = 'Archive disponible : elle vient d’être générée.';
            generatedAtEl.classList.remove('d-none');
        }
    }

    /**
     * @param {number} actionId
     * @returns {void}
     */
    function pollStatus(actionId) {
        pollTimer = window.setInterval(function () {
            fetch('/api/support/package-status/' + actionId)
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status === 'done') {
                        if (data.download_url) {
                            showDownload(data.download_url);
                        } else {
                            showError('L’archive a été générée mais reste introuvable. Rechargez la page.');
                        }
                    } else if (data.status === 'failed' || data.status === 'canceled') {
                        showError('La génération de l’archive de support a échoué.');
                    }
                    // pending / processing: keep polling.
                })
                .catch(function () {
                    // Transient network hiccup — the next tick will retry.
                });
        }, 3000);
    }

    button.addEventListener('click', function () {
        if (errorEl) errorEl.classList.add('d-none');
        if (downloadEl) downloadEl.classList.add('d-none');
        if (generatedAtEl) generatedAtEl.classList.add('d-none');

        button.disabled = true;
        if (progressEl) progressEl.classList.remove('d-none');

        fetch('/config/support/package', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ _csrf_token: csrf() })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) {
                    showError(data.error || 'Erreur lors du lancement de la génération.');
                    return;
                }
                pollStatus(data.action_id);
            })
            .catch(function () {
                showError('Erreur réseau.');
            });
    });
})();
