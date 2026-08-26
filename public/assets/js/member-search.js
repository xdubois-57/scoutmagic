/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Two admin screens, one file, each section guarded on its own anchor:
// the export-selection conveniences of the member search
// (core/View/templates/admin/members/search.html.twig), and the scout-year
// offset buttons and "leaving next year" departure controls of a member's
// own page (core/View/templates/admin/members/show.html.twig). They used
// to be one screen, and the guards are what let the split cost nothing:
// each half simply finds no anchor on the other's page.
// Extracted from the template's two inline <script> blocks so the Vitest
// suite can exercise the production code directly
// (tests/js/member-search.test.js).
//
// Three things changed on the way out of the template:
//   - the two hand-written meta[name="csrf-token"] readers are gone; both
//     endpoints ride ScoutMagicApi.postJson, which carries the token in the
//     body AND the header;
//   - HTTP success (res.ok) and business success (res.data.success) are two
//     separate facts again — the raw fetch + res.json() this replaces threw
//     on an HTML 500 page inside a try/finally with no catch, so a failed
//     save re-enabled the buttons and said nothing at all;
//   - the three alert() failures are toasts (design.md §7.5).
//
// This page renders member personal data (AGENTS.md § Security checklist,
// SECURITY.md). Nothing here logs, nothing here builds a URL out of a member
// value — the only interpolated value is the member_year row id, parsed as a
// positive integer first — and the only server text that reaches the DOM
// (the branch-year label) is written with textContent, never as markup.
(function () {
    var api = window.ScoutMagicApi;

    var exportSubmitBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('member-export-selection-btn'));
    var offsetCard = document.getElementById('scout-year-offset-card');
    var departureCard = document.getElementById('departure-card');
    var noteEditButtons = document.querySelectorAll('.member-note-edit-btn');

    // A no-op on every other page of the site, and on this one before a
    // search has returned anything.
    if (!exportSubmitBtn && !offsetCard && !departureCard && noteEditButtons.length === 0) {
        return;
    }

    /**
     * The failure line for one envelope: the server's own message when it
     * answered JSON, a generic one when it did not (postJson folds a
     * network error or an HTML error page into data:null).
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @param {string} fallback
     * @returns {void}
     */
    function toastError(res, fallback) {
        var message = (res.data && res.data.error) || fallback;
        window.ScoutMagicToast.show(message, { variant: 'error' });
    }

    /**
     * The member_year row id a detail-card control acts on. Parsed as a
     * positive integer before it is ever interpolated into an endpoint
     * path: a member value must never build a URL, and an id that is not
     * one is not an id.
     *
     * @param {HTMLElement} card
     * @returns {number} 0 when the card carries no usable id
     */
    function memberYearIdOf(card) {
        var parsed = parseInt(card.dataset.memberYearId, 10);
        return parsed > 0 ? parsed : 0;
    }

    // --- Export selection ---
    // The form itself works without any of this (plain selected[] checkboxes
    // in a GET form, and an empty selection falls back server-side to "all
    // results of ?q="); this only keeps the submit button honest (disabled
    // with nothing checked, live count) and wires the master checkbox.
    (function () {
        var checkboxes = Array.prototype.slice.call(
            /** @type {NodeListOf<HTMLInputElement>} */ (document.querySelectorAll('.member-export-checkbox'))
        );
        var selectAll = /** @type {HTMLInputElement|null} */ (document.getElementById('member-export-select-all'));
        var countSpan = document.getElementById('member-export-selection-count');
        if (!selectAll || !exportSubmitBtn || !countSpan) {
            return;
        }

        function refresh() {
            var checked = checkboxes.filter(function (cb) { return cb.checked; }).length;
            exportSubmitBtn.disabled = checked === 0;
            countSpan.textContent = checked > 0 ? ' (' + checked + ')' : '';
            selectAll.checked = checked === checkboxes.length && checked > 0;
            selectAll.indeterminate = checked > 0 && checked < checkboxes.length;
        }

        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
            refresh();
        });
        checkboxes.forEach(function (cb) { cb.addEventListener('change', refresh); });
        refresh();
    })();

    // The scroll-into-view that used to live here is gone with the card it
    // scrolled to: a member's detail is its own page now, so the browser
    // lands at the top of it by itself.

    // --- Notes internes: reveal one entry's edit form ---
    // Both forms are already in the DOM and both post on their own, so
    // the page works with JavaScript off — a reader without it simply
    // sees every edit field open. This only folds them away.
    noteEditButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var id = button.dataset.noteId;
            var form = id ? document.getElementById('member-note-edit-' + id) : null;
            if (!form) return;
            form.classList.toggle('d-none');
            var field = form.querySelector('textarea');
            if (field && !form.classList.contains('d-none')) field.focus();
        });
    });

    // --- Scout-year offset ---
    if (offsetCard) {
        (function () {
            var memberYearId = memberYearIdOf(offsetCard);
            /** @type {NodeListOf<HTMLButtonElement>} */
            var buttons = offsetCard.querySelectorAll('.offset-btn');
            var dot = document.getElementById('scout-year-offset-dot');
            var label = document.getElementById('scout-year-offset-label');
            if (!memberYearId || !buttons.length) {
                return;
            }

            /** @param {boolean} disabled */
            function setButtonsDisabled(disabled) {
                buttons.forEach(function (b) { b.disabled = disabled; });
            }

            buttons.forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    var offset = parseInt(btn.dataset.offset, 10);

                    setButtonsDisabled(true);
                    try {
                        var res = await api.postJson('/members/' + memberYearId + '/scout-year-offset', { offset: offset });
                        if (!res.data || !res.data.success) {
                            toastError(res, 'Erreur.');
                            return;
                        }
                        buttons.forEach(function (b) {
                            b.classList.toggle('active', parseInt(b.dataset.offset, 10) === offset);
                        });
                        if (label) {
                            // Server text, so text — never markup.
                            label.textContent = res.data.branch_year_label || '—';
                        }
                        if (dot && res.data.branch_color) {
                            // A CSSOM property assignment, not a cssText
                            // one: an unusable value is dropped by the
                            // browser, it cannot become a declaration.
                            dot.style.backgroundColor = res.data.branch_color;
                        }
                    } finally {
                        setButtonsDisabled(false);
                    }
                });
            });
        })();
    }

    // --- Departure ("leaving next year") ---
    if (departureCard) {
        (function () {
            var memberYearId = memberYearIdOf(departureCard);
            var checkbox = /** @type {HTMLInputElement|null} */ (document.getElementById('departure-checkbox'));
            var commentRow = document.getElementById('departure-comment-row');
            var comment = /** @type {HTMLTextAreaElement|null} */ (document.getElementById('departure-comment'));
            if (!memberYearId || !checkbox || !commentRow || !comment) {
                return;
            }

            /**
             * One field at a time — `leaving` XOR `comment` — which is the
             * concurrency contract the endpoint documents.
             *
             * @param {Object} payload
             * @returns {void}
             */
            function save(payload) {
                api.postJson('/members/' + memberYearId + '/departure', payload).then(function (res) {
                    if (res.data && res.data.success) {
                        return;
                    }
                    // postJson answers status 0 — and only status 0 — when
                    // the request never reached a server, which is the one
                    // case the replaced .catch() handler covered.
                    if (res.status === 0) {
                        window.ScoutMagicToast.show('Erreur réseau.', { variant: 'error' });
                        return;
                    }
                    toastError(res, 'Erreur lors de l\'enregistrement.');
                });
            }

            checkbox.addEventListener('change', function () {
                commentRow.style.display = checkbox.checked ? '' : 'none';
                save({ leaving: checkbox.checked });
            });

            comment.addEventListener('blur', function () {
                save({ comment: comment.value });
            });
        })();
    }
})();
