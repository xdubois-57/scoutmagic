/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * The « rattacher à quel séjour ? » picker
 * (modules/camps/views/partials/stay_picker.html.twig).
 *
 * Wires every `.stay-picker` on the page — the camps mail screen renders
 * one per message — and each owns its own hidden input, so nothing here is
 * global but the document listeners.
 *
 * The same shape as the finance module's « quelle créance ? » picker:
 * type, wait a beat, ask the server, click a result. It searches
 * server-side rather than filtering in the browser because the list is the
 * unit's whole history of stays, and because what a chief types is what
 * they READ — « septembre 2026 » — which no client-side comparison against
 * the raw dates would find.
 *
 * **Upgrading REMOVES the `<select>` this replaces.** Both controls are
 * named `camp_id`; leaving the list in the DOM would post two values, and
 * the one the browser sent first is not the one the chief chose.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 250;

    /**
     * @param {HTMLElement} picker
     */
    function wire(picker) {
        var fallback = picker.querySelector('[data-stay-picker-fallback]');
        var box = /** @type {HTMLElement|null} */ (picker.querySelector('[data-stay-picker-search]'));
        if (!box) {
            // No search half rendered: the server built no search service,
            // and the `<select>` is the whole control. Leave it alone.
            return;
        }

        var search = /** @type {HTMLInputElement|null} */ (
            box.querySelector('input[type="text"], input:not([type])')
        );
        var hidden = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-stay-picker-value]'));
        var results = /** @type {HTMLElement|null} */ (box.querySelector('[data-stay-picker-results]'));
        if (!search || !hidden || !results) {
            return;
        }

        // The upgrade, in this order: enable the input that posts, then
        // drop the one that would post the same name.
        hidden.disabled = false;
        box.classList.remove('d-none');
        if (fallback) {
            fallback.remove();
        }

        var timeout = null;

        /**
         * The choice is shown IN the input rather than on a line under it:
         * these cards put the button beside the field, and anything added
         * below pushes the two out of line.
         *
         * @param {{id: number, label: string}} row
         */
        function choose(row) {
            hidden.value = String(row.id);
            results.classList.add('d-none');
            results.replaceChildren();
            search.value = row.label;
        }

        function hide() {
            results.classList.add('d-none');
        }

        /**
         * @param {Array<{id: number, label: string, detail: string, reason: string}>} rows
         */
        function render(rows) {
            results.replaceChildren();
            if (rows.length === 0) {
                // Said rather than left blank: an empty box that simply
                // stops answering reads as a broken field.
                var empty = document.createElement('div');
                empty.className = 'list-group-item text-body-secondary small';
                empty.textContent = 'Aucun séjour ne correspond.';
                results.appendChild(empty);
                results.classList.remove('d-none');
                return;
            }

            rows.forEach(function (row) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'list-group-item list-group-item-action text-start';

                var name = document.createElement('div');
                name.textContent = row.label;
                button.appendChild(name);

                var note = row.reason || row.detail;
                if (note) {
                    var small = document.createElement('div');
                    small.className = 'small text-body-secondary';
                    small.textContent = note;
                    button.appendChild(small);
                }

                button.addEventListener('click', function () {
                    choose(row);
                });
                results.appendChild(button);
            });
            results.classList.remove('d-none');
        }

        async function run() {
            var url = '/chefs/camps/courrier/sejours?q=' + encodeURIComponent(search.value)
                + '&preferred=' + encodeURIComponent(picker.dataset.preferred || '');

            var res = await window.ScoutMagicApi.getJson(url);
            render((res.data && res.data.success && res.data.stays) || []);
        }

        search.addEventListener('input', function () {
            // Typing again un-chooses: the hidden id and the visible name
            // must never disagree, and a stale id posted under a name the
            // chief has since edited is the one failure this control could
            // produce silently.
            hidden.value = '';

            clearTimeout(timeout);
            timeout = setTimeout(run, DEBOUNCE_MS);
        });

        // The first suggestions before a single keystroke: the stay whose
        // dates this very message announces, then what is still to come.
        search.addEventListener('focus', function () {
            if (hidden.value === '' && results.classList.contains('d-none')) {
                run();
            }
        });

        document.addEventListener('click', function (event) {
            if (!picker.contains(/** @type {Node} */ (event.target))) {
                hide();
            }
        });
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                hide();
            }
        });
    }

    document.querySelectorAll('[data-stay-picker]').forEach(function (picker) {
        wire(/** @type {HTMLElement} */ (picker));
    });
})();
