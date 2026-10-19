/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The Année scoute page (core/View/templates/admin/scout_year.html.twig):
// every action on it stays on the page (CHANTIER-annee-scoute.md §2.8).
//
// Each control is a real form posting to an /admin/scout-year/* route —
// that is the no-JavaScript fallback, and it works on its own. This file
// intercepts those submits and sends the same fields as JSON instead
// (ScoutMagicApi.postJson); the server answers with the page state it
// re-rendered itself — admin/scout_year/_state.html.twig, the very partial
// the page is made of — and this file swaps it in. Nothing here knows what
// a step looks like: there is one rendering, server-side, and no copy of
// it in JavaScript.
//
// Two kinds of form:
//  - `.js-step-mark`: the disc of a human step. Its `done` field is
//    explicit, written by the server as the opposite of the state it
//    rendered, and sent as a JSON boolean — never « toggle » — so a double
//    click or a second tab can only restate a state. No question: a click
//    on the green disc undoes it (design.md §7.15).
//  - `.js-step-action`: a step button that acts at once (preview, leave
//    the preview, activate or deactivate for the staff) or the review's
//    confirmation. When the step's check wrote a question (data-ask-*), it
//    is asked through ScoutMagicConfirm first; the review is its own
//    question and carries none.
//
// While a call runs its control is disabled and marked busy, and every
// other one is ignored. On success the toast says what happened. On a
// refusal the server still sends the state, which may have moved (another
// tab, another person), and the page shows it; on a network or server
// error nothing is replaced and the toast says nothing changed.
(function () {
    'use strict';

    var FAILED = "L'enregistrement a échoué. Rien n'a changé ; réessayez.";
    // A dialog that never reports itself hidden must not freeze the page.
    var HIDE_TIMEOUT_MS = 1000;

    var root = document.getElementById('scout-year-state');

    // A no-op on every other page of the site.
    if (!root) {
        return;
    }

    var busy = false;

    /**
     * The fields a form would have posted, minus the CSRF token
     * (postJson() adds the page's own).
     *
     * @param {HTMLFormElement} form
     * @param {boolean} isMark
     * @returns {Object<string, string|boolean>}
     */
    function payload(form, isMark) {
        /** @type {Object<string, string|boolean>} */
        var body = {};
        form.querySelectorAll('input[type="hidden"][name]').forEach(function (input) {
            var field = /** @type {HTMLInputElement} */ (input);
            if (field.name !== '_csrf_token') {
                body[field.name] = field.value;
            }
        });
        if (isMark) {
            body.done = body.done === '1';
        }
        return body;
    }

    /**
     * Close the dialog an action was started from before anything else
     * opens — a question on top of a dialog, or a dialog replaced while
     * open, leaves Bootstrap's backdrop behind.
     *
     * @returns {Promise<void>}
     */
    function hideOpenDialog() {
        var open = root.querySelector('.modal.show');
        var Modal = window.bootstrap && window.bootstrap.Modal;
        var instance = open && Modal ? Modal.getInstance(open) : null;
        if (!instance) {
            return Promise.resolve();
        }
        return new Promise(function (resolve) {
            var timer = setTimeout(resolve, HIDE_TIMEOUT_MS);
            open.addEventListener('hidden.bs.modal', function () {
                clearTimeout(timer);
                resolve();
            }, { once: true });
            instance.hide();
        });
    }

    /**
     * @param {HTMLButtonElement|null} button
     * @param {boolean} on
     */
    function setBusy(button, on) {
        root.setAttribute('aria-busy', on ? 'true' : 'false');
        if (!button) {
            return;
        }
        button.disabled = on;
        button.classList.toggle('is-busy', on);
        if (on) {
            button.setAttribute('aria-busy', 'true');
        } else {
            button.removeAttribute('aria-busy');
        }
    }

    /**
     * Swap in what the server rendered, and give focus back to the
     * control that had it, by its stable key.
     *
     * @param {{state?: string, banner?: string}} html
     * @param {string} focusKey
     */
    function replace(html, focusKey) {
        if (typeof html.state === 'string') {
            var Modal = window.bootstrap && window.bootstrap.Modal;
            if (Modal) {
                root.querySelectorAll('.modal').forEach(function (modal) {
                    var instance = Modal.getInstance(modal);
                    if (instance) {
                        instance.dispose();
                    }
                });
            }
            root.innerHTML = html.state;
        }

        var banner = document.getElementById('year-override-banner');
        if (banner && typeof html.banner === 'string') {
            banner.innerHTML = html.banner;
        }

        if (focusKey) {
            var target = /** @type {HTMLElement|null} */ (root.querySelector(
                '[data-focus-key="' + focusKey.replace(/["\\]/g, '') + '"]'
            ));
            if (target && !target.closest('.modal')) {
                target.focus();
            }
        }
    }

    /**
     * @param {string} message
     * @param {'success'|'error'} variant
     */
    function toast(message, variant) {
        if (window.ScoutMagicToast) {
            window.ScoutMagicToast.show(message, { variant: variant });
        }
    }

    /**
     * The control that should have focus once the state is swapped: the
     * one focused now if it belongs to the page (after a dialog closes,
     * Bootstrap puts focus back on what opened it), else the button used.
     *
     * @param {HTMLButtonElement|null} button
     * @returns {string}
     */
    function focusKeyFor(button) {
        var active = document.activeElement;
        if (active instanceof HTMLElement && root.contains(active) && active.dataset.focusKey) {
            return active.dataset.focusKey;
        }
        return button && button.dataset.focusKey ? button.dataset.focusKey : '';
    }

    /**
     * One action, start to finish.
     *
     * @param {HTMLFormElement} form
     * @param {HTMLButtonElement|null} button
     * @param {boolean} isMark
     * @returns {Promise<void>}
     */
    async function run(form, button, isMark) {
        if (busy) {
            return;
        }
        // Claimed before the question, so a second click while it is open
        // does not stack a second one.
        busy = true;
        try {
            if (!isMark) {
                await hideOpenDialog();
                var message = form.dataset.askMessage || '';
                if (message !== '' && window.ScoutMagicConfirm) {
                    var yes = await window.ScoutMagicConfirm.ask({
                        title: form.dataset.askTitle || undefined,
                        message: message,
                        confirmLabel: form.dataset.askLabel || undefined,
                        variant: 'primary'
                    });
                    if (!yes) {
                        return;
                    }
                }
            }

            var focusKey = focusKeyFor(button);
            setBusy(button, true);
            var res = await window.ScoutMagicApi.postJson(form.getAttribute('action') || '', payload(form, isMark));
            var data = res.data && typeof res.data === 'object' ? res.data : {};

            if (data.html && typeof data.html === 'object') {
                replace(data.html, focusKey);
            }
            if (res.ok && data.success === true) {
                toast(typeof data.message === 'string' ? data.message : 'Enregistré.', 'success');
            } else {
                toast(typeof data.error === 'string' && data.error !== '' ? data.error : FAILED, 'error');
            }
        } finally {
            busy = false;
            // Still in the page only when nothing was swapped in.
            setBusy(button && root.contains(button) ? button : null, false);
        }
    }

    root.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        var isMark = form.classList.contains('js-step-mark');
        if (!isMark && !form.classList.contains('js-step-action')) {
            return;
        }
        event.preventDefault();

        var submitter = /** @type {SubmitEvent} */ (event).submitter;
        var button = /** @type {HTMLButtonElement|null} */ (
            submitter instanceof HTMLButtonElement ? submitter : form.querySelector('button[type="submit"]')
        );
        run(form, button, isMark).catch(function () {
            toast(FAILED, 'error');
        });
    });

    // Every listener above is now attached, and this says so.
    //
    // Same convention, and the same reason, as sos-admin.js's `sos-js`
    // marker and confirm.js's delegated flag: a control here is driven by
    // the listener bound HERE, not by markup, so a page whose HTML has
    // rendered is not yet a page that reacts — a click landing in that
    // window posts the plain form and reloads the page.
    // specs/scout-year-transition.spec.js waits for this class before it
    // clicks, because a browser suite can lose that race and a human
    // cannot.
    //
    // Written from here rather than into the template, so a page whose
    // JavaScript failed to load never claims otherwise.
    document.documentElement.classList.add('scout-year-js');
})();
