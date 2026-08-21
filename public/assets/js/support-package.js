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

    var POLL_INTERVAL_MS = 3000;
    // Ten minutes. The collectors shell out, walk the filesystem and read
    // logs, so a slow shared host legitimately takes minutes — but a poll
    // that never gives up is a button that never comes back.
    var MAX_POLL_MS = 600000;

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
     * Polling stops on its own, in every branch.
     *
     * Three ways this used to spin forever with the button disabled and the
     * spinner turning: the endpoint answering 404 (the scheduled action was
     * purged, or the id is stale) sends back `{error: …}` with no `status`,
     * which matched none of the branches below; a task left `pending`
     * because nothing is draining the queue never changes status at all;
     * and a `catch` that swallows everything means a server that is simply
     * down looks identical to one that is thinking. A generation that has
     * not finished within the deadline says so instead — the archive may
     * still appear, and reloading the page is exactly how to find out.
     *
     * @param {number} actionId
     * @returns {void}
     */
    function pollStatus(actionId) {
        var deadline = Date.now() + MAX_POLL_MS;

        pollTimer = window.setInterval(function () {
            if (Date.now() > deadline) {
                showError('La génération prend plus de temps que prévu. Rechargez la page pour voir si l’archive est disponible.');
                return;
            }

            fetch('/api/support/package-status/' + actionId)
                .then(function (res) {
                    if (res.status === 404) {
                        throw new Error('gone');
                    }
                    return res.json();
                })
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
                    // pending / processing: keep polling until the deadline.
                })
                .catch(function (error) {
                    if (error && error.message === 'gone') {
                        showError('Cette génération est introuvable. Rechargez la page.');
                    }
                    // Anything else is a transient network hiccup — the next
                    // tick retries, and the deadline bounds how long it can.
                });
        }, POLL_INTERVAL_MS);
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
