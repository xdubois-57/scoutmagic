/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Correspondances Desk page (core/View/templates/config/functions.html.twig): the
// board of role zones functions are moved between (issue #741 — it was a
// role select per function), the module-provided per-function flag
// switches, the per-section name / organisational email / colour /
// visibility controls, and the per-branch explanation link. Every control
// saves itself the moment it changes (or loses focus, for the free-text
// fields) — there is no submit button on this page.
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/config-functions.test.js).
//
// Three things changed on the way out of the template:
//   - the two file-local csrf() readers are gone; every request rides
//     ScoutMagicApi.postJson, which carries the token in the body AND the
//     header, and ScoutMagicApi.withDisabled, which re-enables the control
//     on the failure path the hand-written copies kept forgetting;
//   - HTTP success (res.ok) and business success (res.data.success) are
//     two separate facts again — the raw fetch + res.json() this replaces
//     read an HTML 500 page as a parse exception, or worse, as a save;
//   - the seven alert() failures are toasts (design.md §7.5): a native box
//     shows the origin above the message and labels its button in the
//     browser's language, not French.
//
// Every save answers with a toast, success included (design.md §7.13,
// issue #739): the role row used to flash green and the other controls
// succeeded in silence. A refused pick or switch goes back to the value
// the server still holds; a refused free-text field keeps what was typed,
// so nothing the admin wrote is thrown away.
//
// The section « Visible » control is a role="switch" checkbox: its
// aria-checked is rendered server-side and kept in sync by nav.js's
// delegated change listener (ScoutMagicNav.syncSwitchAriaChecked). Nothing
// here reverts a switch programmatically, so nothing here has to call it —
// see config-badges.js for the case that does.
(function () {
    var api = window.ScoutMagicApi;

    var board = /** @type {HTMLElement|null} */ (document.getElementById('function-board'));
    /** @type {NodeListOf<HTMLElement>} */
    var flagGroups = document.querySelectorAll('.flags-group');
    /** @type {NodeListOf<HTMLElement>} */
    var sectionRows = document.querySelectorAll('.section-row');
    /** @type {NodeListOf<HTMLElement>} */
    var branchRows = document.querySelectorAll('.branch-row');

    // A no-op on every other page of the site: this file is a page script,
    // and each of its four sections can also be legitimately absent here
    // (no import yet, no flag-providing module, no branch).
    if (!board && !flagGroups.length && !sectionRows.length && !branchRows.length) {
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

    /**
     * One field save: the control is disabled for the round trip, the
     * result is toasted either way, and the parsed body comes back only on
     * business success so callers can read what it returned (the colour
     * endpoint answers with the effective colour).
     *
     * @param {HTMLInputElement|HTMLSelectElement|null} control
     * @param {string} url
     * @param {Object} body
     * @param {() => void} [revert] puts the control back on the
     *     value the server still holds, when the save is refused
     * @returns {Promise<any>} the parsed body on success, null otherwise
     */
    function save(control, url, body, revert) {
        return api.withDisabled(/** @type {HTMLInputElement} */ (/** @type {unknown} */ (control)), function () {
            return api.postJson(url, body);
        }).then(function (res) {
            if (res.data?.success) {
                window.ScoutMagicToast.show('Enregistré.', { variant: 'success' });
                return res.data;
            }
            if (revert) {
                revert();
            }
            toastError(res);
            return null;
        });
    }

    /**
     * A text field saved on blur — only when its value differs from the
     * one the server last accepted, so tabbing through untouched fields
     * sends nothing and says nothing.
     *
     * @param {HTMLInputElement} input
     * @param {(value: string) => Promise<any>} send resolves to the parsed
     *     body on success, null otherwise (what save() returns)
     */
    function saveOnChangedBlur(input, send) {
        var saved = input.value;
        input.addEventListener('blur', function () {
            var value = input.value;
            if (value === saved) {
                return;
            }
            void send(value).then(function (data) {
                if (data) {
                    saved = value;
                }
            });
        });
    }

    /**
     * A switch the admin just flipped: a refused save flips it back.
     *
     * @param {HTMLInputElement} input
     * @returns {() => void}
     */
    function flipBack(input) {
        return function () {
            input.checked = !input.checked;
            // Only a real switch carries aria-checked; a plain checkbox
            // (the lead flag) must not grow one.
            if (input.getAttribute('role') === 'switch') {
                window.ScoutMagicNav?.syncSwitchAriaChecked?.(input);
            }
        };
    }

    // --- Function roles: a board of role zones (issue #741) ---
    //
    // A function is assigned by being moved into a role — dragged, or sent
    // one role up or down by the row's arrows. The drag itself is the
    // shared toolbox (sortable.js, connected lists); what is this page's
    // own is the meaning of a move: one POST to /config/functions/update,
    // a toast either way, and on a refusal the row goes back where it was.
    if (board) {
        /**
         * A zone's count and empty sentence, after a row came or went.
         *
         * @param {HTMLElement} zone
         */
        var refreshZone = function (zone) {
            var rows = zone.querySelectorAll('.function-row').length;
            var empty = zone.querySelector('.function-zone-empty');
            if (empty) {
                empty.classList.toggle('d-none', rows > 0);
            }
            var count = zone.closest('.function-zone')?.querySelector('[data-zone-count]');
            if (count) {
                count.textContent = String(rows);
            }
        };

        /**
         * Puts a row where the server now has it: its role, no « Non
         * confirmée » any more, and the module's flag shown only where it
         * applies (Chef, Chef d'Unité).
         *
         * @param {HTMLElement} row
         * @param {string} role
         */
        var settle = function (row, role) {
            row.dataset.role = role;
            row.querySelector('.function-pending-badge')?.remove();
            row.querySelector('.flags-group')?.classList.toggle('d-none', role !== 'chief' && role !== 'admin');
        };

        /**
         * Saves the move of `row` from `from` into `to`, or puts it back.
         *
         * @param {HTMLElement} row
         * @param {HTMLElement} from
         * @param {HTMLElement} to
         */
        var moveFunction = function (row, from, to) {
            refreshZone(from);
            refreshZone(to);
            save(null, '/config/functions/update', {
                function_id: Number.parseInt(row.dataset.id || '', 10),
                role: to.dataset.role || ''
            }, function () {
                // Refused: back to the zone the server still has it in, so
                // the board never shows an assignment that was not made.
                var empty = from.querySelector('.function-zone-empty');
                from.insertBefore(row, empty);
                refreshZone(from);
                refreshZone(to);
            }).then(function (data) {
                if (data) {
                    settle(row, to.dataset.role || '');
                }
            });
        };

        board.querySelectorAll('.function-zone-items').forEach(function (node) {
            var zone = /** @type {HTMLElement} */ (node);
            window.ScoutMagicSortable?.bind(zone, {
                itemSelector: '.function-row',
                draggingClass: 'opacity-50',
                group: 'desk-functions',
                // « À configurer » lends its rows and takes none back.
                receive: zone.dataset.receives !== '0',
                onReorder: function (move) {
                    // The order inside a role means nothing: only a change
                    // of zone is a change at all.
                    if (move && move.from !== move.to) {
                        moveFunction(move.item, move.from, move.to);
                    }
                }
            });
        });

        // The arrows: the same move for a finger or a keyboard — to the
        // zone above or below, never into « À configurer ».
        board.addEventListener('click', function (e) {
            var target = /** @type {HTMLElement|null} */ (e.target);
            var button = target?.closest('.function-move-up, .function-move-down');
            if (!button) {
                return;
            }
            var row = /** @type {HTMLElement} */ (button.closest('.function-row'));
            var from = /** @type {HTMLElement} */ (row.closest('.function-zone-items'));
            var zones = Array.from(board.querySelectorAll('.function-zone-items'));
            var to = /** @type {HTMLElement|undefined} */ (
                zones[zones.indexOf(from) + (button.classList.contains('function-move-up') ? -1 : 1)]
            );
            if (!to || to.dataset.receives === '0') {
                return;
            }
            to.insertBefore(row, to.querySelector('.function-zone-empty'));
            moveFunction(row, from, to);
        });
    }

    // --- Module-provided per-function flags (e.g. trombinoscope lead) ---
    flagGroups.forEach(function (group) {
        var leadInput = /** @type {HTMLInputElement|null} */ (group.querySelector('.flag-lead'));
        if (!leadInput) {
            return;
        }
        leadInput.addEventListener('change', function () {
            save(leadInput, '/config/functions/flags', {
                function_id: Number.parseInt(group.dataset.id, 10),
                lead: leadInput.checked
            }, flipBack(leadInput));
        });
    });

    // --- Sections ---
    sectionRows.forEach(function (row) {
        var sectionId = Number.parseInt(row.dataset.id, 10);
        var nameInput = /** @type {HTMLInputElement|null} */ (row.querySelector('.section-name-input'));
        var emailInput = /** @type {HTMLInputElement|null} */ (row.querySelector('.section-email-input'));
        var visibleInput = /** @type {HTMLInputElement|null} */ (row.querySelector('.section-visible-input'));
        var colorInput = /** @type {HTMLInputElement|null} */ (row.querySelector('.section-color-input'));
        var colorReset = /** @type {HTMLButtonElement|null} */ (row.querySelector('.section-color-reset'));

        if (nameInput) {
            saveOnChangedBlur(nameInput, function (value) {
                return save(nameInput, '/config/functions/section-name', { section_id: sectionId, name: value });
            });
        }

        if (emailInput) {
            var emailWarning = /** @type {HTMLElement|null} */ (row.querySelector('.section-email-warning'));
            var emailWarningText = /** @type {HTMLElement|null} */ (
                row.querySelector('.section-email-warning-text')
            );
            saveOnChangedBlur(emailInput, function (value) {
                return save(emailInput, '/config/functions/section-email', { section_id: sectionId, email: value })
                    .then(function (data) {
                        // The sentence comes from the server, which owns the
                        // rule: this only shows or hides what it answered.
                        // An absent key is « nothing to warn about », the
                        // same as an empty one, so an older answer cannot
                        // leave a stale warning on screen.
                        if (data && emailWarning && emailWarningText) {
                            var warning = data.alignment_warning || '';
                            emailWarningText.textContent = warning;
                            emailWarning.classList.toggle('d-none', warning === '');
                        }
                        return data;
                    });
            });
        }

        if (visibleInput) {
            visibleInput.addEventListener('change', function () {
                save(visibleInput, '/config/functions/section-visibility', {
                    section_id: sectionId,
                    visible: visibleInput.checked
                }, flipBack(visibleInput));
            });
        }

        if (colorInput && colorReset) {
            /**
             * Save an explicit colour override, or clear it: a null colour
             * reverts the section to its branch-derived default, which the
             * server answers with so the picker can show it.
             *
             * @param {string|null} color
             */
            var savedColor = colorInput.value;
            var saveColor = function (color) {
                void save(colorInput, '/config/functions/section-color', { section_id: sectionId, color: color }, function () {
                    colorInput.value = savedColor;
                })
                    .then(function (data) {
                        if (!data) {
                            return;
                        }
                        colorInput.value = data.color;
                        savedColor = data.color;
                        colorInput.dataset.hasOverride = color ? '1' : '0';
                        colorReset.disabled = !color;
                    });
            };

            colorInput.addEventListener('change', function () { saveColor(colorInput.value); });
            colorReset.addEventListener('click', function () { saveColor(null); });
        }
    });

    // --- Age branches ---
    branchRows.forEach(function (row) {
        var branchId = Number.parseInt(row.dataset.id, 10);
        var urlInput = /** @type {HTMLInputElement|null} */ (row.querySelector('.branch-url-input'));
        if (!urlInput) {
            return;
        }
        saveOnChangedBlur(urlInput, function (value) {
            return save(urlInput, '/config/functions/branch-url', { branch_id: branchId, url: value });
        });
    });
})();
