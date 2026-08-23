/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

document.addEventListener('DOMContentLoaded', function() {
    var notifyBtn = /** @type {HTMLButtonElement} */ (document.getElementById('unit-logo-notify-ios-btn'));
    if (!notifyBtn) {
        return;
    }

    notifyBtn.addEventListener('click', async function() {
        // Sending a notification destroys nothing — primary, not danger.
        var confirmed = await window.ScoutMagicConfirm.ask({
            message: 'Envoyer une notification à tous les comptes pour les inviter à réinstaller l\'application sur iOS ?',
            confirmLabel: 'Envoyer',
            variant: 'primary'
        });
        if (!confirmed) {
            return;
        }

        var csrf = /** @type {HTMLMetaElement} */ (document.querySelector('meta[name="csrf-token"]'));
        var res = await fetch('/config/settings/logo-notify-ios', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ _csrf_token: csrf ? csrf.content : '' })
        });
        var data = await res.json();

        if (data.success) {
            notifyBtn.disabled = true;
            notifyBtn.textContent = 'Notification envoyée';
        } else {
            window.ScoutMagicToast.show(data.error || 'Une erreur est survenue.', { variant: 'error' });
        }
    });
});
