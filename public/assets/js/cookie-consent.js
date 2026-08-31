/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

document.addEventListener('DOMContentLoaded', function () {
    var banner = document.getElementById('cookie-banner');
    if (!banner) return;

    function getCsrf() {
        var meta = /** @type {HTMLMetaElement} */ (document.querySelector('meta[name="csrf-token"]'));
        return meta ? meta.content : '';
    }

    /**
     * The banner stays, because it is the only control that can record a
     * choice — taking it away would leave the visitor no way to answer.
     */
    function choiceNotRecorded() {
        var toast = window.ScoutMagicToast;
        if (toast) {
            toast.show(
                "Votre choix n'a pas pu être enregistré. Rechargez la page et réessayez.",
                { variant: 'error' }
            );
        }
    }

    // A refused POST must not look like a recorded choice.
    //
    // Both handlers used to remove the banner from inside
    // `fetch(...).then(...)`, which runs for EVERY response the server
    // sends — a 403 exactly as readily as a 200. A decision the server
    // rejected therefore took the banner away just like an accepted one:
    // the visitor saw their click land, nothing was stored, and the
    // banner returned on the next page load with no explanation. For
    // « Tout refuser » that is worse than cosmetic — a refusal is
    // reported as registered when it was not, which is precisely the
    // claim ePrivacy consent rests on.
    //
    // Found from the other side, and only because something else was
    // being chased: a dynamic-security run caught
    // POST /cookies/reject-all answering 403 while
    // tests/e2e/support/cookie-banner.js still reporting success — its
    // own comment claimed that waiting for the banner to go WAS waiting
    // for the decision to be recorded. That claim is true now, and only
    // because of this function: resolved is not succeeded.
    /**
     * @param {string} endpoint
     * @param {() => void} [onRecorded] side effects that only become
     *        true once the server has actually stored the choice
     */
    function recordChoice(endpoint, onRecorded) {
        fetch(endpoint, {
            method: 'POST',
            headers: { 'X-CSRF-Token': getCsrf() }
        }).then(function (response) {
            if (!response.ok) {
                choiceNotRecorded();
                return;
            }
            banner.remove();
            if (onRecorded) {
                onRecorded();
            }
        }).catch(choiceNotRecorded);
    }

    document.getElementById('cookie-accept-all').addEventListener('click', function () {
        recordChoice('/cookies/accept-all');
    });

    document.getElementById('cookie-reject-all').addEventListener('click', function () {
        recordChoice('/cookies/reject-all', function () {
            // Withdrawing functional consent also withdraws the stored
            // theme preference (public/assets/js/theme.js — functional
            // category, declared in core/Cookie/CookieRegistry.php): the
            // current page keeps its look until the next load, where the
            // 'automatique' default takes over.
            try {
                localStorage.removeItem('theme_preference');
            } catch (e) {
                // Storage disabled — nothing was ever persisted anyway.
            }
            // Withdrawing functional consent (Lot 3) must purge the
            // offline content caches immediately AND stop the service
            // worker writing to them again before the next page load —
            // a bare purge message alone wouldn't update its stored
            // consent flag (see public/assets/js/offline-cache.js and
            // public/sw.js's own 'message' handler for why this is a
            // full config update, not just a purge). Reads the same
            // #offline-config-data blob offline-cache.js does, so the
            // stored whitelist/version/account scope aren't clobbered by
            // a stub — only consent actually changes here.
            if ('serviceWorker' in navigator) {
                var configEl = document.getElementById('offline-config-data');
                var config = null;
                if (configEl) {
                    try {
                        config = JSON.parse(configEl.textContent);
                    } catch (e) {
                        config = null;
                    }
                }
                navigator.serviceWorker.ready.then(function (registration) {
                    if (registration.active) {
                        registration.active.postMessage({
                            type: 'offline-config',
                            consent: false,
                            staleness_days: config ? config.stalenessDays : undefined,
                            whitelist: config ? config.whitelist : [],
                            account_scope: config ? config.accountScope : 'guest',
                            version: config ? config.version : undefined
                        });
                    }
                });
            }
        });
    });

    document.getElementById('cookie-customize').addEventListener('click', function () {
        window.location.href = '/cookies';
    });
});
