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
        for (const entry of whitelist) {
            if (entry.match === 'child') {
                if (pathname.indexOf(entry.path) !== 0) {
                    continue;
                }
                var remainder = trimSlashes(pathname.slice(entry.path.length));
                if (remainder !== '' && !remainder.includes('/')) {
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

    // How often the probe re-asks while the browser CLAIMS to be
    // offline, and how long its answer is trusted afterwards.
    //
    // The interval is short because it costs nothing where it runs: a
    // genuinely offline device fails the request without touching the
    // radio. It only ever runs while `navigator.onLine` is false, and it
    // stops the moment an answer comes back.
    var OFFLINE_RECHECK_MS = 5000;
    var VERDICT_FRESH_MS = 12000;

    var lastVerdictAt = 0;
    var lastVerdictReachable = true;
    var recheckTimer = null;

    /**
     * Which probe is the current one.
     *
     * Two can be in flight at once — the heartbeat ticks while a
     * `visibilitychange` or an `online` event asks again — and they do
     * not come back in the order they left: a probe issued while the
     * network was down times out after PROBE_TIMEOUT_MS, long after a
     * later one has already been answered. Without this, that stale
     * "unreachable" would overwrite the fresh "reachable" and strand the
     * page offline, which is the very symptom #353 is about.
     */
    var probeSequence = 0;

    /**
     * Is this page REALLY offline — not merely told so?
     *
     * `navigator.onLine === false` is the trigger and never the verdict.
     * Issue #353: on iOS, an installed application resuming from a
     * frozen state reports itself offline for a while after the network
     * is back, and every layer below used to act on that word alone. A
     * tap then lost its own navigation — cancelled here, handed to a
     * probe, and re-issued from a promise, which a standalone WebKit
     * window is entitled to ignore. Nothing appeared, nothing failed,
     * and closing the menu and reopening it « fixed » it because by then
     * the flag had corrected itself.
     *
     * So the browser's word only opens the question, and the answer is
     * the probe's, while it is fresh. Unknown counts as ONLINE: letting
     * a click through costs a real offline visitor the service worker's
     * /offline page (layer 3, which exists for exactly this), and
     * holding it back costs everybody else a tap that does nothing. The
     * file already made that trade for a missing dialog; this is the
     * same trade, for a missing answer.
     *
     * @returns {boolean}
     */
    function isOffline() {
        return !navigator.onLine
            && !lastVerdictReachable
            && (Date.now() - lastVerdictAt) <= VERDICT_FRESH_MS;
    }

    /**
     * Ask the network, record what it said, and repaint the page.
     *
     * A reachable answer also stops the heartbeat: the question has been
     * settled, and the next `offline` event — or the next page load — is
     * what asks it again. That matters on iOS precisely because the flag
     * can stay wrong for minutes without ever firing an event.
     *
     * @returns {Promise<void>}
     */
    function confirmConnectivity() {
        var issued = ++probeSequence;

        return probeConnectivity().then(function (reachable) {
            // A probe a newer one has already overtaken says nothing: it
            // describes a network that has since been asked again, and
            // the newer answer is the one to keep.
            if (issued !== probeSequence) {
                return;
            }

            lastVerdictReachable = reachable;
            lastVerdictAt = Date.now();

            // Stop on a reachable answer, keep asking on an unreachable
            // one — and re-arm rather than assume the heartbeat is still
            // running, because the probe that stopped it may have been
            // this one's predecessor.
            if (reachable) {
                stopRechecking();
            } else {
                keepRechecking();
            }

            applyState();
        });
    }

    function stopRechecking() {
        if (recheckTimer !== null) {
            clearInterval(recheckTimer);
            recheckTimer = null;
        }
    }

    /**
     * Ask again in OFFLINE_RECHECK_MS, unless something already will.
     *
     * Guarded on the browser's own flag as well: once it says online
     * there is nothing left to poll for, and the next `offline` event is
     * what starts this again.
     */
    function keepRechecking() {
        if (recheckTimer === null && !navigator.onLine) {
            recheckTimer = setInterval(confirmConnectivity, OFFLINE_RECHECK_MS);
        }
    }

    /**
     * Keep the verdict fresh for as long as the browser claims to be
     * offline, and only then.
     *
     * Without this the verdict goes stale after VERDICT_FRESH_MS and a
     * genuinely offline visitor would stop being offered the dialog —
     * they would get the service worker's page instead, which is worse
     * for them and pointless for everyone. With it, the one window where
     * interception matters is also the one window where the answer is
     * known.
     */
    function watchConnectivity() {
        applyState();

        if (navigator.onLine) {
            stopRechecking();
            return;
        }

        keepRechecking();
        confirmConnectivity();
    }

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
        // The confirmed verdict, not the browser's claim: greying out
        // every link on a false « offline » was the other half of issue
        // #353, and the visible one — a menu that looks disabled while
        // the network is fine.
        var offline = isOffline();
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
    // A refused click is never a silent one, and — since issue #353 — it
    // is never a DEFERRED one either.
    //
    // This used to cancel the click, ask the network once, and re-issue
    // the navigation from the promise if it answered. Three things had
    // to go right afterwards for anything to happen at all, and in an
    // installed iOS application they did not: a navigation re-issued
    // outside the tap's own gesture is one a standalone WebKit window
    // may simply drop. The visitor saw nothing — no page, no dialog, no
    // error — and got it back by closing the menu and reopening it,
    // which changed nothing except that `navigator.onLine` had told the
    // truth again by then.
    //
    // So the question is settled BEFORE the click: isOffline() is only
    // true on a probe that already came back unreachable, and this
    // handler either shows the dialog immediately or gets out of the
    // way, leaving the browser's own navigation untouched. Nothing here
    // navigates any more.
    //
    // Unchanged: a click nothing can explain — no dialog markup, no
    // Bootstrap yet — is never cancelled, because letting the browser
    // fail visibly beats a tap that does nothing. That was already this
    // file's rule; #353 is what happens when it is not applied to the
    // network answer too.
    document.addEventListener('click', function (event) {
        if (!isOffline()) {
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
        if (sameOriginUrl(link.getAttribute('href')) === null) {
            // "/" is also how a protocol-relative "//elsewhere/…" starts,
            // and where that link leads is the browser's business rather
            // than this dialog's.
            return;
        }
        event.preventDefault();
        showDialog(PAGE_MESSAGE);
    }, true);

    /**
     * Whose business is this link?
     *
     * Resolved against the page, and accepted only when it stays on this
     * origin over http(s). A relative path always does; anything else
     * (another host, another scheme) is not this dialog's to speak for,
     * so the click is left alone.
     *
     * It used to guard a `location.assign()` sink, which issue #353
     * removed. The check stays because the QUESTION it answers did not
     * go away — `a[href^="/"]` also matches a protocol-relative
     * `//elsewhere/…`, and telling somebody that another site « n'est
     * pas disponible hors ligne » would be this script speaking out of
     * turn.
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
        } catch {
            // URL() throws on an attribute that is not a URL at all, and
            // that is not a link this can follow.
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
        if (!isOffline() || !canShowDialog()) {
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
            if (init?.method) {
                method = init.method;
            } else if (input && typeof input === 'object' && /** @type {Request} */ (input).method) {
                method = /** @type {Request} */ (input).method;
            }

            if (isOffline() && method.toUpperCase() !== 'GET') {
                showDialog(ACTION_MESSAGE);
                return Promise.reject(new TypeError('Failed to fetch'));
            }

            return originalFetch.apply(window, arguments);
        };
    }

    window.addEventListener('online', watchConnectivity);
    window.addEventListener('offline', watchConnectivity);
    // An installed app resumes from a frozen state with a stale
    // connectivity assumption — re-ask whenever the tab regains focus.
    // This is the moment issue #353 is about, and asking the NETWORK
    // rather than repainting from `navigator.onLine` is the whole fix.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            watchConnectivity();
        }
    });

    // Last, and after the fetch wrapper above: probeConnectivity() needs
    // `originalFetch`, which layer 2 assigns. Started any earlier it
    // would answer « unreachable » without asking anything, and a page
    // would open believing itself offline.
    watchConnectivity();
})();
