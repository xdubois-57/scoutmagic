/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// In the installed application, a link to another site opens in the
// phone's browser (issue #502).
//
// The installed window has no address bar and no back button. A link to
// somebody else's site followed inside it leaves the application behind
// with no way back — and the server cannot help, because such a link
// never reaches it (Core\File\Held\InstalledAppFileInterceptor covers
// this site's own addresses). Templates mostly write `target="_blank"` on
// their external links, but written content — articles, pages,
// descriptions — carries whatever its author pasted, and iOS treats
// `target="_blank"` inside the installed window anyway.
//
// So one delegated listener catches every click on a link to another
// origin and hands it to window.open(), which from an installed web app
// launches the browser, a separate application: ScoutMagic stays where it
// was. A browser tab is never touched — it has a back button.
(function () {
    'use strict';

    var win = window;
    var doc = document;

    function isStandalone() {
        return (typeof win.matchMedia === 'function' && win.matchMedia('(display-mode: standalone)').matches)
            || /** @type {any} */ (win.navigator).standalone === true;
    }

    doc.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey
            || event.shiftKey || event.altKey || !isStandalone()) {
            return;
        }

        var target = /** @type {Element|null} */ (event.target);
        var link = /** @type {HTMLAnchorElement|null} */ (target?.closest ? target.closest('a[href]') : null);
        if (!link) {
            return;
        }

        var url;
        try {
            url = new URL(link.href, doc.location.href);
        } catch (e) {
            return;
        }
        // mailto:, tel: and the like already leave for another app; only
        // web pages would open inside this window.
        if ((url.protocol !== 'http:' && url.protocol !== 'https:') || url.origin === doc.location.origin) {
            return;
        }

        event.preventDefault();
        win.open(url.href, '_blank', 'noopener');
    });
})();
