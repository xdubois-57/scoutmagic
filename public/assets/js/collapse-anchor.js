/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Opens the collapsible section a URL fragment points at.
//
// A page whose boxes are folded breaks every deep link into it, and it
// breaks them SILENTLY: the browser cannot scroll to a target inside a
// `display: none` subtree, so the visitor lands at the top of the page
// with no hint that what they were sent to see is three boxes down,
// closed. Configuration > Maintenance sends itself such links on every
// round trip through Google — `Http\Controller\RemoteBackupController`
// redirects to `/config/maintenance#remote-backup` after connecting,
// disconnecting, testing and regenerating — and the operational alerts
// link to the same page to show a reading.
//
// Two shapes are accepted, because a fragment names what a human would
// name: the collapse itself, or the card that contains it. Anything
// else is left alone, and so is a fragment naming nothing.
//
// **The trigger is clicked rather than the collapse shown directly.**
// Bootstrap's delegated click handler is what keeps `aria-expanded`, the
// `.collapsed` class on the button and the chevron's rotation in step
// with the panel; showing the panel through the Collapse API would open
// it under a button still claiming it is closed. It also means this file
// has no dependency on the `bootstrap` global — if the bundle has not
// run yet, the click is a no-op on a page that still renders correctly.
(function () {
    /**
     * Every `.collapse` that has to open for `node` to be on screen:
     * itself if it is one, then each of its ancestors — a section nested
     * in a folded section is opened from the outside in.
     *
     * @param {Element} node
     * @returns {Element[]}
     */
    function collapsesAround(node) {
        /** @type {Element[]} */
        var chain = [];
        var current = /** @type {Element|null} */ (node);

        while (current) {
            if (current.classList.contains('collapse')) {
                chain.unshift(current);
            }
            current = current.parentElement;
        }

        return chain;
    }

    /**
     * @param {Element} anchor
     * @returns {Element[]}
     */
    function sectionsFor(anchor) {
        var chain = collapsesAround(anchor);
        if (chain.length > 0) {
            return chain;
        }

        // The fragment named the card, not the panel inside it. The
        // first `.collapse` in document order is that panel; a nested
        // one ("Voir plus") comes later and is not what was asked for.
        var inner = anchor.querySelector('.collapse');

        return inner ? [inner] : [];
    }

    /**
     * @param {Element} section
     * @returns {boolean} whether THIS call opened it — a section that was
     *          already showing, or whose trigger is missing, answers
     *          false, and the caller must not wait on an event it will
     *          therefore never get.
     */
    function open(section) {
        if (section.classList.contains('show')) {
            return false;
        }

        var trigger = document.querySelector(
            '[data-bs-toggle="collapse"][data-bs-target="#' + section.id + '"]'
        );
        if (!(trigger instanceof HTMLElement)) {
            return false;
        }

        trigger.click();

        return true;
    }

    /**
     * @param {string} hash
     * @returns {boolean} whether a section was found for it
     */
    function openFromHash(hash) {
        if (!hash || hash.length < 2) {
            return false;
        }

        // A fragment is attacker-writable, and `getElementById` is the
        // only lookup here that cannot be made to mean anything else —
        // no selector is ever built from it.
        //
        // **Decoding is the part that can throw.** `#%E0%A4%A` is not
        // valid percent-encoding and `decodeURIComponent` answers a
        // URIError rather than a string; uncaught at load time it would
        // take the `hashchange` registration below down with it, so one
        // malformed fragment would stop the page reacting to every
        // well-formed one after it.
        var fragment;
        try {
            fragment = decodeURIComponent(hash.slice(1));
        } catch {
            return false;
        }

        var anchor = document.getElementById(fragment);
        if (!anchor) {
            return false;
        }

        var sections = sectionsFor(anchor);
        if (sections.length === 0) {
            return false;
        }

        // Only the panels this pass actually opened, and that is the
        // whole point of `open()` reporting back. Bootstrap fires
        // `shown.bs.collapse` on a panel it opens; on one that was
        // already showing it fires nothing, so a `{ once: true }`
        // listener put there is never consumed and simply waits. It gets
        // its event the next time the reader folds and unfolds that
        // section BY HAND — and the page jumps to a fragment they left
        // long ago. This page makes that reachable: « Mise à jour »
        // ships open and holds a nested « Afficher les précédentes »,
        // so a fragment aimed at the nested one used to leave a listener
        // armed on the outer one for the rest of the visit.
        var opening = sections.filter(open);

        // After the last panel finishes opening, not before: the anchor
        // has no height while the animation runs, so scrolling now would
        // land somewhere above it. Waiting on the OUTERMOST panel is not
        // enough when sections are nested, so every one of them is
        // listened to and the last to finish wins — scrolling twice to
        // the same place costs nothing.
        //
        // Nothing to open means nothing to wait for: the anchor was
        // already on screen, and the browser's own fragment handling has
        // scrolled to it.
        opening.forEach(function (section) {
            section.addEventListener('shown.bs.collapse', function () {
                anchor.scrollIntoView();
            }, { once: true });
        });

        return true;
    }

    window.ScoutMagicCollapseAnchor = { openFromHash: openFromHash };

    openFromHash(window.location.hash);

    // A link to a fragment of the page already open in the browser
    // changes the hash without reloading anything, so the load-time pass
    // above never sees it.
    window.addEventListener('hashchange', function () {
        openFromHash(window.location.hash);
    });
})();
