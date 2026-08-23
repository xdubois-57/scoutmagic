/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

(function () {
    var buttons = document.querySelectorAll('.desktop-menu-btn');
    var panels = document.querySelectorAll('.desktop-megamenu');

    /**
     * @param {string} menuId
     * @returns {HTMLElement|null}
     */
    function panelFor(menuId) {
        return /** @type {HTMLElement|null} */ (
            document.querySelector('.desktop-megamenu[data-megamenu-id="' + menuId + '"]')
        );
    }

    /**
     * @param {string} menuId
     * @returns {HTMLElement|null}
     */
    function triggerFor(menuId) {
        return /** @type {HTMLElement|null} */ (
            document.querySelector('.desktop-menu-btn[data-menu-id="' + menuId + '"]')
        );
    }

    // The trigger's `active` class is NOT touched here: it marks the menu
    // the current page belongs to, rendered server-side and true whatever
    // is open (partials/nav.html.twig). Which panel is open is carried by
    // aria-expanded alone — before, the two shared one class, which was
    // harmless while the sub-menu row was permanent and would now mean
    // closing a panel wiped the "you are here" underline off the bar.
    function hideAllDesktopMenus() {
        panels.forEach(function (p) { p.classList.add('d-none'); });
        buttons.forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    }

    // Always ENSURES the given menu's panel is open — never a toggle.
    // Exposed on window so partials/breadcrumb_bar.html.twig's parent
    // buttons (public/assets/js/breadcrumb.js) can open a menu
    // deterministically: a raw click() on the .desktop-menu-btn would
    // toggle it CLOSED if it happened to already be the open one.
    function showDesktopMenu(menuId) {
        var target = panelFor(menuId);
        if (!target) {
            return;
        }

        hideAllDesktopMenus();

        target.classList.remove('d-none');
        var btn = triggerFor(menuId);
        if (btn) {
            btn.setAttribute('aria-expanded', 'true');
        }
    }

    // Click only, never hover: a hover-opened panel is unusable on a touch
    // laptop and hostile to anyone navigating by keyboard. No focus trap
    // either — the panel sits right after the triggers in DOM order, so
    // Tab already walks into it and out the far side.
    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var menuId = btn.getAttribute('data-menu-id') || '';
            var target = panelFor(menuId);
            var wasVisible = target && !target.classList.contains('d-none');

            if (wasVisible) {
                hideAllDesktopMenus();
            } else {
                showDesktopMenu(menuId);
            }
        });
    });

    // Escape closes and hands focus back to the trigger that opened it,
    // rather than leaving it wherever inside a panel that no longer
    // exists on screen.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }

        var openTrigger = /** @type {HTMLElement|null} */ (
            document.querySelector('.desktop-menu-btn[aria-expanded="true"]')
        );
        if (!openTrigger) {
            return;
        }

        hideAllDesktopMenus();
        openTrigger.focus();
    });

    // mousedown, not click: the panel floats over the page, so it has to
    // be out of the way before the press it was covering completes —
    // otherwise the first click outside only dismisses the panel and the
    // visitor has to aim at the same control twice.
    document.addEventListener('mousedown', function (e) {
        var nav = document.getElementById('desktopNav');
        if (!nav || (e.target instanceof Node && nav.contains(e.target))) {
            return;
        }

        hideAllDesktopMenus();
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
