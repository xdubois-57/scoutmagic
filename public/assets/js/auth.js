(function() {
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

    // Send magic link
    sendBtn.addEventListener('click', function() {
        hideError();
        var email = emailInput.value.trim();
        var csrf = document.querySelector('input[name="_csrf_token"]').value;

        if (!email) {
            showError('Veuillez entrer une adresse email.');
            return;
        }

        sendBtn.disabled = true;
        sendBtn.textContent = 'Envoi en cours…';

        fetch('/login/magic-link', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'email=' + encodeURIComponent(email) + '&_csrf_token=' + encodeURIComponent(csrf)
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

    // Back button
    backBtn.addEventListener('click', function() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
            pollingInterval = null;
        }
        showState(stateEmail);
    });

    // Allow Enter key to submit
    emailInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            sendBtn.click();
        }
    });

    // Polling
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
                .catch(function() {
                    // Silently ignore polling errors
                });
        }, 3000);

        // Stop polling after 15 minutes (token expiry)
        setTimeout(function() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
        }, 15 * 60 * 1000);
    }
})();
