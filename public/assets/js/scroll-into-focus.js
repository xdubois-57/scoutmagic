/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Brings the one item a page was opened FOR into view.
//
// Some pages are reached from a link that names a particular row —
// « voir cette créance » from a finance movement, for instance — and
// that row can be far down a long accordion. The server already knows
// which one it is (it renders it open), so it marks it with
// `data-scroll-focus` and this file scrolls to it.
//
// Marked in the markup rather than computed from the query string: the
// page that renders the row is the one that knows which id it built,
// and the alternative — interpolating an id into a script — is exactly
// the inline JavaScript this file replaces.
(function () {
    const target = document.querySelector('[data-scroll-focus]');
    if (!target) {
        return;
    }

    // `smooth` on a page the visitor has not touched yet: the movement
    // itself is the message — it says « this is the one you asked for »
    // in a way a jump to the middle of a list does not.
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
})();
