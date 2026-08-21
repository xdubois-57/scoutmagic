/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Offline navigation + generic unavailability dialog (Lot 4) — three
// generic layers, no per-page wiring, so a page or a POST written months
// from now is covered without anyone remembering this feature exists:
//
// 1. Delegated CAPTURE listeners on `document` for `click` (internal
//    `<a href="/…">`) and `submit` (any `<form>`) — while offline, a
//    click on a link outside the whitelist, or ANY form submission, is
//    prevented and opens the dialog instead of leading to a dead end.
//    Capture phase (not bubble) so this always runs before any other
//    handler on the page, regardless of what else is listening for the
//    same click/submit.
// 2. A single `window.fetch` wrapper, installed once here — the hole
//    layers 1 and 2 would otherwise leave: marking a notification read,
//    list-editor.js persisting a reorder, chip pickers, and every future
//    module doing a POST with no `<form>` in sight all go through
//    fetch() directly, never a real page navigation. GET requests pass
//    through completely untouched — the notification badge poll and the
//    pre-download script both rely on failing silently, not on being
//    intercepted here.
// 3. The service worker's fallback to /offline (public/sw.js) remains
//    the net for what never reaches this page at all: a typed URL, the
//    back button, or a tab left open since yesterday.
//
// Same server-side whitelist public/sw.js itself routes on (the
// #offline-config-data blob base.html.twig renders) — never a second,
// hand-maintained copy of the DATA here. The generic exact/child matching
// algorithm below necessarily has its own JS implementation (there is no
// shared runtime with Core\Offline\OfflineWhitelist), but it operates
// entirely on server-supplied entries, never a hardcoded path.
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

    var whitelist = config.whitelist || [];

    // 'child' means the entry's path plus EXACTLY one additional segment
    // — e.g. '/members/' covers '/members/12' but not
    // '/members/12/emails/5' (mass-mail content, two extra segments).
    function isWhitelisted(pathname) {
        for (var i = 0; i < whitelist.length; i++) {
            var entry = whitelist[i];
            if (entry.match === 'child') {
                if (pathname.indexOf(entry.path) !== 0) {
                    continue;
                }
                var remainder = pathname.slice(entry.path.length).replace(/^\/+/, '').replace(/\/+$/, '');
                if (remainder !== '' && remainder.indexOf('/') === -1) {
                    return true;
                }
            } else if (pathname === entry.path) {
                return true;
            }
        }
        return false;
    }

    var PAGE_MESSAGE = "Cette page n'est pas disponible hors ligne.";
    var ACTION_MESSAGE = 'Cette action nécessite une connexion.';

    var modalEl = document.getElementById('offline-dialog');
    var modalMessageEl = document.getElementById('offline-dialog-message');
    var modal = null;

    // Dismissing the dialog leaves the page exactly as it was — never a
    // navigation, never a redirect — form fields the visitor already
    // filled in stay filled in, since the only thing that ran was
    // event.preventDefault() below.
    function showDialog(message) {
        if (!modalEl || typeof bootstrap === 'undefined') {
            return;
        }
        if (modalMessageEl) {
            modalMessageEl.textContent = message;
        }
        if (!modal) {
            modal = new bootstrap.Modal(modalEl);
        }
        modal.show();
    }

    // Greys out (never hides) every unavailable link — an aria-disabled,
    // opacity-only style, no pointer-events:none and no forced tabindex,
    // so the link stays reachable and operable by keyboard and can
    // announce its own disabled state to assistive tech (see
    // public/assets/css/app.css's .offline-link-disabled rule). The
    // offcanvas/mobile menu is the same DOM as the desktop nav, so every
    // greyed entry there falls out of this automatically.
    function applyState() {
        var offline = !navigator.onLine;
        document.querySelectorAll('a[href^="/"]').forEach(function (link) {
            var path = link.getAttribute('href').split('?')[0].split('#')[0];
            var unavailable = offline && !isWhitelisted(path);
            link.classList.toggle('offline-link-disabled', unavailable);
            if (unavailable) {
                link.setAttribute('aria-disabled', 'true');
            } else {
                link.removeAttribute('aria-disabled');
            }
        });
    }

    // Layer 1a: links. Recomputed fresh on every click (never relying on
    // the .offline-link-disabled class already being applied), so a link
    // inserted into the DOM after the last applyState() run is covered
    // just the same.
    document.addEventListener('click', function (event) {
        if (navigator.onLine) {
            return;
        }
        var link = /** @type {HTMLElement} */ (event.target).closest('a[href^="/"]');
        if (!link) {
            return;
        }
        var path = link.getAttribute('href').split('?')[0].split('#')[0];
        if (!isWhitelisted(path)) {
            event.preventDefault();
            showDialog(PAGE_MESSAGE);
        }
    }, true);

    // Layer 1b: forms. No whitelist check — a form submission always
    // needs the network (there is no "safe, cacheable" form action),
    // so every one is blocked while offline, unconditionally.
    document.addEventListener('submit', function (event) {
        if (navigator.onLine) {
            return;
        }
        event.preventDefault();
        showDialog(ACTION_MESSAGE);
    }, true);

    // Layer 2: fetch(). GET must pass through untouched.
    var originalFetch = window.fetch;
    if (typeof originalFetch === 'function') {
        window.fetch = function (input, init) {
            var method = 'GET';
            if (init && init.method) {
                method = init.method;
            } else if (input && typeof input === 'object' && /** @type {Request} */ (input).method) {
                method = /** @type {Request} */ (input).method;
            }

            if (!navigator.onLine && method.toUpperCase() !== 'GET') {
                showDialog(ACTION_MESSAGE);
                return Promise.reject(new TypeError('Failed to fetch'));
            }

            return originalFetch.apply(window, arguments);
        };
    }

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
