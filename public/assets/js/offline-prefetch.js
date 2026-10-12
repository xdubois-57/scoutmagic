/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
// Offline pre-download (Lot 4, #883) — decides WHEN the installed app's
// offline copies are refreshed, and asks the service worker to do it.
//
// The work itself — the exact list (GET /api/offline/manifest), the pages,
// the images they show, the help last, what left the list removed — runs
// in public/sw.js (refreshOfflineCopies()). It used to run here, in the
// page, and moving to another page of the app, putting it in the
// background or closing it cut it short; and since the launch was marked
// done when the run STARTED, nothing resumed it for 24 hours.
//
// When:
//
// - only in the INSTALLED app ('display-mode: standalone'), with
//   functional consent, signed in (config.accountScope === 'guest' bails
//   out: the first launch after login does it), and never under Data Saver;
// - once per launch, remembered in sessionStorage, which an installed app
//   keeps for as long as its window lives — and again after
//   REFRESH_AFTER_MS for an app left open for days;
// - after `load`, in idle time, never in the way of the page being read.
//
// A launch is remembered only once the service worker says the pass
// FINISHED ('offline-refresh-done'). Until then every page of the launch
// asks again: a pass still running is joined, an interrupted one is
// resumed where it stopped, doing again only what is missing. Background
// and closing are covered by that same resumption, at the next display —
// a web application on iOS gets no real background refresh.
(function () {
    var LAUNCH_KEY = 'scoutmagic-offline-prefetch';
    var REFRESH_AFTER_MS = 24 * 60 * 60 * 1000;
    var IDLE_FALLBACK_MS = 1500;

    if (!('serviceWorker' in navigator)) {
        return;
    }
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
    if (!config.consent || config.accountScope === 'guest') {
        return;
    }
    var isStandalone = window.matchMedia?.('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
    if (!isStandalone) {
        return;
    }
    var connection = /** @type {any} */ (navigator).connection;
    if (connection?.saveData === true) {
        return;
    }
    var scope = config.accountScope + '|' + config.version;
    if (doneThisLaunch()) {
        return;
    }

    navigator.serviceWorker.addEventListener('message', function (event) {
        // Announced to every window of the app, not only the page that
        // asked (sw.js announcePassDone()): one for another account says
        // nothing about this one.
        if (event.data?.type === 'offline-refresh-done' && (event.data.scope ?? scope) === scope) {
            rememberDone();
        }
    });
    whenIdle(askForRefresh);

    /**
     * Whether a pass finished during this launch, less than
     * REFRESH_AFTER_MS ago. A browser that refuses sessionStorage (private
     * mode, quota) asks every time — the service worker joins a pass that
     * is already running, so that costs a message, not a second pass.
     * @returns {boolean}
     */
    function doneThisLaunch() {
        try {
            var raw = window.sessionStorage.getItem(LAUNCH_KEY);
            if (!raw) {
                return false;
            }
            var last = JSON.parse(raw);
            return last?.scope === scope && (Date.now() - Number(last.at)) < REFRESH_AFTER_MS;
        } catch (e) {
            // Storage refused (private mode) or a value that is not JSON:
            // nothing says a pass already ran, so one runs.
            return false;
        }
    }

    function rememberDone() {
        try {
            window.sessionStorage.setItem(LAUNCH_KEY, JSON.stringify({ scope: scope, at: Date.now() }));
        } catch (e) {
            // Nothing to remember it in: the next page asks again.
        }
    }

    /**
     * After the page has loaded and the browser has nothing better to do.
     * @param {() => void} task
     */
    function whenIdle(task) {
        var start = function () {
            if (typeof window.requestIdleCallback === 'function') {
                window.requestIdleCallback(function () { task(); }, { timeout: IDLE_FALLBACK_MS * 2 });
            } else {
                setTimeout(task, IDLE_FALLBACK_MS);
            }
        };
        if (document.readyState === 'complete') {
            start();
        } else {
            window.addEventListener('load', start, { once: true });
        }
    }

    function askForRefresh() {
        navigator.serviceWorker.ready.then(function (registration) {
            if (registration.active) {
                registration.active.postMessage({ type: 'offline-refresh' });
            }
        }).catch(function () {
            // No worker: nothing is held offline, nothing to refresh.
        });
    }
})();
