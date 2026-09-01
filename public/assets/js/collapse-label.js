/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Afficher les 15 précédentes » ⇄ « Afficher moins » on a Bootstrap
// collapse trigger.
//
// Bootstrap toggles `.collapsed` and `aria-expanded` on the trigger
// itself, so the obvious implementation is two spans and one CSS rule
// hiding whichever one does not apply. That is what shipped, and
// production showed why it is the wrong call HERE: `/assets/css/app.css`
// is part of the service worker's app shell and is matched with
// `ignoreSearch` (see public/sw.js), so its `?v=` cache-buster does NOT
// force a refetch — until the previous worker is released, a page can
// legitimately render NEW html against the PREVIOUS stylesheet. A
// CSS-only swap then fails by showing both labels at once, and the
// button reads « Afficher les 15 précédentes Afficher moins ».
//
// So the label lives in the markup exactly once, and this file — not
// precached, therefore never stale — swaps its text. Degradation is the
// point: if this script never runs, the button still reads correctly in
// its collapsed state, it just stops changing.
(function () {
    document.querySelectorAll('[data-collapse-label-expanded]').forEach(function (node) {
        var trigger = /** @type {HTMLElement} */ (node);
        var selector = trigger.dataset.bsTarget || '';
        var target = selector ? document.querySelector(selector) : null;
        var label = trigger.querySelector('[data-collapse-label]');
        if (!target || !label) {
            return;
        }

        var whenCollapsed = label.textContent || '';
        var whenExpanded = trigger.dataset.collapseLabelExpanded || whenCollapsed;

        // `show`/`hide` rather than `shown`/`hidden`: the label belongs to
        // the click that was just made, not to the end of a 350 ms
        // animation the reader is already looking past.
        target.addEventListener('show.bs.collapse', function () {
            label.textContent = whenExpanded;
        });
        target.addEventListener('hide.bs.collapse', function () {
            label.textContent = whenCollapsed;
        });
    });
})();
