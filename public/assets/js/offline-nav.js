// Offline navigation (Lot 3) — while offline, a link to a page outside
// the offline whitelist is greyed out and inert rather than leading to a
// dead end. The generic offline page (Lot 1) remains the safety net for
// anything reached by direct URL, the back button, or a stale open tab.
// Same server-side whitelist public/sw.js itself routes on (the
// #offline-config-data blob base.html.twig renders) — never a second,
// hand-maintained copy here.
(function () {
    var configEl = document.getElementById('offline-config-data');
    if (!configEl) {
        return;
    }

    var config;
    try {
        config = JSON.parse(configEl.textContent);
    } catch (e) {
        return;
    }

    var whitelistPaths = (config.whitelist || []).map(function (entry) { return entry.path; });

    function isWhitelisted(pathname) {
        return whitelistPaths.indexOf(pathname) !== -1;
    }

    function applyState() {
        var offline = !navigator.onLine;
        document.querySelectorAll('a[href^="/"]').forEach(function (link) {
            var path = link.getAttribute('href').split('?')[0].split('#')[0];
            var unavailable = offline && !isWhitelisted(path);
            link.classList.toggle('offline-link-disabled', unavailable);
            if (unavailable) {
                link.setAttribute('aria-disabled', 'true');
                link.setAttribute('tabindex', '-1');
            } else {
                link.removeAttribute('aria-disabled');
                link.removeAttribute('tabindex');
            }
        });
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a.offline-link-disabled');
        if (link) {
            event.preventDefault();
        }
    });

    window.addEventListener('online', applyState);
    window.addEventListener('offline', applyState);
    // An installed app resumes from a frozen state with a stale
    // connectivity assumption — re-check whenever the tab regains focus.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            applyState();
        }
    });

    applyState();
})();
