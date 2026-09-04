/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
// What the visitor sees between a tap and the next page.
//
// In the installed application (display-mode: standalone) there is no
// address bar and no browser progress indicator: a tap on a menu entry
// used to change NOTHING on screen until the next page arrived — the
// offcanvas stayed open, no spinner, no bar — and people tapped again,
// which cancelled the navigation already in flight. Three things here:
//
// 1. A tap on a link inside the mobile menu closes the menu at once. Done
//    from a script rather than with data-bs-dismiss on the links, because
//    Bootstrap's dismiss handler calls preventDefault() on an <a>, which
//    would cancel the navigation itself.
// 2. A thin progress bar (#navigation-progress, app.css) appears at the
//    top of the viewport a moment after any same-origin navigation link
//    is followed — a moment, so an instant page never flashes it — and
//    disappears the instant a page is shown again.
// 3. pageshow with `persisted` (the back/forward cache restoring the page
//    exactly as it was left) puts the menu, the modals and their backdrops
//    away: a restored page used to come back with the open drawer over
//    the content, and the first tap only ever closed that.
//
// Never runs for a click another script already cancelled (offline-nav.js
// refusing a page while offline): defaultPrevented is checked first.
(function () {
    var PROGRESS_DELAY_MS = 150;
    var progressTimer = null;
    var progressEl = null;

    function progressBar() {
        if (progressEl === null) {
            progressEl = document.getElementById('navigation-progress');
        }
        return progressEl;
    }

    function showProgress() {
        var bar = progressBar();
        if (!bar) {
            return;
        }
        bar.classList.add('is-active');
        bar.removeAttribute('hidden');
    }

    function hideProgress() {
        if (progressTimer !== null) {
            clearTimeout(progressTimer);
            progressTimer = null;
        }
        var bar = progressBar();
        if (bar) {
            bar.classList.remove('is-active');
            bar.setAttribute('hidden', '');
        }
    }

    /**
     * A left-click, unmodified, on a same-origin link that will replace
     * this document — the only kind worth a progress bar.
     * @param {MouseEvent} event
     * @param {HTMLAnchorElement} link
     * @returns {boolean}
     */
    function willNavigate(event, link) {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return false;
        }
        if (link.target && link.target !== '_self') {
            return false;
        }
        if (link.hasAttribute('download') || link.hasAttribute('data-file-viewer')) {
            return false;
        }
        var href = link.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#') {
            return false;
        }
        // The RESOLVED protocol, not a prefix match on the raw attribute.
        // A deny-list of prefixes is never finished — this one named
        // `javascript:` and missed `data:` and `vbscript:` (CodeQL
        // js/incomplete-url-scheme-check), and would still miss the
        // leading-whitespace and mixed-case spellings of all three. An
        // allowlist of the two schemes a navigation can actually use has
        // no such gap, and the browser has already done the parsing.
        if (link.protocol !== 'http:' && link.protocol !== 'https:') {
            return false;
        }
        if (link.origin !== window.location.origin) {
            return false;
        }
        // Same document, different fragment: no navigation happens.
        return !(link.pathname === window.location.pathname && link.search === window.location.search && link.hash !== '');
    }

    function closeOffcanvas(link) {
        var offcanvasEl = link.closest('.offcanvas');
        if (!offcanvasEl || typeof bootstrap === 'undefined' || !bootstrap.Offcanvas) {
            return;
        }
        var instance = bootstrap.Offcanvas.getInstance(offcanvasEl);
        if (instance) {
            instance.hide();
        }
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented) {
            return;
        }
        var target = /** @type {Element|null} */ (event.target instanceof Element ? event.target : null);
        var link = target ? /** @type {HTMLAnchorElement|null} */ (target.closest('a[href]')) : null;
        if (!link || !willNavigate(event, link)) {
            return;
        }
        closeOffcanvas(link);
        if (progressTimer === null) {
            progressTimer = setTimeout(function () {
                progressTimer = null;
                showProgress();
            }, PROGRESS_DELAY_MS);
        }
    });

    // Whatever was left open — the drawer, a modal, their backdrops — must
    // not survive a restore from the back/forward cache, and neither must
    // a progress bar for a navigation that is over.
    function resetAfterRestore() {
        hideProgress();
        if (typeof bootstrap === 'undefined') {
            return;
        }
        document.querySelectorAll('.offcanvas.show').forEach(function (el) {
            var instance = bootstrap.Offcanvas && bootstrap.Offcanvas.getInstance(el);
            if (instance) {
                instance.hide();
            }
        });
        document.querySelectorAll('.modal.show').forEach(function (el) {
            var instance = bootstrap.Modal && bootstrap.Modal.getInstance(el);
            if (instance) {
                instance.hide();
            }
        });
        document.querySelectorAll('.offcanvas-backdrop, .modal-backdrop').forEach(function (el) {
            el.remove();
        });
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            resetAfterRestore();
        } else {
            hideProgress();
        }
    });
    // A navigation the browser cancelled (a download, a refused external
    // handler) never fires pageshow: the bar would otherwise stay up.
    window.addEventListener('pagehide', hideProgress);
})();
