/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

(function () {
    var buttons = document.querySelectorAll('.desktop-menu-btn');
    var submenus = document.querySelectorAll('.desktop-submenu');

    function hideAllDesktopMenus() {
        submenus.forEach(function (s) { s.classList.add('d-none'); });
        buttons.forEach(function (b) {
            b.classList.remove('btn-primary');
            b.classList.add('btn-outline-secondary');
        });
    }

    // Always ENSURES the given menu's submenu bar is open — never a
    // toggle. Exposed on window so partials/breadcrumb_bar.html.twig's
    // parent buttons (public/assets/js/breadcrumb.js) can open a menu
    // deterministically: a raw click() on the .desktop-menu-btn would
    // toggle it CLOSED if it happened to already be the active one.
    function showDesktopMenu(menuId) {
        var target = document.querySelector('.desktop-submenu[data-submenu-id="' + menuId + '"]');
        if (!target) {
            return;
        }

        hideAllDesktopMenus();

        target.classList.remove('d-none');
        var btn = document.querySelector('.desktop-menu-btn[data-menu-id="' + menuId + '"]');
        if (btn) {
            btn.classList.remove('btn-outline-secondary');
            btn.classList.add('btn-primary');
        }
    }

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var menuId = btn.getAttribute('data-menu-id');
            var target = document.querySelector('.desktop-submenu[data-submenu-id="' + menuId + '"]');
            var wasVisible = target && !target.classList.contains('d-none');

            if (wasVisible) {
                hideAllDesktopMenus();
            } else {
                showDesktopMenu(menuId);
            }
        });
    });

    window.ScoutMagicNav = window.ScoutMagicNav || {};
    window.ScoutMagicNav.showDesktopMenu = showDesktopMenu;

    // Bootstrap's role="switch" pattern (form-check form-switch) is purely
    // visual — the aria-checked a screen reader actually announces is the
    // page's own responsibility, and Twig only ever renders it once, at
    // load. Delegated site-wide so every current and future switch stays in
    // sync on ordinary user interaction without each page's own script
    // needing to remember to wire this up. This only covers a real 'change'
    // event; anything that sets .checked programmatically (no event fires)
    // must call ScoutMagicNav.syncSwitchAriaChecked() itself — see
    // public/assets/js/push-notifications.js and
    // public/assets/js/notification-preferences.js.
    function syncSwitchAriaChecked(input) {
        input.setAttribute('aria-checked', input.checked ? 'true' : 'false');
    }

    document.addEventListener('change', function (e) {
        var target = e.target;
        if (target instanceof HTMLInputElement && target.type === 'checkbox' && target.getAttribute('role') === 'switch') {
            syncSwitchAriaChecked(target);
        }
    });

    window.ScoutMagicNav.syncSwitchAriaChecked = syncSwitchAriaChecked;
})();
