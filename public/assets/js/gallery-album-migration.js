/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The album storage-migration table on the gallery's « Albums » tab.
//
// All this file used to do was share a page with the storage locations;
// IT-02 moved those to /config/stockage and their script with them
// (storage-locations.js). The migration stayed here because it is gallery
// work rather than storage work (D5): it moves ALBUMS and their media
// between two destinations, and what it manipulates — an album, its
// renditions, its availability while the copy runs — means nothing to a
// storage location.
(function () {
    // Attribute-safe (quotes included) — several call sites below
    // interpolate into a title="..." attribute. The shared helper covers
    // exactly that (see api.js).
    var escapeHtml = window.ScoutMagicApi.escapeHtml;

    // The local postJson() copy this file carried resolved to the parsed
    // body, substituting {success:false, error:'Réponse inattendue du
    // serveur.'} when the body wasn't JSON, and REJECTED on a network
    // failure — each call site keeps its own handling for that case.
    // Requests now go through the shared window.ScoutMagicApi.postJson
    // envelope ({ok, status, data}), which never rejects; these two helpers
    // map it back to those exact semantics.
    /**
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {boolean}
     */
    function isNetworkFailure(res) {
        return res.status === 0 && res.data === null;
    }

    /**
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {Object}
     */
    function envelopeData(res) {
        return res.data === null ? { success: false, error: 'Réponse inattendue du serveur.' } : res.data;
    }

    // ------------------------------------------------------------------
    // Album storage migration (config.html.twig, onglet « Albums »)
    // ------------------------------------------------------------------
    document.querySelectorAll('.gallery-migrate-start').forEach(function (btn) {
        var button = /** @type {HTMLButtonElement} */ (btn);
        button.addEventListener('click', async function () {
            var select = /** @type {HTMLSelectElement} */ (document.querySelector('.gallery-migrate-target[data-album-id="' + button.dataset.albumId + '"]'));
            if (!select) return;
            // Not destructive: the album is copied to the other location,
            // only unavailable while it moves — 'primary', not 'danger'.
            var confirmed = await window.ScoutMagicConfirm.ask({
                message: 'Démarrer la migration de cet album vers cet autre emplacement ? L\'album sera indisponible pour les membres pendant l\'opération.',
                confirmLabel: 'Migrer',
                variant: 'primary'
            });
            if (!confirmed) return;
            button.disabled = true;
            window.ScoutMagicApi.postJson(button.dataset.url, { target_location_id: Number.parseInt(select.value, 10) }).then(function (res) {
                if (isNetworkFailure(res)) {
                    button.disabled = false;
                    return;
                }
                var data = envelopeData(res);
                if (data.success) {
                    window.location.reload();
                } else {
                    button.disabled = false;
                    window.ScoutMagicToast.show(data.error || 'Erreur lors du démarrage de la migration.', { variant: 'error' });
                }
            });
        });
    });

})();
