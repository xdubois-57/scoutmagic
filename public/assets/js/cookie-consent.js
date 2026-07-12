document.addEventListener('DOMContentLoaded', function () {
    var banner = document.getElementById('cookie-banner');
    if (!banner) return;

    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    document.getElementById('cookie-accept-all').addEventListener('click', function () {
        fetch('/cookies/accept-all', {
            method: 'POST',
            headers: { 'X-CSRF-Token': getCsrf() }
        }).then(function () {
            banner.remove();
        });
    });

    document.getElementById('cookie-reject-all').addEventListener('click', function () {
        fetch('/cookies/reject-all', {
            method: 'POST',
            headers: { 'X-CSRF-Token': getCsrf() }
        }).then(function () {
            banner.remove();
        });
    });

    document.getElementById('cookie-customize').addEventListener('click', function () {
        window.location.href = '/cookies';
    });
});
