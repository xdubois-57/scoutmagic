/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Configuration > Comptes superadmin (core/View/templates/config/
// super_admins.html.twig): the actif/inactif switch on each row.
//
// One independent control, so it saves on change with a toast and no
// button (design.md §7.13) — the page says so once, next to the list,
// because a visitor cannot tell the two saving shapes apart by looking.
//
// The switch does NOT decide anything. The server refuses deactivating
// the last usable super admin, and refuses anyone deactivating
// themselves; this file only reports what came back and puts the switch
// where the server left it. A refusal that only greyed a control out
// would be no refusal at all — which is why the row the visitor cannot
// act on is rendered without a switch server-side, rather than with a
// disabled one this script could re-enable.
(function () {
    var api = window.ScoutMagicApi;

    /** @type {NodeListOf<HTMLInputElement>} */
    var switches = document.querySelectorAll('.super-admin-active-toggle');

    // A no-op on every other page of the site.
    if (!switches.length) {
        return;
    }

    /**
     * Reflects the row's state in the badge beside the switch, so the
     * written state and the control never disagree after a save.
     *
     * @param {HTMLInputElement} control
     * @param {boolean} isActive
     * @returns {void}
     */
    function paintRow(control, isActive) {
        var row = control.closest('tr');
        if (!row) {
            return;
        }

        row.classList.toggle('opacity-50', !isActive);

        var badge = row.querySelector('.super-admin-state-badge');
        if (badge) {
            badge.textContent = isActive ? 'Actif' : 'Désactivé';
            badge.classList.toggle('text-bg-success', isActive);
            badge.classList.toggle('text-bg-secondary', !isActive);
        }
    }

    switches.forEach(function (control) {
        control.addEventListener('change', function () {
            var wanted = control.checked;

            api.withDisabled(control, function () {
                return api.postJson('/config/superadmins/toggle-active', {
                    user_account_id: control.dataset.accountId,
                    is_active: wanted
                });
            }).then(function (res) {
                if (res.data && res.data.success) {
                    paintRow(control, wanted);
                    window.ScoutMagicToast.show(
                        (res.data && res.data.message) || 'Modification enregistrée.',
                        { variant: 'success' }
                    );
                    return;
                }

                // The server said no — or never answered. Either way the
                // switch goes back to what it was, because the screen
                // must not keep claiming a change that did not happen.
                control.checked = !wanted;
                paintRow(control, !wanted);
                window.ScoutMagicToast.show(
                    res.status === 0
                        ? 'Erreur réseau.'
                        : (res.data && res.data.error) || "Le compte n'a pas pu être modifié.",
                    { variant: 'error' }
                );
            });
        });
    });
})();
