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
    /**
     * Strip leading and trailing '/' without a regex.
     *
     * `^\/+` and `\/+$` both let the engine backtrack across a run of
     * slashes, which is super-linear on a crafted path — and this runs on
     * every navigation, against a pathname an attacker can choose. Two
     * counters are linear by construction.
     *
     * @param {string} value
     * @returns {string}
     */
    function trimSlashes(value) {
        var from = 0;
        var to = value.length;
        while (from < to && value[from] === '/') { from++; }
        while (to > from && value[to - 1] === '/') { to--; }
    
        return value.slice(from, to);
    }

    function isWhitelisted(pathname) {
        for (var i = 0; i < whitelist.length; i++) {
            var entry = whitelist[i];
            if (entry.match === 'child') {
                if (pathname.indexOf(entry.path) !== 0) {
                    continue;
                }
                var remainder = trimSlashes(pathname.slice(entry.path.length));
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
    var PROBE_URL = '/api/version';
    var PROBE_TIMEOUT_MS = 1500;
    var ACTION_MESSAGE = 'Cette action nécessite une connexion.';

    var modalEl = document.getElementById('offline-dialog');
    var modalMessageEl = document.getElementById('offline-dialog-message');
    var modal = null;

    // Dismissing the dialog leaves the page exactly as it was — never a
    // navigation, never a redirect — form fields the visitor already
    // filled in stay filled in, since the only thing that ran was
    // event.preventDefault() below.
    function canShowDialog() {
        return !!modalEl && typeof bootstrap !== 'undefined';
    }

    function showDialog(message) {
        if (!canShowDialog()) {
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
        applyFormState(offline);

        var banner = document.getElementById('offline-readonly-banner');
        if (banner) {
            banner.classList.toggle('d-none', !offline);
        }
    }

    // Every cached page is READ ONLY while offline, and says so up front
    // rather than letting a member fill in a form and only then discover
    // it cannot be sent. This is the visible counterpart of layer 1b
    // below, which already refuses EVERY form submission while offline,
    // unconditionally — so "disable every form's submit control" is
    // exactly the same rule, applied to the UI instead of to the event.
    //
    // Deliberately only the SUBMIT controls, never the fields themselves:
    // a member may legitimately want to write a message on the train and
    // send it when the signal comes back, and some pages cache that draft
    // locally for exactly that (Modules\Groups' own composer does). Wiping
    // out their ability to type would defeat the feature that makes an
    // offline draft worth keeping.
    //
    // Opt-out: a form marked data-offline-safe is left alone. Nothing uses
    // it today — it exists for a genuinely local form (a client-side
    // filter with no action) that would otherwise be greyed out for no
    // reason.
    function applyFormState(offline) {
        document.querySelectorAll('form:not([data-offline-safe])').forEach(function (form) {
            form.classList.toggle('offline-form-disabled', offline);
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (el) {
                var control = /** @type {HTMLButtonElement|HTMLInputElement} */ (el);
                // Never un-disables a control something else disabled for
                // its own reasons (a composer mid-submit, a form still
                // validating) — only ever releases what this took.
                if (offline) {
                    if (!control.disabled) {
                        control.disabled = true;
                        control.dataset.offlineDisabled = 'true';
                    }
                } else if (control.dataset.offlineDisabled !== undefined) {
                    control.disabled = false;
                    delete control.dataset.offlineDisabled;
                }
            });
        });
    }

    // Layer 1a: links. Recomputed fresh on every click (never relying on
    // the .offline-link-disabled class already being applied), so a link
    // inserted into the DOM after the last applyState() run is covered
    // just the same.
    //
    // A refused click is never a silent one. `navigator.onLine === false`
    // is only a hint — an installed app thawed by the OS reports it for a
    // while after the network is back — so the click is cancelled, the
    // network is asked once (a short HEAD to /api/version), and the page
    // is opened after all if it answers; the dialog is shown only when it
    // does not. And if the dialog could not be shown at all (no markup,
    // no Bootstrap yet), nothing is cancelled: letting the browser fail
    // visibly beats a tap that does nothing.
    document.addEventListener('click', function (event) {
        if (navigator.onLine) {
            return;
        }
        var link = /** @type {HTMLElement} */ (event.target).closest('a[href^="/"]');
        if (!link) {
            return;
        }
        var path = link.getAttribute('href').split('?')[0].split('#')[0];
        if (isWhitelisted(path) || !canShowDialog()) {
            return;
        }
        var href = sameOriginUrl(link.getAttribute('href'));
        if (href === null) {
            // "/" is also how a protocol-relative "//elsewhere/…" starts;
            // that one is the browser's to refuse or follow, never a URL
            // this script hands to location.assign() itself.
            return;
        }
        event.preventDefault();
        probeConnectivity().then(function (reachable) {
            if (reachable) {
                window.location.assign(href);
            } else {
                showDialog(PAGE_MESSAGE);
            }
        });
    }, true);

    /**
     * The one URL this script ever navigates to on its own, validated at
     * the sink: resolved against the page, and accepted only when it stays
     * on this origin over http(s). A relative path always does; anything
     * else (another host, another scheme) is not this script's to open.
     *
     * @param {string|null} attribute the link's href attribute as written
     * @returns {string|null} the resolved URL, or null when it may not be used
     */
    function sameOriginUrl(attribute) {
        if (!attribute) {
            return null;
        }
        try {
            var resolved = new URL(attribute, window.location.href);
            if ((resolved.protocol !== 'http:' && resolved.protocol !== 'https:') || resolved.origin !== window.location.origin) {
                return null;
            }
            return resolved.href;
        } catch (e) {
            return null;
        }
    }

    /**
     * Is the server actually reachable right now? One HEAD, never cached,
     * bounded by PROBE_TIMEOUT_MS; any answer at all — even an error
     * status — means the network is there. Uses the original fetch, not
     * the wrapper below (a HEAD is not a GET, and the wrapper would refuse
     * it while offline).
     * @returns {Promise<boolean>}
     */
    function probeConnectivity() {
        if (typeof originalFetch !== 'function') {
            return Promise.resolve(false);
        }
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, PROBE_TIMEOUT_MS) : null;
        return originalFetch.call(window, PROBE_URL, { method: 'HEAD', cache: 'no-store', signal: controller ? controller.signal : undefined })
            .then(function () { return true; })
            .catch(function () { return false; })
            .then(function (reachable) {
                if (timer !== null) {
                    clearTimeout(timer);
                }
                return reachable;
            });
    }

    // Layer 1b: forms. No whitelist check — a form submission always
    // needs the network (there is no "safe, cacheable" form action),
    // so every one is blocked while offline, unconditionally.
    document.addEventListener('submit', function (event) {
        if (navigator.onLine || !canShowDialog()) {
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
