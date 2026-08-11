/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

document.addEventListener('DOMContentLoaded', function() {
    var deleteBtn = document.getElementById('unit-logo-delete-btn');
    if (!deleteBtn) {
        return;
    }

    deleteBtn.addEventListener('click', async function() {
        if (!confirm('Supprimer le logo personnalisé ? Le favicon, les icônes de l\'application et le logo du pied de page reviendront aux icônes par défaut.')) {
            return;
        }

        var csrf = document.querySelector('meta[name="csrf-token"]');
        var res = await fetch('/config/settings/logo-delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ _csrf_token: csrf ? csrf.content : '' })
        });
        var data = await res.json();

        if (data.success) {
            window.location.reload();
        } else {
            alert(data.error || 'Une erreur est survenue.');
        }
    });
});
