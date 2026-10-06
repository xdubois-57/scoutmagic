/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The « Du nouveau dans vos groupes » band of the home page (issue #704).
//
// The band is rendered by the server, and it is right when it is rendered:
// opening a group records that it was seen, so a fresh load of the home
// page no longer shows it. What goes wrong is the way BACK. A tap on the
// band's button opens the group; the back button then restores the home
// page as it was left — Safari keeps the whole page in its back/forward
// cache and brings it back without asking the site anything — so the band
// is there again, for messages that have just been read, and stays until
// the visitor goes round through the group list.
//
// So when the page is restored from that cache (`pageshow` with
// `persisted`), the band is asked for again and replaced by what the server
// says now. Only a page that is showing the band does it, so a visitor with
// nothing new pays nothing; and a request that fails (offline, a signed-out
// session) keeps what is shown, which is what the page said a moment ago.
(function () {
    var BAND = '[data-home-band]';
    var GROUP_ACTIVITY = '[data-home-group-activity]';

    /**
     * @returns {Promise<void>}
     */
    function refresh() {
        var band = document.querySelector(BAND);
        if (band === null || band.querySelector(GROUP_ACTIVITY) === null) {
            return Promise.resolve();
        }

        return fetch(window.location.href, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'text/html' }
        })
            .then(function (response) {
                return response.ok ? response.text() : null;
            })
            .then(function (html) {
                if (html === null) {
                    return;
                }

                var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector(BAND);
                if (fresh === null) {
                    return;
                }

                band.innerHTML = fresh.innerHTML;
            })
            .catch(function () {
                // Offline, or the request failed: the band stays as it was.
            });
    }

    window.addEventListener('pageshow', function (event) {
        if ((/** @type {PageTransitionEvent} */ (event)).persisted) {
            void refresh();
        }
    });
})();
