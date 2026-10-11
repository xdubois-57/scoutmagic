/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// pwa/offline.html.twig's script — precached as part of the app shell
// (public/sw.js's APP_SHELL_BASE_URLS) and served back with no network at
// all, so this must never assume anything beyond the DOM it ships with.
// A plain external script, not an inline onclick: the CSP's script-src has
// no 'unsafe-inline' (Core\Http\Response::buildCsp()), and this page is
// precached once with no per-request nonce to attach anyway.
(function () {
    const retryBtn = document.getElementById('offline-retry-btn');
    if (retryBtn) {
        retryBtn.addEventListener('click', function () {
            window.location.reload();
        });
    }

    const backBtn = document.getElementById('offline-back-btn');
    if (backBtn) {
        backBtn.addEventListener('click', function () {
            window.history.back();
        });
    }

    revealHomeIfSaved();

    /**
     * « Accueil » only when the home page would actually open (#883).
     * Without a copy, the link reloaded `/`, the service worker answered
     * with this same screen, and the button looked as if it did nothing.
     * So: a copy of `/` in the content cache it reads, recent enough for the
     * service worker to serve it — the same age rule as its cachedCopy(),
     * read from the setting the server baked into this page. Without one,
     * the screen says that nothing is saved on this device yet.
     *
     * Every failure — no Cache Storage, a denied one — leaves both hidden:
     * a button that may not work is worse than none.
     */
    function revealHomeIfSaved() {
        const home = document.getElementById('offline-home-link');
        const nothingSaved = document.getElementById('offline-nothing-saved');
        if (!home || !nothingSaved || !('caches' in window)) {
            return;
        }
        const days = Number(document.body.dataset.stalenessDays) || 30;

        homeIsSaved(days).then(function (saved) {
            home.classList.toggle('d-none', !saved);
            nothingSaved.classList.toggle('d-none', saved);
        }).catch(function () {});
    }

    /**
     * Whether the content cache the service worker reads holds a copy of
     * `/` younger than $days.
     *
     * The one cache, never any `content-*`: public/sw.js serves a page only
     * from `content-{account_scope}-{version}` of its current config, and
     * a cache left by an earlier release or another account outlives a
     * deploy. A copy there would reveal « Accueil » for a page the worker
     * then cannot find — the very dead button this replaces. The config is
     * the entry sw.js's getOfflineConfig() reads, under the same names.
     *
     * @param {number} days
     * @returns {Promise<boolean>}
     */
    async function homeIsSaved(days) {
        const stored = await (await caches.open('offline-config')).match('https://offline-config.internal/v1');
        const config = stored ? await stored.json() : null;
        if (!config?.consent) {
            return false;
        }
        const name = 'content-' + config.account_scope + '-' + config.version;
        if (!(await caches.has(name))) {
            return false;
        }
        const copy = await (await caches.open(name)).match('/');
        const at = copy ? Date.parse(copy.headers.get('Date') || '') : Number.NaN;

        return !Number.isNaN(at) && Date.now() - at <= days * 86400000;
    }
})();
