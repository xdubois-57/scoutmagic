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
                if (res.data?.success) {
                    window.location.reload();
                    return;
                }
                window.ScoutMagicToast.show(
                    res.status === 0
                        ? 'Erreur réseau.'
                        : res.data?.error || 'Le module n\'a pas pu être modifié.',
                    { variant: 'error' }
                );
                toggle.checked = !enabled;
            });
        });
    });
})();

// The Tous / Actifs / Inactifs filter.
//
// Its own IIFE, deliberately: the block above returns early on a page
// with no `.module-toggle`, and a module that fails validation renders
// no switch — filtering must keep working on an installation where
// every module is in that state.
//
// Purely a view of a list the server already rendered in full. Nothing
// is fetched, so the page still reads correctly with JavaScript off:
// every module is in the DOM and the chips simply do nothing. The
// truth stays server-side in `data-module-enabled`, which survives a
// toggle because a successful toggle reloads the page rather than
// patching it.
(function () {
    /** @type {NodeListOf<HTMLButtonElement>} */
    var chips = document.querySelectorAll('[data-module-filter]');
    if (!chips.length) {
        return;
    }

    /** @type {NodeListOf<HTMLElement>} */
    var rows = document.querySelectorAll('[data-module-row]');
    /** @type {NodeListOf<HTMLElement>} */
    var shelves = document.querySelectorAll('[data-module-shelf]');
    var empty = document.querySelector('[data-module-empty]');

    function apply(wanted) {
        rows.forEach(function (row) {
            var enabled = row.dataset.moduleEnabled === 'yes';
            var keep = wanted === 'all' || (wanted === 'on') === enabled;
            row.classList.toggle('d-none', !keep);
        });

        // A shelf whose every module is filtered out is hidden entirely:
        // a titled section with nothing under it reads as broken rather
        // than as empty, which is the rule the mega-menu follows too.
        var shown = 0;
        shelves.forEach(function (shelf) {
            var visible = shelf.querySelectorAll('[data-module-row]:not(.d-none)').length;
            shelf.classList.toggle('d-none', visible === 0);
            shown += visible;
        });

        if (empty) {
            empty.classList.toggle('d-none', shown > 0);
        }
    }

    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            chips.forEach(function (other) {
                var isThis = other === chip;
                other.classList.toggle('active', isThis);
                other.setAttribute('aria-pressed', isThis ? 'true' : 'false');
            });

            apply(chip.dataset.moduleFilter || 'all');
        });
    });
})();
