/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Mass-mail tracking page (modules/mass_mail/views/tracking.html.twig):
// the client-side filter over the recipients table, and the per-row
// « Renvoyer ». Extracted from the template's inline <script> so the
// Vitest suite can exercise the production code directly
// (tests/js/mass-mail-tracking.test.js).
//
// The filtering is client-side on purpose: the whole recipient list is
// already on the page, and someone hunting for one address among four
// hundred should not wait for a round trip per keystroke. The haystack
// is `data-search`, lowercased server-side, so an accented name matches
// what the server wrote rather than what the browser would fold.
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
            var res = await api.withDisabled(btn, function () {
                return api.postJson('/mass-mail/recipients/' + btn.dataset.id + '/resend', {});
            });

            if (res.data && res.data.success) {
                // The row's status, its timestamps and the counters at
                // the top of the page all move — the page re-renders
                // rather than being patched by hand.
                window.location.reload();
                return;
            }
            window.ScoutMagicToast.show(
                res.status === 0
                    ? 'Erreur réseau.'
                    : (res.data && res.data.error) || 'Erreur.',
                { variant: 'error' }
            );
        });
    });
})();
