/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Departures page (modules/registration/views/departures.html.twig):
// ticking « ne sera plus là l'année prochaine » for a member, and the
// comment that goes with it. Extracted from the template's inline
// <script> so the Vitest suite can exercise the production code directly
// (tests/js/registration-departures.test.js).
//
// Both saves are silent on success — this is a list an animateur goes
// down one row at a time, and a confirmation per row would be noise. A
// FAILURE is not silent: the two `alert()` boxes it used to raise are
// toasts (design.md §7.5), and the row is put back the way the server
// still has it, so the screen never claims a departure that was not
// recorded.
(function () {
    var api = window.ScoutMagicApi;

    /** @type {NodeListOf<HTMLInputElement>} */
    var checkboxes = document.querySelectorAll('.departure-checkbox');
    /** @type {NodeListOf<HTMLTextAreaElement>} */
    var comments = document.querySelectorAll('.departure-comment');

    // A no-op on every other page of the site.
    if (!checkboxes.length && !comments.length) {
        return;
    }

    /**
     * @param {string} memberYearId
     * @param {Record<string, any>} payload
     * @returns {Promise<boolean>} whether the server recorded it
     */
    function save(memberYearId, payload) {
        return api.postJson('/departs/' + memberYearId, payload).then(function (res) {
            if (res.data && res.data.success) {
                return true;
            }
            window.ScoutMagicToast.show(
                res.status === 0
                    ? 'Erreur réseau.'
                    : (res.data && res.data.error) || "Erreur lors de l'enregistrement.",
                { variant: 'error' }
            );
            return false;
        });
    }

    /**
     * @param {string|undefined} memberYearId
     * @param {boolean} shown
     */
    function toggleCommentRow(memberYearId, shown) {
        var row = /** @type {HTMLElement|null} */ (document.querySelector(
            '.departure-comment-row[data-member-year-id="' + memberYearId + '"]'
        ));
        if (row) {
            row.style.display = shown ? '' : 'none';
        }
    }

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            var memberYearId = checkbox.dataset.memberYearId || '';
            toggleCommentRow(memberYearId, checkbox.checked);

            save(memberYearId, { leaving: checkbox.checked }).then(function (recorded) {
                if (recorded) {
                    return;
                }
                // The server did not take it: put the box, and the
                // comment row it governs, back where the server still
                // has them.
                checkbox.checked = !checkbox.checked;
                toggleCommentRow(memberYearId, checkbox.checked);
            });
        });
    });

    comments.forEach(function (field) {
        field.addEventListener('blur', function () {
            save(field.dataset.memberYearId || '', { comment: field.value });
        });
    });
})();
