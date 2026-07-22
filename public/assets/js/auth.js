(function() {
    // --- Tab switching ---
    var tabs = document.querySelectorAll('[data-tab]');
    var tabContents = {
        'magic-link': document.getElementById('tab-magic-link'),
        'password': document.getElementById('tab-password'),
        'passkey': document.getElementById('tab-passkey')
    };

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            var target = tab.getAttribute('data-tab');
            tabs.forEach(function(t) { t.classList.remove('active'); });
            tab.classList.add('active');
            Object.keys(tabContents).forEach(function(key) {
                if (tabContents[key]) {
                    tabContents[key].classList.toggle('d-none', key !== target);
                }
            });
        });
    });

    function getCsrf() {
        return document.getElementById('csrf-token').value;
    }

    // --- Mandatory RGPD consent (module addendum) — one checkbox per tab, each inside its own login box. ---
    function hasRgpdConsent(tab) {
        var checkbox = document.getElementById('rgpd-consent-checkbox-' + tab);
        var error = document.getElementById('rgpd-consent-error-' + tab);
        var ok = checkbox.checked;
        error.classList.toggle('d-none', ok);
        return ok;
    }

    // --- Magic Link ---
    var stateEmail = document.getElementById('state-email');
    var stateWaiting = document.getElementById('state-waiting');
    var stateConfirmed = document.getElementById('state-confirmed');
    var sendBtn = document.getElementById('send-magic-link');
    var backBtn = document.getElementById('back-btn');
    var emailInput = document.getElementById('email');
    var emailError = document.getElementById('email-error');
    var sentEmailSpan = document.getElementById('sent-email');
    var pollingInterval = null;

    function showState(state) {
        stateEmail.classList.add('d-none');
        stateWaiting.classList.add('d-none');
        stateConfirmed.classList.add('d-none');
        state.classList.remove('d-none');
    }

    function showError(msg) {
        emailError.textContent = msg;
        emailError.classList.remove('d-none');
    }

    function hideError() {
        emailError.classList.add('d-none');
    }

    sendBtn.addEventListener('click', function() {
        hideError();
        var email = emailInput.value.trim();
        var csrf = getCsrf();

        if (!hasRgpdConsent('magic-link')) {
            return;
        }

        if (!email) {
            showError('Veuillez entrer une adresse email.');
            return;
        }

        sendBtn.disabled = true;
        sendBtn.textContent = 'Envoi en cours…';

        fetch('/login/magic-link', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'email=' + encodeURIComponent(email) + '&_csrf_token=' + encodeURIComponent(csrf) + '&rgpd_consent=1'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            sendBtn.disabled = false;
            sendBtn.textContent = 'Envoyer le lien de connexion';

            if (data.success) {
                sentEmailSpan.textContent = email;
                showState(stateWaiting);
                startPolling(data.poll_id);
            } else {
                showError(data.error || 'Une erreur est survenue.');
            }
        })
        .catch(function() {
            sendBtn.disabled = false;
            sendBtn.textContent = 'Envoyer le lien de connexion';
            showError('Erreur réseau. Veuillez réessayer.');
        });
    });

    backBtn.addEventListener('click', function() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
            pollingInterval = null;
        }
        showState(stateEmail);
    });

    emailInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            sendBtn.click();
        }
    });

    function startPolling(pollId) {
        pollingInterval = setInterval(function() {
            fetch('/auth/poll/' + pollId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.confirmed) {
                        clearInterval(pollingInterval);
                        pollingInterval = null;
                        showState(stateConfirmed);
                        setTimeout(function() {
                            window.location.href = '/';
                        }, 2000);
                    }
                })
                .catch(function() {});
        }, 3000);

        setTimeout(function() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
        }, 15 * 60 * 1000);
    }

    // --- Password login ---
    var passwordBtn = document.getElementById('password-login-btn');
    if (passwordBtn) {
        passwordBtn.addEventListener('click', async function() {
            var email = document.getElementById('password-email').value.trim();
            var password = document.getElementById('password-input').value;
            var csrf = getCsrf();

            var errorEl = document.getElementById('password-error');
            var lockoutEl = document.getElementById('password-lockout');
            errorEl.classList.add('d-none');
            lockoutEl.classList.add('d-none');

            if (!hasRgpdConsent('password')) {
                return;
            }

            if (!email || !password) {
                errorEl.textContent = 'Veuillez remplir tous les champs.';
                errorEl.classList.remove('d-none');
                return;
            }

            try {
                var res = await fetch('/login/password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email: email, password: password, _csrf_token: csrf, rgpd_consent: true })
                });
                var data = await res.json();

                if (data.success) {
                    window.location.href = '/';
                } else if (data.locked_seconds) {
                    lockoutEl.textContent =
                        'Trop de tentatives. Réessayez dans ' + Math.ceil(data.locked_seconds / 60) + ' minute(s).';
                    lockoutEl.classList.remove('d-none');
                } else {
                    errorEl.textContent = data.error || 'Identifiants invalides.';
                    errorEl.classList.remove('d-none');
                }
            } catch (err) {
                errorEl.textContent = 'Erreur réseau. Veuillez réessayer.';
                errorEl.classList.remove('d-none');
            }
        });

        // Enter key in password field
        document.getElementById('password-input').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); passwordBtn.click(); }
        });
    }

    // --- "Mot de passe oublié ?" (module addendum) ---
    var forgotLink = document.getElementById('forgot-password-link');
    var forgotForm = document.getElementById('forgot-password-form');
    if (forgotLink && forgotForm) {
        forgotLink.addEventListener('click', function (e) {
            e.preventDefault();
            forgotForm.classList.toggle('d-none');
            if (!forgotForm.classList.contains('d-none')) {
                var prefill = document.getElementById('password-email').value.trim();
                if (prefill) document.getElementById('forgot-password-email').value = prefill;
                document.getElementById('forgot-password-email').focus();
            }
        });

        document.getElementById('forgot-password-submit-btn').addEventListener('click', async function () {
            var email = document.getElementById('forgot-password-email').value.trim();
            var messageEl = document.getElementById('forgot-password-message');
            messageEl.classList.add('d-none');

            if (!email) return;

            var btn = this;
            btn.disabled = true;
            try {
                var res = await fetch('/password-reset/request', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'email=' + encodeURIComponent(email) + '&_csrf_token=' + encodeURIComponent(getCsrf())
                });
                var data = await res.json();
                messageEl.textContent = data.success
                    ? 'Si cette adresse correspond à un compte, un email de réinitialisation vient d\'être envoyé.'
                    : (data.error || 'Une erreur est survenue.');
                messageEl.classList.remove('text-danger', 'text-success');
                messageEl.classList.add(data.success ? 'text-success' : 'text-danger');
                messageEl.classList.remove('d-none');
            } catch (err) {
                messageEl.textContent = 'Erreur réseau. Veuillez réessayer.';
                messageEl.classList.remove('text-success');
                messageEl.classList.add('text-danger');
                messageEl.classList.remove('d-none');
            } finally {
                btn.disabled = false;
            }
        });
    }

    // --- Passkey login ---
    var passkeyBtn = document.getElementById('passkey-login-btn');
    if (passkeyBtn) {
        if (!window.PublicKeyCredential) {
            passkeyBtn.disabled = true;
            document.getElementById('passkey-unsupported').classList.remove('d-none');
        }

        passkeyBtn.addEventListener('click', async function() {
            var errorEl = document.getElementById('passkey-error');
            errorEl.classList.add('d-none');

            if (!hasRgpdConsent('passkey')) {
                return;
            }

            try {
                var optRes = await fetch('/login/passkey/options');
                var options = await optRes.json();

                options.challenge = base64ToBuffer(options.challenge);
                if (options.allowCredentials) {
                    options.allowCredentials = options.allowCredentials.map(function(c) {
                        return Object.assign({}, c, { id: base64ToBuffer(c.id) });
                    });
                }

                var credential = await navigator.credentials.get({ publicKey: options });

                var verifyRes = await fetch('/login/passkey/verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
                    body: JSON.stringify({
                        id: credential.id,
                        rawId: bufferToBase64(credential.rawId),
                        response: {
                            authenticatorData: bufferToBase64(credential.response.authenticatorData),
                            clientDataJSON: bufferToBase64(credential.response.clientDataJSON),
                            signature: bufferToBase64(credential.response.signature),
                            userHandle: credential.response.userHandle
                                ? bufferToBase64(credential.response.userHandle) : null
                        },
                        type: credential.type,
                        rgpd_consent: true
                    })
                });
                var result = await verifyRes.json();

                if (result.success) {
                    window.location.href = '/';
                } else {
                    errorEl.textContent = result.error || 'L\'authentification a échoué.';
                    errorEl.classList.remove('d-none');
                }
            } catch (err) {
                errorEl.textContent = 'L\'authentification a été annulée ou a échoué.';
                errorEl.classList.remove('d-none');
            }
        });
    }

    // --- Utilities ---
    function base64ToBuffer(b64) {
        var bin = atob(b64.replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(bin, function(c) { return c.charCodeAt(0); }).buffer;
    }
    function bufferToBase64(buf) {
        return btoa(String.fromCharCode.apply(null, new Uint8Array(buf)))
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
})();
