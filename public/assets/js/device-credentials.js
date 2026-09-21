/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Appareils synchronisés » (core/View/templates/account/devices.html.twig).
//
// One job, and it is the reason this is a script at all rather than a form
// post: the secret a new credential carries exists in exactly one place —
// the JSON answer to the request that created it. A form post would have
// to park it in a flash message on its way to the redirected page, and a
// flash message is the server's session file. So the creation is a JSON
// request, and the answer is written straight into the panel.
//
// The secret is written with textContent and NEVER as markup, and it is
// never logged, never put in the URL, and never stored in the browser: a
// reader who loses it revokes the device and registers another, which the
// panel says in those words.
(function () {
    var api = window.ScoutMagicApi;
    var addButton = /** @type {HTMLButtonElement|null} */ (document.getElementById('device-add-btn'));
    if (!addButton || !api) {
        return;
    }

    var labelInput = /** @type {HTMLInputElement|null} */ (document.getElementById('device-label'));
    var panel = document.getElementById('device-secret-panel');
    var secretValue = document.getElementById('device-secret-value');
    var errorBox = document.getElementById('device-error');

    /**
     * @param {string} message
     * @returns {void}
     */
    function showError(message) {
        if (!errorBox) {
            return;
        }
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
    }

    function clearError() {
        if (errorBox) {
            errorBox.textContent = '';
            errorBox.classList.add('d-none');
        }
    }

    /**
     * What the error box says when the request came back without a
     * secret: the server's own wording when it sent one, a network line
     * when the request never reached it, and a generic French sentence
     * otherwise — never a status code, which tells a reader nothing.
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {string}
     */
    function failureMessage(res) {
        if (res.status === 0) {
            return 'Erreur réseau.';
        }

        return typeof res.data?.error === 'string'
            ? res.data.error
            : 'Impossible d\'enregistrer cet appareil.';
    }

    addButton.addEventListener('click', function () {
        clearError();
        addButton.disabled = true;

        api.postJson('/api/account/devices', { label: labelInput ? labelInput.value : '' })
            .then(function (res) {
                if (!res.data?.success || typeof res.data.secret !== 'string') {
                    showError(failureMessage(res));
                    addButton.disabled = false;
                    return;
                }

                if (secretValue) {
                    secretValue.textContent = res.data.secret;
                }
                if (panel) {
                    panel.classList.remove('d-none');
                }
                // The list on screen no longer matches the database, and
                // rebuilding a row here would be a second copy of the
                // template's markup. The panel holds the only thing that
                // cannot be read again; everything else is one reload
                // away, so the button stays disabled and says so.
                addButton.disabled = true;
                addButton.textContent = 'Rechargez la page pour voir la liste à jour';
            });
    });
})();
