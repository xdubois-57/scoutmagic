/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Config Desk page (core/View/templates/config/functions.html.twig): the
// per-function role selects and the module-provided per-function flag
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
// The section « Visible » control is a role="switch" checkbox: its
// aria-checked is rendered server-side and kept in sync by nav.js's
// delegated change listener (ScoutMagicNav.syncSwitchAriaChecked). Nothing
// here reverts a switch programmatically, so nothing here has to call it —
// see config-badges.js for the case that does.
(function () {
    var api = window.ScoutMagicApi;

    /** @type {NodeListOf<HTMLSelectElement>} */
    var roleSelects = document.querySelectorAll('.role-select');
    /** @type {NodeListOf<HTMLElement>} */
    var flagGroups = document.querySelectorAll('.flags-group');
    /** @type {NodeListOf<HTMLElement>} */
    var sectionRows = document.querySelectorAll('.section-row');
    /** @type {NodeListOf<HTMLElement>} */
    var branchRows = document.querySelectorAll('.branch-row');

    // A no-op on every other page of the site: this file is a page script,
    // and each of its four sections can also be legitimately absent here
    // (no import yet, no flag-providing module, no branch).
    if (!roleSelects.length && !flagGroups.length && !sectionRows.length && !branchRows.length) {
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
     * server's error is toasted, and the parsed body comes back only on
     * business success so callers can read what it returned (the colour
     * endpoint answers with the effective colour).
     *
     * @param {HTMLInputElement|HTMLSelectElement|null} control
     * @param {string} url
     * @param {Object} body
     * @returns {Promise<any>} the parsed body on success, null otherwise
     */
    function save(control, url, body) {
        return api.withDisabled(/** @type {HTMLInputElement} */ (/** @type {unknown} */ (control)), function () {
            return api.postJson(url, body);
        }).then(function (res) {
            if (res.data?.success) {
                return res.data;
            }
            toastError(res);
            return null;
        });
    }

    // --- Function roles ---
    roleSelects.forEach(function (select) {
        select.addEventListener('change', function () {
            save(select, '/config/functions/update', {
                function_id: Number.parseInt(select.dataset.id, 10),
                role: select.value
            }).then(function (data) {
                if (!data) {
                    return;
                }
                var row = /** @type {HTMLElement|null} */ (select.closest('.function-row'));
                if (!row) {
                    return;
                }
                // The server confirms a function as soon as its role is
                // set, so the « Non confirmée » badge no longer applies.
                var pending = row.querySelector('.badge.text-bg-warning');
                if (pending) {
                    pending.remove();
                }
                // Brief visual feedback — the row flashes green.
                row.style.backgroundColor = 'var(--bs-success-bg-subtle)';
                setTimeout(function () { row.style.backgroundColor = ''; }, 1000);
            });
        });
    });

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
            });
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
            nameInput.addEventListener('blur', function () {
                save(nameInput, '/config/functions/section-name', { section_id: sectionId, name: nameInput.value });
            });
        }

        if (emailInput) {
            emailInput.addEventListener('blur', function () {
                save(emailInput, '/config/functions/section-email', { section_id: sectionId, email: emailInput.value });
            });
        }

        if (visibleInput) {
            visibleInput.addEventListener('change', function () {
                save(visibleInput, '/config/functions/section-visibility', {
                    section_id: sectionId,
                    visible: visibleInput.checked
                });
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
            var saveColor = function (color) {
                save(colorInput, '/config/functions/section-color', { section_id: sectionId, color: color })
                    .then(function (data) {
                        if (!data) {
                            return;
                        }
                        colorInput.value = data.color;
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
        urlInput.addEventListener('blur', function () {
            save(urlInput, '/config/functions/branch-url', { branch_id: branchId, url: urlInput.value });
        });
    });
})();
