/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Passage page (modules/registration/views/passage.html.twig): the
// per-row « Enregistrer » that assigns a future member to a section, or
// moves an existing one to their destination section. Extracted from the
// template's inline <script> so the Vitest suite can exercise the
// production code directly (tests/js/registration-passage.test.js).
//
// Each row carries its own endpoint and field name in data-* on the
// select, so the two tables on this page — future members by intended
// section, current members by destination section — share one handler
// and one feedback line.
//
// The feedback is an inline `role="status" aria-live="polite"` line, not
// a toast: this page has one of them per row, and a passage evening
// means dozens of saves in a row. A toast per row would stack, and the
// answer belongs beside the select it answers for.
(function () {
    var api = window.ScoutMagicApi;

    /** @type {NodeListOf<HTMLButtonElement>} */
    var buttons = document.querySelectorAll('.passage-save');

    // A no-op on every other page of the site.
    if (!buttons.length) {
        return;
    }

    /**
     * @param {Element} cell
     * @param {string} message
     * @param {boolean} isError
     */
    function feedback(cell, message, isError) {
        var box = cell.querySelector('.passage-feedback');
        if (!box) {
            return;
        }
        box.textContent = message;
        box.classList.toggle('text-danger', isError);
        box.classList.toggle('text-success', !isError);
    }

    buttons.forEach(function (button) {
        var cell = button.closest('td');
        if (!cell) {
            return;
        }
        var select = /** @type {HTMLSelectElement|null} */ (cell.querySelector('.passage-select'));
        if (!select) {
            return;
        }

        button.addEventListener('click', function () {
            /** @type {Record<string, number>} */
            var payload = {};
            payload[select.dataset.field || ''] = parseInt(select.value, 10);

            feedback(cell, 'Enregistrement…', false);

            api.withDisabled(button, function () {
                return api.postJson(select.dataset.endpoint || '', payload);
            }).then(function (res) {
                if (res.data && res.data.success) {
                    feedback(cell, 'Enregistré.', false);
                    return;
                }
                if (res.status === 0) {
                    feedback(cell, 'Erreur réseau.', true);
                    return;
                }
                feedback(cell, (res.data && res.data.error) || "Erreur lors de l'enregistrement.", true);
            });
        });

        // A pick that hasn't been saved yet shouldn't still show the
        // previous line's « Enregistré. » confirmation.
        select.addEventListener('change', function () {
            feedback(cell, '', false);
        });
    });
})();
