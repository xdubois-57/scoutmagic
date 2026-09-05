/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * The « quelle créance ? » picker (views/partials/receivable_picker.html.twig).
 *
 * Wires every `.receivable-picker` on the page, however many there are —
 * the Rapprochement page renders one per unattributed credit. Each one
 * owns its own hidden input, so nothing here is global but the listener.
 *
 * The same shape as the receipts page's movement picker: type, wait a
 * beat, ask the server, click a result. It searches rather than filters
 * in the browser because the list is the account's whole set of open
 * receivables — a unit with three campaigns has hundreds — and because
 * the names are encrypted at rest and only ever decrypted server-side.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 250;

    function euros(cents) {
        return (cents / 100).toFixed(2).replace('.', ',') + ' €';
    }

    function wire(picker) {
        var search = picker.querySelector('input[type="text"], input:not([type])');
        var hidden = picker.querySelector('[data-receivable-value]');
        var results = picker.querySelector('[data-receivable-results]');
        if (!search || !hidden || !results) {
            return;
        }

        var timeout = null;

        /**
         * The choice is shown IN the input, and not on a line under it:
         * anything below the control makes this column taller than the
         * amount beside it, and `align-items-end` then aligns the bottoms
         * rather than the two inputs. Which is the misalignment this
         * control was reported for.
         */
        function choose(row) {
            hidden.value = String(row.id);
            results.classList.add('d-none');
            results.replaceChildren();
            search.value = row.label + ' — reste ' + euros(row.remaining_cents);
        }

        function render(rows) {
            results.replaceChildren();
            if (rows.length === 0) {
                results.classList.add('d-none');
                return;
            }

            rows.forEach(function (row) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'list-group-item list-group-item-action text-start';
                button.textContent = row.label + ' — ' + row.communication + ' — reste ' + euros(row.remaining_cents);
                button.addEventListener('click', function () {
                    choose(row);
                });
                results.appendChild(button);
            });
            results.classList.remove('d-none');
        }

        async function run() {
            var url = '/finance/reconciliation/creances?account_id='
                + encodeURIComponent(picker.dataset.accountId || '')
                + '&q=' + encodeURIComponent(search.value);
            if (search.value.trim() === '' && picker.dataset.nearAmountCents) {
                url += '&near_amount_cents=' + encodeURIComponent(picker.dataset.nearAmountCents);
            }

            var res = await window.ScoutMagicApi.getJson(url);
            render((res.data?.success && res.data.receivables) || []);
        }

        search.addEventListener('input', function () {
            // Typing again un-chooses: the hidden id and the visible name
            // must never disagree, and a stale id submitted under a name
            // the treasurer has since edited is the one failure this
            // control could produce silently.
            hidden.value = '';

            clearTimeout(timeout);
            timeout = setTimeout(run, DEBOUNCE_MS);
        });

        // The first suggestions before a single keystroke: with no text
        // and a credit to attach, the receivables owing exactly that
        // amount come first, which is usually the whole answer.
        search.addEventListener('focus', function () {
            if (hidden.value === '' && results.classList.contains('d-none')) {
                run();
            }
        });

        // An overlay has to be closable without choosing anything —
        // otherwise it sits on top of the amount field beside it.
        document.addEventListener('click', function (event) {
            if (!picker.contains(/** @type {Node} */ (event.target))) {
                results.classList.add('d-none');
            }
        });
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                results.classList.add('d-none');
            }
        });
    }

    document.querySelectorAll('.receivable-picker').forEach(wire);
})();
