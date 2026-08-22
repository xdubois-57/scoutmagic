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

        var res = await window.ScoutMagicApi.postJson('/config/settings/logo-delete', {});

        if (res.data && res.data.success) {
            window.location.reload();
        } else {
            window.ScoutMagicToast.show((res.data && res.data.error) || 'Une erreur est survenue.', { variant: 'error' });
        }
    });
});
