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
    var dbNotEmptyWarning = document.getElementById('db-not-empty-warning');
    var dbNotEmptyCount = document.getElementById('db-not-empty-count');
    var btnBackupEmptyDb = document.getElementById('btn-backup-empty-db');
    var backupEmptySpinner = document.getElementById('backup-empty-spinner');
    var backupEmptyResult = document.getElementById('backup-empty-result');

    // Copy-to-clipboard for the DKIM key and DNS record values. Delegated
    // on document (not inline onclick="" attributes) because this app's
    // CSP has no 'unsafe-inline' in script-src \u2014 inline event handler
    // attributes are silently blocked by the browser, which is exactly
    // why these buttons previously did nothing. navigator.clipboard also
    // requires a secure context (HTTPS), so a first-time HTTP install
    // falls back to the older select()+execCommand('copy') approach,
    // which works everywhere the async API doesn't.
    function copyValue(button) {
        var input = button.dataset.copyTarget ? document.getElementById(button.dataset.copyTarget) : button.previousElementSibling;
        if (!input) {
            return;
        }

        var originalLabel = button.textContent;
        function showCopied() {
            button.textContent = 'Copi\u00e9 !';
            setTimeout(function() { button.textContent = originalLabel; }, 1500);
        }
        function showFailed() {
            button.textContent = '\u00c9chec';
            setTimeout(function() { button.textContent = originalLabel; }, 1500);
        }
        function fallbackCopy() {
            input.select();
            input.setSelectionRange(0, 99999);
            try {
                if (document.execCommand('copy')) {
                    showCopied();
                } else {
                    showFailed();
                }
            } catch (e) {
                showFailed();
            }
        }

        if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(showCopied).catch(fallbackCopy);
        } else {
            fallbackCopy();
        }
    }

    document.addEventListener('click', function(event) {
        if (event.target.classList && event.target.classList.contains('btn-copy-value')) {
            copyValue(event.target);
        }
    });

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
        data.append('_csrf_token', form.elements['_csrf_token'].value);
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

                    if (json.has_existing_tables) {
                        // Proceeding straight to install here would generate
                        // a fresh encryption key against these old rows \u2014
                        // guaranteed DecryptionException down the line.
                        // Block Save until the operator explicitly backs up
                        // and empties this database (or picks another one).
                        dbTestPassed = false;
                        btnSave.disabled = true;
                        saveHint.style.display = 'block';
                        if (dbNotEmptyWarning) {
                            dbNotEmptyCount.textContent = json.table_count + (json.table_count > 1 ? ' tables' : ' table');
                            dbNotEmptyWarning.classList.remove('d-none');
                            backupEmptyResult.textContent = '';
                        }
                    } else {
                        dbTestPassed = true;
                        btnSave.disabled = false;
                        saveHint.style.display = 'none';
                        if (dbNotEmptyWarning) { dbNotEmptyWarning.classList.add('d-none'); }
                    }
                } else {
                    dbResult.innerHTML = '<span class="text-danger">\u2717 ' + json.message + '</span>';
                    dbTestPassed = false;
                    btnSave.disabled = true;
                    saveHint.style.display = 'block';
                    if (dbNotEmptyWarning) { dbNotEmptyWarning.classList.add('d-none'); }
                }
            })
            .catch(function() {
                dbSpinner.classList.add('d-none');
                dbResult.innerHTML = '<span class="text-danger">\u2717 Erreur r\u00e9seau</span>';
            });
    });

    if (btnBackupEmptyDb) {
        btnBackupEmptyDb.addEventListener('click', function() {
            btnBackupEmptyDb.disabled = true;
            backupEmptySpinner.classList.remove('d-none');
            backupEmptyResult.textContent = '';

            var data = new FormData();
            data.append('_csrf_token', form.elements['_csrf_token'].value);
            data.append('db_host', document.getElementById('db_host').value);
            data.append('db_port', document.getElementById('db_port').value);
            data.append('db_name', document.getElementById('db_name').value);
            data.append('db_user', document.getElementById('db_user').value);
            data.append('db_password', document.getElementById('db_password').value);

            fetch('/setup/backup-and-empty-db', { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    backupEmptySpinner.classList.add('d-none');
                    if (!json.success) {
                        backupEmptyResult.innerHTML = '<span class="text-danger">\u2717 ' + json.message + '</span>';
                        btnBackupEmptyDb.disabled = false;
                        return;
                    }
                    // Trigger the actual file download as a real navigation
                    // (not part of this fetch) \u2014 the server already
                    // deletes the dump on first read, so this must succeed
                    // before the operator navigates away.
                    window.location.href = json.download_url;
                    backupEmptyResult.innerHTML = '<span class="text-success">\u2713 Sauvegarde t\u00e9l\u00e9charg\u00e9e, base vid\u00e9e (' + json.table_count + ' table' + (json.table_count > 1 ? 's' : '') + '). Vous pouvez poursuivre l\u2019installation.</span>';
                    dbNotEmptyWarning.classList.add('d-none');
                    dbTestPassed = true;
                    btnSave.disabled = false;
                    saveHint.style.display = 'none';
                })
                .catch(function() {
                    backupEmptySpinner.classList.add('d-none');
                    backupEmptyResult.innerHTML = '<span class="text-danger">\u2717 Erreur r\u00e9seau</span>';
                    btnBackupEmptyDb.disabled = false;
                });
        });
    }

    // Test email
    var btnTestEmail = document.getElementById('btn-test-email');
    var emailSpinner = document.getElementById('email-spinner');
    var emailResult = document.getElementById('email-test-result');

    if (btnTestEmail) {
        btnTestEmail.addEventListener('click', function() {
            var recipient = document.getElementById('test_email_recipient').value.trim();
            if (!recipient) {
                emailResult.innerHTML = '<span class="text-danger">\u2717 Veuillez entrer une adresse email.</span>';
                return;
            }
            emailSpinner.classList.remove('d-none');
            emailResult.textContent = '';

            var data = new FormData();
            data.append('_csrf_token', form.elements['_csrf_token'].value);
            data.append('recipient', recipient);
            // Needed for the not-yet-initialized case, where testEmail()
            // reads mail settings straight from the request body instead
            // of persisted secrets (harmless extra fields once
            // initialized \u2014 that path reads from secrets.enc instead).
            data.append('mail_mode', mailMode.value);
            data.append('smtp_host', document.getElementById('smtp_host').value);
            data.append('smtp_port', document.getElementById('smtp_port').value);
            data.append('smtp_user', document.getElementById('smtp_user').value);
            data.append('smtp_password', document.getElementById('smtp_password').value);
            data.append('mail_from_address', document.getElementById('mail_from_address').value);
            data.append('mail_from_name', document.getElementById('mail_from_name').value);
            data.append('short_name', document.getElementById('short_name').value);
            data.append('dkim_selector', document.getElementById('dkim_selector').value);

            fetch('/setup/test-email', { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    emailSpinner.classList.add('d-none');
                    if (json.success) {
                        emailResult.innerHTML = '<span class="text-success">\u2713 ' + json.message + '</span>';
                    } else {
                        emailResult.innerHTML = '<span class="text-danger">\u2717 ' + json.message + '</span>';
                    }
                })
                .catch(function() {
                    emailSpinner.classList.add('d-none');
                    emailResult.innerHTML = '<span class="text-danger">\u2717 Erreur r\u00e9seau</span>';
                });
        });
    }

    // Generate DKIM key on demand (before the rest of setup is finished)
    var btnGenerateDkim = document.getElementById('btn-generate-dkim');
    if (btnGenerateDkim) {
        var dkimGenSpinner = document.getElementById('dkim-gen-spinner');
        var dkimGenResult = document.getElementById('dkim-gen-result');

        btnGenerateDkim.addEventListener('click', function() {
            dkimGenSpinner.classList.remove('d-none');
            dkimGenResult.textContent = '';

            var data = new FormData();
            data.append('_csrf_token', form.elements['_csrf_token'].value);

            fetch('/setup/generate-dkim-key', { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    dkimGenSpinner.classList.add('d-none');
                    if (!json.success) {
                        dkimGenResult.innerHTML = '<span class="text-danger">\u2717 ' + json.message + '</span>';
                        return;
                    }
                    // Rebuilt in place (no location.reload()) so the rest
                    // of the \u2014 possibly already partly filled \u2014 form isn't
                    // lost just because the DKIM key got generated early.
                    var selector = document.getElementById('dkim_selector').value
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    var html = '<p class="mb-2 small">Cl\u00e9 DKIM g\u00e9n\u00e9r\u00e9e. S\u00e9lecteur : <strong>' + selector + '</strong>.</p>';
                    html += '<div class="mb-2">';
                    html += '<small class="text-muted">Cl\u00e9 publique (pour l\'enregistrement DNS) :</small>';
                    html += '<div class="input-group input-group-sm mt-1">';
                    html += '<input type="text" class="form-control font-monospace" value="' + json.public_key + '" readonly id="dkim-pubkey-display">';
                    html += '<button type="button" class="btn btn-outline-secondary btn-copy-value" data-copy-target="dkim-pubkey-display">Copier</button>';
                    html += '</div>';
                    html += '</div>';
                    document.getElementById('dkim-key-section').innerHTML = html;
                })
                .catch(function() {
                    dkimGenSpinner.classList.add('d-none');
                    dkimGenResult.innerHTML = '<span class="text-danger">\u2717 Erreur r\u00e9seau</span>';
                });
        });
    }

    // Check DNS
    btnCheckDns.addEventListener('click', function() {
        dnsSpinner.classList.remove('d-none');

        var fromAddress = document.getElementById('mail_from_address').value;
        var domain = fromAddress.indexOf('@') !== -1 ? fromAddress.split('@')[1] : '';
        var selector = document.getElementById('dkim_selector').value;
        var mode = mailMode.value;
        var smtpHost = document.getElementById('smtp_host').value;
        var dmarcFieldValue = document.getElementById('dmarc_report_email').value.trim();
        var dmarcEmail = dmarcFieldValue || fromAddress;

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
                // "name" is the record name relative to the zone root, as
                // most DNS provider UIs expect it (they already scope
                // everything to the domain you're editing) \u2014 "host" is
                // the full name, kept as a fallback for providers that want
                // it spelled out completely instead.
                var records = [
                    { key: 'spf', label: 'SPF', name: '@', host: domain, type: 'TXT' },
                    { key: 'dkim', label: 'DKIM', name: selector + '._domainkey', host: selector + '._domainkey.' + domain, type: 'TXT' },
                    { key: 'dmarc', label: 'DMARC', name: '_dmarc', host: '_dmarc.' + domain, type: 'TXT' }
                ];
                var html = '<p class="text-muted small mb-2">Chez la plupart des h\u00e9bergeurs, la zone DNS demande un <strong>Type</strong>, un <strong>Nom</strong> (parfois appel\u00e9 \u00ab\u00a0Enregistrement\u00a0\u00bb ou \u00ab\u00a0H\u00f4te\u00a0\u00bb) et une <strong>Valeur</strong>. Utilisez le nom court ci-dessous (\u00ab\u00a0@\u00a0\u00bb d\u00e9signe la racine du domaine)\u00a0; si l\'interface de votre h\u00e9bergeur demande le nom complet plut\u00f4t que le nom court, utilisez celui indiqu\u00e9 en bas de chaque bloc.</p>';
                records.forEach(function(rec) {
                    var data = json[rec.key];
                    html += '<div class="mb-3 small border-bottom pb-2">';

                    if (data.key_missing) {
                        html += '<strong>' + rec.label + '</strong> <span class="badge bg-warning text-dark">Cl\u00e9 DKIM requise</span><br>';
                        html += '<span class="text-warning">G\u00e9n\u00e9rez d\'abord la cl\u00e9 DKIM (section \u00ab\u00a0Cl\u00e9 DKIM\u00a0\u00bb ci-dessus), puis relancez cette v\u00e9rification.</span>';
                        html += '</div>';
                        return;
                    }

                    if (rec.key === 'dmarc' && !dmarcFieldValue) {
                        // No report address was provided: this app doesn't
                        // require an rua= tag for anything to work, so
                        // there's nothing to suggest changing \u2014 don't push
                        // an edit the operator didn't ask for.
                        html += '<strong>' + rec.label + '</strong> <span class="badge bg-secondary">Optionnel</span><br>';
                        html += '<span class="text-muted">Aucune adresse de rapport DMARC renseign\u00e9e \u2014 aucune modification de votre DNS n\'est n\u00e9cessaire pour cela. Renseignez une adresse dans le champ \u00ab\u00a0Email rapports DMARC\u00a0\u00bb ci-dessus si vous souhaitez recevoir des rapports.</span>';
                        if (data.actual) {
                            html += '<br><span class="text-muted">Enregistrement DMARC actuel :</span> <code class="text-break">' + data.actual + '</code>';
                        }
                        html += '</div>';
                        return;
                    }

                    var badge = data.exists
                        ? '<span class="badge bg-success">OK</span>'
                        : '<span class="badge bg-warning text-dark">Manquant</span>';
                    var expectedLabel = data.actual ? 'Valeur sugg\u00e9r\u00e9e (remplace l\'actuelle)' : 'Valeur attendue';
                    html += '<strong>' + rec.label + '</strong> ' + badge + '<br>';
                    html += '<span class="text-muted">Type :</span> <code>' + rec.type + '</code><br>';
                    html += '<span class="text-muted">Nom :</span> ';
                    html += '<div class="input-group input-group-sm mt-1 mb-1">';
                    html += '<input type="text" class="form-control form-control-sm font-monospace" value="' + rec.name + '" readonly>';
                    html += '<button type="button" class="btn btn-outline-secondary btn-sm btn-copy-value">Copier</button>';
                    html += '</div>';
                    html += '<span class="text-muted">' + expectedLabel + ' :</span> ';
                    html += '<div class="input-group input-group-sm mt-1 mb-1">';
                    html += '<input type="text" class="form-control form-control-sm font-monospace" value="' + data.expected.replace(/"/g, '&quot;') + '" readonly>';
                    html += '<button type="button" class="btn btn-outline-secondary btn-sm btn-copy-value">Copier</button>';
                    html += '</div>';
                    if (data.actual) {
                        html += '<span class="text-muted">Valeur actuelle :</span> <code class="text-break">' + data.actual + '</code><br>';
                    }
                    html += '<span class="text-muted">Nom complet (si votre h\u00e9bergeur le demande \u00e0 la place du nom court) :</span> <code class="text-break">' + rec.host + '</code>';
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
