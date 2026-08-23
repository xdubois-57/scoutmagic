/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The site-wide fetch toolbox — window.ScoutMagicApi.
//
// Before this file existed, the CSRF-token reader was copied 35 times (12
// identical function declarations, 16 more inline in templates — one file
// carried three), postJson() existed in five copies with three different
// return conventions, and nine scripts called res.json() without ever
// testing res.ok — a 500 error page became a JSON parse exception read as
// "network error", and in one audited case res.ok was read as business
// success. One definition, one envelope, so the next divergence has
// nowhere to start.
//
// Loaded by base.html.twig before {% block scripts %} on every page.
// Deliberately an IIFE exposing a global (the chunked-upload.js pattern),
// never an ES module: the thirty classic scripts of this site cannot
// import one, and production JavaScript ships unbundled (AGENTS.md § CSS /
// frontend). The ES-module scripts read the same global.
(function () {
    /**
     * The CSRF token every state-changing request must carry — one reader
     * for the <meta name="csrf-token"> base.html.twig renders on every
     * page.
     *
     * @returns {string}
     */
    function csrfToken() {
        var meta = /** @type {HTMLMetaElement|null} */ (document.querySelector('meta[name="csrf-token"]'));
        return meta ? meta.content : '';
    }

    /**
     * One JSON request, one envelope, never a rejection.
     *
     * Resolves to `{ok, status, data}`:
     *   ok     — HTTP success AND the body parsed as JSON. Business
     *            success is still the caller's check (`data.success`
     *            where the endpoint uses that convention): ok answers
     *            "did the server answer properly", nothing more — reading
     *            it as business success is the exact bug this file
     *            replaces.
     *   status — the HTTP status, 0 on a network failure.
     *   data   — the parsed body, null when there was none to parse.
     *
     * The CSRF token rides in the JSON body as `_csrf_token` (the
     * dominant convention server-side) AND in the X-CSRF-Token header —
     * covering both validation styles without per-call-site choices.
     *
     * @param {string} url
     * @param {Object} [body]
     * @param {{method?: string}} [options]
     * @returns {Promise<{ok: boolean, status: number, data: any}>}
     */
    function postJson(url, body, options) {
        var method = (options && options.method) || 'POST';
        return fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
            body: JSON.stringify(Object.assign({}, body || {}, { _csrf_token: csrfToken() }))
        }).then(function (res) {
            return res.json().then(
                function (data) { return { ok: res.ok, status: res.status, data: data }; },
                function () { return { ok: false, status: res.status, data: null }; }
            );
        }).catch(function () {
            return { ok: false, status: 0, data: null };
        });
    }

    /**
     * The GET half of the same envelope.
     *
     * @param {string} url
     * @returns {Promise<{ok: boolean, status: number, data: any}>}
     */
    function getJson(url) {
        return fetch(url, { headers: { Accept: 'application/json' } }).then(function (res) {
            return res.json().then(
                function (data) { return { ok: res.ok, status: res.status, data: data }; },
                function () { return { ok: false, status: res.status, data: null }; }
            );
        }).catch(function () {
            return { ok: false, status: 0, data: null };
        });
    }

    /**
     * Disable a control for the duration of a request, and — the half the
     * hand-written copies kept forgetting — re-enable it on the failure
     * path too. ~65 call sites used to write both halves by hand.
     *
     * @template T
     * @param {HTMLButtonElement|HTMLInputElement|null} control
     * @param {() => Promise<T>} run
     * @returns {Promise<T>}
     */
    function withDisabled(control, run) {
        if (control) {
            control.disabled = true;
        }
        return Promise.resolve().then(run).finally(function () {
            if (control) {
                control.disabled = false;
            }
        });
    }

    /**
     * Attribute-safe HTML escaping — & < > AND both quote styles, because
     * the result of the eight copies this replaces was interpolated into
     * alt=/title=/data-* attributes, where a bare textContent→innerHTML
     * round-trip lets a filename with a quote break out (audit M16).
     *
     * @param {unknown} value
     * @returns {string}
     */
    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /**
     * Trailing-edge debounce — the clearTimeout/setTimeout pair that was
     * hand-rolled eight times.
     *
     * @param {(...args: any[]) => void} fn
     * @param {number} delayMs
     * @returns {(...args: any[]) => void}
     */
    function debounce(fn, delayMs) {
        var timer = 0;
        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delayMs);
        };
    }

    /**
     * A polling loop with the three behaviours the eight hand-rolled
     * loops each implemented a different subset of: no overlapping ticks
     * (the next one is scheduled only after the previous resolves), an
     * immediate catch-up tick when the tab becomes visible again, and an
     * optional deadline.
     *
     * `tick` may return a promise; resolving `false` stops the loop (any
     * other value continues). On the deadline, `onExpire` fires once and
     * the loop stops — the caller decides what the screen says then,
     * because a poll that just goes quiet is how the magic-link spinner
     * span forever.
     *
     * @param {() => (boolean|void|Promise<boolean|void>)} tick
     * @param {{intervalMs?: number, delaysMs?: number[], maxMs?: number, resumeOnVisible?: boolean, onExpire?: () => void}} [options]
     * @returns {{stop: () => void}}
     */
    function poll(tick, options) {
        var opts = options || {};
        var delays = opts.delaysMs || null;
        var intervalMs = opts.intervalMs || 3000;
        var stopped = false;
        var inFlight = false;
        var timer = 0;
        var tickIndex = 0;
        var startedAt = Date.now();

        function stop() {
            stopped = true;
            clearTimeout(timer);
            if (opts.resumeOnVisible !== false) {
                document.removeEventListener('visibilitychange', onVisible);
            }
        }

        function schedule() {
            if (stopped) {
                return;
            }
            var delay = delays ? delays[Math.min(tickIndex, delays.length - 1)] : intervalMs;
            timer = setTimeout(runTick, delay);
        }

        function runTick() {
            if (stopped || inFlight) {
                return;
            }
            if (opts.maxMs && Date.now() - startedAt >= opts.maxMs) {
                stop();
                if (opts.onExpire) {
                    opts.onExpire();
                }
                return;
            }
            inFlight = true;
            Promise.resolve().then(tick).then(
                function (result) {
                    inFlight = false;
                    tickIndex++;
                    if (result === false) {
                        stop();
                        return;
                    }
                    schedule();
                },
                function () {
                    // A failed tick is a tick: polling exists to survive
                    // transient failures, so it keeps its rhythm.
                    inFlight = false;
                    tickIndex++;
                    schedule();
                }
            );
        }

        function onVisible() {
            if (document.visibilityState === 'visible' && !stopped && !inFlight) {
                clearTimeout(timer);
                runTick();
            }
        }

        if (opts.resumeOnVisible !== false) {
            document.addEventListener('visibilitychange', onVisible);
        }
        schedule();

        return { stop: stop };
    }

    window.ScoutMagicApi = {
        csrfToken: csrfToken,
        postJson: postJson,
        getJson: getJson,
        withDisabled: withDisabled,
        escapeHtml: escapeHtml,
        debounce: debounce,
        poll: poll
    };
})();
