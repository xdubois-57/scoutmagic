// Gallery module superadmin storage-location pages: the locations table on
// config.html.twig (test/delete buttons), the album storage-migration table
// on the same page, and the add/edit location form (location_form.html.twig:
// type toggle, S3 provider help panels, "Tester la connexion" AJAX check —
// same pattern the single-location config page used to own before
// multi-location support).
(function () {
    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({}, body, { _csrf_token: csrf() }))
        }).then(function (res) { return res.json(); });
    }

    // ------------------------------------------------------------------
    // Locations table (config.html.twig)
    // ------------------------------------------------------------------
    document.querySelectorAll('.gallery-location-test').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var cell = document.querySelector('.gallery-location-status[data-location-id="' + btn.dataset.id + '"]');
            btn.disabled = true;
            postJson('/config/gallery/locations/' + btn.dataset.id + '/test', {}).then(function (data) {
                btn.disabled = false;
                if (!cell) return;
                if (data.success && data.ok) {
                    cell.innerHTML = '<span class="badge text-bg-success">Disponible</span>';
                } else if (data.success) {
                    cell.innerHTML = '<span class="badge text-bg-danger" title="' + escapeHtml(data.error || '') + '">Indisponible</span>';
                } else {
                    cell.innerHTML = '<span class="badge text-bg-danger">' + escapeHtml(data.error || 'Erreur') + '</span>';
                }
            }).catch(function () {
                btn.disabled = false;
            });
        });
    });

    document.querySelectorAll('.gallery-location-set-default').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            postJson('/config/gallery/locations/' + btn.dataset.id + '/default', {}).then(function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    btn.disabled = false;
                    alert(data.error || 'Impossible de définir cet emplacement par défaut.');
                }
            }).catch(function () {
                btn.disabled = false;
            });
        });
    });

    // ------------------------------------------------------------------
    // Album storage migration (config.html.twig)
    // ------------------------------------------------------------------
    document.querySelectorAll('.gallery-migrate-start').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var select = document.querySelector('.gallery-migrate-target[data-album-id="' + btn.dataset.albumId + '"]');
            if (!select) return;
            if (!confirm('Démarrer la migration de cet album vers cet autre emplacement ? L\'album sera indisponible pour les membres pendant l\'opération.')) return;
            btn.disabled = true;
            postJson(btn.dataset.url, { target_location_id: parseInt(select.value, 10) }).then(function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    btn.disabled = false;
                    alert(data.error || 'Erreur lors du démarrage de la migration.');
                }
            }).catch(function () {
                btn.disabled = false;
            });
        });
    });

    document.querySelectorAll('.gallery-location-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.disabled) return;
            if (!confirm('Supprimer cet emplacement de stockage ?')) return;
            btn.disabled = true;
            postJson('/config/gallery/locations/' + btn.dataset.id + '/delete', {}).then(function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    btn.disabled = false;
                    alert(data.error || 'Suppression impossible.');
                }
            }).catch(function () {
                btn.disabled = false;
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
        var checked = document.querySelector('input[name="type"]:checked');
        var isS3 = !!checked && checked.value === 's3';
        if (localFields) localFields.classList.toggle('d-none', isS3);
        if (s3Fields) s3Fields.classList.toggle('d-none', !isS3);
    }
    if (typeRadios.length) {
        typeRadios.forEach(function (r) { r.addEventListener('change', syncType); });
        syncType();
    }

    var providerSelect = document.getElementById('s3-provider');
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

    var testBtn = document.getElementById('s3-test-connection');
    var testResult = document.getElementById('s3-test-result');
    var explainWrap = document.getElementById('s3-explain-wrap');
    var explainBtn = document.getElementById('s3-explain-ai');
    var explainResult = document.getElementById('s3-explain-result');
    var lastError = '';

    if (testBtn) {
        testBtn.addEventListener('click', function () {
            testBtn.disabled = true;
            testResult.innerHTML = '<div class="alert alert-info mb-0 py-2">Test en cours…</div>';
            if (explainWrap) explainWrap.classList.add('d-none');
            if (explainResult) explainResult.innerHTML = '';

            postJson('/config/gallery/test-connection', {
                endpoint: document.getElementById('s3-endpoint').value,
                region: document.getElementById('s3-region').value,
                bucket: document.getElementById('s3-bucket').value,
                access_key: document.getElementById('s3-access-key').value,
                secret_key: document.getElementById('s3-secret-key').value
            }).then(function (data) {
                testBtn.disabled = false;
                if (data.success) {
                    testResult.innerHTML = '<div class="alert alert-success mb-0 py-2">Connexion réussie.</div>';
                } else {
                    testResult.innerHTML = '<div class="alert alert-danger mb-0 py-2">' + escapeHtml(data.error || 'Échec de la connexion.') + '</div>';
                    lastError = data.error || 'Échec de la connexion.';
                    if (explainWrap) explainWrap.classList.remove('d-none');
                }
            }).catch(function () {
                testBtn.disabled = false;
                testResult.innerHTML = '<div class="alert alert-danger mb-0 py-2">Erreur réseau.</div>';
            });
        });
    }

    if (explainBtn) {
        explainBtn.addEventListener('click', function () {
            explainBtn.disabled = true;
            explainResult.innerHTML = '<div class="alert alert-info mb-0 py-2">Analyse en cours…</div>';

            var secretKey = document.getElementById('s3-secret-key').value;

            postJson('/config/gallery/explain-s3-error', {
                provider: document.getElementById('s3-provider').value,
                endpoint: document.getElementById('s3-endpoint').value,
                region: document.getElementById('s3-region').value,
                bucket: document.getElementById('s3-bucket').value,
                access_key: document.getElementById('s3-access-key').value,
                secret_key_length: secretKey.length,
                error: lastError
            }).then(function (data) {
                explainBtn.disabled = false;
                if (data.success) {
                    explainResult.innerHTML = '<div class="alert alert-light border mb-0 py-2">' + escapeHtml(data.explanation).replace(/\n/g, '<br>') + '</div>';
                } else {
                    explainResult.innerHTML = '<div class="alert alert-danger mb-0 py-2">' + escapeHtml(data.error || 'Échec de l\'analyse.') + '</div>';
                }
            }).catch(function () {
                explainBtn.disabled = false;
                explainResult.innerHTML = '<div class="alert alert-danger mb-0 py-2">Erreur réseau.</div>';
            });
        });
    }
})();
