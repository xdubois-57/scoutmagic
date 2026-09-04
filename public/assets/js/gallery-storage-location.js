/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Gallery module superadmin storage-location pages: the locations table on
// config.html.twig (test/delete buttons), the album storage-migration table
// on the same page, and the add/edit location form (location_form.html.twig:
// type toggle, S3 provider help panels, "Tester la connexion" AJAX check —
// same pattern the single-location config page used to own before
// multi-location support).
(function () {
    // Attribute-safe (quotes included) — several call sites below
    // interpolate into a title="..." attribute. The shared helper covers
    // exactly that (see api.js).
    var escapeHtml = window.ScoutMagicApi.escapeHtml;

    // The local postJson() copy this file carried resolved to the parsed
    // body, substituting {success:false, error:'Réponse inattendue du
    // serveur.'} when the body wasn't JSON, and REJECTED on a network
    // failure — each call site keeps its own handling for that case.
    // Requests now go through the shared window.ScoutMagicApi.postJson
    // envelope ({ok, status, data}), which never rejects; these two helpers
    // map it back to those exact semantics.
    /**
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {boolean}
     */
    function isNetworkFailure(res) {
        return res.status === 0 && res.data === null;
    }

    /**
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {Object}
     */
    function envelopeData(res) {
        return res.data === null ? { success: false, error: 'Réponse inattendue du serveur.' } : res.data;
    }

    // ------------------------------------------------------------------
    // Locations table (config.html.twig)
    // ------------------------------------------------------------------
    document.querySelectorAll('.gallery-location-test').forEach(function (btn) {
        var button = /** @type {HTMLButtonElement} */ (btn);
        button.addEventListener('click', function () {
            var cell = document.querySelector('.gallery-location-status[data-location-id="' + button.dataset.id + '"]');
            button.disabled = true;
            window.ScoutMagicApi.postJson('/config/gallery/locations/' + button.dataset.id + '/test', {}).then(function (res) {
                button.disabled = false;
                if (isNetworkFailure(res)) return;
                var data = envelopeData(res);
                if (!cell) return;
                if (data.success && data.ok) {
                    cell.innerHTML = '<span class="badge text-bg-success">Disponible</span>';
                } else if (data.success) {
                    cell.innerHTML = '<span class="badge text-bg-danger" title="' + escapeHtml(data.error || '') + '">Indisponible</span>';
                } else {
                    cell.innerHTML = '<span class="badge text-bg-danger">' + escapeHtml(data.error || 'Erreur') + '</span>';
                }
            });
        });
    });

    document.querySelectorAll('.gallery-location-set-default').forEach(function (btn) {
        var button = /** @type {HTMLButtonElement} */ (btn);
        button.addEventListener('click', function () {
            button.disabled = true;
            window.ScoutMagicApi.postJson('/config/gallery/locations/' + button.dataset.id + '/default', {}).then(function (res) {
                if (isNetworkFailure(res)) {
                    button.disabled = false;
                    return;
                }
                var data = envelopeData(res);
                if (data.success) {
                    window.location.reload();
                } else {
                    button.disabled = false;
                    window.ScoutMagicToast.show(data.error || 'Impossible de définir cet emplacement par défaut.', { variant: 'error' });
                }
            });
        });
    });

    // ------------------------------------------------------------------
    // Album storage migration (config.html.twig)
    // ------------------------------------------------------------------
    document.querySelectorAll('.gallery-migrate-start').forEach(function (btn) {
        var button = /** @type {HTMLButtonElement} */ (btn);
        button.addEventListener('click', async function () {
            var select = /** @type {HTMLSelectElement} */ (document.querySelector('.gallery-migrate-target[data-album-id="' + button.dataset.albumId + '"]'));
            if (!select) return;
            // Not destructive: the album is copied to the other location,
            // only unavailable while it moves — 'primary', not 'danger'.
            var confirmed = await window.ScoutMagicConfirm.ask({
                message: 'Démarrer la migration de cet album vers cet autre emplacement ? L\'album sera indisponible pour les membres pendant l\'opération.',
                confirmLabel: 'Migrer',
                variant: 'primary'
            });
            if (!confirmed) return;
            button.disabled = true;
            window.ScoutMagicApi.postJson(button.dataset.url, { target_location_id: Number.parseInt(select.value, 10) }).then(function (res) {
                if (isNetworkFailure(res)) {
                    button.disabled = false;
                    return;
                }
                var data = envelopeData(res);
                if (data.success) {
                    window.location.reload();
                } else {
                    button.disabled = false;
                    window.ScoutMagicToast.show(data.error || 'Erreur lors du démarrage de la migration.', { variant: 'error' });
                }
            });
        });
    });

    document.querySelectorAll('.gallery-location-delete').forEach(function (btn) {
        var button = /** @type {HTMLButtonElement} */ (btn);
        button.addEventListener('click', async function () {
            if (button.disabled) return;
            var confirmed = await window.ScoutMagicConfirm.ask({
                message: 'Supprimer cet emplacement de stockage ?',
                confirmLabel: 'Supprimer'
            });
            if (!confirmed) return;
            button.disabled = true;
            window.ScoutMagicApi.postJson('/config/gallery/locations/' + button.dataset.id + '/delete', {}).then(function (res) {
                if (isNetworkFailure(res)) {
                    button.disabled = false;
                    return;
                }
                var data = envelopeData(res);
                if (data.success) {
                    window.location.reload();
                } else {
                    button.disabled = false;
                    window.ScoutMagicToast.show(data.error || 'Suppression impossible.', { variant: 'error' });
                }
            });
        });
    });

    // ------------------------------------------------------------------
    // Add/edit location form (location_form.html.twig)
    // ------------------------------------------------------------------
    var localFields = document.querySelector('.gallery-storage-local');
    var s3Fields = document.querySelector('.gallery-storage-s3');
    var typeRadios = document.querySelectorAll('input[name="type"]');

    function syncType() {
        var checked = /** @type {HTMLInputElement} */ (document.querySelector('input[name="type"]:checked'));
        var isS3 = checked?.value === 's3';
        if (localFields) localFields.classList.toggle('d-none', isS3);
        if (s3Fields) s3Fields.classList.toggle('d-none', !isS3);
    }
    if (typeRadios.length) {
        typeRadios.forEach(function (r) { r.addEventListener('change', syncType); });
        syncType();
    }

    var providerSelect = /** @type {HTMLSelectElement} */ (document.getElementById('s3-provider'));
    function syncProviderHelp() {
        if (!providerSelect) return;
        document.querySelectorAll('[id^="s3-help-"]').forEach(function (el) {
            el.classList.toggle('d-none', el.id !== 's3-help-' + providerSelect.value);
        });
    }
    if (providerSelect) {
        providerSelect.addEventListener('change', syncProviderHelp);
        syncProviderHelp();
    }

    var testBtn = /** @type {HTMLButtonElement} */ (document.getElementById('s3-test-connection'));
    var testResult = document.getElementById('s3-test-result');
    var explainWrap = document.getElementById('s3-explain-wrap');
    var explainBtn = /** @type {HTMLButtonElement} */ (document.getElementById('s3-explain-ai'));
    var explainResult = document.getElementById('s3-explain-result');

    if (testBtn) {
        testBtn.addEventListener('click', function () {
            testBtn.disabled = true;
            testResult.innerHTML = '<div class="alert alert-info mb-0 py-2">Test en cours…</div>';
            if (explainWrap) explainWrap.classList.add('d-none');
            if (explainResult) explainResult.innerHTML = '';

            // location_id lets the server fall back to the secret already
            // stored for this location: on the edit form the secret field is
            // deliberately left blank ("laisser vide pour conserver la clé
            // actuelle"), so testing an existing location otherwise sent an
            // empty secret and could only ever fail on authentication.
            window.ScoutMagicApi.postJson('/config/gallery/test-connection', {
                location_id: Number.parseInt(testBtn.dataset.locationId || '0', 10) || 0,
                endpoint: /** @type {HTMLInputElement} */ (document.getElementById('s3-endpoint')).value,
                region: /** @type {HTMLInputElement} */ (document.getElementById('s3-region')).value,
                bucket: /** @type {HTMLInputElement} */ (document.getElementById('s3-bucket')).value,
                access_key: /** @type {HTMLInputElement} */ (document.getElementById('s3-access-key')).value,
                secret_key: /** @type {HTMLInputElement} */ (document.getElementById('s3-secret-key')).value
            }).then(function (res) {
                testBtn.disabled = false;
                if (isNetworkFailure(res)) {
                    testResult.innerHTML = '<div class="alert alert-danger mb-0 py-2">Erreur réseau.</div>';
                    return;
                }
                var data = envelopeData(res);
                if (data.success) {
                    testResult.innerHTML = '<div class="alert alert-success mb-0 py-2">Connexion réussie.</div>';
                } else {
                    testResult.innerHTML = '<div class="alert alert-danger mb-0 py-2">' + escapeHtml(data.error || 'Échec de la connexion.') + '</div>';
                    if (explainWrap) explainWrap.classList.remove('d-none');
                }
            });
        });
    }

    if (explainBtn) {
        explainBtn.addEventListener('click', function () {
            explainBtn.disabled = true;
            explainResult.innerHTML = '<div class="alert alert-info mb-0 py-2">Analyse en cours…</div>';

            var secretKey = /** @type {HTMLInputElement} */ (document.getElementById('s3-secret-key')).value;

            window.ScoutMagicApi.postJson('/config/gallery/explain-s3-error', {
                provider: /** @type {HTMLSelectElement} */ (document.getElementById('s3-provider')).value,
                endpoint: /** @type {HTMLInputElement} */ (document.getElementById('s3-endpoint')).value,
                region: /** @type {HTMLInputElement} */ (document.getElementById('s3-region')).value,
                bucket: /** @type {HTMLInputElement} */ (document.getElementById('s3-bucket')).value,
                access_key: /** @type {HTMLInputElement} */ (document.getElementById('s3-access-key')).value,
                secret_key_length: secretKey.length
                // The error itself is deliberately NOT sent. The browser
                // only ever had the French summary, which says « vérifiez
                // vos identifiants » for half a dozen distinct mistakes;
                // the provider's own words are what diagnose it, and they
                // stay server-side (Service\S3TestFailure) rather than
                // being handed to the page and handed back.
            }).then(function (res) {
                explainBtn.disabled = false;
                if (isNetworkFailure(res)) {
                    explainResult.innerHTML = '<div class="alert alert-danger mb-0 py-2">Erreur réseau.</div>';
                    return;
                }
                var data = envelopeData(res);
                if (data.success) {
                    explainResult.innerHTML = '<div class="alert alert-light border mb-0 py-2">' + escapeHtml(data.explanation).replaceAll('\n', '<br>') + '</div>';
                } else {
                    explainResult.innerHTML = '<div class="alert alert-danger mb-0 py-2">' + escapeHtml(data.error || 'Échec de l\'analyse.') + '</div>';
                }
            });
        });
    }
})();
