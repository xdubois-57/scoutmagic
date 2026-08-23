/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Modules configuration page (core/View/templates/config/modules.html.twig):
// the switch that turns one module on or off. Extracted from the
// template's inline <script> so the Vitest suite can exercise the
// production code directly (tests/js/config-modules.test.js).
//
// Turning a module on or off adds or removes whole menus, routes and
// pages, so a success reloads: nothing on this page could be patched
// into agreement with the new navigation anyway. A refusal must put the
// switch back — ModuleManager::activate() rejects a module whose
// requirements are unmet, and the screen must not keep claiming an
// activation that did not happen.
//
// The `alert()` is a toast, with a sentence of its own for a refusal
// that carries no message (it used to pass `data.error` straight to
// alert(), so such a refusal showed the word « undefined »).
(function () {
    var api = window.ScoutMagicApi;

    /** @type {NodeListOf<HTMLInputElement>} */
    var toggles = document.querySelectorAll('.module-toggle');

    // A no-op on every other page of the site.
    if (!toggles.length) {
        return;
    }

    toggles.forEach(function (toggle) {
        toggle.addEventListener('change', function () {
            var enabled = toggle.checked;

            api.withDisabled(toggle, function () {
                return api.postJson('/config/modules/toggle', {
                    module_id: toggle.dataset.module,
                    enabled: enabled
                });
            }).then(function (res) {
                if (res.data && res.data.success) {
                    window.location.reload();
                    return;
                }
                window.ScoutMagicToast.show(
                    res.status === 0
                        ? 'Erreur réseau.'
                        : (res.data && res.data.error) || 'Le module n\'a pas pu être modifié.',
                    { variant: 'error' }
                );
                toggle.checked = !enabled;
            });
        });
    });
})();
