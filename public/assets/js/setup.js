(function() {
    var form = document.getElementById('setup-form');
    var mailMode = document.getElementById('mail_mode');
    var smtpFields = document.getElementById('smtp-fields');
    var btnTestDb = document.getElementById('btn-test-db');
    var btnSave = document.getElementById('btn-save');
    var saveHint = document.getElementById('save-hint');
    var dbSpinner = document.getElementById('db-spinner');
    var dbResult = document.getElementById('db-test-result');
    var btnCheckDns = document.getElementById('btn-check-dns');
    var dnsSpinner = document.getElementById('dns-spinner');
    var dnsRecords = document.getElementById('dns-records');

    // Toggle SMTP fields visibility
    function toggleSmtp() {
        smtpFields.style.display = mailMode.value === 'smtp' ? 'block' : 'none';
    }
    mailMode.addEventListener('change', toggleSmtp);
    toggleSmtp();

    // Test DB connection
    var dbTestPassed = form.dataset.initialized === '1';
    if (dbTestPassed) {
        btnSave.disabled = false;
        saveHint.style.display = 'none';
    }

    btnTestDb.addEventListener('click', function() {
        dbSpinner.classList.remove('d-none');
        dbResult.textContent = '';

        var data = new FormData();
        data.append('db_host', document.getElementById('db_host').value);
        data.append('db_port', document.getElementById('db_port').value);
        data.append('db_name', document.getElementById('db_name').value);
        data.append('db_user', document.getElementById('db_user').value);
        data.append('db_password', document.getElementById('db_password').value);

        fetch('/setup/test-db', { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                dbSpinner.classList.add('d-none');
                if (json.success) {
                    dbResult.innerHTML = '<span class="text-success">\u2713 Connexion r\u00e9ussie</span>';
                    dbTestPassed = true;
                    btnSave.disabled = false;
                    saveHint.style.display = 'none';
                } else {
                    dbResult.innerHTML = '<span class="text-danger">\u2717 ' + json.message + '</span>';
                    dbTestPassed = false;
                    btnSave.disabled = true;
                    saveHint.style.display = 'block';
                }
            })
            .catch(function() {
                dbSpinner.classList.add('d-none');
                dbResult.innerHTML = '<span class="text-danger">\u2717 Erreur r\u00e9seau</span>';
            });
    });

    // Check DNS
    btnCheckDns.addEventListener('click', function() {
        dnsSpinner.classList.remove('d-none');

        var fromAddress = document.getElementById('mail_from_address').value;
        var domain = fromAddress.indexOf('@') !== -1 ? fromAddress.split('@')[1] : '';
        var selector = document.getElementById('dkim_selector').value;
        var mode = mailMode.value;
        var smtpHost = document.getElementById('smtp_host').value;
        var dmarcEmail = document.getElementById('dmarc_report_email').value;

        if (!domain || !selector) {
            dnsSpinner.classList.add('d-none');
            dnsRecords.innerHTML = '<p class="text-warning small">Veuillez remplir l\'adresse d\'exp\u00e9dition et le s\u00e9lecteur DKIM.</p>';
            return;
        }

        var params = new URLSearchParams({ domain: domain, selector: selector, mode: mode, smtp_host: smtpHost, dmarc_email: dmarcEmail });
        fetch('/setup/dns?' + params.toString())
            .then(function(r) { return r.json(); })
            .then(function(json) {
                dnsSpinner.classList.add('d-none');
                var html = '';
                var records = [
                    { key: 'spf', label: 'SPF', host: domain, type: 'TXT' },
                    { key: 'dkim', label: 'DKIM', host: selector + '._domainkey.' + domain, type: 'TXT' },
                    { key: 'dmarc', label: 'DMARC', host: '_dmarc.' + domain, type: 'TXT' }
                ];
                records.forEach(function(rec) {
                    var data = json[rec.key];
                    var badge = data.exists
                        ? '<span class="badge bg-success">OK</span>'
                        : '<span class="badge bg-warning text-dark">Manquant</span>';
                    html += '<div class="mb-2 small">';
                    html += '<strong>' + rec.label + '</strong> ' + badge + '<br>';
                    html += '<span class="text-muted">H\u00f4te :</span> <code>' + rec.host + '</code><br>';
                    html += '<span class="text-muted">Valeur attendue :</span> ';
                    html += '<div class="input-group input-group-sm mt-1 mb-1">';
                    html += '<input type="text" class="form-control form-control-sm font-monospace" value="' + data.expected.replace(/"/g, '&quot;') + '" readonly>';
                    html += '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">Copier</button>';
                    html += '</div>';
                    if (data.actual) {
                        html += '<span class="text-muted">Valeur actuelle :</span> <code class="text-break">' + data.actual + '</code>';
                    }
                    html += '</div>';
                });
                dnsRecords.innerHTML = html;
            })
            .catch(function() {
                dnsSpinner.classList.add('d-none');
                dnsRecords.innerHTML = '<p class="text-danger small">Erreur lors de la v\u00e9rification DNS.</p>';
            });
    });
})();
