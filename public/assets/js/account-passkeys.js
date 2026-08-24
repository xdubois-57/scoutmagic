/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Digital keys (WebAuthn passkeys) on « Mon compte »
// (core/View/templates/account/index.html.twig): registering one, and
// revoking one. Extracted from the template's inline <script> so the
// Vitest suite can exercise the production code directly
// (tests/js/account-passkeys.test.js).
//
// Four corrections came with the move:
//
// 1. The five native boxes are gone. `alert()` in a registration flow is
//    the worst place for one: it is the browser's own dialog, in the
//    browser's language, on the screen where the site asks someone to
//    trust it with an authenticator. They are toasts and
//    ScoutMagicConfirm (design.md §7.5).
// 2. The file-local getCsrf() read the hidden `_csrf_token` input, which
//    exists only because some other form on the page happens to render
//    one. ScoutMagicApi reads the `<meta name="csrf-token">` that
//    base.html.twig puts on every page.
// 3. Business success is checked separately from HTTP success on the
//    registration path too — it already was on the deletion path, and
//    the comment there explains why (a refused deletion answers HTTP 200
//    with {success:false}).
// 4. Deleting the LAST key now really shows the empty state. The template
//    used to render `#no-passkeys-msg` inside the `{% else %}` of the
//    loop, so on a page that HAD keys the element this code un-hides did
//    not exist at all: the list simply emptied itself into nothing. The
//    template renders it always, hidden, and this file reveals it.
(function () {
    var api = window.ScoutMagicApi;

    var addBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('passkey-add-btn'));
    /** @type {NodeListOf<HTMLElement>} */
    var deleteBtns = document.querySelectorAll('.passkey-delete-btn');

    // A no-op on every other page of the site.
    if (!addBtn && !deleteBtns.length) {
        return;
    }

    /** @param {string} message */
    function toastError(message) {
        window.ScoutMagicToast.show(message, { variant: 'error' });
    }

    /**
     * WebAuthn speaks ArrayBuffer; the server speaks base64url. These two
     * are the whole translation.
     *
     * @param {string} b64
     * @returns {ArrayBuffer}
     */
    function base64ToBuffer(b64) {
        var bin = atob(String(b64).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(bin, function (c) { return c.charCodeAt(0); }).buffer;
    }

    /**
     * @param {ArrayBuffer} buf
     * @returns {string}
     */
    function bufferToBase64(buf) {
        return btoa(String.fromCharCode.apply(null, /** @type {any} */ (new Uint8Array(buf))))
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    // A browser with no WebAuthn cannot be talked into it: say so, and
    // take the button out of the tab order rather than let it throw.
    if (!window.PublicKeyCredential) {
        if (addBtn) {
            addBtn.disabled = true;
        }
        var unsupported = document.getElementById('passkey-unsupported-notice');
        if (unsupported) {
            unsupported.classList.remove('d-none');
        }
    }

    if (addBtn) {
        addBtn.addEventListener('click', async function () {
            var labelBox = document.getElementById('passkey-label-input');
            var labelInput = /** @type {HTMLInputElement|null} */ (document.getElementById('passkey-device-label'));

            // First click reveals the name field — naming the key is the
            // only chance to tell two authenticators apart later.
            if (labelBox && labelBox.style.display === 'none') {
                labelBox.style.display = 'block';
                if (labelInput) {
                    labelInput.focus();
                }
                return;
            }

            var deviceLabel = (labelInput && labelInput.value) || 'Clé sans nom';

            var optRes = await api.getJson('/account/passkey/register-options');
            if (!optRes.ok || !optRes.data) {
                toastError('Les options d\'enregistrement n\'ont pas pu être obtenues.');
                return;
            }

            var options = optRes.data;
            options.challenge = base64ToBuffer(options.challenge);
            options.user.id = base64ToBuffer(options.user.id);
            if (options.excludeCredentials) {
                options.excludeCredentials = options.excludeCredentials.map(function (c) {
                    return Object.assign({}, c, { id: base64ToBuffer(c.id) });
                });
            }

            /** @type {any} */
            var credential;
            try {
                credential = await navigator.credentials.create({ publicKey: options });
            } catch (err) {
                var name = err && /** @type {any} */ (err).name;
                if (name === 'InvalidStateError') {
                    // The platform authenticator already holds a key for
                    // this account on this device (excludeCredentials
                    // prevents a duplicate).
                    toastError('Cet appareil possède déjà une clé enregistrée pour ce compte.');
                } else if (name === 'NotAllowedError') {
                    toastError('L\'enregistrement a été annulé.');
                } else {
                    toastError('L\'enregistrement a échoué.');
                }
                return;
            }

            var res = await api.postJson('/account/passkey/register', {
                id: credential.id,
                rawId: bufferToBase64(credential.rawId),
                response: {
                    attestationObject: bufferToBase64(credential.response.attestationObject),
                    clientDataJSON: bufferToBase64(credential.response.clientDataJSON)
                },
                type: credential.type,
                device_label: deviceLabel
            });

            if (res.data && res.data.success) {
                // A new key changes the list, its labels and its dates —
                // the page re-renders rather than patching them by hand.
                window.location.reload();
                return;
            }
            toastError((res.data && res.data.error) || 'L\'enregistrement a échoué.');
        });
    }

    // Delete passkey. The row only disappears on a CONFIRMED deletion:
    // res.ok is not that — the endpoint answers HTTP 200 with
    // {success:false, error} on a business refusal ("Clé introuvable."),
    // and this handler used to read res.ok as success, remove the row,
    // and let the owner of a lost device believe a still-valid key had
    // been revoked. Authentication is the one place the UI must never
    // claim more than the server did.
    deleteBtns.forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var confirmed = await window.ScoutMagicConfirm.ask({
                message: 'Supprimer cette clé ? Vous ne pourrez plus vous connecter avec.',
                confirmLabel: 'Supprimer'
            });
            if (!confirmed) {
                return;
            }

            var errorBox = document.getElementById('passkey-error');
            if (errorBox) {
                errorBox.classList.add('d-none');
                errorBox.textContent = '';
            }

            var res = await api.postJson('/account/passkey/delete', {
                id: parseInt(btn.dataset.id || '', 10)
            });

            if (!res.ok || !res.data || !res.data.success) {
                // role="alert" on this box, not a toast: a revocation the
                // server refused is the one message on this page that
                // must not scroll away unread.
                var failure = 'La clé n\'a pas pu être supprimée. Rechargez la page et réessayez.';
                if (errorBox) {
                    errorBox.textContent = (res.data && res.data.error) || failure;
                    errorBox.classList.remove('d-none');
                }
                return;
            }

            var row = btn.closest('[data-passkey-id]');
            if (row) {
                row.remove();
            }
            if (document.querySelectorAll('[data-passkey-id]').length === 0) {
                var msg = document.getElementById('no-passkeys-msg');
                if (msg) {
                    msg.classList.remove('d-none');
                }
            }
        });
    });
})();
