/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// partials/breadcrumb_bar.html.twig — a breadcrumb parent (e.g. "Espace
// animés") never links to a specific page anymore: clicking it OPENS that
// menu's section so the visitor picks the sub-page themselves, rather than
// landing on a single, arbitrarily-chosen page within it. Desktop: opens
// the menu's own mega-menu panel (public/assets/js/nav.js's
// showDesktopMenu(), never a raw button click — that would toggle an
// already-open menu closed instead of ensuring it's open). Mobile: opens
// the offcanvas and expands the matching accordion section.
(function () {
    var buttons = document.querySelectorAll('.breadcrumb-parent-btn');
    if (buttons.length === 0) {
        return;
    }

    // Matches Bootstrap 5's own --bs-breakpoint-lg (992px) — the same
    // breakpoint that switches the mobile header/offcanvas (d-lg-none)
    // and the desktop bar (d-none d-lg-block) in partials/nav.html.twig.
    var DESKTOP_QUERY = '(min-width: 992px)';

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var menuId = /** @type {HTMLElement} */ (btn).dataset.openMenu;
            if (!menuId) {
                return;
            }

            if (window.matchMedia(DESKTOP_QUERY).matches) {
                openDesktopMenu(menuId);
            } else {
                openMobileMenu(menuId);
            }
        });
    });

    function openDesktopMenu(menuId) {
        if (window.ScoutMagicNav && typeof window.ScoutMagicNav.showDesktopMenu === 'function') {
            window.ScoutMagicNav.showDesktopMenu(menuId);
        }
        var menuBtn = document.querySelector('.desktop-menu-btn[data-menu-id="' + menuId + '"]');
        if (menuBtn) {
            menuBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function openMobileMenu(menuId) {
        var offcanvasEl = document.getElementById('navOffcanvas');
        if (!offcanvasEl || typeof bootstrap === 'undefined') {
            return;
        }

        var collapseEl = document.getElementById('mob-' + menuId);
        if (collapseEl) {
            // Expand only once the offcanvas has actually finished
            // opening — showing the Collapse immediately can race with
            // the offcanvas's own opening transition/focus trap.
            var expand = function () {
                bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false }).show();
                offcanvasEl.removeEventListener('shown.bs.offcanvas', expand);
            };
            offcanvasEl.addEventListener('shown.bs.offcanvas', expand);
        }

        bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl).show();
    }
})();
