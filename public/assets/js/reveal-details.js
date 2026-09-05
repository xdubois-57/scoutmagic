/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Click this row to see its detail », in one place.
//
// Three screens had written this by hand — the journal's table rows, the
// journal's cards (design.md §7.6: the same log is a table on a wide
// screen and cards on a narrow one), and the scheduled-actions table —
// each hard-coding the class name of the element it revealed, each in
// its own inline <script>.
//
// Markup-driven instead of class-driven, so a fourth screen needs no
// JavaScript at all:
//
//   <tr class="…" data-reveal="next:.error-row">        the sibling that follows
//   <div class="card" data-reveal=".context-detail">    an element inside
//
// One delegated listener on the document, so rows added after load work
// too, and a control inside a revealing row (a link, a button) still
// does its own job — the handler ignores a click that landed on one.
(function () {
    /** @param {HTMLElement} trigger */
    function targetOf(trigger) {
        var spec = trigger.dataset.reveal || '';
        if (spec.startsWith('next:')) {
            var next = trigger.nextElementSibling;
            var selector = spec.slice(5);
            return next?.matches(selector) ? next : null;
        }
        return trigger.querySelector(spec);
    }

    document.addEventListener('click', function (event) {
        var target = /** @type {HTMLElement|null} */ (event.target);
        if (!target?.closest) {
            return;
        }

        var trigger = /** @type {HTMLElement|null} */ (target.closest('[data-reveal]'));
        if (!trigger) {
            return;
        }

        // A link or a button inside the row keeps its own meaning.
        if (target.closest('a, button, input, select, textarea, label')) {
            return;
        }

        var detail = targetOf(trigger);
        if (detail) {
            detail.classList.toggle('d-none');
        }
    });
})();
