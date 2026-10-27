/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Mass-mail tracking page (modules/mass_mail/views/tracking.html.twig):
// the client-side filter over the table of messages — one per address
// (issue #999) — and the per-message « Renvoyer ». Extracted from the template's inline <script> so the
// Vitest suite can exercise the production code directly
// (tests/js/mass-mail-tracking.test.js).
//
// The filtering is client-side on purpose: every message is already on
// the page, and someone hunting for one address among four hundred should
// not wait for a round trip per keystroke. The haystack is `data-search` —
// the address and the names of the members the message concerns —
// lowercased server-side, so an accented name matches what the server
// wrote rather than what the browser would fold.
//
// Corrections that came with the move: the `alert()` is a toast
// (design.md §7.5), and a resend that fails at the network — not just
// one the server refused — now says so instead of leaving the button
// disabled with no explanation at all.
(function () {
    var api = window.ScoutMagicApi;

    var searchInput = /** @type {HTMLInputElement|null} */ (document.getElementById('mmt-search'));
    var statusSelect = /** @type {HTMLSelectElement|null} */ (document.getElementById('mmt-status'));
    /** @type {NodeListOf<HTMLButtonElement>} */
    var resendBtns = document.querySelectorAll('.mmt-resend-btn');

    // A no-op on every other page of the site.
    if (!searchInput && !statusSelect && !resendBtns.length) {
        return;
    }

    if (searchInput && statusSelect) {
        var applyFilter = function () {
            var search = searchInput.value.trim().toLowerCase();
            var status = statusSelect.value;

            /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll(
                '#mmt-table tbody tr[data-status]'
            )).forEach(function (row) {
                var matchesSearch = !search
                    || (row.dataset.search || '').includes(search);
                var matchesStatus = !status || row.dataset.status === status;
                row.classList.toggle('d-none', !(matchesSearch && matchesStatus));
            });
        };

        searchInput.addEventListener('input', applyFilter);
        statusSelect.addEventListener('change', applyFilter);
        // A browser that restored the previous filter on a reload must
        // get the table it describes, not the whole list.
        applyFilter();
    }

    resendBtns.forEach(function (btn) {
        btn.addEventListener('click', async function () {
            // An e-mail to somebody else cannot be recalled, so it asks
            // first (design.md §7.5). This gesture is driven from
            // JavaScript, so `data-confirm` — which confirm.js delegates
            // from a <form> — does not apply; the same dialog is opened by
            // hand, as the other modules do (#485).
            //
            // The message says « repasse en attente », not « part
            // immédiatement »: `MassMailService::resendDelivery()` puts the
            // message back to `pending` and calls
            // `ensureBatchTaskScheduled()`, which queues a batch task — and
            // returns early when one is already queued, so the resend rides
            // that one. The page reloads onto the row reading « En
            // attente », which the first wording contradicted. It goes to
            // this one address, and notifies nobody again (issue #999).
            var confirmed = await window.ScoutMagicConfirm.ask({
                message: 'Renvoyer ce message à ' + (btn.dataset.recipient || 'cette adresse')
                    + ' ? Il repasse en attente et part d\'ici quelques minutes, sans pouvoir être rappelé.'
                    + ' Aucune nouvelle notification n\'est envoyée.',
                confirmLabel: 'Renvoyer'
            });
            if (!confirmed) return;

            var res = await api.withDisabled(btn, function () {
                return api.postJson('/mass-mail/deliveries/' + btn.dataset.id + '/resend', {});
            });

            if (res.data?.success) {
                // The row's status, its timestamps and the counters at
                // the top of the page all move — the page re-renders
                // rather than being patched by hand.
                window.location.reload();
                return;
            }
            window.ScoutMagicToast.show(
                res.status === 0
                    ? 'Erreur réseau.'
                    : res.data?.error || 'Erreur.',
                { variant: 'error' }
            );
        });
    });
})();
