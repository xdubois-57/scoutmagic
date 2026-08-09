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
})();
