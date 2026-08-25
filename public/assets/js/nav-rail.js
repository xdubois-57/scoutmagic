/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Nav rail — see core/View/templates/partials/nav_rail.html.twig.
// This is the whole file: scroll the selected tab into view, so the
// current page is visible without the visitor having to swipe the rail
// looking for it. Everything else about the rail is server-rendered
// markup and Bootstrap's own `nav-underline`; selection is a plain
// <a href>, so the rail is complete and operable with no JS at all.
//
// The scroll honours prefers-reduced-motion: an unrequested horizontal
// animation is exactly what that setting exists to suppress.
(function () {
    function prefersReducedMotion() {
        return typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function init() {
        var behavior = /** @type {ScrollBehavior} */ (prefersReducedMotion() ? 'auto' : 'smooth');

        document.querySelectorAll('.nav-rail').forEach(function (railEl) {
            var selected = railEl.querySelector('.nav-link[aria-current="page"]');
            if (!selected || typeof selected.scrollIntoView !== 'function') return;

            selected.scrollIntoView({ behavior: behavior, inline: 'center', block: 'nearest' });
        });
    }

    init();
})();
