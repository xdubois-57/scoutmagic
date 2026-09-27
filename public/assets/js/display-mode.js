/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Tells the server, on every page, whether this window is the INSTALLED
// application (issue #502).
//
// The server needs to know before it answers a navigation: in the
// installed app a navigation that ends on a file strands the window —
// no address bar, no back button, and on iOS nothing to press but kill
// the app — so Core\File\Held\InstalledAppFileInterceptor answers with a
// viewer page instead. A navigation carries no header a script can add,
// so it is a cookie: `sm_display=standalone`, declared strictly necessary
// in Core\Cookie\CookieRegistry (without it the installed app breaks, and
// it says nothing about the visitor).
//
// Loaded in <head>, not deferred: the cookie must be written before the
// first link on the page can be followed.
//
// Removed rather than left behind in a browser tab. On a desktop the
// installed app and the browser share their cookies, and a stale
// `standalone` would turn a tab's download into a viewer page.
(function () {
    'use strict';

    var NAME = 'sm_display';

    function isStandalone() {
        return (typeof window.matchMedia === 'function' && window.matchMedia('(display-mode: standalone)').matches)
            || /** @type {any} */ (window.navigator).standalone === true;
    }

    var secure = window.location.protocol === 'https:' ? '; Secure' : '';
    if (isStandalone()) {
        document.cookie = NAME + '=standalone; path=/; SameSite=Lax' + secure;
    } else if (document.cookie.split(';').some(function (part) { return part.trim().indexOf(NAME + '=') === 0; })) {
        document.cookie = NAME + '=; path=/; SameSite=Lax; Max-Age=0' + secure;
    }
})();
