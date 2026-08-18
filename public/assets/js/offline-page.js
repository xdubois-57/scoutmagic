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
})();
