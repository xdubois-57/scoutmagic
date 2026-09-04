/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * « Rattacher à… » on a message of the unit's mail
 * (modules/inbound_mail/views/mailbox/show.html.twig).
 *
 * Pick a module, type what you would say out loud — a place and a month,
 * a renter's name, a booking reference — and choose. The server answers
 * from the module's own directory (Api\ReferenceDirectory), so this file
 * knows nothing about what a stay or a booking is.
 *
 * Progressive: without this script the reference field is a plain text
 * input, and a reference typed in full is accepted as it stands. With it,
 * choosing a suggestion writes the reference into that same field and
 * shows the chosen label beside it, so what the form posts never differs
 * from what the chief saw.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 250;

    /**
     * @param {HTMLElement} form
     */
    function wire(form) {
        var module = /** @type {HTMLSelectElement|null} */ (form.querySelector('[data-attach-module]'));
        var input = /** @type {HTMLInputElement|null} */ (form.querySelector('[data-attach-reference]'));
        var results = /** @type {HTMLElement|null} */ (form.querySelector('[data-attach-results]'));
        var chosen = /** @type {HTMLElement|null} */ (form.querySelector('[data-attach-chosen]'));
        if (!module || !input || !results || !chosen) {
            return;
        }

        var timeout = null;

        function hide() {
            results.classList.add('d-none');
        }

        /**
         * @param {{reference: string, label: string, detail: string|null}} row
         */
        function choose(row) {
            input.value = row.reference;
            chosen.textContent = row.label;
            chosen.classList.remove('d-none');
            hide();
            results.replaceChildren();
        }

        /**
         * @param {Array<{reference: string, label: string, detail: string|null}>} rows
         */
        function render(rows) {
            results.replaceChildren();
            if (rows.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'list-group-item text-body-secondary small';
                empty.textContent = 'Rien ne correspond.';
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

                if (row.detail) {
                    var small = document.createElement('div');
                    small.className = 'small text-body-secondary';
                    small.textContent = row.detail;
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
            var query = input.value.trim();
            if (query === '' || !module.value) {
                hide();
                return;
            }

            var url = '/courrier/cibles?module=' + encodeURIComponent(module.value)
                + '&q=' + encodeURIComponent(query);
            var res = await window.ScoutMagicApi.getJson(url);
            render((res.data?.success && res.data.targets) || []);
        }

        input.addEventListener('input', function () {
            // Typing again un-chooses: the label shown must never describe
            // a reference the chief has since edited.
            chosen.classList.add('d-none');
            chosen.textContent = '';

            clearTimeout(timeout);
            timeout = setTimeout(run, DEBOUNCE_MS);
        });
        module.addEventListener('change', function () {
            chosen.classList.add('d-none');
            input.value = '';
            hide();
        });
        document.addEventListener('click', function (event) {
            if (!form.contains(/** @type {Node} */ (event.target))) {
                hide();
            }
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                hide();
            }
        });
    }

    document.querySelectorAll('[data-inbound-attach]').forEach(function (form) {
        wire(/** @type {HTMLElement} */ (form));
    });
})();
