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

    // ── The statistics box (spec §8) ─────────────────────────────────
    //
    // Its markup is the server's — both scopes rendered at once, one of
    // them hidden — and the save response carries the whole thing back
    // re-rendered (`statistics_html`). Nothing here formats a number: a
    // second formatter in the browser would be a second place for « 3 G ·
    // 2 F » to be written, and the two would drift.
    //
    // The scope switch is therefore pure visibility, and it survives a
    // refresh because it is re-applied to whatever markup just arrived.

    /** @returns {string} the scope currently selected, 'projected' by default */
    function currentScope() {
        var checked = /** @type {HTMLInputElement|null} */ (
            document.querySelector('input[name="passage-stats-scope"]:checked')
        );
        return checked ? checked.value : 'projected';
    }

    function applyScope() {
        var scope = currentScope();

        document.querySelectorAll('.passage-stats-scope').forEach(function (block) {
            /** @type {HTMLElement} */ (block).hidden = block.getAttribute('data-scope') !== scope;
        });

        var warning = document.getElementById('passage-arrivals-warning');
        if (warning) {
            warning.classList.toggle('d-none', scope !== 'arrivals');
        }
    }

    // Delegated on the document: the radios live inside the box, which is
    // replaced wholesale on every save, so a listener bound to them
    // directly would be gone after the first one.
    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target instanceof HTMLInputElement && target.name === 'passage-stats-scope') {
            applyScope();
        }
    });

    /** @param {string|undefined} html */
    function refreshStatistics(html) {
        if (typeof html !== 'string' || html === '') {
            return;
        }

        var box = document.getElementById('passage-statistics');
        if (!box || !box.parentElement) {
            return;
        }

        var scope = currentScope();
        box.outerHTML = html;

        // The freshly-rendered box always comes back on « Effectif
        // projeté » — put the reader's own choice back, or a chief working
        // in « Arrivées seules » would be thrown out of it on every save.
        var restored = /** @type {HTMLInputElement|null} */ (
            document.querySelector('input[name="passage-stats-scope"][value="' + scope + '"]')
        );
        if (restored) {
            restored.checked = true;
        }
        applyScope();
    }

    applyScope();

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
                    // The box comes back in the save's own answer (one
                    // round trip, no cache to invalidate) — spec §8.
                    refreshStatistics(res.data.statistics_html);
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
