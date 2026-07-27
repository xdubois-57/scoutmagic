// Gallery module superadmin config page (config.html.twig): storage-backend
// toggle, S3 provider help panels, "Tester la connexion" AJAX check.
(function () {
    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    var localFields = document.querySelector('.gallery-storage-local');
    var s3Fields = document.querySelector('.gallery-storage-s3');
    var backendRadios = document.querySelectorAll('input[name="gallery_storage_backend"]');

    function syncBackend() {
        var checked = document.querySelector('input[name="gallery_storage_backend"]:checked');
        var isS3 = checked && checked.value === 's3';
        if (localFields) localFields.classList.toggle('d-none', isS3);
        if (s3Fields) s3Fields.classList.toggle('d-none', !isS3);
    }
    backendRadios.forEach(function (r) { r.addEventListener('change', syncBackend); });
    syncBackend();

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
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            testBtn.disabled = true;
            testResult.innerHTML = '<div class="alert alert-info mb-0 py-2">Test en cours…</div>';

            fetch('/config/gallery/test-connection', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    endpoint: document.getElementById('s3-endpoint').value,
                    region: document.getElementById('s3-region').value,
                    bucket: document.getElementById('s3-bucket').value,
                    access_key: document.getElementById('s3-access-key').value,
                    secret_key: document.getElementById('s3-secret-key').value,
                    _csrf_token: csrf()
                })
            }).then(function (res) { return res.json(); }).then(function (data) {
                testBtn.disabled = false;
                if (data.success) {
                    testResult.innerHTML = '<div class="alert alert-success mb-0 py-2">Connexion réussie.</div>';
                } else {
                    testResult.innerHTML = '<div class="alert alert-danger mb-0 py-2">' + (data.error || 'Échec de la connexion.') + '</div>';
                }
            }).catch(function () {
                testBtn.disabled = false;
                testResult.innerHTML = '<div class="alert alert-danger mb-0 py-2">Erreur réseau.</div>';
            });
        });
    }
})();
