// Configuration > Support — "Générer un paquet de support".
//
// Posts to /config/support/package, then polls
// GET /api/support/package-status/{id} via ScoutMagicApi.poll until the
// background generation finishes — the same shape as maintenance.js's
// backup polling.
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
    /** @type {{stop: () => void}|null} */
    var pollHandle = null;

    var POLL_INTERVAL_MS = 3000;
    // Ten minutes. The collectors shell out, walk the filesystem and read
    // logs, so a slow shared host legitimately takes minutes — but a poll
    // that never gives up is a button that never comes back.
    var MAX_POLL_MS = 600000;

    function stopPolling() {
        if (pollHandle) {
            pollHandle.stop();
            pollHandle = null;
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
     * Polling stops on its own, in every branch.
     *
     * Three ways this used to spin forever with the button disabled and the
     * spinner turning: the endpoint answering 404 (the scheduled action was
     * purged, or the id is stale) sends back `{error: …}` with no `status`,
     * which matched none of the branches below; a task left `pending`
     * because nothing is draining the queue never changes status at all;
     * and treating a server that is simply down like one that is thinking.
     * ScoutMagicApi.poll's maxMs/onExpire bounds every one of those: a
     * generation that has not finished within the deadline says so instead
     * — the archive may still appear, and reloading the page is exactly how
     * to find out.
     *
     * @param {number} actionId
     * @returns {void}
     */
    function pollStatus(actionId) {
        pollHandle = window.ScoutMagicApi.poll(function () {
            return window.ScoutMagicApi.getJson('/api/support/package-status/' + actionId).then(function (res) {
                if (res.status === 404) {
                    showError('Cette génération est introuvable. Rechargez la page.');
                    return false;
                }
                var data = res.data;
                if (!data) {
                    // Transient network hiccup — the next tick retries, and
                    // the deadline bounds how long it can.
                    return undefined;
                }
                if (data.status === 'done') {
                    if (data.download_url) {
                        showDownload(data.download_url);
                    } else {
                        showError('L’archive a été générée mais reste introuvable. Rechargez la page.');
                    }
                    return false;
                }
                if (data.status === 'failed' || data.status === 'canceled') {
                    showError('La génération de l’archive de support a échoué.');
                    return false;
                }
                // pending / processing: keep polling until the deadline.
                return undefined;
            });
        }, {
            intervalMs: POLL_INTERVAL_MS,
            maxMs: MAX_POLL_MS,
            onExpire: function () {
                showError('La génération prend plus de temps que prévu. Rechargez la page pour voir si l’archive est disponible.');
            }
        });
    }

    button.addEventListener('click', function () {
        if (errorEl) errorEl.classList.add('d-none');
        if (downloadEl) downloadEl.classList.add('d-none');
        if (generatedAtEl) generatedAtEl.classList.add('d-none');

        button.disabled = true;
        if (progressEl) progressEl.classList.remove('d-none');

        window.ScoutMagicApi.postJson('/config/support/package', {})
            .then(function (res) {
                if (!res.data) {
                    showError('Erreur réseau.');
                    return;
                }
                if (!res.data.success) {
                    showError(res.data.error || 'Erreur lors du lancement de la génération.');
                    return;
                }
                pollStatus(res.data.action_id);
            });
    });
})();
