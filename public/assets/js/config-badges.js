/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Badges configuration page (core/View/templates/admin/badges/configuration.html.twig):
// renaming a custom badge, activating/deactivating any badge, deleting an
// unassigned custom one, and adding a new one — every one of them without
// reloading the page, and every one of them confirmed or refused by a toast
// (#1337).
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/config-badges.test.js).
//
// Same three corrections as config-functions.js: the file-local badgeCsrf()
// reader is gone (ScoutMagicApi.postJson carries the token), HTTP success
// and business success are two separate checks again, and the four alert()
// failures are toasts. The confirm() guarding the permanent deletion is
// ScoutMagicConfirm (design.md §7.5) — a native box gives a permanent
// delete the same two buttons as a harmless question, in the browser's
// language rather than French.
//
// EVENTS ARE DELEGATED, from the document. A badge created here has to behave
// exactly like one the server rendered, at once, and a listener attached to
// each row at load would miss it. `blur` does not bubble, so the rename
// listens to `focusout`, which does.
//
// A NEW ROW IS A CLONE of <template id="badge-row-template">, rendered by the
// server from the same partial as every other row (admin/badges/_row.html.twig),
// then filled with what the server answered. Nothing is concatenated into
// markup: the name reaches the DOM as an input value, never as HTML.
//
// The « Actif » control is a role="switch" checkbox whose aria-checked
// nav.js syncs on `change`. A failed toggle reverts `checked` in code,
// which fires no event, so this file calls
// ScoutMagicNav.syncSwitchAriaChecked() itself — otherwise a screen reader
// keeps announcing the state the server refused.
(function () {
    var api = window.ScoutMagicApi;

    var list = document.getElementById('badge-list');
    var addBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('badge-add-btn'));
    var newNameInput = /** @type {HTMLInputElement|null} */ (document.getElementById('new-badge-name'));

    // A no-op on every other page of the site — #badge-add-btn used to be
    // dereferenced unconditionally and threw wherever it was absent.
    if (!document.querySelector('.badge-row') && !addBtn) {
        return;
    }

    /**
     * The failure line for one envelope: the server's own message when it
     * answered JSON, a generic one when it did not (postJson folds a
     * network error or an HTML error page into data:null).
     *
     * @param {{ok: boolean, status: number, data: any}} res
     */
    function toastError(res) {
        var message = res.data?.error || 'Erreur : réponse serveur invalide.';
        window.ScoutMagicToast.show(message, { variant: 'error' });
    }

    /** @param {string} message */
    function toastSuccess(message) {
        window.ScoutMagicToast.show(message, { variant: 'success' });
    }

    /** @param {{ok: boolean, status: number, data: any}} res */
    function isSuccess(res) {
        return !!(res.data?.success);
    }

    /**
     * @param {Element} element
     * @returns {HTMLElement|null}
     */
    function rowOf(element) {
        return /** @type {HTMLElement|null} */ (element.closest('.badge-row'));
    }

    /** @param {HTMLElement} row */
    function badgeIdOf(row) {
        return Number.parseInt(row.dataset.id, 10);
    }

    /**
     * The name an input last held that the server confirmed. For a row the
     * server rendered that is the `value` attribute, which `defaultValue`
     * reads; after a rename it is moved forward to what the server answered.
     *
     * @param {HTMLInputElement} input
     */
    function savedName(input) {
        return input.defaultValue;
    }

    /**
     * Puts a row where the server would have listed it. The page is ordered by
     * name (BadgeRepository::findAll() — ORDER BY name) and a reload used to be
     * what kept it so; without one, an added or renamed badge has to find its
     * own place. Compared the way the database compares: letters without regard
     * to case or accents. The saved names are the reference (`defaultValue`): a
     * neighbour's field may hold an edit not yet saved.
     *
     * @param {HTMLElement} row
     */
    function placeRow(row) {
        var input = /** @type {HTMLInputElement|null} */ (row.querySelector('.badge-name-input'));
        if (!list || !input) {
            return;
        }
        var name = input.defaultValue;
        var next = Array.from(list.querySelectorAll('.badge-row')).find(function (other) {
            var otherInput = /** @type {HTMLInputElement|null} */ (other.querySelector('.badge-name-input'));
            return other !== row && !!otherInput
                && otherInput.defaultValue.localeCompare(name, 'fr', { sensitivity: 'base' }) > 0;
        });
        if (next) {
            if (row.nextElementSibling !== next) {
                list.insertBefore(row, next);
            }
        } else if (row !== list.lastElementChild) {
            list.appendChild(row);
        }
    }

    /**
     * Rename: saved when the field loses focus, only when the name really
     * changed, and never twice at once (the field is locked for the round
     * trip, so typing during it is not possible either). The displayed value
     * is the one the SERVER answered — it trims and may normalise — and a
     * refusal puts the previous name back, so the page never shows as saved
     * a name that was refused.
     *
     * @param {HTMLInputElement} input
     */
    function saveName(input) {
        var row = rowOf(input);
        // A default badge's name is server-owned: the input is readonly and
        // saving it is not offered at all.
        if (!row || row.dataset.default === '1' || input.readOnly || input.disabled) {
            return;
        }
        var previous = savedName(input);
        if (input.value.trim() === previous) {
            input.value = previous;
            return;
        }

        void api.withDisabled(input, function () {
            return api.postJson('/admin/badges/update', { badge_id: badgeIdOf(row), name: input.value });
        }).then(function (res) {
            // The row was deleted while the rename was on its way: it is not on
            // the page any more, and putting it back would show a badge the
            // server no longer has.
            if (!row.isConnected) {
                return;
            }
            if (isSuccess(res)) {
                var name = typeof res.data?.badge?.name === 'string' ? res.data.badge.name : input.value.trim();
                input.value = name;
                input.defaultValue = name;
                placeRow(row);
                // An autosave confirms itself (design.md §7.13).
                toastSuccess('Enregistré.');
                return;
            }
            toastError(res);
            input.value = previous;
        });
    }

    /** @param {HTMLInputElement} input */
    function saveActive(input) {
        var row = rowOf(input);
        if (!row) {
            return;
        }
        void api.withDisabled(input, function () {
            return api.postJson('/admin/badges/toggle-active', {
                badge_id: badgeIdOf(row),
                active: input.checked
            });
        }).then(function (res) {
            if (isSuccess(res)) {
                toastSuccess('Enregistré.');
                return;
            }
            toastError(res);
            // The server refused: put the switch back where it was,
            // and tell assistive technology about it (no `change`
            // event fires for a programmatic revert).
            input.checked = !input.checked;
            if (window.ScoutMagicNav?.syncSwitchAriaChecked) {
                window.ScoutMagicNav.syncSwitchAriaChecked(input);
            }
        });
    }

    /** @param {HTMLButtonElement} button */
    async function deleteBadge(button) {
        var row = rowOf(button);
        if (!row) {
            return;
        }
        var confirmed = await window.ScoutMagicConfirm.ask({
            message: 'Supprimer définitivement ce badge ?',
            confirmLabel: 'Supprimer'
        });
        if (!confirmed) {
            return;
        }
        var res = await api.withDisabled(button, function () {
            return api.postJson('/admin/badges/delete', { badge_id: badgeIdOf(row) });
        });
        if (isSuccess(res)) {
            row.remove();
            toastSuccess('Badge supprimé.');
        } else {
            toastError(res);
        }
    }

    /**
     * The row for a badge the server just created: the server's own markup,
     * from the template, with the id and name it answered.
     *
     * @param {{id: number, name: string, is_default?: boolean, is_active?: boolean}} badge
     * @returns {HTMLElement|null}
     */
    function buildRow(badge) {
        var template = /** @type {HTMLTemplateElement|null} */ (document.getElementById('badge-row-template'));
        var source = template?.content.firstElementChild;
        if (!source) {
            return null;
        }
        var row = /** @type {HTMLElement} */ (source.cloneNode(true));
        row.dataset.id = String(badge.id);

        var nameInput = /** @type {HTMLInputElement|null} */ (row.querySelector('.badge-name-input'));
        if (nameInput) {
            nameInput.value = badge.name;
            nameInput.defaultValue = badge.name;
        }
        var activeInput = /** @type {HTMLInputElement|null} */ (row.querySelector('.badge-active-input'));
        if (activeInput) {
            var inputId = 'badge-active-' + String(badge.id);
            activeInput.id = inputId;
            activeInput.checked = badge.is_active !== false;
            activeInput.setAttribute('aria-checked', activeInput.checked ? 'true' : 'false');
            var label = row.querySelector('label[for]');
            if (label) {
                label.setAttribute('for', inputId);
            }
        }

        return row;
    }

    function addBadge() {
        void api.withDisabled(addBtn, function () {
            return api.postJson('/admin/badges/add', { name: newNameInput.value });
        }).then(function (res) {
            var badge = res.data?.badge;
            var row = isSuccess(res) && badge && Number.isInteger(badge.id) ? buildRow(badge) : null;
            if (!row) {
                // A "success" that carries no usable badge is not one: nothing
                // was inserted, so nothing may be announced as added.
                toastError(isSuccess(res) ? { ok: res.ok, status: res.status, data: null } : res);
                return;
            }
            list?.appendChild(row);
            placeRow(row);
            newNameInput.value = '';
            toastSuccess('Badge ajouté.');
        });
    }

    document.addEventListener('focusout', function (event) {
        var target = event.target;
        if (target instanceof HTMLInputElement && target.classList.contains('badge-name-input')) {
            saveName(target);
        }
    });

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target instanceof HTMLInputElement && target.classList.contains('badge-active-input')) {
            saveActive(target);
        }
    });

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var button = target?.closest('.badge-delete-btn');
        if (button instanceof HTMLButtonElement && !button.disabled && rowOf(button)) {
            void deleteBadge(button);
            return;
        }
        if (addBtn && newNameInput && target?.closest('#badge-add-btn')) {
            addBadge();
        }
    });
})();
