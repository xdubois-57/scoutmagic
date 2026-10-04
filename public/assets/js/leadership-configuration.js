/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Encadrement > Configuration (#727): each Desk formation wording's step
// select saves itself, and its trash removes the decision after a
// confirmation. JSON only — the old form, redirect and flash message are
// gone, so a refusal is a toast and the select goes back to what the
// server holds.
//
// A wording attached from « À configurer » moves to « Correspondances
// existantes » as it is: same row, placeholder option dropped, trash shown.
// Its trash does the reverse for a wording someone still carries.
(function () {
    var unresolved = document.getElementById('leadership-mapping-unresolved');
    var decided = document.getElementById('leadership-mapping-decided');
    if (!unresolved || !decided) {
        return;
    }

    var api = window.ScoutMagicApi;
    var ENDPOINT = '/admin/leadership/configuration/mapping';

    /** Shows each list's empty sentence exactly when it holds no row. */
    function refreshEmpties() {
        [unresolved, decided].forEach(function (list) {
            var empty = document.querySelector('[data-empty-for="' + list.id + '"]');
            if (empty) {
                empty.classList.toggle('d-none', list.querySelector('.leadership-mapping-row') !== null);
            }
        });
    }

    /**
     * @param {{status: number, data: any}} res
     * @param {string} fallback
     */
    function toastFailure(res, fallback) {
        window.ScoutMagicToast.show(
            res.status === 0 ? 'Erreur réseau.' : (res.data?.error || fallback),
            { variant: 'error' }
        );
    }

    /** @param {HTMLElement} row */
    function wire(row) {
        var select = /** @type {HTMLSelectElement} */ (row.querySelector('.leadership-mapping-select'));
        var trash = /** @type {HTMLButtonElement} */ (row.querySelector('.leadership-mapping-delete'));
        var rawValue = row.dataset.rawValue || '';
        // What the server holds: what a refused change goes back to.
        var saved = select.value;

        select.addEventListener('change', function () {
            var chosen = select.value;
            if (chosen === '') {
                return;
            }
            void api.withDisabled(/** @type {HTMLInputElement} */ (/** @type {unknown} */ (select)), function () {
                return api.postJson(ENDPOINT, { raw_value: rawValue, step: chosen });
            }).then(function (res) {
                if (!res.data?.success) {
                    select.value = saved;
                    toastFailure(res, "Erreur lors de l'enregistrement.");
                    return;
                }
                saved = chosen;
                if (row.parentElement === unresolved) {
                    select.querySelector('option[value=""]')?.remove();
                    trash.classList.remove('d-none');
                    decided.appendChild(row);
                    refreshEmpties();
                }
                window.ScoutMagicToast.show('Enregistré.', { variant: 'success' });
            });
        });

        trash.addEventListener('click', async function () {
            var fallback = row.dataset.fallbackLabel || '';
            var confirmed = await window.ScoutMagicConfirm.ask({
                message: 'Supprimer le rattachement de « ' + rawValue + ' » ? ' + (fallback
                    ? 'Le site la lira de nouveau seul, comme « ' + fallback + ' ».'
                    : "Cette valeur redeviendra non reconnue tant qu'elle ne sera pas rattachée à nouveau."),
                confirmLabel: 'Supprimer',
            });
            if (!confirmed) {
                return;
            }
            var res = await api.withDisabled(trash, function () {
                return api.postJson(ENDPOINT, { raw_value: rawValue, step: '' });
            });
            if (!res.data?.success) {
                toastFailure(res, 'Erreur lors de la suppression.');
                return;
            }
            if (res.data.unresolved === true && Number.parseInt(row.dataset.holders || '0', 10) > 0) {
                // Someone still carries this wording this year and the
                // server no longer understands it: it goes back to
                // « À configurer », as the next load will show it.
                var placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Choisir une étape…';
                select.prepend(placeholder);
                select.value = '';
                saved = '';
                trash.classList.add('d-none');
                unresolved.appendChild(row);
            } else {
                // Nobody carries it any more, or the site reads it on its
                // own: it belongs in neither list.
                row.remove();
            }
            refreshEmpties();
            window.ScoutMagicToast.show('Rattachement supprimé.', { variant: 'success' });
        });
    }

    document.querySelectorAll('.leadership-mapping-row').forEach(function (row) {
        wire(/** @type {HTMLElement} */ (row));
    });
})();
