/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

/*
 * Color scheme (design.md §7.8) — Bootstrap 5.3 does all the theming work
 * through the `data-bs-theme` attribute on <html>; this file only decides
 * which value that attribute gets and keeps it current.
 *
 * The visitor's preference is one of 'light' | 'dark' | 'auto' (default).
 * 'auto' follows the OS/browser `prefers-color-scheme` media query, live —
 * flipping the OS to dark flips the site without a reload. The preference
 * persists in localStorage under 'theme_preference' (declared in
 * core/Cookie/CookieRegistry.php, category "functional") ONLY when the
 * visitor has given functional cookie consent (read from the
 * client-readable `cookie_consent` JSON cookie — see
 * Core\Cookie\CookieConsentService). Without that consent the toggle still
 * works, but for the current page/session only: the choice lives in a
 * variable here and is never written to storage. 'auto' being the default,
 * it is stored as an absence: choosing it back REMOVES the key.
 *
 * A tiny inline <script nonce> in base.html.twig's <head> applies the
 * stored preference before the stylesheets load, so a dark-mode visitor
 * never sees a white flash; this file, loaded normally, owns everything
 * after that first paint: the [data-theme-toggle] buttons (partials/
 * theme_toggle.html.twig — cycle clair → sombre → auto), their icon and
 * aria-label, and the live media-query follow-up.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'theme_preference';
    var ORDER = ['light', 'dark', 'auto'];
    var META = {
        light: { icon: 'bi-sun', label: 'Thème : clair (cliquer pour changer)' },
        dark: { icon: 'bi-moon-stars', label: 'Thème : sombre (cliquer pour changer)' },
        auto: { icon: 'bi-circle-half', label: 'Thème : automatique (cliquer pour changer)' }
    };

    /** @type {string} The preference in effect (may be unpersisted). */
    var preference = readStoredPreference();

    /**
     * @returns {string} 'light' | 'dark' | 'auto' — 'auto' when nothing
     * (or anything invalid) is stored, or storage is unavailable.
     */
    function readStoredPreference() {
        var raw = null;
        try {
            raw = localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            // Storage disabled or private browsing — behave as default.
        }
        return raw === 'light' || raw === 'dark' ? raw : 'auto';
    }

    /**
     * Functional cookie consent, read from the client-readable
     * `cookie_consent` cookie (httponly:false on purpose — see
     * CookieConsentService::writeCookie()). No cookie, or unparseable
     * content, means no consent.
     *
     * @returns {boolean}
     */
    function hasFunctionalConsent() {
        var cookies = document.cookie ? document.cookie.split(';') : [];
        for (var i = 0; i < cookies.length; i++) {
            var pair = cookies[i];
            var eq = pair.indexOf('=');
            if (eq === -1) continue;
            if (pair.slice(0, eq).trim() !== 'cookie_consent') continue;
            try {
                var data = JSON.parse(decodeURIComponent(pair.slice(eq + 1).trim()));
                return !!(data?.functional);
            } catch (e) {
                return false;
            }
        }
        return false;
    }

    /** @returns {boolean} Whether the OS currently prefers a dark scheme. */
    function systemPrefersDark() {
        return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }

    /**
     * Resolve a preference to the concrete Bootstrap theme.
     *
     * @param {string} pref 'light' | 'dark' | 'auto'
     * @returns {string} 'light' | 'dark'
     */
    function resolve(pref) {
        if (pref === 'light' || pref === 'dark') return pref;
        return systemPrefersDark() ? 'dark' : 'light';
    }

    /** Apply the current preference to <html> and the toggle buttons. */
    function apply() {
        document.documentElement.setAttribute('data-bs-theme', resolve(preference));
        var meta = META[preference];
        var buttons = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].setAttribute('aria-label', meta.label);
            var icon = buttons[i].querySelector('[data-theme-toggle-icon]');
            if (icon) {
                icon.classList.remove(META.light.icon, META.dark.icon, META.auto.icon);
                icon.classList.add(meta.icon);
            }
        }
    }

    /** @returns {string} The preference in effect ('light'|'dark'|'auto'). */
    function getPreference() {
        return preference;
    }

    /**
     * Change the preference. Persists to localStorage only with functional
     * consent ('auto', the default, is stored as an absence: the key is
     * removed); without consent the change still applies, unpersisted.
     *
     * @param {string} pref 'light' | 'dark' | 'auto'
     */
    function setPreference(pref) {
        if (pref !== 'light' && pref !== 'dark' && pref !== 'auto') return;
        preference = pref;
        if (hasFunctionalConsent()) {
            try {
                if (pref === 'auto') {
                    localStorage.removeItem(STORAGE_KEY);
                } else {
                    localStorage.setItem(STORAGE_KEY, pref);
                }
            } catch (e) {
                // Storage full or disabled — the session behavior above
                // is the whole feature then.
            }
        }
        apply();
    }

    /** One step of the toggle cycle: clair → sombre → automatique. */
    function cycle() {
        setPreference(ORDER[(ORDER.indexOf(preference) + 1) % ORDER.length]);
    }

    // 'auto' follows the OS live. Registered once, unconditionally: the
    // handler itself checks the preference, so a later switch to 'auto'
    // needs no (re-)subscription. addEventListener on a MediaQueryList
    // needs Safari 14+ — every browser this site supports has it, but the
    // guard keeps older engines merely static instead of broken.
    if (window.matchMedia) {
        var media = window.matchMedia('(prefers-color-scheme: dark)');
        if (typeof media.addEventListener === 'function') {
            media.addEventListener('change', function () {
                if (preference === 'auto') apply();
            });
        }
    }

    // Applied the instant this script is evaluated, before waiting for
    // DOMContentLoaded below. On base.html.twig, where a tiny inline
    // <script nonce> in <head> has already set the attribute and this
    // file loads at the end of <body>, that is a harmless no-op. It is
    // what lets a page with NO inline script load this one from its
    // <head> and be themed before its first paint — pwa/offline.html.twig
    // is precisely that page: precached by the service worker, it can
    // carry no nonce (a nonce baked into a cached response goes stale on
    // the very next request), so it was served in light mode to every
    // dark-mode reader.
    //
    // <html> exists as soon as any script runs, whether from <head> or
    // <body>, and apply()'s querySelectorAll simply finds no toggle
    // buttons yet — they are wired on DOMContentLoaded, below.
    apply();

    document.addEventListener('DOMContentLoaded', function () {
        // Re-read here: the module-scope read above ran when the script
        // loaded; in practice identical, but a test (or a slow storage
        // event) may have changed it in between.
        preference = readStoredPreference();
        apply();
        var buttons = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', cycle);
        }
    });

    // Site-wide toolbox global, same pattern as ScoutMagicToast — and the
    // seam tests/js/theme.test.js exercises.
    window.ScoutMagicTheme = {
        getPreference: getPreference,
        setPreference: setPreference,
        cycle: cycle,
        hasFunctionalConsent: hasFunctionalConsent
    };
})();
