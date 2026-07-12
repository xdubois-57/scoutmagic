(function () {
    var buttons = document.querySelectorAll('.desktop-menu-btn');
    var submenus = document.querySelectorAll('.desktop-submenu');

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var menuId = btn.getAttribute('data-menu-id');
            var target = document.querySelector('[data-submenu-id="' + menuId + '"]');
            var wasVisible = target && !target.classList.contains('d-none');

            // Hide all submenus, reset all buttons
            submenus.forEach(function (s) { s.classList.add('d-none'); });
            buttons.forEach(function (b) {
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline-secondary');
            });

            if (!wasVisible && target) {
                target.classList.remove('d-none');
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-primary');
            }
        });
    });
})();
