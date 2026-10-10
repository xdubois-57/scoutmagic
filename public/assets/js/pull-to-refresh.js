/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Pull to refresh, in the installed application only (issue #842).
//
// The installed app has no address bar and no reload button: on iOS the
// only way to see a page again from the server used to be killing the
// app. So, from the very top of a page, pulling down shows a small round
// indicator under the finger (#pull-to-refresh, app.css), and letting go
// past the threshold reloads the current page.
//
// This file knows three things and no more: the gesture, the indicator,
// and the decision « reload now » or « ask the network first ». Both
// other questions belong to scripts that already answer them:
//
// - « is this window the installed app? » is display-mode.js's
//   (window.ScoutMagicDisplayMode). In a browser tab nothing here is
//   even listened to — the browser's own pull-to-refresh, where it has
//   one, is the visitor's;
// - « is this page offline? » is offline-nav.js's
//   (window.ScoutMagicConnectivity), whose verdict is a real probe and not
//   navigator.onLine — issue #353, where iOS kept an installed app
//   « offline » for minutes after the network was back.
//
// The decision. While the page is NOT in the confirmed offline state the
// page simply reloads — a probe first would slow every refresh down for
// nothing, and a navigation that fails is the service worker's to catch.
// While it IS, reloading would only land on the offline page; so the
// gesture re-asks the network instead. Still unreachable: no reload, the
// indicator retracts, and the banner and greyed links stay as the fresh
// verdict painted them. Reachable — even with iOS still claiming
// navigator.onLine === false — the page is back online (offline-nav.js has
// already repainted it) and reloads, once.
//
// The browser's own pull-to-refresh, where an installed app has one, is
// switched off by app.css (overscroll-behavior-y, in standalone only) and
// by preventDefault() on the moves this gesture claims: two reloads for
// one pull is the thing not to do.
(function () {
    'use strict';

    // Distances in CSS pixels, measured on the indicator rather than the
    // finger: the indicator moves at RESISTANCE times the finger's speed,
    // which is what makes a pull feel like a pull rather than a drag.
    var THRESHOLD = 64;
    var MAX_PULL = 96;
    var RESISTANCE = 0.5;
    // How far a finger may wander before its direction counts — a tap
    // that trembles is not a gesture.
    var SLOP = 8;
    // Where the indicator sits when it does not follow the finger
    // (prefers-reduced-motion) and while it spins.
    var REST_OFFSET = THRESHOLD;

    var REFRESHING_TEXT = 'Actualisation…';

    // Surfaces that scroll on their own or sit over the page: a pull
    // started inside one belongs to it.
    // Bootstrap's responsive offcanvas (.offcanvas-lg…) carries no plain
    // .offcanvas class, hence the list.
    var OFFCANVAS = ['.offcanvas', '.offcanvas-sm', '.offcanvas-md', '.offcanvas-lg', '.offcanvas-xl', '.offcanvas-xxl'];
    var OWN_SCROLL_SURFACES = ['.modal', '.dropdown-menu'].concat(OFFCANVAS).join(', ');
    var OPEN_OVERLAYS = ['.modal.show'].concat(
        OFFCANVAS.map(function (selector) { return selector + '.show, ' + selector + '.showing'; })
    ).join(', ');

    var displayMode = window.ScoutMagicDisplayMode;
    if (!displayMode || !displayMode.isStandalone()) {
        return;
    }

    var indicator = document.getElementById('pull-to-refresh');
    if (!indicator) {
        return;
    }
    var status = document.getElementById('pull-to-refresh-status');

    // idle → (touchstart) pending → (moved down) pulling → (released past
    // the threshold) refreshing → navigation, or back to idle.
    var state = 'idle';
    var startX = 0;
    var startY = 0;
    var pull = 0;
    var reducedMotion = false;

    function prefersReducedMotion() {
        return typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function scrollTop() {
        return window.scrollY || document.documentElement.scrollTop || 0;
    }

    /**
     * May a pull start from here?
     *
     * Only from the very top of the document, outside any surface that
     * has a scroll of its own, and with nothing laid over the page — a
     * dialog's content pulled down must scroll the dialog, never reload
     * what is behind it.
     *
     * @param {EventTarget|null} target
     * @returns {boolean}
     */
    function canStartFrom(target) {
        if (scrollTop() > 0) {
            return false;
        }
        if (document.body.classList.contains('modal-open') || document.querySelector(OPEN_OVERLAYS)) {
            return false;
        }
        var element = target instanceof Element ? target : null;
        if (element && element.closest(OWN_SCROLL_SURFACES)) {
            return false;
        }
        // An inner list scrolled down is pulled back up first, like the
        // page itself.
        for (var node = element; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
            if (node.scrollTop > 0) {
                return false;
            }
        }
        return true;
    }

    function paint() {
        var progress = Math.min(pull / THRESHOLD, 1);
        var offset = reducedMotion ? REST_OFFSET : pull;
        indicator.style.setProperty('--ptr-pull', offset + 'px');
        indicator.style.setProperty('--ptr-progress', String(progress));
        indicator.classList.toggle('is-armed', pull >= THRESHOLD);
    }

    function rest() {
        state = 'idle';
        pull = 0;
        indicator.classList.remove('is-pulling', 'is-armed', 'is-refreshing', 'is-reduced-motion');
        indicator.style.removeProperty('--ptr-pull');
        indicator.style.removeProperty('--ptr-progress');
        if (status) {
            status.textContent = '';
        }
    }

    function reload() {
        // The indicator keeps spinning until the page goes: a second pull
        // in the meantime is ignored (state stays 'refreshing').
        window.location.reload();
    }

    /**
     * Released past the threshold: reload, or ask the network first.
     */
    function refresh() {
        state = 'refreshing';
        pull = REST_OFFSET;
        indicator.classList.remove('is-pulling', 'is-armed');
        indicator.classList.add('is-refreshing');
        indicator.style.setProperty('--ptr-pull', REST_OFFSET + 'px');
        indicator.style.setProperty('--ptr-progress', '1');
        if (status) {
            status.textContent = REFRESHING_TEXT;
        }

        var connectivity = window.ScoutMagicConnectivity;
        if (!connectivity || !connectivity.isOfflineConfirmed()) {
            reload();
            return;
        }

        connectivity.recheck().then(function (online) {
            if (online) {
                reload();
            } else {
                rest();
            }
        }, rest);
    }

    /**
     * @param {TouchEvent} event
     * @returns {Touch|null}
     */
    function singleTouch(event) {
        return event.touches && event.touches.length === 1 ? event.touches[0] : null;
    }

    document.addEventListener('touchstart', function (event) {
        if (state === 'refreshing') {
            return;
        }
        var touch = singleTouch(event);
        if (!touch || !canStartFrom(event.target)) {
            rest();
            return;
        }
        state = 'pending';
        startX = touch.clientX;
        startY = touch.clientY;
        pull = 0;
        reducedMotion = prefersReducedMotion();
    }, { passive: true });

    // Not passive: once the pull is ours, preventDefault() is what keeps
    // the browser from scrolling, bouncing, or running a pull-to-refresh
    // of its own on the same finger.
    document.addEventListener('touchmove', function (event) {
        if (state !== 'pending' && state !== 'pulling') {
            return;
        }
        var touch = singleTouch(event);
        if (!touch) {
            rest();
            return;
        }
        var dx = touch.clientX - startX;
        var dy = touch.clientY - startY;

        if (state === 'pending') {
            if (Math.abs(dx) < SLOP && Math.abs(dy) < SLOP) {
                return;
            }
            // Mostly sideways, or upwards, or the page has scrolled since
            // the finger landed: not a pull, and not ours to watch any
            // longer.
            if (dy <= 0 || Math.abs(dx) >= Math.abs(dy) || scrollTop() > 0) {
                rest();
                return;
            }
            state = 'pulling';
            indicator.classList.add('is-pulling');
            indicator.classList.toggle('is-reduced-motion', reducedMotion);
        }

        pull = Math.max(0, Math.min(dy * RESISTANCE, MAX_PULL));
        paint();
        if (event.cancelable) {
            event.preventDefault();
        }
    }, { passive: false });

    document.addEventListener('touchend', function () {
        if (state === 'pulling' && pull >= THRESHOLD) {
            refresh();
            return;
        }
        if (state !== 'refreshing') {
            rest();
        }
    });

    document.addEventListener('touchcancel', function () {
        if (state !== 'refreshing') {
            rest();
        }
    });

    // A page restored from the back/forward cache comes back exactly as it
    // was left — spinning, if it was left by this reload.
    window.addEventListener('pageshow', function (event) {
        if (/** @type {PageTransitionEvent} */ (event).persisted) {
            rest();
        }
    });
})();
