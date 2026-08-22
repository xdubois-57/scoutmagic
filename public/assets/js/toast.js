/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The site's one client-side notification — window.ScoutMagicToast.
//
// It replaces ~120 native alert() calls (design.md §7.5): a native box
// blocks the thread, styles itself like a phone alarm on iOS, speaks the
// OS language on its button, and vanishes without trace. A toast looks
// like the site, stacks, waits for nobody, and announces itself to screen
// readers — the container is a polite live region, which also makes it
// the site's first aria-live surface for fetch feedback.
//
// Loaded by base.html.twig before {% block scripts %} on every page.
// IIFE exposing a global, same reasoning as api.js. Uses the Bootstrap
// bundle already loaded on every page; if it is somehow absent, the toast
// still shows and removes itself on a timer.
(function () {
    var container = null;

    function ensureContainer() {
        if (container && document.body.contains(container)) {
            return container;
        }
        container = document.createElement('div');
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        // One region for every toast: polite, atomic per message —
        // assertive would interrupt mid-sentence for what is mostly
        // confirmation noise.
        container.setAttribute('aria-live', 'polite');
        document.body.appendChild(container);
        return container;
    }

    /**
     * @param {string} message      plain text — always set via textContent,
     *                              a message must never inject markup
     * @param {{variant?: 'success'|'error'|'warning'|'info', delayMs?: number}} [options]
     *        variant defaults to 'success'; 'error' sticks around longer
     *        by default because it is the one worth reading twice.
     * @returns {HTMLElement} the toast element (tests grip it; callers
     *                        can ignore it)
     */
    function show(message, options) {
        var opts = options || {};
        var variant = opts.variant || 'success';
        // The flash-message vocabulary (success|error|warning) plus info,
        // mapped onto Bootstrap's contrast-guaranteed text-bg-* tones.
        var tone = { success: 'success', error: 'danger', warning: 'warning', info: 'info' }[variant] || 'success';
        var delayMs = opts.delayMs || (variant === 'error' ? 8000 : 4000);

        var toast = document.createElement('div');
        toast.className = 'toast align-items-center text-bg-' + tone + ' border-0 show';
        toast.setAttribute('role', 'status');

        var flex = document.createElement('div');
        flex.className = 'd-flex';
        var body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = message;
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close btn-close-white me-2 m-auto';
        close.setAttribute('aria-label', 'Fermer');
        flex.appendChild(body);
        flex.appendChild(close);
        toast.appendChild(flex);
        ensureContainer().appendChild(toast);

        var Toast = window.bootstrap && window.bootstrap.Toast;
        if (Toast) {
            var instance = new Toast(toast, { delay: delayMs });
            toast.addEventListener('hidden.bs.toast', function () { toast.remove(); });
            close.addEventListener('click', function () { instance.hide(); });
            instance.show();
        } else {
            var timer = setTimeout(function () { toast.remove(); }, delayMs);
            close.addEventListener('click', function () {
                clearTimeout(timer);
                toast.remove();
            });
        }

        return toast;
    }

    window.ScoutMagicToast = { show: show };
})();
